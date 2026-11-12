<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Command;

use OCA\Social\Command\Benchmark;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ConfigService;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ social:debug:benchmark`: what it seeds, what each probe asks, and how a
 * timing is held to its limit.
 *
 * The rows are caught on their way to the database and the timings are
 * scripted, so this pins the shape of the seed and the verdicts; what the
 * queries cost against the rows is the nightly workflow's to measure.
 */
class BenchmarkTest extends TestCase {
	private const VIEWER = 'https://cloud.example.org/users/viewer';

	/** @var array<string, list<array<string, mixed>>> rows by table, as the command wrote them */
	private array $written = [];

	/** @var list<ProbeOptions> */
	private array $asked = [];

	/** @var list<array{string, int}> */
	private array $searched = [];

	/** @var list<string> the statements run outside the multi-row inserts */
	private array $statements = [];

	private function viewer(): Person {
		$viewer = new Person();
		$viewer->setId(self::VIEWER);
		$viewer->setPreferredUsername('viewer');
		$viewer->setFollowers(self::VIEWER . '/followers');

		return $viewer;
	}

	/**
	 * The command, with its writes recorded and, when `$ms` is given, every
	 * probe taking that many milliseconds.
	 */
	private function tester(?float $ms = null, string $database = 'sqlite'): CommandTester {
		$accountService = $this->createStub(AccountService::class);
		$accountService->method('getActor')->willReturn($this->viewer());

		$streamRequest = $this->createStub(StreamRequest::class);
		$streamRequest->method('getTimeline')->willReturnCallback(function (ProbeOptions $options): array {
			$this->asked[] = clone $options;

			return array_fill(0, $options->getLimit(), new Note());
		});
		$streamRequest->method('searchContent')->willReturnCallback(function (string $term, int $limit): array {
			$this->searched[] = [$term, $limit];

			return array_fill(0, $limit, new Note());
		});

		$connection = $this->createStub(IDBConnection::class);
		$connection->method('executeQuery')->willReturnCallback(function (string $sql): IResult {
			$this->statements[] = $sql;

			return $this->createStub(IResult::class);
		});

		$written = &$this->written;
		$command = new class($streamRequest, $this->createStub(StreamDestRequest::class), $this->createStub(CacheActorsRequest::class), $this->createStub(FollowsRequest::class), $accountService, $this->createStub(ActorsRequest::class), $connection, $this->createStub(IRequest::class), $this->createStub(ConfigService::class), $written, $ms, $database) extends Benchmark {
			/** @var array<string, list<array<string, mixed>>> */
			private array $sink;

			public function __construct(
				StreamRequest $streamRequest,
				StreamDestRequest $streamDestRequest,
				CacheActorsRequest $cacheActorsRequest,
				FollowsRequest $followsRequest,
				AccountService $accountService,
				ActorsRequest $actorsRequest,
				IDBConnection $connection,
				IRequest $request,
				ConfigService $configService,
				array &$sink,
				private ?float $ms,
				private string $database,
			) {
				parent::__construct($streamRequest, $streamDestRequest, $cacheActorsRequest, $followsRequest, $accountService, $actorsRequest, $connection, $request, $configService);
				$this->sink = &$sink;
			}

			protected function insertMany(string $table, array $rows): void {
				foreach ($rows as $row) {
					$this->sink[$table][] = $row;
				}
			}

			protected function databaseName(): string {
				return $this->database;
			}

			protected function measure(callable $read, int $limit, int $runs): array {
				if ($this->ms === null) {
					return parent::measure($read, $limit, $runs);
				}

				return [$this->ms, $read($limit)];
			}
		};

		return new CommandTester($command);
	}

	/** @return array<string, mixed> the JSON report of a time-only run */
	private function report(CommandTester $tester, array $options, int $expectedExit = 0): array {
		$exit = $tester->execute(['--viewer' => 'viewer', '--time-only' => true, '--output' => 'json'] + $options);
		$this->assertSame($expectedExit, $exit, $tester->getDisplay());
		$report = json_decode($tester->getDisplay(), true);
		$this->assertIsArray($report, 'stdout is one JSON document: ' . $tester->getDisplay());

		return $report;
	}

	/** @return list<array<string, mixed>> */
	private function rows(string $table): array {
		return $this->written[$table] ?? [];
	}

	private function seed(int $notes = 1000, int $actors = 50): void {
		$tester = $this->tester();
		$this->assertSame(0, $tester->execute([
			'--viewer' => 'viewer', '--notes' => (string)$notes, '--actors' => (string)$actors,
			'--follows' => '40', '--seed-only' => true, '--force' => true,
		]), $tester->getDisplay());
	}

	/** @return list<array<string, mixed>> the seeded posts, without the notifications */
	private function notes(): array {
		return array_values(array_filter(
			$this->rows(CoreRequestBuilder::TABLE_STREAM),
			static fn (array $row): bool => $row['type'] === Note::TYPE
		));
	}

	public function testTheSeedHasMediaAsTheMinorityItIsInLife(): void {
		$this->seed();
		$kinds = array_count_values(array_map(static fn (array $row): string => (string)$row['media_kind'], $this->notes()));

		$this->assertCount(1000, $this->notes());
		$this->assertSame(1000 / Benchmark::IMAGE_EVERY, $kinds['image'] ?? 0, 'one post in ten has a picture');
		$this->assertSame(1000 / Benchmark::VIDEO_EVERY, $kinds['video'] ?? 0, 'one in twenty-five a video');
		$this->assertSame(1000 - 100 - 40, $kinds[Stream::MEDIA_KIND_NONE] ?? 0, 'and the rest are text, stated as such rather than left NULL');
	}

	public function testAMediaPostIsStoredTheWayTheModelStoresOne(): void {
		$this->seed(100, 10);
		foreach ($this->notes() as $row) {
			$this->assertSame(
				Stream::mediaKindOf((string)$row['attachments']), $row['media_kind'],
				'the kind column agrees with the attachments, as StreamRequest::save() writes it'
			);
			if ($row['media_kind'] === Stream::MEDIA_KIND_NONE) {
				$this->assertSame('[]', $row['attachments']);
				continue;
			}
			$attachments = json_decode((string)$row['attachments'], true);
			$this->assertCount(1, $attachments);
			$this->assertSame($row['media_kind'], $attachments[0]['type']);
			$this->assertStringStartsWith($row['media_kind'] . '/', $attachments[0]['media_type']);
		}
	}

	public function testOnePostInTwentyMentionsTheViewerAndNotifiesThem(): void {
		$this->seed();
		$viewerPrim = md5(self::VIEWER);

		$mentioning = array_filter($this->notes(), static fn (array $row): bool => in_array(self::VIEWER, json_decode((string)$row['cc'], true), true));
		$this->assertCount(1000 / Benchmark::MENTION_EVERY, $mentioning);

		$notifications = array_values(array_filter(
			$this->rows(CoreRequestBuilder::TABLE_STREAM),
			static fn (array $row): bool => $row['type'] === SocialAppNotification::TYPE
		));
		$this->assertCount(count($mentioning), $notifications, 'one notification per mention');

		$notifRows = array_filter(
			$this->rows(CoreRequestBuilder::TABLE_STREAM_DEST),
			static fn (array $row): bool => $row['type'] === 'notif'
		);
		$this->assertCount(count($notifications), $notifRows);
		foreach ($notifRows as $row) {
			$this->assertSame($viewerPrim, $row['actor_id'], 'what the notifications timeline finds them by');
		}

		$mentionedPrims = array_values(array_map(static fn (array $row): string => (string)$row['id_prim'], $mentioning));
		$this->assertEqualsCanonicalizing(
			$mentionedPrims, array_map(static fn (array $row): string => (string)$row['object_id_prim'], $notifications),
			'each notification points at the post that mentioned the viewer'
		);
	}

	public function testAQuarterOfThePostsShareTwentyHashtags(): void {
		$this->seed();
		$tags = array_column($this->rows(CoreRequestBuilder::TABLE_STREAM_TAGS), 'hashtag');

		$this->assertCount(1000 / Benchmark::HASHTAG_EVERY, $tags);
		$this->assertCount(Benchmark::HASHTAGS, array_unique($tags));
		$this->assertGreaterThanOrEqual(12, array_count_values($tags)[Benchmark::PROBED_TAG] ?? 0, 'the probed tag is as common as any');
	}

	public function testEveryRecipientRowCarriesItsPostsNid(): void {
		$this->seed(200, 20);
		$nids = [];
		foreach ($this->rows(CoreRequestBuilder::TABLE_STREAM) as $row) {
			$this->assertNotSame('0', (string)$row['nid']);
			$nids[$row['id_prim']] = (string)$row['nid'];
		}

		$dest = $this->rows(CoreRequestBuilder::TABLE_STREAM_DEST);
		$this->assertNotEmpty($dest);
		foreach ($dest as $row) {
			$this->assertSame($nids[$row['stream_id']] ?? null, (string)$row['nid'], 'the home page query reads the nid off this row');
		}
	}

	public function testEveryPostIsInTheSearchIndexAndTheProbedWordIsInAKnownShare(): void {
		$this->seed();
		$terms = $this->rows(CoreRequestBuilder::TABLE_SEARCH_TERMS);
		$indexed = array_unique(array_column($terms, 'stream_id_prim'));
		$this->assertCount(1000, $indexed, 'every seeded post has words in the index');

		$probed = array_filter($terms, static fn (array $row): bool => $row['term'] === Benchmark::PROBED_WORD);
		$this->assertCount((int)ceil(1000 / count(Benchmark::TOPICS)), $probed);
	}

	public function testEveryRowOfATableHasTheSameColumns(): void {
		$this->seed(600, 30);
		foreach ($this->written as $table => $rows) {
			$columns = array_keys($rows[0]);
			foreach ($rows as $row) {
				$this->assertSame($columns, array_keys($row), $table . ': one multi-row INSERT needs one column list');
			}
		}
	}

	/** @return array<string, array{string, string}> */
	public static function databases(): array {
		return [
			'PostgreSQL' => ['pgsql', 'ANALYZE "*PREFIX*%s"'],
			'MySQL' => ['mysql', 'ANALYZE TABLE `*PREFIX*%s`'],
			'SQLite' => ['sqlite', 'ANALYZE `*PREFIX*%s`'],
		];
	}

	/**
	 * A table that grew by a hundred thousand rows a minute ago still has the
	 * statistics of the table it was, so a plan made straight after the seed
	 * is not the plan a live instance gets.
	 */
	#[DataProvider('databases')]
	public function testTheSeededTablesAreAnalysedBeforeAnythingIsTimed(string $database, string $statement): void {
		$tester = $this->tester(null, $database);
		$this->assertSame(0, $tester->execute([
			'--viewer' => 'viewer', '--notes' => '10', '--actors' => '2', '--seed-only' => true, '--force' => true,
		]));

		$this->assertSame(
			array_map(static fn (string $table): string => sprintf($statement, $table), Benchmark::SEEDED_TABLES),
			$this->statements
		);
		$this->assertEqualsCanonicalizing(
			array_keys($this->written), Benchmark::SEEDED_TABLES, 'every table written to is analysed, and only those'
		);
	}

	public function testEveryProbeAsksWhatTheWebClientAsks(): void {
		$report = $this->report($this->tester(), []);

		$keys = [];
		foreach (Benchmark::PROBES as $probe) {
			foreach (Benchmark::PROBE_LIMITS as $limit) {
				$keys[] = $probe . '@' . $limit;
			}
		}
		$this->assertSame($keys, array_keys($report['probes']));

		$byProbe = $this->asked;
		$photos = array_values(array_filter($byProbe, static fn (ProbeOptions $o): bool => $o->getMediaType() === 'image'));
		$this->assertNotEmpty($photos);
		$this->assertSame(ProbeOptions::HOME, $photos[0]->getProbe());
		$this->assertTrue($photos[0]->isOnlyMedia());

		$videos = array_values(array_filter($byProbe, static fn (ProbeOptions $o): bool => $o->isOnlyVideo()));
		$this->assertNotEmpty($videos);
		$this->assertSame(ProbeOptions::HOME, $videos[0]->getProbe());

		$hashtag = array_values(array_filter($byProbe, static fn (ProbeOptions $o): bool => $o->getProbe() === ProbeOptions::HASHTAG));
		$this->assertSame(Benchmark::PROBED_TAG, $hashtag[0]->getArgument());

		$account = array_values(array_filter($byProbe, static fn (ProbeOptions $o): bool => $o->getProbe() === ProbeOptions::ACCOUNT));
		$this->assertSame(Benchmark::actorId(0), $account[0]->getAccountId());

		$this->assertContains([Benchmark::PROBED_WORD, 20], $this->searched);
		$this->assertSame(20, $report['probes']['search@20']['rows']);
	}

	public function testTheMedianIsReportedSoOneSlowRunDoesNotDecide(): void {
		$this->assertSame(3.0, Benchmark::median([9.0, 1.0, 3.0]));
		$this->assertSame(2.5, Benchmark::median([4.0, 1.0, 2.0, 3.0]));
		$this->assertSame(7.0, Benchmark::median([7.0]));
	}

	/** @return array<string, array{array<string, float>, ?float, array<string, float>, ?float}> */
	public static function limits(): array {
		return [
			'no limit at all' => [[], null, [], null],
			'--fail-over alone' => [[], 100.0, [], 100.0],
			'a probe threshold beats --fail-over' => [['home' => 150.0], 500.0, [], 150.0],
			'a page-size threshold beats the probe one' => [['home' => 150.0, 'home@20' => 120.0], null, [], 120.0],
			'the baseline alone, times the factor' => [[], null, ['home@20' => 40.0], 120.0],
			'a tiny baseline is held to the ceiling, not to noise' => [['home' => 150.0], null, ['home@20' => 2.0], 150.0],
			'a probe slower than the ceiling is held to its baseline' => [['home' => 150.0], null, ['home@20' => 80.0], 240.0],
		];
	}

	/**
	 * @param array<string, float> $thresholds
	 * @param array<string, float> $baseline
	 */
	#[DataProvider('limits')]
	public function testAProbeIsHeldToTheLargerOfItsCeilingAndItsBaselineAllowance(
		array $thresholds, ?float $failOver, array $baseline, ?float $expected,
	): void {
		$results = ['home@20' => ['probe' => 'home', 'limit' => 20, 'ms' => 130.0, 'rows' => 20]];
		$verdict = Benchmark::judge($results, $thresholds, $failOver, $baseline, 3.0)['home@20'];

		$this->assertSame($expected, $verdict['limit_ms']);
		$this->assertSame($expected !== null && $expected < 130.0, $verdict['over']);
	}

	public function testAProbeOverItsLimitFailsTheRunAndSaysWhich(): void {
		$report = $this->report($this->tester(200.0), ['--threshold' => ['home=150'], '--fail-over' => '500'], 1);

		$this->assertSame(['home@20', 'home@50'], $report['over']);
		$this->assertSame(150.0, (float)$report['probes']['home@20']['limit_ms']);
		$this->assertTrue($report['probes']['home@20']['over']);
		$this->assertSame(500.0, (float)$report['probes']['public@20']['limit_ms']);
		$this->assertFalse($report['probes']['public@20']['over']);
	}

	public function testEveryProbeUnderItsLimitPasses(): void {
		$report = $this->report($this->tester(20.0), ['--fail-over' => '100']);

		$this->assertSame([], $report['over']);
	}

	public function testTheBaselineIsReadForTheDatabaseBeingMeasured(): void {
		$file = tempnam(sys_get_temp_dir(), 'social-baseline');
		file_put_contents($file, json_encode([
			'measured' => 'how these were measured',
			'sqlite' => ['home@20' => 10.0],
			'pgsql' => ['home@20' => 1000.0],
		]));

		try {
			$sqlite = $this->report($this->tester(40.0, 'sqlite'), ['--baseline' => $file], 1);
			$this->assertSame(['home@20'], $sqlite['over'], 'three times 10 ms is 30 ms');

			$pgsql = $this->report($this->tester(40.0, 'pgsql'), ['--baseline' => $file, '--baseline-factor' => '2']);
			$this->assertSame(2000.0, (float)$pgsql['probes']['home@20']['limit_ms']);
			$this->assertNull($pgsql['probes']['public@20']['limit_ms'], 'a probe the baseline does not name has no limit');
		} finally {
			unlink($file);
		}
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function badOptions(): array {
		return [
			'an unknown probe' => [['--threshold' => ['homepage=100']]],
			'a page size nobody measures' => [['--threshold' => ['home@30=100']]],
			'a threshold that is not a number' => [['--threshold' => ['home=fast']]],
			'a threshold without a value' => [['--threshold' => ['home']]],
			'a zero ceiling' => [['--fail-over' => '0']],
			'no runs' => [['--runs' => '0']],
			'a negative factor' => [['--baseline-factor' => '-1']],
			'a baseline that is not there' => [['--baseline' => '/nonexistent/baseline.json']],
		];
	}

	/** @param array<string, mixed> $options */
	#[DataProvider('badOptions')]
	public function testAMistakenOptionIsRefusedBeforeAnythingIsSeeded(array $options): void {
		$tester = $this->tester();
		$exit = $tester->execute(['--viewer' => 'viewer', '--notes' => '10', '--actors' => '2', '--force' => true] + $options);

		$this->assertSame(1, $exit);
		$this->assertSame([], $this->written, 'nothing was written');
	}

	public function testWithJsonOnStdoutTheProgressGoesToStderr(): void {
		$tester = $this->tester(1.0);
		$tester->execute(
			['--viewer' => 'viewer', '--notes' => '30', '--actors' => '3', '--force' => true, '--output' => 'json'],
			['capture_stderr_separately' => true]
		);

		$this->assertIsArray(json_decode($tester->getDisplay(), true), 'stdout is one JSON document');
		$this->assertStringContainsString('seeded in', $tester->getErrorOutput());
		$report = json_decode($tester->getDisplay(), true);
		$this->assertSame(['actors' => 3, 'notes' => 30, 'follows' => 150, 'followers' => 0], $report['seeded']);
	}
}
