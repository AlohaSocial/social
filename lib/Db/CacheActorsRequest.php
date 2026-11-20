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
use OCA\Social\Model\Details;
use OCA\Social\Security\HtmlSanitizer;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;

class CacheActorsRequest extends CacheActorsRequestBuilder {
	/**
	 * The most rows a search will read, whatever it was asked for: a search
	 * box shows a handful.
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
	 * With `syncWait()` doubling from an hour, ten failures span about six
	 * weeks. Past this only the refresh stops; the row is kept, and a fetch
	 * that is asked for (a mention, a profile opened) still goes out and,
	 * when it works, resets the count.
	 */
	public const SYNC_MAX_FAILURES = 10;

	/** The first wait after a failed refresh, in seconds; it doubles per failure. */
	public const SYNC_BACKOFF_BASE = 3600;

	/**
	 * How long a cached remote actor is left alone after a refresh attempt:
	 * CACHE_TTL with no failure on record, else `SYNC_BACKOFF_BASE * 2^(n-1)`
	 * after `n` failures in a row. Shared by `limitToSyncDue()` and by
	 * callers judging an actor in PHP.
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
			// the bio as a read hands it out, stamped (Person::importFromDatabase())
			->setValue('description', $qb->createNamedParameter(HtmlSanitizer::sanitize($actor->getSummary(), $actor->getId())))
			->setValue('sanitized', $qb->createNamedParameter(HtmlSanitizer::version(), IQueryBuilder::PARAM_INT))
			->setValue('public_key', $qb->createNamedParameter($actor->getPublicKey()))
			->setValue('source', $qb->createNamedParameter($actor->getSource()))
			->setValue('details', $qb->createNamedParameter(json_encode($actor->getDetailsAll())))
			// the server this account is on, so "which servers do we know"
			// is a grouped query; derived from the handle, which never changes
			->setValue('host', $qb->createNamedParameter(self::hostOf($actor)))
			// what an account search matches a prefix of; see searchAccounts()
			->setValue('account_lower', $qb->createNamedParameter(self::lowerAccount($actor->getAccount())));

		try {
			if ($actor->getCreation() > 0) {
				$dTime = new DateTime('@' . $actor->getCreation());
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

		// the counters the actor arrives with (a local account's, just counted
		// by AccountService::addLocalActorDetailCount()) go into the columns
		// too, or a new row starts at "never counted" and shows 0
		$count = $actor->getDetails(Details::COUNT);
		if (isset($count['followers'])) {
			$qb->setValue('count_followers', $qb->createNamedParameter(max(0, (int)$count['followers']), IQueryBuilder::PARAM_INT))
				->setValue('count_following', $qb->createNamedParameter(max(0, (int)($count['following'] ?? 0)), IQueryBuilder::PARAM_INT))
				->setValue('count_posts', $qb->createNamedParameter(max(0, (int)($count['post'] ?? 0)), IQueryBuilder::PARAM_INT));
		}

		try {
			$qb->executeStatement();
		} catch (DBException $e) {
		}
	}

	public function update(Person $actor): int {
		$qb = $this->getCacheActorsUpdateSql();

		// The handle is derived, not carried on the wire. A caller that has one
		// is correcting it (a local actor after the address changed); one that
		// has none must not erase what is stored.
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
			->set('description', $qb->createNamedParameter(HtmlSanitizer::sanitize($actor->getSummary(), $actor->getId())))
			->set('sanitized', $qb->createNamedParameter(HtmlSanitizer::version(), IQueryBuilder::PARAM_INT))
			->set('public_key', $qb->createNamedParameter($actor->getPublicKey()))
			->set('source', $qb->createNamedParameter($actor->getSource()))
			->set('details', $qb->createNamedParameter(json_encode($actor->getDetailsAll())));

		try {
			if ($actor->getCreation() > 0) {
				$dTime = new DateTime('@' . $actor->getCreation());
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
	 * Moves one of an account's counters by `$by`, without counting anything.
	 * `SET col = col + ?` is atomic, so concurrent follows lose no update. A
	 * counter never counted (`-1`) is left for the cron's walk. A decrement
	 * stops at 0 in the same statement; `CASE` rather than `GREATEST`, which
	 * SQLite does not have.
	 *
	 * @param string $column one of `count_followers`, `count_following`, `count_posts`
	 */
	public function bumpCount(string $actorId, string $column, int $by): void {
		if (!in_array($column, self::COUNT_COLUMNS, true) || $by === 0) {
			return;
		}

		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$step = abs($by);
		$moved = ($by > 0)
			? '`' . $column . '` + ' . $step
			: 'CASE WHEN `' . $column . '` > ' . $step . ' THEN `' . $column . '` - ' . $step . ' ELSE 0 END';
		$qb->update(self::TABLE_CACHE_ACTORS)
			->set($column, $qb->createFunction($moved))
			->where($expr->eq('id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($expr->gte($column, $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/**
	 * Writes the counters the cron walk counted, reconciling any drift of the
	 * increments.
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
	 * been counted.
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
	 * The cached actors behind a set of ids, in one query, for callers holding
	 * a page of actor ids (reports, blocks) that must not fetch on a miss.
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
	 * The cached accounts whose handle starts with what was typed, asked on
	 * every keystroke of mention pickers and account searches. A prefix
	 * `LIKE` on the lowercased, indexed `account_lower` is a range scan on
	 * MySQL and MariaDB; PostgreSQL and SQLite scan the one short column.
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
	 * The cached remote actors whose refresh is due, the longest-waiting
	 * first: the wait `syncWait()` prescribes for the row's failure count has
	 * passed since its last attempt. A row past SYNC_MAX_FAILURES is never due.
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
	 * longest-untried first. A batch of its own, since the refresh stamps
	 * what it touches and would otherwise leave nothing for the sync; on the
	 * same stamp, the actors just refreshed sort last. Only actors somebody
	 * here follows, since a synced timeline only feeds followers' homes.
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
			// a failed or given-up actor is skipped, or the oldest
			// `details_update` would be the dead ones on every pass
			$this->limitToSyncDue($qb, $now ?? time(), false);
			// three outbound requests per actor (followers, following, outbox),
			// so one cron pass takes a bounded batch
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
	 * Stamps a refresh attempt on one cached actor, whether it worked or not,
	 * so a dead instance's rows are not due again on every pass. A success
	 * clears the failure count; a failure adds to it in SQL, so concurrent
	 * workers lose no count.
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
	 * Records on a cached actor that it moved to `$target`. Written into the
	 * stored source document, where `movedTo` is read like `alsoKnownAs`,
	 * since Mastodon keeps serving the pre-move document for minutes.
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
	 * time: no follow, follow request or block/mute/endorsement either way,
	 * no stored post, and neither `creation` nor `sync_attempt` since
	 * `$cutoff` (a row tried recently is one the cron still cares about). The
	 * caller deletes each page and asks again.
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
	 * The cached actors of one instance, a page at a time, so a domain purge
	 * does not read them all into memory; the caller deletes each page and
	 * asks again.
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
			// an actor with no shared inbox has ''
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
	 * One shared inbox per server that has answered since `$since`, for an
	 * activity addressed to everybody (an account deletion). Grouped on the
	 * indexed `host`. A server counts as seen when a refresh of one of its
	 * accounts worked in the window or one of its accounts was made in it.
	 *
	 * @return string[]
	 */
	public function getSharedInboxesSeenSince(int $since): array {
		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$qb->selectAlias($qb->func()->max('ca.nid'), 'nid')
			->from(self::TABLE_CACHE_ACTORS, 'ca')
			->where($expr->eq('ca.local', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($expr->neq('ca.host', $qb->createNamedParameter('')))
			->andWhere($expr->neq('ca.shared_inbox', $qb->createNamedParameter('')))
			->andWhere($expr->orX(
				$expr->andX(
					$expr->gte('ca.sync_attempt', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)),
					$expr->eq('ca.sync_failures', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
				),
				$expr->gte('ca.creation', $qb->createNamedParameter(
					new DateTime('@' . $since), IQueryBuilder::PARAM_DATE
				))
			))
			->groupBy('ca.host');

		$nids = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$nids[] = (int)$data['nid'];
		}
		$cursor->closeCursor();

		$inboxes = [];
		foreach (array_chunk($nids, 500) as $chunk) {
			$qb = $this->getQueryBuilder();
			$qb->select('shared_inbox')
				->from(self::TABLE_CACHE_ACTORS)
				->where($qb->expr()->in('nid', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));

			$cursor = $qb->executeQuery();
			while ($data = $cursor->fetch()) {
				$inboxes[(string)$data['shared_inbox']] = true;
			}
			$cursor->closeCursor();
		}

		return array_map('strval', array_keys($inboxes));
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
	 * joined in so the avatar has a local copy to name.
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
	 * How many followers each of these accounts has, as its instance last
	 * reported in the cached `details`. An account nothing is known about is
	 * absent rather than zero, so a caller can say "not known".
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
