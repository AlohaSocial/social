<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateInterval;
use DateTime;
use Exception;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Migration\Version1000Date20260920000002;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;

class CacheActorsRequest extends CacheActorsRequestBuilder {
	/**
	 * The most rows a search will read, whatever it was asked for: a search
	 * box shows a handful, and a caller that asks for a thousand is asking the
	 * database to build a page nobody will look at.
	 */
	public const SEARCH_LIMIT = 25;

	/** The counter columns `bumpCount()` will touch, and nothing else. */
	private const COUNT_COLUMNS = ['count_followers', 'count_following', 'count_posts'];

	public const CACHE_TTL = 60 * 24 * 10; // 10d
	/** Remote actors synced per cron pass. */
	public const SYNC_BATCH = 50;
	public const DETAILS_TTL = 60 * 18; // 18h

	/**
	 * How many refreshes in a row may fail before an actor is left alone.
	 *
	 * With `syncWait()` doubling from an hour, ten failures span about six
	 * weeks: long enough to outlast any outage a server comes back from, short
	 * enough that a server that is gone stops costing a request every pass.
	 * Past this the row is kept as it is — the follows that point at it, the
	 * posts it wrote — and only the refresh stops; a fetch that is asked for
	 * (a mention, a profile opened) still goes to the network and, when it
	 * works, resets the count.
	 */
	public const SYNC_MAX_FAILURES = 10;

	/** The first wait after a failed refresh, in seconds; it doubles per failure. */
	public const SYNC_BACKOFF_BASE = 3600;

	/**
	 * How long a cached remote actor is left alone after a refresh attempt.
	 *
	 * With no failure on record it is the cache lifetime: the actor is due
	 * again CACHE_TTL after the last attempt. After `n` failures in a row it
	 * is `SYNC_BACKOFF_BASE * 2^(n-1)` — an hour, two, four ... — so one dead
	 * instance costs the cron a request an hour, then a request a day, then
	 * nothing, instead of fifty requests every twelve minutes for ever. The
	 * same schedule has to be applied by the query that reads the queue
	 * (`limitToSyncDue()`) and by the caller that judges an actor in PHP, so
	 * both call this.
	 */
	public static function syncWait(int $failures): int {
		if ($failures < 1) {
			return self::CACHE_TTL * 60;
		}

		// shifted rather than raised to a power: `2 **` is a float in PHP, and
		// a wait is a number of seconds
		return self::SYNC_BACKOFF_BASE << (min($failures, self::SYNC_MAX_FAILURES) - 1);
	}

	/**
	 * A handle as `account_lower` holds it: what the account lookups and the
	 * account search compare, so that a handle typed in any case finds the
	 * account through the index.
	 */
	public static function lowerAccount(string $account): string {
		return mb_strtolower($account, 'UTF-8');
	}

	/**
	 * Insert cache about an Actor in database.
	 */
	/**
	 * The server an account is on: the part after the `@`, or, for a Bluesky
	 * handle (no `@`, a domain of its own), the handle's domain.
	 */
	private static function hostOf(Person $actor): string {
		$host = Version1000Date20260920000002::hostOf($actor->getAccount());
		if ($host === '' && !$actor->isLocal() && str_contains($actor->getAccount(), '.')) {
			return substr($actor->getAccount(), strpos($actor->getAccount(), '.') + 1);
		}

		return $host;
	}

	public function save(Person $actor): void {
		$qb = $this->getCacheActorsInsertSql();
		$qb->setValue('id', $qb->createNamedParameter($actor->getId()))
			->setValue('id_prim', $qb->createNamedParameter($qb->prim($actor->getId())))
			->setValue('account', $qb->createNamedParameter($actor->getAccount()))
			->setValue('type', $qb->createNamedParameter($actor->getType()))
			->setValue('local', $qb->createNamedParameter(($actor->isLocal()) ? '1' : '0'))
			->setValue('following', $qb->createNamedParameter($actor->getFollowing()))
			->setValue('followers', $qb->createNamedParameter($actor->getFollowers()))
			->setValue('inbox', $qb->createNamedParameter($actor->getInbox()))
			->setValue('shared_inbox', $qb->createNamedParameter($actor->getSharedInbox()))
			->setValue('outbox', $qb->createNamedParameter($actor->getOutbox()))
			->setValue('featured', $qb->createNamedParameter($actor->getFeatured()))
			->setValue('url', $qb->createNamedParameter($actor->getUrl()))
			->setValue(
				'preferred_username', $qb->createNamedParameter($actor->getPreferredUsername())
			)
			->setValue('name', $qb->createNamedParameter($actor->getName()))
			->setValue('summary', $qb->createNamedParameter($actor->getSummary()))
			->setValue('public_key', $qb->createNamedParameter($actor->getPublicKey()))
			->setValue('source', $qb->createNamedParameter($actor->getSource()))
			->setValue('details', $qb->createNamedParameter(json_encode($actor->getDetailsAll())))
			// the server this account is on, so "which servers do we know"
			// is a grouped query rather than a read of every row; derived
			// from the handle, which never changes for a row
			->setValue('host', $qb->createNamedParameter(self::hostOf($actor)))
			// what an account search matches a prefix of; see searchAccounts()
			->setValue('account_lower', $qb->createNamedParameter(self::lowerAccount($actor->getAccount())));

		try {
			if ($actor->getCreation() > 0) {
				$dTime = new DateTime();
				$dTime->setTimestamp($actor->getCreation());
			} else {
				$dTime = new DateTime('now');
			}

			$qb->setValue(
				'creation',
				$qb->createNamedParameter($dTime, IQueryBuilder::PARAM_DATE)
			);
		} catch (Exception $e) {
		}

		if ($actor->hasIcon()) {
			$iconId = $actor->getIcon()
				->getId();
		} else {
			$iconId = $actor->getIconId();
		}

		$qb->setValue('icon_id', $qb->createNamedParameter($qb->prim($iconId)));
		$qb->generatePrimaryKey($actor->getId());

		try {
			$qb->executeStatement();
		} catch (DBException $e) {
		}
	}

	public function update(Person $actor): int {
		$qb = $this->getCacheActorsUpdateSql();

		// The handle is derived, not carried on the wire: a local actor gets it
		// from the configured address, and a remote one from the host it was
		// fetched over. A caller that has one is correcting it — refreshing a
		// local actor after the address changed is the case that matters — and
		// one that has none must not be able to erase what is stored.
		if ($actor->getAccount() !== '') {
			$qb->set('account', $qb->createNamedParameter($actor->getAccount()));
			$qb->set('account_lower', $qb->createNamedParameter(self::lowerAccount($actor->getAccount())));
		}

		$qb->set('following', $qb->createNamedParameter($actor->getFollowing()))
			->set('followers', $qb->createNamedParameter($actor->getFollowers()))
			->set('inbox', $qb->createNamedParameter($actor->getInbox()))
			->set('shared_inbox', $qb->createNamedParameter($actor->getSharedInbox()))
			->set('outbox', $qb->createNamedParameter($actor->getOutbox()))
			->set('featured', $qb->createNamedParameter($actor->getFeatured()))
			->set('url', $qb->createNamedParameter($actor->getUrl()))
			->set(
				'preferred_username', $qb->createNamedParameter($actor->getPreferredUsername())
			)
			->set('name', $qb->createNamedParameter($actor->getName()))
			->set('summary', $qb->createNamedParameter($actor->getSummary()))
			->set('public_key', $qb->createNamedParameter($actor->getPublicKey()))
			->set('source', $qb->createNamedParameter($actor->getSource()))
			->set('details', $qb->createNamedParameter(json_encode($actor->getDetailsAll())));

		try {
			if ($actor->getCreation() > 0) {
				$dTime = new DateTime();
				$dTime->setTimestamp($actor->getCreation());
			} else {
				$dTime = new DateTime('now');
			}
			$qb->set(
				'creation',
				$qb->createNamedParameter($dTime, IQueryBuilder::PARAM_DATE)
			);
		} catch (Exception $e) {
		}

		if ($actor->hasIcon()) {
			$iconId = $actor->getIcon()
				->getId();
		} else {
			$iconId = $actor->getIconId();
		}

		$qb->set('icon_id', $qb->createNamedParameter($qb->prim($iconId)));
		$this->limitToIdPrimString($qb, $actor->getId());

		return $qb->executeStatement();
	}

	/**
	 * Moves one of an account's counters, without counting anything.
	 *
	 * The three counters used to live only in the `details` JSON, so the only
	 * way to change one was to recompute all of them — four aggregate queries,
	 * on every post written and every follow accepted. For an account with a
	 * million followers that is a million index entries counted so a number can
	 * go up by one.
	 *
	 * `SET col = col + ?` is one row and is atomic, which is the whole reason
	 * the counters are columns: a read-modify-write of the JSON would lose
	 * updates whenever two follows arrived together, and the drift would be
	 * unexplainable. A counter that has never been counted (`-1`) is left
	 * alone — adding one to "unknown" gives a number that looks authoritative
	 * and is not; the cron's walk will count it properly.
	 *
	 * @param string $column one of `count_followers`, `count_following`, `count_posts`
	 */
	public function bumpCount(string $actorId, string $column, int $by): void {
		if (!in_array($column, self::COUNT_COLUMNS, true) || $by === 0) {
			return;
		}

		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$qb->update(self::TABLE_CACHE_ACTORS)
			->set($column, $qb->createFunction(
				'`' . $column . '` + ' . (($by > 0) ? '' : '-') . abs($by)
			))
			->where($expr->eq('id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($expr->gte($column, $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/**
	 * Writes the counters the cron walk counted, which is what reconciles the
	 * drift the increments above can accumulate.
	 *
	 * @param array{followers?: int, following?: int, post?: int} $count
	 */
	public function setCounts(string $actorId, array $count): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_CACHE_ACTORS)
			->set('count_followers', $qb->createNamedParameter(max(0, (int)($count['followers'] ?? 0)), IQueryBuilder::PARAM_INT))
			->set('count_following', $qb->createNamedParameter(max(0, (int)($count['following'] ?? 0)), IQueryBuilder::PARAM_INT))
			->set('count_posts', $qb->createNamedParameter(max(0, (int)($count['post'] ?? 0)), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$qb->executeStatement();
	}

	/**
	 * The three counters as they stand, or null where this account has never
	 * been counted — which is every row written before they were columns.
	 *
	 * @return array{followers: int, following: int, post: int}|null
	 */
	public function getCounts(string $actorId): ?array {
		$qb = $this->getQueryBuilder();
		$qb->select('count_followers', 'count_following', 'count_posts')
			->from(self::TABLE_CACHE_ACTORS)
			->where($qb->expr()->eq('id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->setMaxResults(1);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false || (int)$data['count_followers'] < 0) {
			return null;
		}

		return [
			'followers' => (int)$data['count_followers'],
			'following' => (int)$data['count_following'],
			'post' => max(0, (int)$data['count_posts']),
		];
	}

	public function updateDetails(Person $actor): int {
		$qb = $this->getCacheActorsUpdateSql();
		$qb->set('details', $qb->createNamedParameter(json_encode($actor->getDetailsAll())));

		try {
			$qb->set(
				'details_update',
				$qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
			);
		} catch (Exception $e) {
		}

		$this->limitToIdPrimString($qb, $actor->getId());

		return $qb->executeStatement();
	}

	/**
	 * get Cached version of an Actor, based on the UriId
	 *
	 * @param string $id
	 *
	 * @return Person
	 * @throws CacheActorDoesNotExistException
	 */
	public function getFromId(string $id): Person {
		$qb = $this->getCacheActorsSelectSql();
		$this->limitToIdPrimString($qb, $id);
		$qb->leftJoinCacheDocuments('icon_id');

		return $this->getCacheActorFromRequest($qb);
	}

	/**
	 * The cached actors behind a set of ids, in one query.
	 *
	 * For the callers that hold a list of actor ids and want whatever is known
	 * about them — a page of reports, a page of blocks — instead of a lookup,
	 * and a federated fetch on every miss, per row.
	 *
	 * @param string[] $ids
	 *
	 * @return Person[] keyed by actor id; ids that are not cached are absent
	 */
	public function getFromIds(array $ids): array {
		$qb = $this->getCacheActorsSelectSql();

		$prims = [];
		foreach ($ids as $id) {
			$prim = $qb->prim($id);
			if ($prim !== '') {
				$prims[$prim] = $prim;
			}
		}

		if ($prims === []) {
			return [];
		}

		$qb->limitInArray('id_prim', array_values($prims));
		$qb->leftJoinCacheDocuments('icon_id');

		$actors = [];
		foreach ($this->getCacheActorsFromRequest($qb) as $actor) {
			$actors[$actor->getId()] = $actor;
		}

		return $actors;
	}

	/**
	 * get Cached version of an Actor, based on the Account
	 *
	 * @param string $account
	 *
	 * @return Person
	 * @throws CacheActorDoesNotExistException
	 */
	public function getFromAccount(string $account): Person {
		$qb = $this->getCacheActorsSelectSql();
		$qb->limitToDBField('account_lower', self::lowerAccount($account));
		$qb->leftJoinCacheDocuments('icon_id');
		$this->leftJoinDetails($qb);

		return $this->getCacheActorFromRequest($qb);
	}

	/**
	 * get Cached version of a local Actor, based on the preferred username
	 *
	 * @param string $account
	 *
	 * @return Person
	 * @throws CacheActorDoesNotExistException
	 */
	public function getFromLocalAccount(string $account): Person {
		$qb = $this->getCacheActorsSelectSql();
		$qb->limitToPreferredUsername($account);
		$qb->limitToLocal(true);
		$qb->leftJoinCacheDocuments('icon_id');
		$this->leftJoinDetails($qb);

		return $this->getCacheActorFromRequest($qb);
	}

	/**
	 * The cached accounts whose handle starts with what was typed.
	 *
	 * Asked on every keystroke of the composer's mention picker, and of
	 * `/api/v2/search`, `/api/v1/accounts/search` and the unified search. The
	 * handle used to be matched through `account LIKE ?`, which MySQL was told
	 * to compare `COLLATE utf8mb4_general_ci` to make it case-insensitive — and
	 * an unindexed column compared under another collation is a scan of every
	 * cached actor. The lowercased copy `account_lower` carries the index
	 * `social_ca_al`, and a prefix `LIKE` on it, compared as it stands, is a
	 * range scan on MySQL and MariaDB. PostgreSQL and SQLite plan a `LIKE` as
	 * a range only under a C collation or `case_sensitive_like`; there it is
	 * still a scan, of one short column.
	 *
	 * @param string $followedBy when set, only the accounts this actor follows
	 *                           — narrowed in the query, so the limit counts
	 *                           followed accounts rather than cutting the
	 *                           search short before any of them is reached
	 *
	 * @return Person[]
	 */
	public function searchAccounts(string $search, ?int $limit = null, string $followedBy = ''): array {
		$qb = $this->getCacheActorsSelectSql();
		$qb->searchInAccount($search);
		if ($followedBy !== '') {
			$qb->innerJoin(
				$qb->getDefaultSelectAlias(),
				CoreRequestBuilder::TABLE_FOLLOWS,
				'ca_f',
				$qb->expr()->eq('ca.id_prim', 'ca_f.object_id_prim')
			);
			$qb->limitToType(Follow::TYPE, 'ca_f');
			$qb->limitToAccepted(true, 'ca_f');
			$qb->limitToActorIdPrim($qb->prim($followedBy), 'ca_f');
		}
		$qb->leftJoinCacheDocuments('icon_id');
		$this->leftJoinDetails($qb);
		$qb->limitResults(min($limit ?? self::SEARCH_LIMIT, self::SEARCH_LIMIT));

		return $this->getCacheActorsFromRequest($qb);
	}

	/**
	 * The cached remote actors whose refresh is due, the longest-waiting first.
	 *
	 * Due means the wait `syncWait()` prescribes for the row's failure count
	 * has passed since its last attempt, whether or not that attempt worked;
	 * a row past SYNC_MAX_FAILURES is not due at all. The selection used to
	 * read `creation`, which for a remote actor is the `published` date its
	 * instance reports and never moves, so every remote actor was always
	 * stale and, with no order, the same fifty came back on every run.
	 *
	 * @param int|null $now the clock to judge "due" by; the wall clock when null
	 *
	 * @return Person[]
	 * @throws Exception
	 */
	public function getRemoteActorsToUpdate(bool $force = false, ?int $now = null): array {
		$qb = $this->getCacheActorsSelectSql();
		$qb->limitToLocal(false);
		if (!$force) {
			$this->limitToSyncDue($qb, $now ?? time(), true);
			// One cron pass syncs a bounded batch; on a large instance the full set
			// would be thousands of outbound requests in a single job.
			$qb->setMaxResults(self::SYNC_BATCH);
		}

		// the oldest attempt first, so the batch walks the whole table rather
		// than returning whatever the database stores first; nid breaks the
		// tie among the rows that were never attempted
		$qb->orderBy('ca.sync_attempt', 'asc');
		$qb->addOrderBy('ca.nid', 'asc');

		return $this->getCacheActorsFromRequest($qb);
	}

	/**
	 * The cached remote actors whose timeline the cron syncs next, the
	 * longest-untried first.
	 *
	 * A batch of its own rather than the refresh's. The timeline sync used to
	 * be handed whatever `getRemoteActorsToUpdate()` returned, which worked
	 * only because nothing the refresh did could make an actor stop being
	 * stale — every remote actor always was. Now that a refresh stamps what it
	 * touched, the refresh step would consume the whole due set before the
	 * sync step ran, and on an instance with fewer than SYNC_BATCH remote
	 * actors no timeline would ever be synced again.
	 *
	 * Ordering on the same attempt stamp still rotates it: the actors the
	 * refresh just stamped sort last, so this gets the ones it did not reach.
	 * The give-up threshold is shared, because a dead instance has no timeline
	 * to read either.
	 *
	 * Only the actors somebody here follows. A synced timeline feeds the home
	 * timelines of the followers, and nothing else reads it: an actor the
	 * instance merely met — a like, a boost seen in passing — has no reader
	 * for its outbox, and every cached actor was fetched on every rotation.
	 *
	 * @return Person[]
	 * @throws Exception
	 */
	public function getRemoteActorsToSync(int $limit = self::SYNC_BATCH): array {
		$qb = $this->getCacheActorsSelectSql();
		$qb->limitToLocal(false);
		$qb->andWhere(
			$qb->expr()->lt(
				'ca.sync_failures',
				$qb->createNamedParameter(self::SYNC_MAX_FAILURES, IQueryBuilder::PARAM_INT)
			)
		);

		// an accepted follow of the actor by a local account
		$follow = $this->getQueryBuilder();
		$follow->select($follow->createFunction('1'))
			->from(self::TABLE_FOLLOWS, 'f')
			->from(self::TABLE_CACHE_ACTORS, 'la')
			->where('f.object_id_prim = ca.id_prim')
			// quoted literals: boolean columns on PostgreSQL, ints elsewhere
			->andWhere("f.accepted = '1'")
			->andWhere('la.id_prim = f.actor_id_prim')
			->andWhere("la.local = '1'");
		$qb->andWhere('EXISTS (' . $follow->getSQL() . ')');

		$qb->orderBy('ca.sync_attempt', 'asc');
		$qb->addOrderBy('ca.nid', 'asc');
		$qb->setMaxResults($limit);

		return $this->getCacheActorsFromRequest($qb);
	}

	/**
	 * @return Person[]
	 * @throws Exception
	 */
	public function getRemoteActorsToUpdateDetails(bool $force = false, ?int $now = null): array {
		$qb = $this->getCacheActorsSelectSql();
		$qb->limitToLocal(false);
		if (!$force) {
			$date = new DateTime('now');
			$date->sub(new DateInterval('PT' . self::DETAILS_TTL . 'M'));
			$qb->limitToDBFieldDateTime('details_update', $date, true);
			// an actor whose refresh just failed is not asked three more
			// questions; one that has been given up on is not asked at all.
			// Without this the fifty oldest `details_update` were the fifty
			// dead ones, on every pass, since a failure never advanced it
			$this->limitToSyncDue($qb, $now ?? time(), false);
			// three outbound requests per actor (followers, following, outbox),
			// so one cron pass takes a bounded batch just as the sibling above
			// does — the rest are picked up by the next pass
			$qb->setMaxResults(self::SYNC_BATCH);
		}

		$qb->orderBy('ca.details_update', 'asc');

		return $this->getCacheActorsFromRequest($qb);
	}

	/**
	 * The backoff schedule, in SQL: one branch per failure count below the
	 * give-up threshold, `sync_failures = n AND sync_attempt <= now - wait(n)`.
	 * The threshold falls out of the same expression — no branch, never due.
	 *
	 * @param bool $waitWhenHealthy whether a row with no failure on record
	 *                              waits CACHE_TTL (the refresh) or is due at
	 *                              once (the details, which have a clock of
	 *                              their own in `details_update`)
	 */
	private function limitToSyncDue(SocialQueryBuilder $qb, int $now, bool $waitWhenHealthy): void {
		$expr = $qb->expr();
		// built first and passed in one go: an empty orX() is deprecated and
		// will throw
		$due = [];
		for ($failures = 0; $failures < self::SYNC_MAX_FAILURES; $failures++) {
			$wait = ($failures === 0 && !$waitWhenHealthy) ? 0 : self::syncWait($failures);
			$due[] = $expr->andX(
				$expr->eq('ca.sync_failures', $qb->createNamedParameter($failures, IQueryBuilder::PARAM_INT)),
				$expr->lte('ca.sync_attempt', $qb->createNamedParameter($now - $wait, IQueryBuilder::PARAM_INT))
			);
		}

		$qb->andWhere($expr->orX(...$due));
	}

	/**
	 * Stamps a refresh attempt on one cached actor.
	 *
	 * Written whether the attempt worked or not — that is the whole point: a
	 * row that is never stamped is due again on the next pass, and fifty of
	 * those on a dead instance were the only fifty the refresh ever saw. A
	 * success clears the failure count; a failure adds to it, in SQL, so two
	 * workers stamping the same row cannot lose a count between them.
	 */
	public function recordSyncAttempt(string $id, bool $success, int $now): void {
		$qb = $this->getCacheActorsUpdateSql();
		$qb->set('sync_attempt', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT));
		if ($success) {
			$qb->set('sync_failures', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT));
		} else {
			$qb->set('sync_failures', $qb->func()->add('sync_failures', $qb->expr()->literal(1)));
		}

		$this->limitToIdPrimString($qb, $id);

		$qb->executeStatement();
	}

	/**
	 * Records on a cached actor that it moved to `$target`.
	 *
	 * A cache row has no column for `movedTo`: like `alsoKnownAs` it is read
	 * off the stored source document, so that is where it is written. The
	 * document is what the actor's server will serve once its own caches have
	 * turned over — Mastodon keeps serving the pre-move one for minutes — and
	 * a refresh that gets the new document says the same thing.
	 *
	 * @throws CacheActorDoesNotExistException
	 */
	public function markMoved(string $id, string $target): void {
		$actor = $this->getFromId($id);
		$source = json_decode($actor->getSource(), true);
		if (!is_array($source)) {
			$source = ['id' => $actor->getId()];
		}
		$source['movedTo'] = $target;

		$qb = $this->getCacheActorsUpdateSql();
		$qb->set('source', $qb->createNamedParameter(json_encode($source, JSON_UNESCAPED_SLASHES)));
		$this->limitToIdPrimString($qb, $id);

		$qb->executeStatement();
	}

	/**
	 * The cached remote actors nothing here refers to any more, a page at a
	 * time: not followed by and not following any account this instance
	 * knows, no follow request or block/mute/endorsement either way, no post
	 * of theirs stored, and neither seen (`creation`) nor tried (`sync_attempt`)
	 * since `$cutoff`.
	 *
	 * Both dates have to be old. `creation` is the `published` date the
	 * actor's instance reports, or the moment it was first cached when it
	 * reports none; `sync_attempt` is when the refresh last looked, 0 for
	 * never. A row that was tried last week is one the cron still cares
	 * about, however old its profile says it is.
	 *
	 * Bounded because the caller deletes what it is handed and asks again; the
	 * next call returns the next page without an offset to keep track of.
	 *
	 * @return string[] actor ids, the longest-untouched first
	 */
	public function getSweepableIds(int $cutoff, int $limit): array {
		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$qb->select('ca.id')
			->from(self::TABLE_CACHE_ACTORS, 'ca')
			->where($expr->eq('ca.local', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($expr->lt('ca.sync_attempt', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)))
			->andWhere($expr->lt('ca.creation', $qb->createNamedParameter(
				new DateTime('@' . $cutoff), IQueryBuilder::PARAM_DATE
			)))
			->orderBy('ca.sync_attempt', 'asc')
			->addOrderBy('ca.nid', 'asc')
			->setMaxResults($limit);

		// a follow in either direction, accepted or still pending
		$follow = $this->getQueryBuilder();
		$follow->select($follow->createFunction('1'))
			->from(self::TABLE_FOLLOWS, 'f')
			->where('(f.actor_id_prim = ca.id_prim OR f.object_id_prim = ca.id_prim)');
		$qb->andWhere('NOT EXISTS (' . $follow->getSQL() . ')');

		// a block, a mute, an endorsement, a bell — anything one account has
		// decided about another
		$relation = $this->getQueryBuilder();
		$relation->select($relation->createFunction('1'))
			->from(self::TABLE_ACTOR_RELATION, 'r')
			->where('(r.actor_id_prim = ca.id_prim OR r.object_id_prim = ca.id_prim)');
		$qb->andWhere('NOT EXISTS (' . $relation->getSQL() . ')');

		// a post of theirs that is still stored here needs its author
		$stream = $this->getQueryBuilder();
		$stream->select($stream->createFunction('1'))
			->from(self::TABLE_STREAM, 's')
			->where('s.attributed_to_prim = ca.id_prim');
		$qb->andWhere('NOT EXISTS (' . $stream->getSQL() . ')');

		$ids = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$ids[] = (string)$data['id'];
		}
		$cursor->closeCursor();

		return $ids;
	}

	/**
	 * delete cached version of an Actor, based on the UriId
	 *
	 * @param string $id
	 */
	public function deleteCacheById(string $id) {
		$qb = $this->getCacheActorsDeleteSql();
		$qb->limitToIdPrim($qb->prim($id));

		$qb->executeStatement();
	}

	/**
	 * The cached actors of one instance, a page at a time.
	 *
	 * Bounded because a domain purge must not read a whole instance's accounts
	 * into memory to delete them; the caller deletes each row it is handed, so
	 * the next call returns the next page without an offset to keep track of.
	 *
	 * @return string[] actor ids
	 *
	 * @throws InvalidResourceException the domain is not one
	 */
	public function getIdsFromDomain(string $domain, int $limit = 100): array {
		$qb = $this->getQueryBuilder();
		$qb->select('ca.id')
			->from(self::TABLE_CACHE_ACTORS, 'ca')
			->where(DomainBlocksRequestBuilder::onDomain($qb, 'ca.id', $domain))
			->setMaxResults($limit);

		$ids = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$ids[] = (string)$data['id'];
		}
		$cursor->closeCursor();

		return $ids;
	}

	/**
	 * @return array
	 */
	public function getSharedInboxes(): array {
		$qb = $this->getQueryBuilder();
		$qb->selectDistinct('shared_inbox')
			->from(self::TABLE_CACHE_ACTORS)
			// an actor with no shared inbox used to come back as '', which the
			// caller then turned into a delivery to the host ''
			->where($qb->expr()->neq('shared_inbox', $qb->createNamedParameter('')))
			->andWhere($qb->expr()->eq('local', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		$inbox = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$inbox[] = $data['shared_inbox'];
		}
		$cursor->closeCursor();

		return $inbox;
	}

	/**
	 * @param ProbeOptions $options
	 *
	 * @return Person[]
	 */
	public function probeActors(ProbeOptions $options): array {
		switch (strtolower($options->getProbe())) {
			case ProbeOptions::FOLLOWING:
				$result = $this->probeActorsFollowing($options);
				break;
			case ProbeOptions::FOLLOWERS:
				$result = $this->probeActorsFollowers($options);
				break;
			default:
				return [];
		}

		if ($options->isInverted()) {
			// in case we inverted the order during the request, we revert the results
			$result = array_reverse($result);
		}

		return $result;
	}

	/**
	 * @param ProbeOptions $options
	 *
	 * @return Person[]
	 */
	public function probeActorsFollowing(ProbeOptions $options): array {
		$qb = $this->getCacheActorsSelectSql($options->getFormat());

		$qb->paginate($options);

		$qb->leftJoin(
			$qb->getDefaultSelectAlias(),
			CoreRequestBuilder::TABLE_FOLLOWS,
			'ca_f',
			// object_id of follow is equal to actor's id
			$qb->expr()->eq('ca.id_prim', 'ca_f.object_id_prim')
		);

		// follow must be accepted
		$qb->limitToType(Follow::TYPE, 'ca_f');
		$qb->limitToAccepted(true, 'ca_f');
		// actor_id of follow is equal to requested account
		$qb->limitToActorIdPrim($qb->prim($options->getAccountId()), 'ca_f');

		return $this->getCacheActorsFromRequest($qb);
	}

	/**
	 * @param ProbeOptions $options
	 *
	 * @return Person[]
	 */
	public function probeActorsFollowers(ProbeOptions $options): array {
		$qb = $this->getCacheActorsSelectSql($options->getFormat());

		$qb->paginate($options);

		$qb->leftJoin(
			$qb->getDefaultSelectAlias(),
			CoreRequestBuilder::TABLE_FOLLOWS,
			'ca_f',
			// actor_id of follow is equal to actor's id
			$qb->expr()->eq('ca.id_prim', 'ca_f.actor_id_prim')
		);

		// follow must be accepted
		$qb->limitToType(Follow::TYPE, 'ca_f');
		$qb->limitToAccepted(true, 'ca_f');
		// object_id of follow is equal to requested account
		$qb->limitToObjectIdPrim($qb->prim($options->getAccountId()), 'ca_f');

		return $this->getCacheActorsFromRequest($qb);
	}

	/**
	 * The cached actors behind a set of numeric ids, with their cached icon
	 * joined in: without it the avatar has no local copy to name, and the
	 * account read by id was given its placeholder while every other route
	 * gave its picture.
	 *
	 * @param array $ids
	 *
	 * @return Person[]
	 */
	public function getFromNids(array $ids): array {
		$qb = $this->getCacheActorsSelectSql();

		$qb->limitInArray('nid', $ids);
		$qb->leftJoinCacheDocuments('icon_id');

		return $this->getCacheActorsFromRequest($qb);
	}

	/**
	 * How big an audience each of these accounts has, as far as this server
	 * has been told.
	 *
	 * Read out of the cached `details` rather than counted here: a remote
	 * account's followers are not on this server, and what is stored is
	 * whatever its own instance last reported to the details refresh. An
	 * account nothing is known about is *absent* from the answer rather than
	 * present as a zero, so a caller can say "not known" instead of stating a
	 * number that is only a gap.
	 *
	 * @param string[] $ids the accounts, by id
	 * @return array<string, int> actor id => how many followers it has
	 */
	public function followerCountsOf(array $ids): array {
		if ($ids === []) {
			return [];
		}

		$qb = $this->getQueryBuilder();

		$prims = [];
		foreach ($ids as $id) {
			$prim = $qb->prim($id);
			if ($prim !== '') {
				$prims[$prim] = $prim;
			}
		}

		if ($prims === []) {
			return [];
		}

		$qb->select('ca.id', 'ca.details')
			->from(self::TABLE_CACHE_ACTORS, 'ca')
			->where($qb->expr()->in(
				'ca.id_prim',
				$qb->createNamedParameter(array_values($prims), IQueryBuilder::PARAM_STR_ARRAY)
			));

		$counts = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$details = json_decode((string)($data['details'] ?? ''), true);
			$followers = (is_array($details) && isset($details['count']['followers']))
				? (int)$details['count']['followers'] : 0;
			if ($followers > 0) {
				$counts[(string)$data['id']] = $followers;
			}
		}
		$cursor->closeCursor();

		return $counts;
	}
}
