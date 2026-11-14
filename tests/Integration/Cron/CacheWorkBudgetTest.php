<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Cron;

use DateTime;
use OCA\Social\Cron\Cache;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\HostBreakerRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CacheActorSweepService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\RemoteCountService;
use OCA\Social\Service\StreamPruneService;
use OCA\Social\Tests\Integration\Helper\RowSeeder;
use OCA\Social\Tests\Integration\OfflineContainer;
use OCA\Social\Tests\Integration\QueryCounter;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;

/**
 * What one pass of `Cron\Cache` costs the database, step by step, against a
 * backlog three times what one pass may take.
 *
 * The job is built from the app's own services with the network edge
 * replaced (`OfflineContainer`): every remote server is unreachable, at
 * once, and the job's clock does not move, so no step runs out of time —
 * what ends a step is its own bound, or nothing. Each step must take at most
 * its batch whatever is waiting behind it, and its statements must be a
 * constant per item taken on top of a constant per pass: a statement that
 * grows with the backlog is what turns the pass on an instance with a week of
 * it into one that never finishes.
 *
 * The budgets are today's counts with a little room, so a change that adds a
 * statement per item fails here and says which statement it was.
 *
 * Not here: the poll announcements, the profile links, the group lists, the
 * Discover directory and the disk measurement, which have no backlog a seed
 * can build without the network or the filesystem behind them; and the two
 * purges (expired cache rows, unused client registrations), which are a
 * fixed handful of set-based statements whatever they remove.
 */
class CacheWorkBudgetTest extends TestCase {
	private const BASE = 'https://cacheworkbudget.invalid';

	private const SETTINGS = [
		ConfigService::SOCIAL_LOCAL_ACTOR_CURSOR,
		ConfigService::SOCIAL_RETENTION_DAYS,
		ConfigService::SOCIAL_CACHE_ACTOR_DAYS,
	];

	/** @var array<string, string> */
	private array $settings = [];

	/** @var array<string, list<string>> table => the id_prims seeded into it */
	private array $seeded = [];

	private int $sequence = 0;

	/** @var list<string> the remote requests the steps tried, by CurlService method */
	private array $requests = [];

	protected function setUp(): void {
		parent::setUp();
		foreach (self::SETTINGS as $key) {
			$this->settings[$key] = Server::get(IAppConfig::class)->getValueString('social', $key, '');
		}
		$this->closeBreaker();
	}

	/**
	 * Every request here fails, so a step that talks to the seeded host can
	 * open its circuit breaker, which the remote media prefetch honours: each
	 * test starts, and leaves, with the host closed.
	 */
	private function closeBreaker(): void {
		Server::get(HostBreakerRequest::class)->close((string)parse_url(self::BASE, PHP_URL_HOST));
	}

	protected function tearDown(): void {
		foreach ($this->seeded as $table => $prims) {
			$column = match ($table) {
				CoreRequestBuilder::TABLE_STREAM_DEST, CoreRequestBuilder::TABLE_STREAM_TAGS => 'stream_id',
				CoreRequestBuilder::TABLE_HASHTAGS => 'hashtag',
				default => 'id_prim',
			};
			RowSeeder::deleteIn($table, $column, $prims);
		}
		foreach ($this->settings as $key => $value) {
			Server::get(IAppConfig::class)->setValueString('social', $key, $value);
		}
		$this->closeBreaker();
		parent::tearDown();
	}

	/** The job, with every remote server unreachable and a clock that does not move. */
	private function job(): Cache {
		$curl = $this->createStub(CurlService::class);
		foreach ((new ReflectionClass(CurlService::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			if (!$method->isConstructor() && !$method->isStatic() && $method->getName() !== 'userAgent') {
				$name = $method->getName();
				$curl->method($name)->willReturnCallback(function () use ($name): never {
					$this->requests[] = $name;

					throw new RequestNetworkException('offline');
				});
			}
		}
		$http = $this->createStub(IClientService::class);
		$http->method('newClient')->willThrowException(new \RuntimeException('offline'));
		$clock = $this->createStub(ITimeFactory::class);
		$clock->method('getTime')->willReturn(time());

		$job = (new OfflineContainer([
			CurlService::class => $curl,
			IClientService::class => $http,
			ITimeFactory::class => $clock,
		]))->get(Cache::class);
		$this->assertInstanceOf(Cache::class, $job);

		return $job;
	}

	/** The statements one step of a pass runs. */
	private function step(string $name): QueryCounter {
		$job = $this->job();
		$steps = (new ReflectionMethod(Cache::class, 'steps'))->invoke($job, time() + Cache::MAX_DURATION);
		$this->assertArrayHasKey($name, $steps);
		$step = $steps[$name];

		return QueryCounter::record(static fn () => $step());
	}

	/** @param list<array<string, mixed>> $rows */
	private function seed(string $table, array $rows): void {
		foreach ($rows as $row) {
			$this->seeded[$table][] = (string)($row['id_prim'] ?? $row['stream_id'] ?? $row['hashtag']);
		}
		RowSeeder::insertAll($table, $rows);
	}

	/**
	 * `$count` remote actors in the cache, due for everything.
	 *
	 * @param array<string, mixed> $columns
	 * @return list<string> their ids
	 */
	private function seedRemoteActors(int $count, array $columns = []): array {
		$rows = [];
		$ids = [];
		for ($i = 0; $i < $count; $i++) {
			$name = 'a' . (++$this->sequence);
			$id = self::BASE . '/users/' . $name;
			$ids[] = $id;
			$rows[] = array_merge([
				'id' => $id, 'id_prim' => md5($id), 'type' => 'Person', 'local' => false,
				'account' => $name . '@cacheworkbudget.invalid', 'host' => 'cacheworkbudget.invalid',
				'preferred_username' => $name, 'name' => '', 'icon_id' => '', 'summary' => '',
				'inbox' => $id . '/inbox', 'outbox' => $id . '/outbox', 'followers' => $id . '/followers',
				'following' => $id . '/following', 'shared_inbox' => '', 'featured' => '', 'url' => $id,
				'public_key' => '', 'source' => '{}', 'details' => '{}', 'creation' => new DateTime(),
				'details_update' => null, 'sync_attempt' => 0, 'sync_failures' => 0,
			], $columns);
		}
		$this->seed(CoreRequestBuilder::TABLE_CACHE_ACTORS, $rows);

		return $ids;
	}

	/** The local accounts the instance had before this test seeded any. */
	private function localAccountsAlreadyHere(): int {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))->from(CoreRequestBuilder::TABLE_ACTORS);
		$cursor = $qb->executeQuery();
		$count = (int)$cursor->fetchOne();
		$cursor->closeCursor();

		return $count;
	}

	/**
	 * `$count` local accounts with no Nextcloud user behind them, their ids
	 * what this instance would have given them.
	 *
	 * @param array<string, mixed> $columns
	 */
	private function seedLocalActors(int $count, array $columns = []): void {
		$root = Server::get(ConfigService::class)->getSocialUrl();
		$rows = [];
		for ($i = 0; $i < $count; $i++) {
			$name = 'cwb' . (++$this->sequence) . bin2hex(random_bytes(2));
			$id = $root . '@' . $name;
			$rows[] = array_merge([
				'id' => $id, 'id_prim' => md5($id), 'user_id' => $name, 'preferred_username' => $name,
				'name' => '', 'summary' => '', 'public_key' => '', 'private_key' => '',
				'creation' => new DateTime(), 'deleted' => null, 'fields' => '[]', 'actor_type' => '',
			], $columns);
		}
		$this->seed(CoreRequestBuilder::TABLE_ACTORS, $rows);
	}

	/**
	 * `$count` remote notes by one cached author.
	 *
	 * @param array<string, mixed> $columns
	 */
	private function seedRemoteNotes(int $count, array $columns = []): void {
		[$author] = $this->seedRemoteActors(1);
		$rows = [];
		for ($i = 0; $i < $count; $i++) {
			$id = self::BASE . '/notes/' . (++$this->sequence);
			$rows[] = array_merge([
				'id' => $id, 'id_prim' => md5($id), 'type' => Note::TYPE, 'subtype' => '', 'local' => 0,
				'to' => ACore::CONTEXT_PUBLIC, 'to_array' => '[]', 'cc' => '[]', 'bcc' => '[]',
				'visibility' => Stream::TYPE_PUBLIC, 'attributed_to' => $author, 'attributed_to_prim' => md5($author),
				'object_id' => '', 'object_id_prim' => '', 'in_reply_to' => '', 'in_reply_to_prim' => '',
				'content' => '<p>' . $i . '</p>', 'summary' => '', 'source' => '{}', 'details' => '{}',
				'cache' => '[]', 'instances' => '[]', 'attachments' => '[]', 'hashtags' => '[]',
				'published' => gmdate('Y-m-d\TH:i:s\Z'), 'published_time' => new DateTime(), 'creation' => new DateTime(),
				'archived' => false, 'counts_at' => new DateTime(),
			], $columns);
		}
		$this->seed(CoreRequestBuilder::TABLE_STREAM, $rows);
	}

	/** How many rows of `$table` seeded here satisfy `$where`, or are left at all with none. */
	private function seededWhere(string $table, ?callable $where = null): int {
		$count = 0;
		foreach (array_chunk($this->seeded[$table] ?? [], 1000) as $chunk) {
			$qb = Server::get(IDBConnection::class)->getQueryBuilder();
			$qb->select($qb->func()->count('*', 'n'))->from($table)
				->where($qb->expr()->in('id_prim', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)));
			if ($where !== null) {
				$where($qb);
			}
			$cursor = $qb->executeQuery();
			$count += (int)$cursor->fetchOne();
			$cursor->closeCursor();
		}

		return $count;
	}

	private function attempted(): int {
		return $this->seededWhere(
			CoreRequestBuilder::TABLE_CACHE_ACTORS,
			static fn (IQueryBuilder $qb) => $qb->andWhere($qb->expr()->gt('sync_attempt', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
		);
	}

	/**
	 * The statements of a run, counted by kind: thousands of them are one
	 * line each, and a budget that is exceeded should say which kind grew.
	 */
	private static function kinds(QueryCounter $run): string {
		$counts = [];
		foreach ($run->queries() as $query) {
			$key = mb_strimwidth((string)preg_replace('/\s+/', ' ', $query['sql']), 0, 120, '…');
			$counts[$key] = ($counts[$key] ?? 0) + 1;
		}
		arsort($counts);
		$lines = [];
		foreach ($counts as $key => $n) {
			$lines[] = sprintf('%6d × %s', $n, $key);
		}

		return $run->count() . " statements:\n" . implode("\n", $lines);
	}

	/**
	 * At most `$perItem` statements for each of at most `$items` items, and
	 * `$perPass` on top.
	 */
	private function assertWithinBudget(QueryCounter $run, int $items, int $perItem, int $perPass): void {
		$this->assertLessThanOrEqual($items * $perItem + $perPass, $run->count(), self::kinds($run));
	}

	/** One remote-actor refresh pass: forty batches of fifty, the most it may take. */
	private static function remotePass(): int {
		return CacheActorService::MAX_BATCHES * CacheActorsRequest::SYNC_BATCH;
	}

	/**
	 * Forty batches of fifty and one stamp per actor, however many are due:
	 * the deadline that would end it on a slow network never comes here.
	 */
	public function testTheRemoteActorRefreshTakesItsBatchesAndNoMore(): void {
		$this->seedRemoteActors(3 * self::remotePass());

		$run = $this->step('manageCacheRemoteActors');

		$this->assertLessThanOrEqual(self::remotePass(), $this->attempted(), 'at most one pass of the backlog is attempted');
		$this->assertGreaterThan(0, $this->attempted());
		$this->assertLessThanOrEqual(CacheActorService::MAX_BATCHES, count($run->matching('/^SELECT .* FROM \S*social_cache_actor/')));
		// one stamp per actor, one read per batch: 2,040 today
		$this->assertWithinBudget($run, self::remotePass(), 1, CacheActorService::MAX_BATCHES + 5);
	}

	/**
	 * The details are three questions an actor, all unanswered here, and the
	 * row is written once — whether anything came back or not.
	 */
	public function testTheRemoteDetailsRefreshTakesItsBatchesAndNoMore(): void {
		$this->seedRemoteActors(3 * self::remotePass());

		$run = $this->step('manageDetailsRemoteActors');

		$updated = $this->seededWhere(
			CoreRequestBuilder::TABLE_CACHE_ACTORS,
			static fn (IQueryBuilder $qb) => $qb->andWhere($qb->expr()->isNotNull('details_update'))
		);
		$this->assertLessThanOrEqual(self::remotePass(), $updated, 'at most one pass of the backlog is refreshed');
		$this->assertGreaterThan(0, $updated);
		// one write per actor, one read per batch: 2,040 today
		$this->assertWithinBudget($run, self::remotePass(), 1, CacheActorService::MAX_BATCHES + 5);
	}

	/**
	 * One batch of the followed actors; asking a server for an outbox costs
	 * the database nothing until it answers.
	 */
	public function testTheTimelineSyncTakesOneBatch(): void {
		[$reader] = $this->seedRemoteActors(1, ['local' => true]);
		$ids = $this->seedRemoteActors(3 * CacheActorsRequest::SYNC_BATCH);
		$follows = [];
		foreach ($ids as $id) {
			$followId = $id . '/followed-by-reader';
			$follows[] = [
				'id' => $followId, 'id_prim' => md5($followId), 'type' => 'Follow',
				'actor_id' => $reader, 'actor_id_prim' => md5($reader), 'object_id' => $id, 'object_id_prim' => md5($id),
				'follow_id' => $id . '/followers', 'follow_id_prim' => md5($id . '/followers'),
				'accepted' => true, 'creation' => new DateTime(),
			];
		}
		$this->seed(CoreRequestBuilder::TABLE_FOLLOWS, $follows);

		$run = $this->step('syncRemoteTimelines');

		$this->assertNotEmpty($this->requests, 'the followed actors\' outboxes were asked for');
		$this->assertLessThanOrEqual(CacheActorsRequest::SYNC_BATCH, count($this->requests), 'one outbox a followed actor, one batch of them');
		// the batch, read once: 1 today
		$this->assertWithinBudget($run, CacheActorsRequest::SYNC_BATCH, 0, 3);
	}

	/** Five pages of a hundred unreferenced actors, three statements each. */
	public function testTheSweepTakesItsBatch(): void {
		Server::get(IAppConfig::class)->setValueString('social', ConfigService::SOCIAL_CACHE_ACTOR_DAYS, '30');
		$this->seedRemoteActors(3 * Cache::SWEEP_BATCH, ['creation' => new DateTime('2001-01-01')]);

		$run = $this->step('sweepCachedActors');

		$swept = 3 * Cache::SWEEP_BATCH - $this->seededWhere(CoreRequestBuilder::TABLE_CACHE_ACTORS);
		$this->assertLessThanOrEqual(Cache::SWEEP_BATCH, $swept);
		$this->assertGreaterThan(0, $swept);
		// its documents read and removed, the actor removed, and a read a
		// page: 1,505 today
		$this->assertWithinBudget($run, Cache::SWEEP_BATCH, 3, intdiv(Cache::SWEEP_BATCH, CacheActorSweepService::PAGE) + 5);
	}

	/**
	 * Ten pages of two hundred local accounts a pass, wherever the walk is,
	 * five statements each when the account has no Nextcloud user to read a
	 * name from.
	 */
	public function testTheLocalActorRefreshTakesItsPages(): void {
		Server::get(IAppConfig::class)->setValueString('social', ConfigService::SOCIAL_LOCAL_ACTOR_CURSOR, '');
		$batch = (int)(new ReflectionClassConstant(AccountService::class, 'LOCAL_ACTOR_BATCH'))->getValue();
		$pages = (int)(new ReflectionClassConstant(AccountService::class, 'LOCAL_ACTOR_PAGES'))->getValue();
		$own = $this->localAccountsAlreadyHere();
		$this->seedLocalActors(3 * $batch * $pages);

		$run = $this->step('manageCacheLocalActors');

		$this->assertLessThanOrEqual($pages, count($run->matching('/ORDER BY "?`?a"?`?\."?`?id_prim/')), 'at most its pages');
		// the account, its moderation level and the user behind it, three
		// reads for the name, and a read a page: 10,022 today; an account the
		// instance already had has a user, a picture and counts, and is
		// refreshed whole (about twenty statements)
		$this->assertWithinBudget($run, $batch * $pages, 5, 2 * $pages + 10 + 25 * $own);
	}

	/** A batch of the accounts past their grace period, one delete each. */
	public function testTheDeletedAccountsAreRemovedABatchAtATime(): void {
		$this->seedLocalActors(3 * AccountService::DELETED_ACTOR_BATCH, ['deleted' => new DateTime('2 days ago')]);

		$run = $this->step('manageDeletedActors');

		$removed = 3 * AccountService::DELETED_ACTOR_BATCH - $this->seededWhere(CoreRequestBuilder::TABLE_ACTORS);
		$this->assertLessThanOrEqual(AccountService::DELETED_ACTOR_BATCH, $removed);
		$this->assertGreaterThan(0, $removed);
		// the batch, read once, and a delete each: 501 today
		$this->assertWithinBudget($run, AccountService::DELETED_ACTOR_BATCH, 1, 3);
	}

	/** Five thousand posts a pass in chunks of five hundred, each chunk a fixed set of statements. */
	public function testThePruneTakesItsCap(): void {
		$cap = 5000;
		Server::get(IAppConfig::class)->setValueString('social', ConfigService::SOCIAL_RETENTION_DAYS, '30');
		$this->seedRemoteNotes(3 * $cap, ['creation' => new DateTime('2001-01-01')]);

		$run = $this->step('prune');

		$pruned = 3 * $cap - $this->seededWhere(CoreRequestBuilder::TABLE_STREAM);
		$this->assertLessThanOrEqual($cap, $pruned);
		$this->assertGreaterThan(0, $pruned);
		// the chunk's candidates and the ids no rule keeps, their documents,
		// every table that names a post, the trend hours its public posts
		// were published in (one read, one mark per hour) and the place the
		// walk got to, per chunk of five hundred, and the queue and
		// notification sweeps: 264 for ten chunks whose posts share an hour
		$this->assertWithinBudget($run, intdiv($cap, StreamPruneService::CHUNK), 27, 10);
	}

	/** One batch of remote posts asked for their counts, each stamped once. */
	public function testTheRemoteCountsTakeOneBatch(): void {
		$this->seedRemoteNotes(3 * RemoteCountService::BATCH, ['counts_at' => new DateTime('2001-01-01')]);

		$run = $this->step('refreshRemoteCounts');

		$asked = $this->seededWhere(
			CoreRequestBuilder::TABLE_STREAM,
			static fn (IQueryBuilder $qb) => $qb->andWhere($qb->expr()->gt('counts_at', $qb->createNamedParameter(new DateTime('2002-01-01'), IQueryBuilder::PARAM_DATE)))
		);
		$this->assertLessThanOrEqual(RemoteCountService::BATCH, $asked);
		$this->assertGreaterThan(0, $asked);
		// the batch, read once, and a stamp each: 51 today
		$this->assertWithinBudget($run, RemoteCountService::BATCH, 1, 3);
	}

	/**
	 * At most one page of documents not yet cached is tried, each claimed and
	 * its outcome written. Every host here is unreachable, so the first wave
	 * fails and the rest of that host's documents are not asked for in this
	 * run: what is left is a read per page.
	 */
	public function testTheDocumentCacheTakesOneBatch(): void {
		$rows = [];
		for ($i = 0; $i < 3 * CacheDocumentsRequest::CACHE_BATCH; $i++) {
			$id = self::BASE . '/media/' . (++$this->sequence) . '.jpg';
			$rows[] = [
				'id' => $id, 'id_prim' => md5($id), 'type' => 'Image', 'parent_id' => '', 'parent_id_prim' => '',
				'media_type' => 'image/jpeg', 'mime_type' => '', 'url' => $id, 'local_copy' => '', 'resized_copy' => '',
				'account' => '', 'meta' => '[]', 'blurhash' => '', 'description' => '', 'public' => false,
				'error' => 0, 'creation' => new DateTime(), 'caching' => new DateTime('2001-01-01'),
			];
		}
		$this->seed(CoreRequestBuilder::TABLE_CACHE_DOCUMENTS, $rows);

		$run = $this->step('manageCacheDocuments');

		$tried = $this->seededWhere(
			CoreRequestBuilder::TABLE_CACHE_DOCUMENTS,
			static fn (IQueryBuilder $qb) => $qb->andWhere($qb->expr()->gt('caching', $qb->createNamedParameter(new DateTime('2002-01-01'), IQueryBuilder::PARAM_DATE)))
		);
		$this->assertLessThanOrEqual(CacheDocumentsRequest::CACHE_BATCH, $tried);
		$this->assertGreaterThan(0, $tried);
		// a claim and an outcome per document tried, a read per page and the
		// breaker read once
		$this->assertWithinBudget($run, CacheDocumentsRequest::CACHE_BATCH, 3, 3);
	}

	/**
	 * The trends are rewritten only for the hashtags whose counts moved: the
	 * first pass over a thousand new ones writes each of them, the next one —
	 * nothing having moved — reads and writes nothing more. There is no batch
	 * to this step; what keeps it from growing with every hashtag the
	 * instance has seen in ten days is that skip.
	 */
	public function testTheTrendsAreWrittenOnlyForTheHashtagsThatMoved(): void {
		$this->step('manageHashtags');
		$this->seedRemoteNotes(1000, ['published_time' => new DateTime('-10 minutes')]);
		$tags = [];
		$hashtags = [];
		foreach ($this->seeded[CoreRequestBuilder::TABLE_STREAM] as $i => $prim) {
			$tag = 'cacheworkbudget' . $i;
			$tags[] = ['stream_id' => $prim, 'hashtag' => $tag];
			$hashtags[] = ['hashtag' => $tag];
		}
		$this->seed(CoreRequestBuilder::TABLE_STREAM_TAGS, $tags);
		foreach ($hashtags as $row) {
			$this->seeded[CoreRequestBuilder::TABLE_HASHTAGS][] = $row['hashtag'];
		}

		$first = $this->step('manageHashtags');
		$second = $this->step('manageHashtags');

		$written = count($first->matching('/^(UPDATE|INSERT).*social_hashtag/'));
		$this->assertGreaterThanOrEqual(1000, $written, 'every new hashtag is written: ' . self::kinds($first));
		// an update that finds no row, then the insert: two a new hashtag; the
		// hour's buckets, TREND_INSERT_ROWS to a statement; and the reads and
		// the mark of the pass, two more when the oldest kept hour moved
		// since the last one: 2012 today
		$this->assertWithinBudget($first, 1000, 2, 11 + (int)ceil(1000 / StreamRequest::TREND_INSERT_ROWS));
		// nothing moved: the hour is recounted and found as it is stored, so
		// not one bucket or hashtag is written
		$this->assertSame([], $second->matching('/^(UPDATE|INSERT|DELETE)\b.*social_(hashtag|tag_hour)\b/'), self::kinds($second));
		// the reads of the pass and its mark: 8 today
		$this->assertWithinBudget($second, 0, 0, 10);
	}
}
