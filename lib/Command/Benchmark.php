<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\DomainBlocksRequestBuilder;
use OCA\Social\Db\FollowedTagsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\SearchTermsRequest;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Mention;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Tools\Nid;
use OCA\Social\Tools\SearchTerms;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Fills the tables with a plausible amount of content and times the queries
 * that serve a timeline, because a query's cost cannot be judged on the dozen
 * rows a development instance has.
 *
 * Everything it writes carries the same host, so `--clean` can take it all
 * back out again and nothing else is touched.
 */
class Benchmark extends SocialCommand {
	/** every actor, note and follow this command writes lives under this host */
	public const HOST = 'benchmark.invalid';

	/**
	 * The page sizes every probe is timed at: Mastodon's default page, and
	 * the largest one `ProbeOptions` lets a client ask for.
	 */
	public const PROBE_LIMITS = [20, ProbeOptions::MAX_LIMIT];

	/** What the probes are, in the order they are timed and reported. */
	public const PROBES = [
		'home', 'public', 'direct', 'notifications',
		'photos', 'videos', 'hashtag', 'account', 'search',
	];

	/*
	 * The mix of what is seeded, each as "one post in N".
	 *
	 * A Photos or Videos timeline narrows the page to the posts carrying that
	 * kind of media, and that narrowing is only measured when media is the
	 * minority it is in life: an instance seeded with text alone answers the
	 * Photos timeline with nothing, at once, and an instance where every post
	 * has a picture answers it as if it were the home timeline. Roughly one
	 * post in seven carrying media is what a Mastodon timeline looks like.
	 */

	/** One post in this many carries a picture. */
	public const IMAGE_EVERY = 10;

	/** One post in this many carries a video, and none of those also a picture. */
	public const VIDEO_EVERY = 25;

	/** One post in this many mentions the viewer, and so notifies them. */
	public const MENTION_EVERY = 20;

	/** One post in this many carries a hashtag. */
	public const HASHTAG_EVERY = 4;

	/** How many distinct hashtags those posts share between them. */
	public const HASHTAGS = 20;

	/** The hashtag the hashtag probe reads: one of the shared ones, as common as any. */
	public const PROBED_TAG = 'benchtag0';

	/**
	 * Words every seeded post says one of, so that a search has something to
	 * find in a known share of them. Thirteen, so that which one a post says
	 * does not line up with which media or hashtag it carries.
	 */
	public const TOPICS = [
		'harbour', 'lantern', 'meadow', 'granite', 'orchard', 'saffron', 'glacier',
		'violin', 'compass', 'thunder', 'marble', 'pelican', 'juniper',
	];

	/** The word the search probe looks for. */
	public const PROBED_WORD = 'harbour';

	/** Every table the seed writes to. */
	public const SEEDED_TABLES = [
		CoreRequestBuilder::TABLE_CACHE_ACTORS,
		CoreRequestBuilder::TABLE_FOLLOWS,
		CoreRequestBuilder::TABLE_STREAM,
		CoreRequestBuilder::TABLE_STREAM_DEST,
		CoreRequestBuilder::TABLE_STREAM_TAGS,
		CoreRequestBuilder::TABLE_SEARCH_TERMS,
	];

	/** How many seeded posts one round of `--clean` takes with it. */
	private const CLEAN_PAGE = 1000;

	/**
	 * How many rows one `INSERT` carries.
	 *
	 * Five hundred tuples of a dozen columns is a statement of a few thousand
	 * placeholders — comfortably inside every driver's limit, and large enough
	 * that the per-statement cost stops mattering.
	 */
	private const CHUNK = 500;

	/**
	 * The most placeholders one `INSERT` binds. A post is thirty-odd columns
	 * and a page of them is split to stay under this; SQLite, the tightest of
	 * the three, allows 32,766.
	 */
	private const MAX_PLACEHOLDERS = 20000;

	/** `--followers`, read in `execute()` and used by the seeder. */
	private int $followersWanted = 0;

	/** The attachment column of a seeded picture or video post, by kind. */
	private array $attachmentTemplates = [];

	public function __construct(
		private StreamRequest $streamRequest,
		private StreamDestRequest $streamDestRequest,
		private CacheActorsRequest $cacheActorsRequest,
		private FollowsRequest $followsRequest,
		private AccountService $accountService,
		private ActorsRequest $actorsRequest,
		private IDBConnection $connection,
		private IRequest $request,
		private ConfigService $configService,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:debug:benchmark')
			->setDescription('Seed a realistic amount of test content and time the timeline queries (development instances only)')
			->addOption('actors', '', InputOption::VALUE_REQUIRED, 'remote actors to seed', '200')
			->addOption('notes', '', InputOption::VALUE_REQUIRED, 'notes to seed', '5000')
			->addOption('follows', '', InputOption::VALUE_REQUIRED, 'of those actors, how many the viewer follows', '150')
			->addOption(
				'followers', '', InputOption::VALUE_REQUIRED,
				'seeded actors that follow the viewer back, to size the delivery fan-out', '0'
			)
			->addOption('viewer', '', InputOption::VALUE_REQUIRED, 'the local account the timelines are read as', '')
			->addOption('seed-only', '', InputOption::VALUE_NONE, 'seed without timing')
			->addOption('time-only', '', InputOption::VALUE_NONE, 'time what is already seeded')
			->addOption('runs', '', InputOption::VALUE_REQUIRED, 'timed runs of each probe; the median is reported', '3')
			->addOption(
				'fail-over', '', InputOption::VALUE_REQUIRED,
				'fail when a probe takes longer than this many milliseconds'
			)
			->addOption(
				'threshold', '', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
				'a ceiling for one probe, as PROBE=MS or PROBE@LIMIT=MS; repeatable'
			)
			->addOption(
				'baseline', '', InputOption::VALUE_REQUIRED,
				'a JSON file of earlier timings per database; a probe also fails over --baseline-factor times its entry'
			)
			->addOption(
				'baseline-factor', '', InputOption::VALUE_REQUIRED,
				'how many times its baseline a probe may take', '3'
			)
			->addOption('clean', '', InputOption::VALUE_NONE, 'remove everything this command wrote')
			->addOption(
				'force', 'f', InputOption::VALUE_NONE,
				'seed without asking (required with --no-interaction)'
			);
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$json = in_array(
			$input->getOption('output'), [self::OUTPUT_FORMAT_JSON, self::OUTPUT_FORMAT_JSON_PRETTY], true
		);
		// with JSON on stdout, everything said along the way goes to stderr,
		// so that what a script redirects into a file is one JSON document
		$log = $output;
		if ($json) {
			$log = ($output instanceof ConsoleOutputInterface) ? $output->getErrorOutput() : new NullOutput();
		}

		if ($input->getOption('clean')) {
			$log->writeln('removed ' . $this->clean() . ' seeded rows');

			return 0;
		}

		// every option is checked before anything is written: a typo in a
		// threshold should not cost the half hour a large seed takes
		try {
			$limits = $this->limitOptions($input);
		} catch (\InvalidArgumentException $e) {
			$log->writeln('<error>' . $e->getMessage() . '</error>');

			return 1;
		}

		$viewer = $this->viewer((string)$input->getOption('viewer'), $log);
		if ($viewer === null) {
			return 1;
		}

		$seeded = null;
		if (!$input->getOption('time-only')) {
			$confirmed = $this->confirmSeeding(
				$input,
				$log,
				(int)$input->getOption('actors'),
				(int)$input->getOption('notes')
			);
			if (!$confirmed) {
				return 1;
			}

			$this->followersWanted = (int)$input->getOption('followers');
			$seeded = [
				'actors' => (int)$input->getOption('actors'),
				'notes' => (int)$input->getOption('notes'),
				'follows' => (int)$input->getOption('follows'),
				'followers' => max(0, $this->followersWanted),
			];
			$this->seed($seeded['actors'], $seeded['notes'], $seeded['follows'], $viewer, $log);
		}

		if ($input->getOption('seed-only')) {
			return 0;
		}

		$results = $this->time($viewer, $limits['runs'], $log);
		$verdicts = self::judge($results, $limits['thresholds'], $limits['failOver'], $limits['baseline'], $limits['factor']);
		$over = array_keys(array_filter($verdicts, static fn (array $v): bool => $v['over']));

		if ($json) {
			$output->writeln((string)json_encode(
				$this->report($results, $verdicts, $over, $seeded, $viewer, $limits['runs']),
				JSON_UNESCAPED_SLASHES | (($input->getOption('output') === self::OUTPUT_FORMAT_JSON_PRETTY) ? JSON_PRETTY_PRINT : 0)
			));
		} else {
			$this->printResults($results, $verdicts, $viewer, $output);
		}

		if ($over !== []) {
			$log->writeln('<error>over their limit: ' . implode(', ', $over) . '</error>');

			return 1;
		}

		return 0;
	}

	/**
	 * `--runs`, `--fail-over`, `--threshold`, `--baseline` and
	 * `--baseline-factor`, read and checked.
	 *
	 * @return array{runs: int, failOver: ?float, thresholds: array<string, float>, baseline: array<string, float>, factor: float}
	 * @throws \InvalidArgumentException naming the option that is wrong
	 */
	private function limitOptions(InputInterface $input): array {
		$runs = (int)$input->getOption('runs');
		if ($runs < 1) {
			throw new \InvalidArgumentException('--runs must be at least 1');
		}

		$failOver = null;
		$raw = $input->getOption('fail-over');
		if ($raw !== null && $raw !== '') {
			$failOver = self::milliseconds((string)$raw, '--fail-over');
		}

		$thresholds = [];
		foreach ((array)$input->getOption('threshold') as $entry) {
			$parts = explode('=', (string)$entry, 2);
			if (count($parts) !== 2 || !self::isProbeKey($parts[0])) {
				throw new \InvalidArgumentException(
					'--threshold ' . $entry . ': expected PROBE=MS or PROBE@LIMIT=MS, PROBE one of '
					. implode(', ', self::PROBES)
				);
			}
			$thresholds[$parts[0]] = self::milliseconds($parts[1], '--threshold ' . $entry);
		}

		$factor = (float)$input->getOption('baseline-factor');
		if ($factor <= 0.0) {
			throw new \InvalidArgumentException('--baseline-factor must be greater than 0');
		}

		$baseline = [];
		$path = (string)($input->getOption('baseline') ?? '');
		if ($path !== '') {
			$baseline = $this->readBaseline($path);
		}

		return [
			'runs' => $runs, 'failOver' => $failOver, 'thresholds' => $thresholds,
			'baseline' => $baseline, 'factor' => $factor,
		];
	}

	/** `home`, or `home@20` for one of `PROBE_LIMITS`. */
	private static function isProbeKey(string $key): bool {
		$parts = explode('@', $key, 2);
		if (!in_array($parts[0], self::PROBES, true)) {
			return false;
		}

		return count($parts) === 1 || in_array((int)$parts[1], self::PROBE_LIMITS, true) && (string)(int)$parts[1] === $parts[1];
	}

	private static function milliseconds(string $value, string $option): float {
		if (!is_numeric($value) || (float)$value <= 0.0) {
			throw new \InvalidArgumentException($option . ': ' . $value . ' is not a positive number of milliseconds');
		}

		return (float)$value;
	}

	/**
	 * This database's entries of a baseline file: `{"pgsql": {"home@20": 12.5,
	 * …}, "mysql": {…}}`. Other top-level keys are free for a note on how
	 * the numbers were measured.
	 *
	 * @return array<string, float>
	 */
	private function readBaseline(string $path): array {
		$content = is_readable($path) ? file_get_contents($path) : false;
		if ($content === false) {
			throw new \InvalidArgumentException('--baseline ' . $path . ': cannot be read');
		}

		$decoded = json_decode($content, true);
		if (!is_array($decoded)) {
			throw new \InvalidArgumentException('--baseline ' . $path . ': not a JSON object');
		}

		$entries = $decoded[$this->databaseName()] ?? [];
		$baseline = [];
		foreach (is_array($entries) ? $entries : [] as $key => $ms) {
			if (self::isProbeKey((string)$key) && str_contains((string)$key, '@') && is_numeric($ms) && (float)$ms > 0.0) {
				$baseline[(string)$key] = (float)$ms;
			}
		}

		return $baseline;
	}

	/**
	 * The limit each timing is held to, and whether it went over.
	 *
	 * A probe's ceiling is its own `--threshold` (for that page size, else for
	 * the probe), else `--fail-over`. Its baseline allowance is
	 * `$factor` times its entry in the baseline. The limit is the larger of
	 * the two: the ceiling keeps a probe whose baseline is two milliseconds
	 * from failing on the noise of a shared runner, and the baseline lets a
	 * probe that has always been slower than the ceiling run without failing
	 * every night — while still failing the night it gets three times slower.
	 * A probe with neither has no limit.
	 *
	 * @param array<string, array{probe: string, limit: int, ms: float, rows: int}> $results
	 * @param array<string, float> $thresholds
	 * @param array<string, float> $baseline
	 *
	 * @return array<string, array{limit_ms: ?float, over: bool}>
	 */
	public static function judge(
		array $results, array $thresholds, ?float $failOver, array $baseline, float $factor,
	): array {
		$verdicts = [];
		foreach ($results as $key => $result) {
			$ceiling = $thresholds[$key] ?? $thresholds[$result['probe']] ?? $failOver;
			$allowance = isset($baseline[$key]) ? $baseline[$key] * $factor : null;

			$limit = null;
			if ($ceiling !== null || $allowance !== null) {
				$limit = max($ceiling ?? 0.0, $allowance ?? 0.0);
			}

			$verdicts[$key] = ['limit_ms' => $limit, 'over' => $limit !== null && $result['ms'] > $limit];
		}

		return $verdicts;
	}

	/**
	 * This command ships to every install (appinfo/info.xml registers it), and
	 * seeding writes thousands of rows into whichever database occ is pointed
	 * at. There is nothing in the name to warn somebody that `social:debug:benchmark`
	 * is not read-only, so it asks — and refuses to guess when nobody is
	 * there to answer.
	 */
	private function confirmSeeding(InputInterface $input, OutputInterface $output, int $actors, int $notes): bool {
		$output->writeln(
			'<error>This writes ' . $notes . ' notes and ' . $actors
			. ' remote actors into the database this occ is pointed at.</error>'
		);
		$output->writeln(
			'Everything it writes carries the host <info>' . self::HOST
			. '</info>, so "--clean" takes it all back out — but do not run it on a'
			. ' production instance.'
		);
		$output->writeln('');

		if ($input->getOption('force')) {
			return true;
		}

		if (!$input->isInteractive()) {
			$output->writeln(
				'<error>Refusing to seed non-interactively without --force.</error>'
			);

			return false;
		}

		$question = new ConfirmationQuestion(
			'<info>Seed this database?</info> (y/N) ', false, '/^(y|Y)/i'
		);

		if ((bool)$this->questionHelper()->ask($input, $output, $question)) {
			return true;
		}

		$output->writeln('cancelled, nothing was written.');

		return false;
	}

	private function viewer(string $username, OutputInterface $output): ?Person {
		try {
			if ($username === '') {
				$actor = $this->actorsRequest->getAny();
				if ($actor === null) {
					$output->writeln('<error>no local account to read as; create one first</error>');
				}

				return $actor;
			}

			return $this->accountService->getActor($username);
		} catch (\Exception $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return null;
		}
	}

	/** The id of the `$i`th seeded actor. */
	public static function actorId(int $i): string {
		return 'https://' . self::HOST . '/users/actor' . $i;
	}

	/**
	 * Writes the rows, in bulk.
	 *
	 * **Not through the model layer.** An earlier version of this built a
	 * `Note`, called `StreamRequest::save()` and then `generateStreamDest()`
	 * for each post: three statements and a transaction per row, which is
	 * about 400 rows a second and makes ten million posts a seven-hour wait —
	 * so nobody ever seeded enough to find out what the queries cost, which is
	 * the entire purpose of the command. Multi-row `INSERT`s of
	 * `self::CHUNK` tuples do the same work at tens of thousands of rows a
	 * second.
	 *
	 * The cost of going around the model is that this has to write the columns
	 * the model would have written, and a column added later is one this
	 * forgets. That is the right trade for a development-only command and the
	 * wrong one for anything else: what is seeded has to be *shaped* like real
	 * data — the prim hashes, the recipient rows, the follower collections —
	 * because a query plan is only worth measuring against rows the planner
	 * sees the way it sees real ones.
	 */
	private function seed(int $actors, int $notes, int $follows, Person $viewer, OutputInterface $output): void {
		$followers = max(0, $this->followersWanted);
		$output->writeln(sprintf(
			'seeding %s actors, %s notes, %s follows, %s followers…',
			number_format($actors), number_format($notes),
			number_format($follows), number_format($followers)
		));

		$started = microtime(true);
		$ids = $this->seedActors($actors, $output);
		$this->seedFollows($ids, $follows, $followers, $viewer, $output);
		$this->seedNotes($ids, $notes, $viewer, $output);
		$this->analyze();

		$output->writeln(sprintf('seeded in %.1f s.', microtime(true) - $started));
	}

	/**
	 * Brings the planner's statistics up to date with what was just written.
	 *
	 * A table that grew by a hundred thousand rows in a minute is one whose
	 * statistics still describe it as it was, until autovacuum or InnoDB's
	 * sampling gets round to it — and a plan made from those is not the plan
	 * a live instance gets. Timings taken straight after a seed then depend
	 * on how soon that happened, which is noise in a number that is compared
	 * night to night.
	 */
	private function analyze(): void {
		$database = $this->databaseName();
		foreach (self::SEEDED_TABLES as $table) {
			$sql = match ($database) {
				'pgsql' => 'ANALYZE "*PREFIX*' . $table . '"',
				'mysql' => 'ANALYZE TABLE `*PREFIX*' . $table . '`',
				'sqlite' => 'ANALYZE `*PREFIX*' . $table . '`',
				default => '',
			};
			if ($sql === '') {
				return;
			}

			// MySQL answers ANALYZE TABLE with a result set, the others with none
			$result = $this->connection->executeQuery($sql);
			$result->closeCursor();
		}
	}

	/**
	 * The remote accounts everything else hangs off.
	 *
	 * @return string[] their ids, in the order they were written
	 */
	private function seedActors(int $actors, OutputInterface $output): array {
		$ids = [];
		$rows = [];
		$now = $this->stamp();

		for ($i = 0; $i < $actors; $i++) {
			$id = self::actorId($i);
			$ids[] = $id;
			$rows[] = [
				'id' => $id,
				'id_prim' => md5($id),
				'type' => Person::TYPE,
				'account' => 'actor' . $i . '@' . self::HOST,
				'account_lower' => 'actor' . $i . '@' . self::HOST,
				'preferred_username' => 'actor' . $i,
				'name' => 'Actor ' . $i,
				'inbox' => $id . '/inbox',
				'shared_inbox' => 'https://' . self::HOST . '/inbox',
				'outbox' => $id . '/outbox',
				'followers' => $id . '/followers',
				'following' => $id . '/following',
				'source' => '{}',
				'details' => '{}',
				'local' => 0,
				'creation' => $now,
			];

			if (count($rows) >= self::CHUNK) {
				$this->insertMany(CoreRequestBuilder::TABLE_CACHE_ACTORS, $rows);
				$rows = [];
				$this->progress($output, 'actors', $i + 1, $actors);
			}
		}

		$this->insertMany(CoreRequestBuilder::TABLE_CACHE_ACTORS, $rows);
		$this->progress($output, 'actors', $actors, $actors, true);

		return $ids;
	}

	/**
	 * Who follows whom.
	 *
	 * Two directions, because they cost different things. The viewer following
	 * seeded accounts is what the **home timeline** reads: its page query
	 * resolves the viewer's follows and then the posts addressed to each of
	 * their follower collections. Seeded accounts following the viewer is what
	 * **delivery** reads: one queue row per distinct inbox when the viewer
	 * posts. An instance seeded with only the first measures reads and says
	 * nothing about writes.
	 *
	 * @param string[] $ids
	 */
	private function seedFollows(
		array $ids, int $follows, int $followers, Person $viewer, OutputInterface $output,
	): void {
		if ($ids === []) {
			return;
		}

		$rows = [];
		$now = $this->stamp();
		$written = 0;
		$wanted = min($follows, count($ids)) + min($followers, count($ids));

		for ($i = 0; $i < min($follows, count($ids)); $i++) {
			$id = $ids[$i];
			// the followed actor's **followers collection**, which is what a
			// recipient row names — not the actor itself
			$rows[] = $this->followRow(
				$id . '/follow/' . $viewer->getPreferredUsername(),
				$viewer->getId(), $id, $id . '/followers', $now
			);
			$written++;
			if (count($rows) >= self::CHUNK) {
				$this->insertMany(CoreRequestBuilder::TABLE_FOLLOWS, $rows);
				$rows = [];
				$this->progress($output, 'follows', $written, $wanted);
			}
		}

		for ($i = 0; $i < min($followers, count($ids)); $i++) {
			$id = $ids[$i];
			$rows[] = $this->followRow(
				$id . '/follows-back/' . $viewer->getPreferredUsername(),
				$id, $viewer->getId(), $viewer->getFollowers(), $now
			);
			$written++;
			if (count($rows) >= self::CHUNK) {
				$this->insertMany(CoreRequestBuilder::TABLE_FOLLOWS, $rows);
				$rows = [];
				$this->progress($output, 'follows', $written, $wanted);
			}
		}

		$this->insertMany(CoreRequestBuilder::TABLE_FOLLOWS, $rows);
		$this->progress($output, 'follows', $wanted, $wanted, true);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function followRow(
		string $id, string $actorId, string $objectId, string $followId, string $now,
	): array {
		return [
			'id' => $id,
			'id_prim' => md5($id),
			'type' => Follow::TYPE,
			'actor_id' => $actorId,
			'actor_id_prim' => md5($actorId),
			'object_id' => $objectId,
			'object_id_prim' => md5($objectId),
			'follow_id' => $followId,
			'follow_id_prim' => md5($followId),
			'accepted' => 1,
			'creation' => $now,
		];
	}

	/**
	 * The posts, and every row that puts them somewhere: the recipient rows of
	 * the timelines, the tag rows of the hashtag timeline, the words of the
	 * search index, and a notification for each post that mentions the viewer.
	 *
	 * All of them are written here rather than derived from the posts,
	 * because each `generate…()` is a statement per row and the whole point of
	 * this path is not to do that. What is written is what those methods
	 * would have written for a public post: recipient rows for the public
	 * collection, the author and the author's follower collection, each
	 * carrying the post's nid as the home timeline's page query reads it.
	 *
	 * @param string[] $ids
	 */
	private function seedNotes(array $ids, int $notes, Person $viewer, OutputInterface $output): void {
		if ($ids === [] || $notes < 1) {
			return;
		}

		$rows = self::emptyBatch();
		$now = time();

		for ($i = 0; $i < $notes; $i++) {
			$this->noteRows($i, $ids, $viewer, $now, $rows);

			if (count($rows[CoreRequestBuilder::TABLE_STREAM]) >= self::CHUNK) {
				$this->insertBatch($rows);
				$rows = self::emptyBatch();
				$this->progress($output, 'notes', $i + 1, $notes);
			}
		}

		$this->insertBatch($rows);
		$this->progress($output, 'notes', $notes, $notes, true);
	}

	/** @return array<string, list<array<string, mixed>>> */
	private static function emptyBatch(): array {
		return [
			CoreRequestBuilder::TABLE_STREAM => [],
			CoreRequestBuilder::TABLE_STREAM_DEST => [],
			CoreRequestBuilder::TABLE_STREAM_TAGS => [],
			CoreRequestBuilder::TABLE_SEARCH_TERMS => [],
		];
	}

	/** @param array<string, list<array<string, mixed>>> $rows */
	private function insertBatch(array $rows): void {
		foreach ($rows as $table => $tableRows) {
			$this->insertMany($table, $tableRows);
		}
	}

	/**
	 * What kind of media the `$i`th seeded post carries: `video`, `image`,
	 * or `''` for none.
	 */
	public static function mediaOf(int $i): string {
		if ($i % self::VIDEO_EVERY === 7) {
			return 'video';
		}

		return ($i % self::IMAGE_EVERY === 3) ? 'image' : '';
	}

	/** The hashtag the `$i`th seeded post carries, `''` for none. */
	public static function hashtagOf(int $i): string {
		if ($i % self::HASHTAG_EVERY !== 0) {
			return '';
		}

		return 'benchtag' . (intdiv($i, self::HASHTAG_EVERY) % self::HASHTAGS);
	}

	/** Whether the `$i`th seeded post mentions the viewer. */
	public static function mentionsViewer(int $i): bool {
		return $i % self::MENTION_EVERY === 11;
	}

	/**
	 * The rows of the `$i`th seeded post, added to `$rows`.
	 *
	 * @param string[] $ids the seeded actors, who take turns writing
	 * @param array<string, list<array<string, mixed>>> $rows
	 */
	private function noteRows(int $i, array $ids, Person $viewer, int $now, array &$rows): void {
		$author = $ids[$i % count($ids)];
		$id = 'https://' . self::HOST . '/notes/' . $i;
		$prim = md5($id);
		// spread over the recent past, newest first, so that a page of
		// twenty is a page of twenty *recent* posts as it would be in life
		$published = $now - $i * 5;
		$followers = $author . '/followers';
		// the same snowflake `StreamRequest::save()` mints: the second it was
		// published in, times the limit, plus a random offset. It is the column
		// every timeline pages and orders on, so a seeded row whose nid did not
		// sort with the real ones would measure a different query than the one
		// being served.
		$nid = Nid::fromPublishedTime($published, random_int(1, StreamRequest::NID_LIMIT - 1), StreamRequest::NID_LIMIT);

		$media = self::mediaOf($i);
		$hashtag = self::hashtagOf($i);
		$mention = self::mentionsViewer($i);
		$cc = [$followers];
		$content = '<p>seeded note ' . $i . ' about ' . self::TOPICS[max(0, $i) % count(self::TOPICS)];
		if ($mention) {
			$cc[] = $viewer->getId();
			$content .= ' <span class="h-card"><a href="' . $viewer->getId() . '" class="u-url mention">@<span>'
				. $viewer->getPreferredUsername() . '</span></a></span>';
		}
		if ($hashtag !== '') {
			$content .= ' <a href="https://' . self::HOST . '/tags/' . $hashtag . '" class="mention hashtag" rel="tag">#<span>'
				. $hashtag . '</span></a>';
		}
		$content .= '</p>';
		$attachments = ($media === '') ? '[]' : $this->attachmentsOf($media);

		$rows[CoreRequestBuilder::TABLE_STREAM][] = $this->streamRow([
			'nid' => $nid,
			'id' => $id,
			'id_prim' => $prim,
			'type' => Note::TYPE,
			'to' => ACore::CONTEXT_PUBLIC,
			'cc' => (string)json_encode($cc, JSON_UNESCAPED_SLASHES),
			'content' => $content,
			'published' => gmdate('Y-m-d\TH:i:s\Z', $published),
			'published_time' => $this->stamp($published),
			'attributed_to' => $author,
			'attributed_to_prim' => md5($author),
			'author_host' => DomainBlocksRequestBuilder::authorHostOf($author),
			'visibility' => Stream::TYPE_PUBLIC,
			'hashtags' => (string)json_encode(($hashtag === '') ? [] : [$hashtag]),
			'attachments' => $attachments,
			'media_kind' => Stream::mediaKindOf($attachments),
			'news_kind' => Stream::newsKindOf($content),
			'creation' => $this->stamp($published),
		]);

		foreach ([
			[ACore::CONTEXT_PUBLIC, 'to'],
			[$author, 'to'],
			[$followers, 'cc'],
		] as [$recipient, $subtype]) {
			$rows[CoreRequestBuilder::TABLE_STREAM_DEST][] = self::destRow($prim, $recipient, 'recipient', $subtype, $nid);
		}

		if ($hashtag !== '') {
			$rows[CoreRequestBuilder::TABLE_STREAM_TAGS][] = [
				'stream_id' => $prim, 'hashtag' => FollowedTagsRequest::normalise($hashtag),
			];
		}

		foreach (SearchTerms::ofHtml($content) as $term) {
			$rows[CoreRequestBuilder::TABLE_SEARCH_TERMS][] = [
				'term' => $term, 'head' => SearchTerms::head($term), 'nid' => $nid, 'stream_id_prim' => $prim,
			];
		}

		if (!$mention) {
			return;
		}

		// the post names the viewer, so it reaches them as a recipient, and
		// the inbox writes them a notification of it: a row of its own, found
		// by its `notif` recipient row, as `NoteInterface` saves it
		$rows[CoreRequestBuilder::TABLE_STREAM_DEST][] = self::destRow($prim, $viewer->getId(), 'recipient', 'cc', $nid);

		$notificationId = $id . '/notification+mention/' . md5($viewer->getId());
		$notificationPrim = md5($notificationId);
		$notificationNid = Nid::fromPublishedTime($published, random_int(1, StreamRequest::NID_LIMIT - 1), StreamRequest::NID_LIMIT);
		$rows[CoreRequestBuilder::TABLE_STREAM][] = $this->streamRow([
			'nid' => $notificationNid,
			'id' => $notificationId,
			'id_prim' => $notificationPrim,
			'type' => SocialAppNotification::TYPE,
			'subtype' => Mention::TYPE,
			'to' => $viewer->getId(),
			'summary' => '{account} mentioned you in a post',
			'published' => gmdate('Y-m-d\TH:i:s\Z', $published),
			'published_time' => $this->stamp($published),
			'attributed_to' => $author,
			'attributed_to_prim' => md5($author),
			'author_host' => DomainBlocksRequestBuilder::authorHostOf($author),
			'object_id' => $id,
			'object_id_prim' => $prim,
			'local' => 1,
			'creation' => $this->stamp($published),
		]);
		$rows[CoreRequestBuilder::TABLE_STREAM_DEST][] = self::destRow(
			$notificationPrim, $viewer->getId(), 'notif', '', $notificationNid
		);
	}

	/**
	 * One `social_stream` row: `$values` over the defaults of a post that
	 * says nothing. Every row has the same columns, which a multi-row
	 * `INSERT` needs.
	 *
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	private function streamRow(array $values): array {
		return array_merge([
			'nid' => '0',
			'id' => '',
			'id_prim' => '',
			'type' => '',
			'subtype' => '',
			'to' => '',
			'to_array' => '[]',
			'cc' => '[]',
			'bcc' => '[]',
			'content' => '',
			'summary' => '',
			'published' => '',
			'published_time' => '',
			'attributed_to' => '',
			'attributed_to_prim' => '',
			'author_host' => '',
			'object_id' => '',
			'object_id_prim' => '',
			'visibility' => '',
			'hashtags' => '[]',
			'details' => '{}',
			'source' => '{}',
			'instances' => '[]',
			'attachments' => '[]',
			'media_kind' => Stream::MEDIA_KIND_NONE,
			'news_kind' => '',
			'cache' => '{}',
			'local' => 0,
			'filter_duplicate' => 0,
			'creation' => '',
		], $values);
	}

	/** @return array{stream_id: string, actor_id: string, type: string, subtype: string, nid: int|string} */
	private static function destRow(string $streamPrim, string $actorId, string $type, string $subtype, int|string $nid): array {
		return ['stream_id' => $streamPrim, 'actor_id' => md5($actorId), 'type' => $type, 'subtype' => $subtype, 'nid' => $nid];
	}

	/**
	 * The `attachments` column of a seeded post carrying one `$kind`: what
	 * `StreamRequest::save()` stores for a remote picture or video this
	 * instance holds no copy of. Built once through the model and reused.
	 */
	private function attachmentsOf(string $kind): string {
		if (!isset($this->attachmentTemplates[$kind])) {
			$media = new MediaAttachment();
			$extension = ($kind === 'video') ? 'mp4' : 'jpg';
			$media->setId('benchmark-' . $kind);
			$media->setType($kind);
			$media->setMediaType(MediaAttachment::guessMediaType($kind, 'x.' . $extension));
			$media->setUrl('https://' . self::HOST . '/media/' . $kind . '.' . $extension);
			$media->setPreviewUrl('https://' . self::HOST . '/media/' . $kind . '-preview.jpg');
			$this->attachmentTemplates[$kind] = (string)json_encode([$media->asLocal()], JSON_UNESCAPED_SLASHES);
		}

		return $this->attachmentTemplates[$kind];
	}

	/**
	 * One `INSERT` carrying many rows.
	 *
	 * Written as SQL rather than through `IQueryBuilder`, which has no
	 * multi-row form: the placeholders are positional and every value is bound,
	 * so it is the same escaping the builder would do and it runs unchanged on
	 * MySQL, PostgreSQL and SQLite. The column list comes from the first row,
	 * and every row is required to have the same keys — which they do, because
	 * each is built by one loop above.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 */
	protected function insertMany(string $table, array $rows): void {
		if ($rows === []) {
			return;
		}

		$columns = array_keys($rows[0]);
		$perStatement = max(1, intdiv(self::MAX_PLACEHOLDERS, count($columns)));
		if (count($rows) > $perStatement) {
			foreach (array_chunk($rows, $perStatement) as $chunk) {
				$this->insertMany($table, $chunk);
			}

			return;
		}

		$platform = $this->databaseName();
		$quote = ($platform === 'pgsql') ? '"' : '`';
		$quoted = array_map(static fn (string $c): string => $quote . $c . $quote, $columns);

		$tuple = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
		$values = [];
		foreach ($rows as $row) {
			foreach ($columns as $column) {
				$values[] = $row[$column];
			}
		}

		// A seed that dies halfway and cannot be run again is a tool nobody
		// uses twice: the second attempt collides with the actors the first
		// one wrote. Every database spells "insert what is new and say nothing
		// about the rest" differently, and all three of them have it.
		$verb = 'INSERT IGNORE';
		$suffix = '';
		if ($platform === 'pgsql') {
			$verb = 'INSERT';
			$suffix = ' ON CONFLICT DO NOTHING';
		} elseif ($platform === 'sqlite') {
			$verb = 'INSERT OR IGNORE';
		}

		$sql = $verb . ' INTO `*PREFIX*' . $table . '` (' . implode(', ', $quoted) . ') VALUES '
			. implode(', ', array_fill(0, count($rows), $tuple)) . $suffix;

		$this->connection->executeStatement($sql, $values);
	}

	/**
	 * `pgsql`, `mysql` or `sqlite`, the names a baseline file is keyed by;
	 * `other` for anything else.
	 */
	protected function databaseName(): string {
		// The platform classes live in Doctrine, which the OCP stub this app is
		// analysed against does not carry, so the platform is identified by
		// name rather than by `instanceof`.
		/** @psalm-suppress UndefinedDocblockClass the ocp stub carries no Doctrine platform classes */
		$platform = strtolower(get_class($this->connection->getDatabasePlatform()));

		return match (true) {
			str_contains($platform, 'postgres') => 'pgsql',
			str_contains($platform, 'sqlite') => 'sqlite',
			str_contains($platform, 'mysql'), str_contains($platform, 'maria') => 'mysql',
			default => 'other',
		};
	}

	/** The datetime string every `creation` column in this app carries. */
	private function stamp(?int $at = null): string {
		return date('Y-m-d H:i:s', $at ?? time());
	}

	/**
	 * Says how far it has got, on one line.
	 *
	 * Seeding ten million rows is minutes, and a command that prints nothing
	 * for minutes is one somebody kills.
	 */
	private function progress(
		OutputInterface $output, string $what, int $done, int $total, bool $last = false,
	): void {
		if ($total < 1) {
			return;
		}

		$output->write(sprintf(
			"\r  %-8s %s / %s (%d%%)   ",
			$what, number_format($done), number_format($total), (int)((float)$done / (float)$total * 100.0)
		));

		if ($last) {
			$output->writeln('');
		}
	}

	/**
	 * What each probe reads, as the web client asks for it: Photos and
	 * Videos are the home timeline narrowed to a kind of media, the account
	 * is the first seeded actor's profile, the search is a word a known share
	 * of the seeded posts say.
	 *
	 * @return array<string, callable(int): int> probe => reads one page of
	 *                                           that size and says how many rows it had
	 */
	private function probes(): array {
		$timeline = fn (string $probe, ?callable $narrow = null): callable => function (int $limit) use ($probe, $narrow): int {
			$options = new ProbeOptions($this->request);
			$options->setProbe($probe)->setLimit($limit);
			if ($narrow !== null) {
				$narrow($options);
			}

			return count($this->streamRequest->getTimeline($options));
		};

		return [
			'home' => $timeline(ProbeOptions::HOME),
			'public' => $timeline(ProbeOptions::PUBLIC),
			'direct' => $timeline(ProbeOptions::DIRECT),
			'notifications' => $timeline(ProbeOptions::NOTIFICATIONS),
			'photos' => $timeline(ProbeOptions::HOME, static function (ProbeOptions $options): void {
				$options->setOnlyMedia(true)->setMediaType('image');
			}),
			'videos' => $timeline(ProbeOptions::HOME, static function (ProbeOptions $options): void {
				$options->setOnlyMedia(true)->setOnlyVideo(true);
			}),
			'hashtag' => $timeline(ProbeOptions::HASHTAG, static function (ProbeOptions $options): void {
				$options->setArgument(self::PROBED_TAG);
			}),
			'account' => $timeline(ProbeOptions::ACCOUNT, static function (ProbeOptions $options): void {
				$options->setAccountId(self::actorId(0));
			}),
			'search' => fn (int $limit): int => count($this->streamRequest->searchContent(self::PROBED_WORD, $limit)),
		];
	}

	/**
	 * Every probe at every page size.
	 *
	 * @return array<string, array{probe: string, limit: int, ms: float, rows: int}> keyed `probe@limit`
	 */
	private function time(Person $viewer, int $runs, OutputInterface $log): array {
		$this->streamRequest->setViewer($viewer);
		$log->writeln('');
		$log->writeln('timing the timeline queries as ' . $viewer->getPreferredUsername() . '…');

		$results = [];
		foreach ($this->probes() as $probe => $read) {
			foreach (self::PROBE_LIMITS as $limit) {
				[$ms, $rows] = $this->measure($read, $limit, $runs);
				$results[$probe . '@' . $limit] = ['probe' => $probe, 'limit' => $limit, 'ms' => round($ms, 2), 'rows' => $rows];
			}
		}

		return $results;
	}

	/**
	 * The median of `$runs` reads of one page, after one that is not counted:
	 * the first read warms the caches, and what is reported is what a served
	 * request costs. The median rather than the mean, so that one run that
	 * met a checkpoint or a neighbour's job does not decide the number.
	 *
	 * @param callable(int): int $read
	 * @return array{0: float, 1: int} milliseconds, and the rows the page had
	 */
	protected function measure(callable $read, int $limit, int $runs): array {
		$read($limit);

		$times = [];
		$rows = 0;
		for ($run = 0; $run < $runs; $run++) {
			$start = (float)hrtime(true);
			$rows = $read($limit);
			$times[] = ((float)hrtime(true) - $start) / 1e6;
		}

		return [self::median($times), $rows];
	}

	/** @param list<float> $values */
	public static function median(array $values): float {
		if ($values === []) {
			return 0.0;
		}

		sort($values);
		$middle = intdiv(count($values), 2);

		return (count($values) % 2 === 1) ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2.0;
	}

	/**
	 * @param array<string, array{probe: string, limit: int, ms: float, rows: int}> $results
	 * @param array<string, array{limit_ms: ?float, over: bool}> $verdicts
	 */
	private function printResults(array $results, array $verdicts, Person $viewer, OutputInterface $output): void {
		$output->writeln('');
		$output->writeln('as ' . $viewer->getPreferredUsername() . ', median of each probe:');
		foreach ($results as $key => $result) {
			$limit = $verdicts[$key]['limit_ms'];
			$output->writeln(sprintf(
				'  %-14s limit %-4d %8.1f ms  (%d rows)%s',
				$result['probe'], $result['limit'], $result['ms'], $result['rows'],
				($limit === null) ? '' : sprintf('  %s %.0f ms', $verdicts[$key]['over'] ? 'OVER' : '≤', $limit)
			));
		}
	}

	/**
	 * What `--output=json` prints: the timings, what they were held to, and
	 * enough about the instance to tell two runs' numbers apart.
	 *
	 * @param array<string, array{probe: string, limit: int, ms: float, rows: int}> $results
	 * @param array<string, array{limit_ms: ?float, over: bool}> $verdicts
	 * @param string[] $over
	 * @param ?array<string, int> $seeded
	 * @return array<string, mixed>
	 */
	private function report(array $results, array $verdicts, array $over, ?array $seeded, Person $viewer, int $runs): array {
		$probes = [];
		foreach ($results as $key => $result) {
			$probes[$key] = $result + $verdicts[$key];
		}

		return [
			'database' => $this->databaseName(),
			'php' => PHP_VERSION,
			'measured_at' => gmdate('Y-m-d\TH:i:s\Z'),
			'viewer' => $viewer->getPreferredUsername(),
			'runs' => $runs,
			'seeded' => $seeded,
			// the two switches that decide which query a timeline or a search
			// is answered by; a number measured with either off is a number
			// about the slower path
			'flags' => [
				'search_index_ready' => $this->configService->getAppValue(SearchTermsRequest::READY) === '1',
				'dest_nid_filled' => $this->configService->getAppValueBool(ConfigService::SOCIAL_DEST_NID_FILLED),
			],
			'probes' => $probes,
			'over' => $over,
		];
	}

	/**
	 * Takes back out everything `seed()` wrote.
	 *
	 * The seeded rows carry the host in their ids — but only in the columns
	 * that hold an id. The rows that *point* at them do not: a recipient row
	 * keys its post by `stream_id`, which is the md5 of the post's id, so
	 * `LIKE '%benchmark.invalid%'` could never match one. That is how a
	 * previous version of this left 60,000 recipient rows behind while
	 * reporting that it had removed everything: twelve times as many rows as
	 * the instance's own, with no post to belong to, on the table every
	 * timeline reads.
	 *
	 * So the posts are found first and their dependants deleted by the key
	 * they are actually keyed on, in pages — this can be a hundred thousand
	 * rows and an `IN` list has a size limit on every database.
	 *
	 * A full scan is the right trade for the rest: this runs by hand on a
	 * development instance, never on a request.
	 */
	private function clean(): int {
		$like = '%' . $this->connection->escapeLikeParameter(self::HOST) . '%';
		$removed = 0;

		// what the seeded posts are keyed by, a page at a time
		while (true) {
			$prims = $this->seededStreamPrims($like);
			if ($prims === []) {
				break;
			}

			foreach ([
				[CoreRequestBuilder::TABLE_STREAM_DEST, 'stream_id'],
				[CoreRequestBuilder::TABLE_STREAM_TAGS, 'stream_id'],
				[CoreRequestBuilder::TABLE_STREAM_ACTIONS, 'stream_id_prim'],
				[CoreRequestBuilder::TABLE_SEARCH_TERMS, 'stream_id_prim'],
			] as [$table, $column]) {
				$qb = $this->connection->getQueryBuilder();
				$qb->delete($table)->where(
					$qb->expr()->in($column, $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY))
				);
				$removed += $qb->executeStatement();
			}

			$qb = $this->connection->getQueryBuilder();
			$qb->delete(CoreRequestBuilder::TABLE_STREAM)->where(
				$qb->expr()->in('id_prim', $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY))
			);
			$removed += $qb->executeStatement();
		}

		// the actors, and the follows in either direction: a seeded follow has
		// the viewer as its actor, so matching only the object would leave it
		foreach ([
			[CoreRequestBuilder::TABLE_CACHE_ACTORS, ['id']],
			[CoreRequestBuilder::TABLE_FOLLOWS, ['id', 'object_id', 'follow_id']],
		] as [$table, $columns]) {
			foreach ($columns as $column) {
				$qb = $this->connection->getQueryBuilder();
				$qb->delete($table)->where($qb->expr()->like($column, $qb->createNamedParameter($like)));
				$removed += $qb->executeStatement();
			}
		}

		return $removed;
	}

	/**
	 * @param string $like the seeded host, as a LIKE pattern
	 * @return string[] the `id_prim` of one page of seeded posts
	 */
	private function seededStreamPrims(string $like): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('id_prim')
			->from(CoreRequestBuilder::TABLE_STREAM)
			->where($qb->expr()->like('id', $qb->createNamedParameter($like)))
			->setMaxResults(self::CLEAN_PAGE);

		$cursor = $qb->executeQuery();
		$prims = [];
		while ($row = $cursor->fetch()) {
			$prim = (string)($row['id_prim'] ?? '');
			if ($prim !== '') {
				$prims[] = $prim;
			}
		}
		$cursor->closeCursor();

		return $prims;
	}
}
