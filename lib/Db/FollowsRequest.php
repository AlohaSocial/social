<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use Exception;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Class FollowsRequest
 *
 * @package OCA\Social\Db
 */
class FollowsRequest extends FollowsRequestBuilder {
	use TArrayTools;

	/**
	 * Inserts a follow unless a row with its id or (actor, object) pair is
	 * already there: a redelivery can race the inbox's lookup, and a failed
	 * insert would abort the transaction on PostgreSQL.
	 *
	 * @return bool whether the row was written: false when it was already there
	 */
	public function save(Follow $follow): bool {
		$qb = $this->getQueryBuilder();

		return $this->dbConnection->insertIgnoreConflict(self::TABLE_FOLLOWS, [
			'id' => $follow->getId(),
			'id_prim' => $qb->prim($follow->getId()),
			'actor_id' => $follow->getActorId(),
			'type' => $follow->getType(),
			'object_id' => $follow->getObjectId(),
			'follow_id' => $follow->getFollowId(),
			'accepted' => ($follow->isAccepted()) ? '1' : '0',
			'actor_id_prim' => $qb->prim($follow->getActorId()),
			'object_id_prim' => $qb->prim($follow->getObjectId()),
			'follow_id_prim' => $qb->prim($follow->getFollowId()),
			'creation' => (new DateTime('now'))->format('Y-m-d H:i:s'),
		]) > 0;
	}

	/**
	 * Create a self-follow (Loopback) entry for a local actor.
	 *
	 * This ensures the user appears in their own home timeline.
	 * Uses INSERT IGNORE to handle duplicate calls safely.
	 *
	 * @param Person $actor
	 */
	public function generateLoopbackAccount(Person $actor) {
		if ($this->isLoppbackExisting($actor->getId())) {
			return;  // Already has a loopback, skip
		}

		$qb = $this->getFollowsInsertSql();
		$qb->setValue('id', $qb->createNamedParameter($actor->getId()))
			->setValue('actor_id', $qb->createNamedParameter($actor->getId()))
			->setValue('type', $qb->createNamedParameter('Loopback'))
			->setValue('object_id', $qb->createNamedParameter($actor->getId()))
			->setValue('follow_id', $qb->createNamedParameter($actor->getId()))
			->setValue('accepted', $qb->createNamedParameter('1'))
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($actor->getId())))
			->setValue('object_id_prim', $qb->createNamedParameter($qb->prim($actor->getId())))
			->setValue('follow_id_prim', $qb->createNamedParameter($qb->prim($actor->getId())));

		try {
			$qb->setValue(
				'creation',
				$qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
			);
		} catch (Exception $e) {
			// 'now' is a constant, so this does not happen; were it ever to,
			// the row would go in with no date at all, and every list that
			// orders on it would place it arbitrarily
			$this->logger->warning('could not timestamp a row', ['exception' => $e]);
		}

		$qb->generatePrimaryKey($actor->getId());
		$qb->executeStatement();
	}

	/**
	 * Check if a loopback (self-follow) already exists for this actor.
	 *
	 * @param string $actorId
	 *
	 * @return bool
	 */
	private function isLoppbackExisting(string $actorId): bool {
		try {
			$this->getByPersons($actorId, $actorId);
			return true;
		} catch (FollowNotFoundException $e) {
			return false;
		}
	}

	/**
	 * Mark a follow as accepted.
	 *
	 * Critical: remote servers embed the Follow in their Accept with THEIR id,
	 * not ours. Match by actor+object pair instead.
	 *
	 * @return bool whether a pending row was marked: false when there is
	 *              none, or it was accepted already
	 */
	public function accepted(Follow $follow): bool {
		$qb = $this->getFollowsUpdateSql();
		$qb->set('accepted', $qb->createNamedParameter('1'));
		$this->limitToPrim($qb, 'actor_id_prim', $follow->getActorId());
		$this->limitToPrim($qb, 'object_id_prim', $follow->getObjectId());
		$qb->limitToAccepted(false);

		return $qb->executeStatement() > 0;
	}

	/**
	 * One page of every follow, in key order: its id and the two accounts it
	 * joins, which is all a maintenance walk compares. Not built into a
	 * `Follow` each, which looks up the actor of every row.
	 *
	 * @return array<string, array{id: string, actor_id: string, object_id: string}>
	 *                                                                               keyed by id_prim
	 */
	public function getKeyPage(int $limit, string $afterPrim = ''): array {
		$qb = $this->getQueryBuilder();
		$qb->select('f.id_prim', 'f.id', 'f.actor_id', 'f.object_id')
			->from(self::TABLE_FOLLOWS, 'f');
		if ($afterPrim !== '') {
			$qb->andWhere($qb->expr()->gt('f.id_prim', $qb->createNamedParameter($afterPrim)));
		}
		$qb->orderBy('f.id_prim', 'asc');
		$qb->setMaxResults($limit);

		$page = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$page[(string)$data['id_prim']] = [
				'id' => (string)$data['id'],
				'actor_id' => (string)$data['actor_id'],
				'object_id' => (string)$data['object_id'],
			];
		}
		$cursor->closeCursor();

		return $page;
	}

	/**
	 * The follow a URI names.
	 *
	 * Only a peer that sends the object of an `Undo`/`Accept`/`Reject` as a
	 * bare link needs this: the id is then all there is to go on.
	 *
	 * @throws FollowNotFoundException
	 */
	public function getById(string $id): Follow {
		if ($id === '') {
			throw new FollowNotFoundException('empty follow id');
		}

		$qb = $this->getFollowsSelectSql();
		$this->limitToIdPrimString($qb, $id);

		return $this->getFollowFromRequest($qb);
	}

	/**
	 * @param string $actorId
	 * @param string $remoteActorId
	 *
	 * @return Follow
	 * @throws FollowNotFoundException
	 */
	public function getByPersons(string $actorId, string $remoteActorId): Follow {
		$qb = $this->getFollowsSelectSql();
		$this->limitToPrim($qb, 'actor_id_prim', $actorId);
		$this->limitToPrim($qb, 'object_id_prim', $remoteActorId);

		return $this->getFollowFromRequest($qb);
	}

	/**
	 * The follow rows between one actor and a *set* of others, both ways round.
	 *
	 * Answers the same two questions `getByPersons()` does — does the viewer
	 * follow them, do they follow the viewer — for a whole page in two queries
	 * rather than two per account.
	 *
	 * @param string[] $others
	 *
	 * @return array{following: array<string, Follow>, followedBy: array<string, Follow>}
	 */
	public function getBetweenMany(string $actorId, array $others): array {
		if ($others === []) {
			return ['following' => [], 'followedBy' => []];
		}

		return [
			'following' => $this->followsOneWay($actorId, $others, 'actor_id_prim', 'object_id_prim'),
			'followedBy' => $this->followsOneWay($actorId, $others, 'object_id_prim', 'actor_id_prim'),
		];
	}

	/**
	 * @param string[] $others
	 *
	 * @return array<string, Follow>
	 */
	private function followsOneWay(string $actorId, array $others, string $mine, string $theirs): array {
		$qb = $this->getFollowsSelectSql();
		$prims = array_map(static fn (string $id): string => $qb->prim($id), $others);
		$qb->andWhere($qb->expr()->eq('f.' . $mine, $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($qb->expr()->in('f.' . $theirs, $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)));

		$follows = [];
		foreach ($this->getFollowsFromRequest($qb) as $follow) {
			// keyed by the *other* actor, whichever end of the row that is
			$other = ($mine === 'actor_id_prim') ? $follow->getObjectId() : $follow->getActorId();
			$follows[$other] = $follow;
		}

		return $follows;
	}

	/**
	 * @param string $actorId
	 *
	 * @return int
	 */
	public function countFollowers(string $actorId): int {
		$qb = $this->countFollowsSelectSql();
		$this->limitToPrim($qb, 'object_id_prim', $actorId);
		$qb->limitToType(Follow::TYPE);
		$qb->limitToAccepted(true);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $this->getInt('count', $data, 0);
	}

	/**
	 * Where an account's followers are and when each arrived, for its
	 * statistics: two columns, not hydrated `Follow`s. The host is parsed in
	 * PHP, as the three databases cut strings differently.
	 *
	 * @param int $limit a ceiling on the rows read; newest first, so the cap
	 *                   drops the oldest followers rather than a random slice
	 *
	 * @return array<array{actor_id: string, creation: string}>
	 */
	public function getFollowerOrigins(string $actorId, int $limit): array {
		$qb = $this->getQueryBuilder();
		$qb->select('actor_id', 'creation')
			->from(CoreRequestBuilder::TABLE_FOLLOWS);
		$qb->andWhere(
			$qb->expr()->eq('object_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
		);
		$qb->andWhere($qb->expr()->eq('type', $qb->createNamedParameter(Follow::TYPE)));
		$qb->andWhere($qb->expr()->eq('accepted', $qb->createNamedParameter('1')));
		$qb->orderBy('creation', 'desc');
		// the cap cuts the list, so the rows sharing the second it falls in
		// need an order of their own for the page to be the same twice
		$qb->addOrderBy('id_prim', 'desc');
		$qb->setMaxResults($limit);

		$rows = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$rows[] = [
				'actor_id' => (string)($data['actor_id'] ?? ''),
				'creation' => (string)($data['creation'] ?? ''),
			];
		}
		$cursor->closeCursor();

		return $rows;
	}

	/**
	 * @param string $actorId
	 *
	 * @return int
	 */
	public function countPendingRequests(string $actorId): int {
		$qb = $this->countFollowsSelectSql();
		$this->limitToPrim($qb, 'object_id_prim', $actorId);
		$qb->limitToType(Follow::TYPE);
		$qb->limitToAccepted(false);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $this->getInt('count', $data, 0);
	}

	/**
	 * @param string $actorId
	 *
	 * @return int
	 */
	public function countFollowing(string $actorId): int {
		$qb = $this->countFollowsSelectSql();
		$this->limitToPrim($qb, 'actor_id_prim', $actorId);
		$qb->limitToType(Follow::TYPE);
		$qb->limitToAccepted(true);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $this->getInt('count', $data, 0);
	}

	/**
	 * How many follows this actor has sent since a moment, pending or accepted,
	 * counted from the rows: the limit is enforced against this, and most
	 * instances have no memcache for a counter.
	 */
	public function countFollowsSince(string $actorId, int $since): int {
		$qb = $this->countFollowsSelectSql();
		$this->limitToPrim($qb, 'actor_id_prim', $actorId);
		$qb->limitToType(Follow::TYPE);
		$qb->andWhere(
			$qb->expr()->gt('f.creation', $qb->createNamedParameter(
				$this->dateTime($since), IQueryBuilder::PARAM_DATE
			))
		);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $this->getInt('count', $data, 0);
	}

	/** A timestamp as the DateTime the date parameters take. */
	private function dateTime(int $timestamp): DateTime {
		$date = new DateTime('@' . $timestamp);

		return $date;
	}

	/**
	 * @return int
	 */
	public function countFollows() {
		$qb = $this->countFollowsSelectSql();

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $this->getInt('count', $data, 0);
	}

	/**
	 * @param string $followId
	 *
	 * @return Follow[]
	 */
	public function getByFollowId(string $followId): array {
		$qb = $this->getFollowsSelectSql();
		$this->limitToPrim($qb, 'follow_id_prim', $followId);
		$qb->limitToAccepted(true);
		$this->leftJoinCacheActors($qb, 'actor_id');

		return $this->getFollowsFromRequest($qb);
	}

	/**
	 * The accepted followers of an actor, newest first, hydrated into Follows,
	 * for the paged `followers` collection and the re-follow after a Move.
	 * Delivery uses getFollowerInboxes() instead.
	 *
	 * @return Follow[]
	 */
	public function getFollowersByActorId(string $actorId, int $limit = 0, int $offset = 0, string $before = ''): array {
		$qb = $this->getFollowsSelectSql();
		$this->limitToPrim($qb, 'object_id_prim', $actorId);
		$qb->limitToAccepted(true);
		// the accepted Loopback row an actor has on itself is how its own posts
		// reach its home timeline; it is not a follower
		$qb->limitToType(Follow::TYPE);
		$this->limitBeforeCursor($qb, $before);
		$this->leftJoinCacheActors($qb, 'actor_id');
		$this->leftJoinDetails($qb, 'id', 'ca');
		$qb->orderBy('f.creation', 'desc');
		// `creation` is stored to the second; the primary key breaks ties so an
		// offset page neither repeats nor skips a row
		$qb->addOrderBy('f.id_prim', 'desc');

		if ($limit > 0) {
			$qb->setMaxResults($limit);
			$qb->setFirstResult($offset);
		}

		return $this->getFollowsFromRequest($qb);
	}

	/**
	 * Where the page after `$follow` starts, in the order the follower and
	 * following lists are read: `creation` descending, then `id_prim`.
	 */
	public static function cursorAfter(Follow $follow): string {
		return $follow->getCreation() . '-' . md5($follow->getId());
	}

	/** Whether `$cursor` is one cursorAfter() could have written. */
	public static function isCursor(string $cursor): bool {
		return preg_match('/^\d{1,12}-[0-9a-f]{32}$/', $cursor) === 1;
	}

	/**
	 * Limit a follower or following list to the rows after a cursor: a keyset,
	 * as an offset page reads and discards every row before it. The first
	 * predicate is the range `social_f_ocr` (object_id_prim, creation) serves;
	 * the second breaks ties within one second as the order does.
	 *
	 * @param string $cursor '' for the first page; see cursorAfter()
	 */
	private function limitBeforeCursor(SocialQueryBuilder $qb, string $cursor): void {
		if ($cursor === '') {
			return;
		}

		$expr = $qb->expr();
		if (!self::isCursor($cursor)) {
			// no row comes after something that is not a cursor
			$qb->andWhere($expr->eq('f.id_prim', $qb->createNamedParameter('')));

			return;
		}

		[$time, $prim] = explode('-', $cursor, 2);
		$creation = $qb->createNamedParameter(new DateTime('@' . $time), IQueryBuilder::PARAM_DATE);
		$qb->andWhere($expr->lte('f.creation', $creation));
		$qb->andWhere($expr->orX(
			$expr->lt('f.creation', $creation),
			$expr->lt('f.id_prim', $qb->createNamedParameter($prim))
		));
	}

	/**
	 * The accounts that follow `$targetId` and are followed by `$viewerId`,
	 * Mastodon's "familiar followers", as one self-join, so neither list,
	 * possibly tens of thousands of rows, leaves the database.
	 *
	 * @return string[] actor ids
	 */
	public function getFamiliarFollowers(string $viewerId, string $targetId, int $limit): array {
		$qb = $this->getFollowsSelectSql();
		$qb->limitToType(Follow::TYPE);
		$this->limitToPrim($qb, 'object_id_prim', $targetId);
		// the builder's own, not `CoreRequestBuilder::limitToAccepted()`: that
		// one takes the builder by reference as an `IQueryBuilder`, which
		// re-types `$qb` for the rest of the method and loses `prim()` below
		$qb->limitToAccepted(true);

		// the same table again: a row saying the viewer follows whoever follows
		// the target
		$qb->innerJoin(
			'f', self::TABLE_FOLLOWS, 'mine',
			$qb->expr()->andX(
				$qb->expr()->eq('mine.object_id_prim', 'f.actor_id_prim'),
				$qb->expr()->eq('mine.actor_id_prim', $qb->createNamedParameter($qb->prim($viewerId))),
				$qb->expr()->eq('mine.accepted', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
			)
		);
		// the viewer's own loopback follow joins like any other row, and the
		// reader is never one of their own familiar followers
		$qb->andWhere($qb->expr()->neq('f.actor_id_prim', $qb->createNamedParameter($qb->prim($viewerId))));
		$qb->setMaxResults(max(1, $limit));

		$ids = [];
		foreach ($this->getFollowsFromRequest($qb) as $follow) {
			$ids[] = $follow->getActorId();
		}

		return $ids;
	}

	/**
	 * Where a post has to be delivered for the followers of an actor to see
	 * it: one row per distinct inbox, the shared one where there is one,
	 * resolved and grouped in the database, so a hundred thousand followers on
	 * a few hundred servers are a few hundred rows.
	 *
	 * @return string[] the shared inbox where there is one, the personal inbox otherwise
	 */
	public function getFollowerInboxes(string $actorId): array {
		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();

		$inbox = $qb->createFunction(
			'COALESCE(NULLIF(' . $qb->getColumnName('shared_inbox', 'ca') . ", ''), "
			. $qb->getColumnName('inbox', 'ca') . ')'
		);
		$qb->selectAlias($inbox, 'delivery_inbox')
			->from(self::TABLE_FOLLOWS, 'f')
			->innerJoin('f', self::TABLE_CACHE_ACTORS, 'ca', $expr->eq('ca.id_prim', 'f.actor_id_prim'))
			->where($expr->eq('f.object_id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($expr->eq('f.type', $qb->createNamedParameter(Follow::TYPE)))
			->andWhere($expr->eq('f.accepted', $qb->createNamedParameter('1')))
			->groupBy($inbox);

		$inboxes = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$inbox = (string)($data['delivery_inbox'] ?? '');
			if ($inbox !== '') {
				$inboxes[$inbox] = $inbox;
			}
		}
		$cursor->closeCursor();

		return array_values($inboxes);
	}

	/**
	 * The follows towards this actor that still wait for approval, newest
	 * first, paged on `(creation, id_prim)` (see pendingCursor()): `creation`
	 * is stored to the second, and the cursor carries the values rather than
	 * a row id, as that row may be rejected and deleted by the next page.
	 * `$maxId` asks for older rows, `$minId` for newer ones (read oldest first
	 * and turned round); a cursor that is not ours pages nothing.
	 *
	 * @param int $limit 0 for every pending request
	 *
	 * @return Follow[]
	 */
	public function getPendingByObjectId(
		string $actorId,
		int $limit = 0,
		string $maxId = '',
		string $minId = '',
	): array {
		$qb = $this->getFollowsSelectSql();
		$this->limitToPrim($qb, 'object_id_prim', $actorId);
		$qb->limitToAccepted(false);

		$newer = ($minId !== '');
		foreach ([[$maxId, false], [$minId, true]] as [$cursor, $after]) {
			if ($cursor === '') {
				continue;
			}

			$position = self::parsePendingCursor($cursor);
			if ($position === null) {
				return [];
			}

			$qb->andWhere($this->beyondPending($qb, $position[0], $position[1], $after));
		}

		if ($limit > 0) {
			$qb->setMaxResults($limit);
		}
		$this->leftJoinCacheActors($qb, 'actor_id');
		$this->leftJoinDetails($qb, 'id', 'ca');
		$qb->orderBy('f.creation', $newer ? 'asc' : 'desc');
		$qb->addOrderBy('f.id_prim', $newer ? 'asc' : 'desc');

		$follows = $this->getFollowsFromRequest($qb);

		return $newer ? array_reverse($follows) : $follows;
	}

	/**
	 * Where a pending follow sits in getPendingByObjectId()'s order, as the
	 * cursor a page of it is continued from.
	 */
	public static function pendingCursor(Follow $follow): string {
		return $follow->getCreation() . '-' . $follow->getIdPrim();
	}

	/**
	 * @return array{0: int, 1: string}|null the timestamp and the key, or null
	 *                                       for anything pendingCursor() does
	 *                                       not write
	 */
	private static function parsePendingCursor(string $cursor): ?array {
		if (preg_match('/^(\d{1,12})-([0-9a-f]{32})$/', $cursor, $match) !== 1) {
			return null;
		}

		return [(int)$match[1], $match[2]];
	}

	/**
	 * The rows ordered after (or before) a cursor's `(creation, id_prim)`,
	 * spelled out rather than as a row constructor, which SQLite does not
	 * index.
	 */
	private function beyondPending(
		SocialQueryBuilder $qb,
		int $creation,
		string $idPrim,
		bool $after,
	): ICompositeExpression|string {
		$expr = $qb->expr();
		$time = $qb->createNamedParameter($this->dateTime($creation), IQueryBuilder::PARAM_DATE);
		$key = $qb->createNamedParameter($idPrim);

		return $expr->orX(
			$after ? $expr->gt('f.creation', $time) : $expr->lt('f.creation', $time),
			$expr->andX(
				$expr->eq('f.creation', $time),
				$after ? $expr->gt('f.id_prim', $key) : $expr->lt('f.id_prim', $key)
			)
		);
	}

	/**
	 * The recipient rows a home timeline is read from, as prims: a recipient
	 * row names the author's followers collection, so this is the set
	 * `social_stream_dest.actor_id` is matched against.
	 *
	 * Every accepted row counts, whatever its type: the Loopback self-follow of
	 * a local actor names the account's own prim, which is how a post
	 * addressed to the reader by name (a mention, a reply from somebody they do
	 * not follow) reaches their home timeline. Queries should use
	 * limitToHomeCollections(), which bounds the list.
	 *
	 * @param int $limit 0 for every collection
	 *
	 * @return string[] md5 hashes, deduplicated
	 */
	public function getHomeCollectionPrims(string $actorId, int $limit = 0): array {
		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();

		$qb->selectDistinct('follow_id_prim')
			->from(self::TABLE_FOLLOWS)
			->where($expr->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($expr->eq('accepted', $qb->createNamedParameter('1')))
			->andWhere($expr->neq('follow_id_prim', $qb->createNamedParameter('')));

		if ($limit > 0) {
			$qb->setMaxResults($limit);
		}

		$prims = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$prim = (string)($data['follow_id_prim'] ?? '');
			if ($prim !== '') {
				$prims[] = $prim;
			}
		}
		$cursor->closeCursor();

		return $prims;
	}

	public function followsCollection(string $actorId, string $collectionPrim): bool {
		$qb = $this->getQueryBuilder();
		$qb->select('f.id_prim')
			->from(self::TABLE_FOLLOWS, 'f')
			->where($qb->expr()->eq('f.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($qb->expr()->eq('f.follow_id_prim', $qb->createNamedParameter($collectionPrim)))
			->andWhere($qb->expr()->eq('f.accepted', $qb->createNamedParameter('1')))
			->andWhere($qb->expr()->eq('f.type', $qb->createNamedParameter(Follow::TYPE)))
			->setMaxResults(1);

		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return $row !== false;
	}

	/**
	 * How many followed collections a query names one by one: each is a bound
	 * parameter, SQLite may refuse more than 999, and MySQL and PostgreSQL plan
	 * long lists worse. Past this the database finds them itself.
	 */
	public const HOME_COLLECTIONS_IN_A_QUERY = 500;

	/**
	 * The predicate matching the collections one account's home timeline is
	 * read from, for `social_stream_dest.actor_id` or any column holding a
	 * collection prim: up to the cap an `IN (…)`, a range scan over an index;
	 * past it a correlated `EXISTS` over `social_follow`.
	 *
	 * @param string $field the column to match, qualified with its alias
	 * @param string[] $also collections to match besides the followed ones —
	 *                       the account's own, which it does not follow
	 *
	 * @return string an expression for andWhere(), or '' when there is nothing
	 *                to match and the caller has no timeline to build
	 */
	public function limitToHomeCollections(
		SocialQueryBuilder $qb, string $field, string $actorId, array $also = [],
	): string {
		$also = array_values(array_unique(array_filter($also)));
		$prims = $this->getHomeCollectionPrims($actorId, self::HOME_COLLECTIONS_IN_A_QUERY + 1);

		if (count($prims) <= self::HOME_COLLECTIONS_IN_A_QUERY) {
			$all = array_values(array_unique(array_merge($prims, $also)));
			if ($all === []) {
				return '';
			}

			return $qb->expr()->in(
				$field, $qb->createNamedParameter($all, IQueryBuilder::PARAM_STR_ARRAY)
			);
		}

		// the parameters are created on the outer builder: this query is
		// embedded in it and its placeholders are bound there
		$follows = $this->getQueryBuilder();
		$follows->select($follows->createFunction('1'))
			->from(self::TABLE_FOLLOWS, 'hf')
			->where('hf.follow_id_prim = ' . $field)
			->andWhere('hf.actor_id_prim = ' . (string)$qb->createNamedParameter($qb->prim($actorId)))
			->andWhere('hf.accepted = ' . (string)$qb->createNamedParameter('1'))
			->andWhere('hf.follow_id_prim <> ' . (string)$qb->createNamedParameter(''));

		$clause = 'EXISTS (' . $follows->getSQL() . ')';
		if ($also === []) {
			return $clause;
		}

		return '(' . $clause . ' OR ' . $qb->expr()->in(
			$field, $qb->createNamedParameter($also, IQueryBuilder::PARAM_STR_ARRAY)
		) . ')';
	}

	public function getFollowingByActorId(string $actorId, int $limit = 0, int $offset = 0, string $before = ''): array {
		$qb = $this->getFollowsSelectSql();
		$this->limitToPrim($qb, 'actor_id_prim', $actorId);
		$qb->limitToAccepted(true);
		// not the Loopback row; see getFollowersByActorId()
		$qb->limitToType(Follow::TYPE);
		$this->limitBeforeCursor($qb, $before);
		if ($limit > 0) {
			$qb->setMaxResults($limit);
			$qb->setFirstResult($offset);
		}
		$this->leftJoinCacheActors($qb, 'object_id');
		$this->leftJoinDetails($qb, 'id', 'ca');
		$qb->orderBy('f.creation', 'desc');
		// a second-resolution date is not a page order on its own; see
		// getFollowersByActorId()
		$qb->addOrderBy('f.id_prim', 'desc');

		return $this->getFollowsFromRequest($qb);
	}

	/**
	 * @param string $followId
	 *
	 * @return Follow[]
	 */
	public function getFollowersByFollowId(string $followId, int $limit = 0): array {
		$qb = $this->getFollowsSelectSql();
		$this->limitToPrim($qb, 'follow_id_prim', $followId);
		$qb->limitToAccepted(true);
		if ($limit > 0) {
			$qb->setMaxResults($limit);
		}
		$this->leftJoinAccounts($qb, 'actor_id');

		return $this->getFollowsFromRequest($qb);
	}

	/**
	 * @param Follow $follow
	 */
	public function delete(Follow $follow) {
		$qb = $this->getFollowsDeleteSql();
		$this->limitToIdPrimString($qb, $follow->getId());

		$qb->executeStatement();
	}

	/**
	 * @param Follow $follow
	 */
	public function deleteByPersons(Follow $follow) {
		$qb = $this->getFollowsDeleteSql();
		$this->limitToPrim($qb, 'actor_id_prim', $follow->getActorId());
		$this->limitToPrim($qb, 'object_id_prim', $follow->getObjectId());

		$qb->executeStatement();
	}

	/**
	 * @param string $actorId
	 */
	/**
	 * The accounts of one instance a follow row still names, in either
	 * direction: a purge removes both, or a blocked instance stays in the
	 * delivery fan-out and the follower counts.
	 *
	 * @return string[] actor ids
	 *
	 * @throws InvalidResourceException the domain is not one
	 */
	public function getActorIdsFromDomain(string $domain, int $limit = 100): array {
		$ids = [];
		foreach (['actor_id', 'object_id'] as $column) {
			$qb = $this->getQueryBuilder();
			$qb->selectDistinct('f.' . $column)
				->from(self::TABLE_FOLLOWS, 'f')
				->where(DomainBlocksRequestBuilder::onDomain($qb, 'f.' . $column, $domain))
				->setMaxResults($limit);

			$cursor = $qb->executeQuery();
			while ($data = $cursor->fetch()) {
				$ids[(string)$data[$column]] = true;
			}
			$cursor->closeCursor();
		}

		return array_slice(array_keys($ids), 0, $limit);
	}

	public function deleteRelatedId(string $actorId) {
		$qb = $this->getFollowsDeleteSql();
		$orX = $qb->expr()->orX(
			$qb->exprLimitToDBField('actor_id_prim', $qb->prim($actorId)),
			$qb->exprLimitToDBField('object_id_prim', $qb->prim($actorId))
		);
		$qb->where($orX);
		$qb->executeStatement();
	}

	/**
	 * @param string $id
	 */
	public function deleteById(string $id) {
		$qb = $this->getFollowsDeleteSql();
		$this->limitToIdPrimString($qb, $id);

		$qb->executeStatement();
	}

	/**
	 * Repoints the follows of an account that moved at the account it moved
	 * to, after clearing the rows that would collide (see clearMoveConflicts()).
	 *
	 * A follower that already follows the new account keeps that row, and it
	 * counts as accepted when either of the two was: the follower had been
	 * let in by the old account, which is what this move carries over.
	 */
	public function moveAccountFollowers(string $actorId, Person $new): void {
		$this->clearMoveConflicts('object_id_prim', 'actor_id_prim', $actorId, $new->getId(), true);

		$qb = $this->getFollowsUpdateSql();
		$qb->set('object_id', $qb->createNamedParameter($new->getId()))
			->set('object_id_prim', $qb->createNamedParameter($qb->prim($new->getId())))
			->set('follow_id', $qb->createNamedParameter($new->getFollowers()))
			->set('follow_id_prim', $qb->createNamedParameter($qb->prim($new->getFollowers())));

		$this->limitToPrim($qb, 'object_id_prim', $actorId);

		$qb->executeStatement();
	}

	/**
	 * Repoints the follows an account that moved had made at the account it
	 * moved to, after clearing the rows that would collide (see
	 * clearMoveConflicts()).
	 *
	 * A follow the new account already has is kept as it is, pending
	 * included: its server is waiting for the Accept only the followed
	 * account here can send, and marking it accepted would leave that server
	 * waiting for good.
	 */
	public function moveAccountFollowing(string $actorId, Person $new): void {
		$this->clearMoveConflicts('actor_id_prim', 'object_id_prim', $actorId, $new->getId(), false);

		$qb = $this->getFollowsUpdateSql();
		$qb->set('actor_id', $qb->createNamedParameter($new->getId()))
			->set('actor_id_prim', $qb->createNamedParameter($qb->prim($new->getId())));

		$this->limitToPrim($qb, 'actor_id_prim', $actorId);

		$qb->executeStatement();
	}

	/** Rows per statement of clearMoveConflicts(): one bound parameter each. */
	private const MOVE_CHUNK = 500;

	/**
	 * Drops the old account's rows that repointing one end of its follows
	 * would turn into a duplicate of the unique (object, actor) index
	 * `social_f_oa_u`, which would refuse the whole Move; usual, as a
	 * migration starts by importing the old follow list into the new account.
	 * Dropped are a follow between the two accounts and every row whose other
	 * end already has one with the new account, which stays as the one the
	 * other server knows. No statement here can be refused, so the move can
	 * run inside a transaction on PostgreSQL.
	 *
	 * @param string $end the column naming the account that moved
	 * @param string $other the column naming the account at the other end
	 * @param bool $acceptedWins whether a kept pending row becomes accepted
	 *                           when the dropped one was
	 */
	private function clearMoveConflicts(
		string $end, string $other, string $oldId, string $newId, bool $acceptedWins,
	): void {
		$old = $this->rowsAtEnd($end, $other, $oldId);
		if ($old === []) {
			return;
		}

		$drop = [];
		$accept = [];
		$newPrim = $this->getQueryBuilder()->prim($newId);
		if (array_key_exists($newPrim, $old)) {
			$drop[] = $old[$newPrim]['id_prim'];
			unset($old[$newPrim]);
		}

		foreach (array_chunk(array_keys($old), self::MOVE_CHUNK) as $chunk) {
			foreach ($this->rowsAtEnd($end, $other, $newId, $chunk) as $prim => $kept) {
				$drop[] = $old[$prim]['id_prim'];
				if ($acceptedWins && $old[$prim]['accepted'] && !$kept['accepted']) {
					$accept[] = $kept['id_prim'];
				}
			}
		}

		foreach (array_chunk($accept, self::MOVE_CHUNK) as $chunk) {
			$qb = $this->getFollowsUpdateSql();
			$qb->set('accepted', $qb->createNamedParameter('1'))
				->where($qb->expr()->in('id_prim', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}

		foreach (array_chunk($drop, self::MOVE_CHUNK) as $chunk) {
			$qb = $this->getFollowsDeleteSql();
			$qb->where($qb->expr()->in('id_prim', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
	}

	/**
	 * The follows with `$actorId` at one end, keyed by the prim of the
	 * account at the other.
	 *
	 * @param string[]|null $others prims of the accounts at the other end to
	 *                              limit to; null for all of them
	 *
	 * @return array<string, array{id_prim: string, accepted: bool}>
	 */
	private function rowsAtEnd(string $end, string $other, string $actorId, ?array $others = null): array {
		$qb = $this->getQueryBuilder();
		$qb->select('id_prim', 'accepted', $other)
			->from(self::TABLE_FOLLOWS)
			->where($qb->expr()->eq($end, $qb->createNamedParameter($qb->prim($actorId))));
		if ($others !== null) {
			$qb->andWhere($qb->expr()->in($other, $qb->createNamedParameter($others, IQueryBuilder::PARAM_STR_ARRAY)));
		}

		$rows = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$rows[(string)$data[$other]] = [
				'id_prim' => (string)$data['id_prim'],
				'accepted' => $this->getBool('accepted', $data),
			];
		}
		$cursor->closeCursor();

		return $rows;
	}

	/**
	 * Returns everything related to a list of actorIds.
	 * Looking at actor_id_prim and object_id_prim.
	 *
	 * @param array $actorIds
	 *
	 * @return Follow[]
	 */
	public function getFollows(array $actorIds): array {
		$qb = $this->getFollowsSelectSql();
		$qb->limitToType(Follow::TYPE);

		$prims = [];
		foreach ($actorIds as $actorId) {
			$prims[] = $qb->prim($actorId);
		}

		$qb->andWhere(
			$qb->expr()->orX(
				$qb->exprLimitInArray('actor_id_prim', $prims),
				$qb->exprLimitInArray('object_id_prim', $prims)
			)
		);

		return $this->getFollowsFromRequest($qb);
	}
}
