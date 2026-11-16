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
	 * Inserts a follow, unless a row with its id or its (actor, object) pair
	 * is already there.
	 *
	 * The inbox looks a follow up before it writes it, and a redelivery that
	 * overlaps the first copy passes that lookup too; the database skips the
	 * second insert rather than failing it — a failed statement would abort
	 * the surrounding transaction on PostgreSQL.
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
	 * Where an account's followers are, and when each of them arrived.
	 *
	 * Two columns rather than a hydrated `Follow` with its cached actor and
	 * that actor's details: the statistics page wants a host and a date per
	 * follower, and building the other two hundred bytes of each one only to
	 * throw them away is the whole cost of the query.
	 *
	 * The host is parsed out of `actor_id` in PHP rather than in SQL because
	 * the three databases this app supports have three different ways of
	 * cutting a string up, and none of them knows what a URL is.
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
	 * How many follows this actor has sent since a moment.
	 *
	 * Counted from the rows rather than from a cache counter: this is the
	 * number a limit is enforced against, and an instance with no memcache —
	 * which is most of them — would otherwise have no limit at all. Pending
	 * and accepted alike, because what is being limited is the sending.
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
	 * The accepted followers of an actor, newest first.
	 *
	 * Each row is hydrated into a Follow with a Person and its details, so
	 * $limit/$offset are what keep this bounded — it serves the paged
	 * `followers` collection and the re-follow after a Move, both of which want
	 * the follows themselves. Federated delivery used to fan out over it and
	 * loaded a popular actor's whole follower list into memory for every post;
	 * it asks getFollowerInboxes() for the distinct inboxes instead.
	 *
	 * @return Follow[]
	 */
	public function getFollowersByActorId(string $actorId, int $limit = 0, int $offset = 0, string $before = ''): array {
		$qb = $this->getFollowsSelectSql();
		$this->limitToPrim($qb, 'object_id_prim', $actorId);
		$qb->limitToAccepted(true);
		// the accepted Loopback row an actor has on itself is how its own
		// posts reach its home timeline, not a follower: listed, it made
		// every actor a follower of itself and the page one longer than
		// the collection's totalItems
		$qb->limitToType(Follow::TYPE);
		$this->limitBeforeCursor($qb, $before);
		$this->leftJoinCacheActors($qb, 'actor_id');
		$this->leftJoinDetails($qb, 'id', 'ca');
		$qb->orderBy('f.creation', 'desc');
		// `creation` is a DATETIME, so the follows made within one second of
		// each other have no order of their own: with an offset page, one of
		// them can come back on two consecutive pages while another is never
		// returned at all. The primary key breaks the tie.
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
	 * Limit a follower or following list to the rows after a cursor.
	 *
	 * A keyset rather than an offset: an offset page reads and discards every
	 * row before it, so page 2,500 of a large follower list read 100,000 rows
	 * to return forty, on every fetch a crawling peer made. The first
	 * predicate is the range `social_f_ocr` (object_id_prim, creation) serves;
	 * the second breaks the tie within one second the way the order does.
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
	 * The accounts that follow `$targetId` and are also followed by
	 * `$viewerId` — Mastodon's "familiar followers".
	 *
	 * One query with a self-join rather than two follower lists intersected in
	 * PHP: the viewer's following list and the target's follower list can each
	 * be tens of thousands of rows, and what is wanted is the overlap, which
	 * the database can find without either of them leaving it.
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
	 * it: one row per distinct inbox, resolved in the database.
	 *
	 * The fan-out only ever needed the inboxes, and there are as many of those
	 * as there are instances involved — not as many as there are followers.
	 * Each follower's delivery inbox — the shared one where there is one, the
	 * personal one otherwise — is worked out and grouped in the query, so a
	 * hundred thousand followers on a few hundred Mastodon servers are a few
	 * hundred rows. Grouped as distinct (shared, personal) pairs they were a
	 * row per follower, since every personal inbox is different. A follower
	 * without a shared inbox still brings one row each; they are told apart
	 * by key, which costs the same however many there are.
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
	 * first.
	 *
	 * Paged on the pair the list is ordered by, `(creation, id_prim)`, which is
	 * what pendingCursor() spells out for a row. `creation` alone is stored to
	 * the second, and a cursor on it would skip or repeat the requests that
	 * arrived within the same one; `id_prim` is the table's key and settles
	 * every tie. The cursor carries the date itself rather than naming a row
	 * to look it up from: the row a page ended on is as likely as not to have
	 * been answered — rejected, and so deleted — by the time the next page is
	 * asked for.
	 *
	 * `$maxId` asks for what is older than the cursor, `$minId` for what is
	 * newer; the second is read oldest first so that the rows next to the
	 * cursor are the ones the limit keeps, and turned round again. A cursor
	 * that is not one of ours pages nothing, rather than starting the list
	 * again for a client that would follow it round forever.
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
	 * The recipient rows a home timeline is read from, as prims.
	 *
	 * What the home timeline is actually built on: a recipient row names the
	 * author's **followers collection**, never the author, so this is the set
	 * the page query matches `social_stream_dest.actor_id` against. One
	 * projected column and no hydration — the page needs the hashes and
	 * nothing else, and an account following two thousand people would
	 * otherwise build two thousand `Follow` objects to read one string off
	 * each.
	 *
	 * Every accepted row of the account's counts, whatever its type, because
	 * that is exactly what the join this replaced matched:
	 * `f.actor_id_prim = viewer AND f.accepted = 1 AND f.follow_id_prim =
	 * sd.actor_id`, with no condition on the type. The row that makes the
	 * difference is the **Loopback**, the self-follow every local actor is
	 * given, whose `follow_id_prim` is the account's own prim: it is how a
	 * post addressed to the reader *by name* — a mention, a reply from someone
	 * they do not follow — reaches their home timeline at all. Narrowing this
	 * to `type = 'Follow'` dropped every one of them: the notification arrived,
	 * the post was readable at its own URL, and the timeline paged straight
	 * past it.
	 *
	 * A query that matches these as a list of bound parameters must not ask for
	 * the list unbounded — see limitToHomeCollections(), which is what a query
	 * should use.
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
	 * How many followed collections a query will name one by one.
	 *
	 * Each is one bound parameter, and every statement has a ceiling on those:
	 * a SQLite built with the historical default refuses a statement with more
	 * than 999, and MySQL and PostgreSQL accept a list of ten thousand but plan
	 * it worse the longer it gets. Below this the list is the faster query by
	 * far; above it the account is one of the few that follow more accounts
	 * than a page can name, and the database is asked to find them itself.
	 */
	public const HOME_COLLECTIONS_IN_A_QUERY = 500;

	/**
	 * The predicate matching the collections one account's home timeline is
	 * read from — for `social_stream_dest.actor_id`, or any other column
	 * holding a collection prim.
	 *
	 * Two shapes behind one call. Up to the cap, the collections are read and
	 * named in an `IN (…)`, which is what makes the home timeline a range scan
	 * over an index. Past it, the same set is left to an `EXISTS` over
	 * `social_follow`, correlated on the column: one bound parameter instead of
	 * ten thousand, at the cost of a lookup per candidate row.
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
	 * The accounts of one instance that a follow row still names, in either
	 * direction — the ones following somebody here and the ones followed from
	 * here.
	 *
	 * Both halves matter to a purge: leaving the follows of a blocked instance
	 * behind keeps it in the delivery fan-out of every local post, and keeps
	 * its accounts in the follower counts and lists shown here.
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
	 * would turn into a duplicate: `social_f_oa_u` makes (object, actor)
	 * unique, and an UPDATE onto a pair that exists is refused, and the whole
	 * Move with it. That is the usual case, not a corner: a migration starts
	 * by creating the new account and importing the old one's follow list
	 * into it.
	 *
	 * Dropped are a follow between the two accounts, which would become the
	 * new account following itself, and every row whose other end already
	 * has a row with the new account; that row stays, being the one the
	 * other server knows by its id. Every statement here is one the database
	 * cannot refuse, so the move can run inside a transaction on PostgreSQL.
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
