<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Exceptions\QueueStatusException;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Service\RequestQueueService;
use OCA\Social\Tools\DbTime;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Class RequestQueueRequest
 *
 * @package OCA\Social\Db
 */
class RequestQueueRequest extends RequestQueueRequestBuilder {
	/** How many standby requests a single cron pass hydrates. */
	public const STANDBY_BATCH = 200;

	/** How many rows one fan-out transaction writes before committing. */
	public const INSERT_CHUNK = 500;

	/**
	 * Writes the whole fan-out of one activity.
	 *
	 * One INSERT per inbox, each its own round trip and its own commit, was
	 * done in the web request that made the post: a local account followed
	 * from twenty thousand instances paid twenty thousand sequential
	 * round trips before `createPost()` returned. The statement is prepared
	 * once and the rows are committed in chunks instead.
	 *
	 * A row that carries a `last` is written with it, so a fan-out that is
	 * held back until then is never due for a moment between the insert and
	 * an update — a drain in that moment would deliver it.
	 *
	 * @param RequestQueue[] $queues
	 *
	 * @throws Exception
	 */
	public function multiple(array $queues): void {
		if ($queues === []) {
			return;
		}

		$qb = $this->getRequestQueueInsertSql();
		foreach ([
			'token', 'author', 'author_prim', 'activity', 'object_id_prim', 'instance',
			'priority', 'status', 'tries', 'last', 'creation',
		] as $field) {
			$qb->setValue($field, $qb->createParameter($field));
		}
		$qb->setParameter('creation', new DateTime('now'), IQueryBuilder::PARAM_DATE);

		foreach (array_chunk($queues, self::INSERT_CHUNK) as $chunk) {
			$this->dbConnection->beginTransaction();
			try {
				foreach ($chunk as $queue) {
					$this->bindQueue($qb, $queue);
					$qb->executeStatement();
				}
				$this->dbConnection->commit();
			} catch (\Throwable $e) {
				$this->dbConnection->rollBack();

				throw $e;
			}
		}
	}

	private function bindQueue(SocialQueryBuilder $qb, RequestQueue $queue): void {
		$qb->setParameter('token', $queue->getToken());
		$qb->setParameter('author', $queue->getAuthor());
		$qb->setParameter('author_prim', $qb->prim($queue->getAuthor()));
		$qb->setParameter('activity', $queue->getActivity());
		$qb->setParameter('object_id_prim', $queue->getObjectIdPrim());
		$qb->setParameter('instance', json_encode($queue->getInstance(), JSON_UNESCAPED_SLASHES));
		$qb->setParameter('priority', $queue->getPriority(), IQueryBuilder::PARAM_INT);
		$qb->setParameter('status', $queue->getStatus(), IQueryBuilder::PARAM_INT);
		$qb->setParameter('tries', $queue->getTries(), IQueryBuilder::PARAM_INT);
		if ($queue->getLast() > 0) {
			$qb->setParameter('last', new DateTime('@' . $queue->getLast()), IQueryBuilder::PARAM_DATE);
		} else {
			$qb->setParameter('last', null, IQueryBuilder::PARAM_NULL);
		}
	}

	/**
	 * Create a new Queue in the database.
	 *
	 * @throws Exception
	 */
	public function create(RequestQueue $queue): void {
		$qb = $this->getRequestQueueInsertSql();
		$qb->setValue('token', $qb->createNamedParameter($queue->getToken()))
			->setValue('author', $qb->createNamedParameter($queue->getAuthor()))
			->setValue('author_prim', $qb->createNamedParameter($qb->prim($queue->getAuthor())))
			->setValue('activity', $qb->createNamedParameter($queue->getActivity()))
			->setValue('object_id_prim', $qb->createNamedParameter($queue->getObjectIdPrim()))
			->setValue(
				'instance', $qb->createNamedParameter(
					json_encode($queue->getInstance(), JSON_UNESCAPED_SLASHES)
				)
			)
			->setValue('priority', $qb->createNamedParameter($queue->getPriority()))
			->setValue('status', $qb->createNamedParameter($queue->getStatus()))
			->setValue('tries', $qb->createNamedParameter($queue->getTries()))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));
		$qb->executeStatement();
	}

	/**
	 * Return Queue from database based on the status=0
	 *
	 * @return list<RequestQueue>
	 * @throws Exception
	 */
	public function getStandby(): array {
		$backoff = Backoff::outbound();
		// what the drain has given up on is marked first: the query below cannot
		// return those rows any more, and the author is entitled to see that a
		// server never got their post rather than watch the row vanish
		$this->abandonExhausted($backoff->maxTries(), time() - RequestQueueService::MAX_AGE);

		$qb = $this->getRequestQueueSelectSql();
		$qb->limitToStatus(RequestQueue::STATUS_STANDBY);
		// the retry backoff and the give-up threshold, in the query rather than
		// in PHP afterwards: one dead instance otherwise fills the whole window
		// with rows that are not due, and starves every other delivery
		$this->limitToQueueDue($qb, $backoff);
		// what is most urgent first, then what has never been attempted, then
		// whatever has waited longest since its last attempt or hold. 'id asc'
		// alone handed the window to whatever was queued earliest regardless
		// of priority. A row the breaker held back keeps `tries = 0`, so the
		// try count is no measure of how long a row has waited: ordered by it,
		// the held rows of dead instances sorted ahead of every retry to a
		// healthy one. NULL sorts first on MySQL and SQLite and last on
		// PostgreSQL, so the never-attempted rows are told apart explicitly.
		$last = $qb->getColumnName('last', 'rq');
		$qb->orderBy('rq.priority', 'desc');
		$qb->addOrderBy($qb->createFunction('CASE WHEN ' . $last . ' IS NULL THEN 0 ELSE 1 END'), 'asc');
		$qb->addOrderBy('rq.last', 'asc');
		$qb->addOrderBy('rq.id', 'asc');
		$qb->setMaxResults(self::STANDBY_BATCH);

		$requests = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$requests[] = $this->parseRequestQueueSelectSql($data);
		}
		$cursor->closeCursor();

		return $requests;
	}

	/**
	 * How many requests sit in the queue in each state.
	 *
	 * @return array<int, int> status => count
	 * @throws Exception
	 */
	/**
	 * When the longest-failing delivery was last attempted, or 0 where nothing
	 * is failing.
	 *
	 * The one figure the federation card could not answer: how long this
	 * instance has been stuck. The counts say how much is failing and the
	 * per-instance list says where, but neither says whether it started an
	 * hour ago or a week ago — which is the difference between a peer
	 * rebooting and a delivery that is never going to happen.
	 *
	 * Attempted rather than queued: `social_request_queue` records the last
	 * try and not the creation, and the last try is the more useful of the two
	 * anyway — a row whose last attempt was six days ago has been retried all
	 * week.
	 */
	public function oldestFailingAttempt(): int {
		// `last` is a datetime column and not a timestamp: a row that was
		// never attempted has it NULL rather than zero, and comparing it
		// against 0 asks PostgreSQL to read "0" as a date.
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->func()->min('last'), 'oldest')
			->from(self::TABLE_REQUEST_QUEUE)
			->where($qb->expr()->eq('status', $qb->createNamedParameter(RequestQueue::STATUS_STANDBY, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('tries', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('last'));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		// what comes back is whatever shape the database writes a datetime
		// in, so it is parsed the same way a hydrated row's is
		return DbTime::toTimestamp($data['oldest'] ?? null);
	}

	public function countByStatus(): array {
		$qb = $this->getQueryBuilder();
		$qb->select('status')
			->selectAlias($qb->func()->count('*'), 'total')
			->from(self::TABLE_REQUEST_QUEUE)
			->groupBy('status');

		$counts = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$counts[(int)$data['status']] = (int)$data['total'];
		}
		$cursor->closeCursor();

		return $counts;
	}

	/**
	 * How many standby requests were last attempted before `$before` and are
	 * still waiting.
	 *
	 * The longest wait in the retry schedule is fourteen hours, so a row that
	 * has sat on standby for a day past its last attempt is one no drain has
	 * come back for: the cron is not running, or something ahead of it in the
	 * batch never lets it through. A row that was never attempted has no
	 * `last` and is not counted — its age is unknown.
	 *
	 * @throws Exception
	 */
	public function countStandbyOlderThan(int $before): int {
		$qb = $this->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))
			->from(self::TABLE_REQUEST_QUEUE)
			->where($qb->expr()->eq('status', $qb->createNamedParameter(RequestQueue::STATUS_STANDBY, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('last'))
			->andWhere($qb->expr()->lt('last', $qb->createNamedParameter(
				new DateTime('@' . $before), IQueryBuilder::PARAM_DATE
			)));

		$cursor = $qb->executeQuery();
		$total = (int)$cursor->fetchOne();
		$cursor->closeCursor();

		return $total;
	}

	/**
	 * The requests that have already failed at least `$minTries` times, worst
	 * first — the ones on their way to being abandoned.
	 *
	 * @return list<RequestQueue>
	 * @throws Exception
	 */
	public function getFailing(int $minTries = 1, int $limit = 500): array {
		$qb = $this->getRequestQueueSelectSql();
		// still in play: a row kept as delivered or abandoned has tries too,
		// and is not a delivery anybody is worried about
		$qb->limitToStatus(RequestQueue::STATUS_STANDBY);
		$qb->andWhere($qb->expr()->gte('tries', $qb->createNamedParameter($minTries, IQueryBuilder::PARAM_INT)));
		$qb->orderBy('tries', 'desc');
		$qb->setMaxResults($limit);

		$requests = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$requests[] = $this->parseRequestQueueSelectSql($data);
		}
		$cursor->closeCursor();

		return $requests;
	}

	/**
	 * The deliveries this instance has given up on, the most recently
	 * abandoned first.
	 *
	 * They are the ones nobody saw: a failing request is at least counted
	 * while it is still being retried, and then it changes status and drops
	 * out of every summary there is. The rows live `RETENTION_SECONDS` (seven
	 * days) past their last attempt, which is exactly the window in which an
	 * administrator can still be told that a server stopped receiving
	 * anything from here.
	 *
	 * @return list<RequestQueue>
	 * @throws Exception
	 */
	public function getAbandoned(int $limit = 500): array {
		$qb = $this->getRequestQueueSelectSql();
		$qb->limitToStatus(RequestQueue::STATUS_ABANDONED);
		$qb->orderBy('last', 'desc');
		$qb->setMaxResults($limit);

		$requests = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$requests[] = $this->parseRequestQueueSelectSql($data);
		}
		$cursor->closeCursor();

		return $requests;
	}

	/**
	 * Return Queue from database based on the token
	 *
	 * @param int $limit how many rows at most, 0 for all of them: a post's
	 *                   fan-out is a row per inbox, each carrying the signed
	 *                   activity
	 *
	 * @return list<RequestQueue>
	 * @throws Exception
	 */
	public function getFromToken(string $token, int $status = -1, int $limit = 0): array {
		$qb = $this->getRequestQueueSelectSql();
		$qb->limitToToken($token);

		if ($status > -1) {
			$qb->limitToStatus($status);
		}

		$qb->orderBy('priority', 'desc');
		if ($limit > 0) {
			$qb->addOrderBy('id', 'asc');
			$qb->setMaxResults($limit);
		}

		$requests = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$requests[] = $this->parseRequestQueueSelectSql($data);
		}
		$cursor->closeCursor();

		return $requests;
	}

	/** Whether anything under a token is in that state, without reading the rows. */
	public function hasToken(string $token, int $status): bool {
		$qb = $this->getQueryBuilder();
		$qb->select('id')
			->from(self::TABLE_REQUEST_QUEUE)
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$cursor = $qb->executeQuery();
		$found = $cursor->fetchOne();
		$cursor->closeCursor();

		return $found !== false;
	}

	/**
	 * Up to `$limit` standby rows under a token that are due at `$now`, most
	 * urgent first.
	 *
	 * A row whose `last` is still ahead is held back on purpose and left
	 * out; so is every row already tried, when `$neverTried` asks for that.
	 *
	 * @return list<RequestQueue>
	 * @throws Exception
	 */
	public function getDueFromToken(string $token, int $now, bool $neverTried, int $limit): array {
		$qb = $this->getRequestQueueSelectSql();
		$expr = $qb->expr();
		$qb->limitToToken($token);
		$qb->limitToStatus(RequestQueue::STATUS_STANDBY);
		$qb->andWhere($expr->orX(
			$expr->isNull('rq.last'),
			$expr->lte('rq.last', $qb->createNamedParameter(new DateTime('@' . $now), IQueryBuilder::PARAM_DATE))
		));
		if ($neverTried) {
			$qb->andWhere($expr->eq('rq.tries', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		}
		$qb->orderBy('rq.priority', 'desc');
		$qb->addOrderBy('rq.id', 'asc');
		$qb->setMaxResults($limit);

		$requests = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$requests[] = $this->parseRequestQueueSelectSql($data);
		}
		$cursor->closeCursor();

		return $requests;
	}

	/**
	 * @throws QueueStatusException|Exception
	 */
	public function setAsRunning(RequestQueue &$queue): void {
		$qb = $this->getRequestQueueUpdateSql();
		$qb->set('status', $qb->createNamedParameter(RequestQueue::STATUS_RUNNING))
			->set(
				'last',
				$qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
			);
		$qb->limitToId($queue->getId());
		$qb->limitToStatus(RequestQueue::STATUS_STANDBY);

		$count = $qb->executeStatement();

		if ($count === 0) {
			throw new QueueStatusException();
		}

		$queue->setStatus(RequestQueue::STATUS_RUNNING);
	}

	/**
	 * Marks a delivered row, and drops the body it carried.
	 *
	 * The row is kept for `RequestQueueService::RETENTION_SECONDS` to say that
	 * this server got the post; the signed activity itself is never read
	 * again — nothing re-sends a delivered row, `social:queue:retry` included
	 * — and kept per inbox it was the bulk of the table: one copy of the whole
	 * document per recipient server, for a week. An abandoned row keeps its
	 * body, because handing it back to the queue is what `social:queue:retry`
	 * is for.
	 *
	 * @throws QueueStatusException|Exception
	 */
	public function setAsSuccess(RequestQueue &$queue): void {
		$qb = $this->getRequestQueueUpdateSql();
		$qb->set('status', $qb->createNamedParameter(RequestQueue::STATUS_SUCCESS));
		$qb->set('activity', $qb->createNamedParameter(''));
		$qb->limitToId($queue->getId());
		$qb->limitToStatus(RequestQueue::STATUS_RUNNING);

		$count = $qb->executeStatement();

		if ($count === 0) {
			throw new QueueStatusException();
		}

		$queue->setStatus(RequestQueue::STATUS_SUCCESS);
	}

	/**
	 * @throws QueueStatusException|Exception
	 */
	public function setAsFailure(RequestQueue &$queue): void {
		$qb = $this->getRequestQueueUpdateSql();
		$func = $qb->func();
		$expr = $qb->expr();

		$qb->set('status', $qb->createNamedParameter(RequestQueue::STATUS_STANDBY))
			->set('tries', $func->add('tries', $expr->literal(1)));
		$qb->limitToId($queue->getId());
		$qb->limitToStatus(RequestQueue::STATUS_RUNNING);

		$count = $qb->executeStatement();

		if ($count === 0) {
			throw new QueueStatusException();
		}

		$queue->setStatus(RequestQueue::STATUS_STANDBY);
	}

	/**
	 * Moves a standby request out of the due window until `$until`.
	 *
	 * `last` is what the retry schedule is measured from, so a timestamp in
	 * the future both holds the row back (`limitToQueueDue()`) and sorts it
	 * behind everything that is due (`getStandby()`), without spending one of
	 * its tries on an attempt that was never made.
	 *
	 * @throws QueueStatusException when the row was not on standby any more
	 * @throws Exception
	 */
	public function postpone(RequestQueue &$queue, int $until): void {
		$qb = $this->getRequestQueueUpdateSql();
		$qb->set('last', $qb->createNamedParameter(
			new DateTime('@' . $until), IQueryBuilder::PARAM_DATE
		));
		$qb->limitToId($queue->getId());
		$qb->limitToStatus(RequestQueue::STATUS_STANDBY);

		if ($qb->executeStatement() === 0) {
			throw new QueueStatusException();
		}

		$queue->setLast($until);
	}

	/**
	 * Hands a held request its final body and makes it due at once.
	 *
	 * `last` goes back to NULL, which is what a row that has never been
	 * attempted has, so the next drain delivers it as it would a fresh one.
	 *
	 * @throws QueueStatusException when the row was not on standby any more
	 * @throws Exception
	 */
	public function release(RequestQueue &$queue, string $activity): void {
		$qb = $this->getRequestQueueUpdateSql();
		$qb->set('activity', $qb->createNamedParameter($activity));
		$qb->set('last', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL));
		$qb->limitToId($queue->getId());
		$qb->limitToStatus(RequestQueue::STATUS_STANDBY);

		if ($qb->executeStatement() === 0) {
			throw new QueueStatusException();
		}

		$queue->setActivity($activity);
		$queue->setLast(0);
	}

	/**
	 * Return every request stuck `running` since before $before to standby.
	 *
	 * @return int the number of requests re-queued
	 * @throws Exception
	 */
	public function resetStaleRunning(int $before): int {
		$qb = $this->getRequestQueueUpdateSql();
		$qb->set('status', $qb->createNamedParameter(RequestQueue::STATUS_STANDBY));
		$qb->limitToStatus(RequestQueue::STATUS_RUNNING);
		$qb->andWhere(
			$qb->expr()->lt('last', $qb->createNamedParameter(
				new DateTime('@' . $before), IQueryBuilder::PARAM_DATE
			))
		);

		return $qb->executeStatement();
	}

	public function delete(RequestQueue $queue): void {
		$qb = $this->getRequestQueueDeleteSql();
		$qb->limitToId($queue->getId());

		$qb->executeStatement();
	}

	/**
	 * Drops the requests that have exhausted their retries. getStandby() no
	 * longer returns them — the threshold is part of the query — so this is
	 * what keeps the table from holding them forever.
	 *
	 * @return int rows removed
	 * @throws Exception
	 */
	/**
	 * Gives up on one standby row: kept as abandoned until `deleteFinished()`.
	 *
	 * @throws QueueStatusException when the row was not on standby any more
	 */
	public function setAsAbandoned(RequestQueue &$queue): void {
		$qb = $this->getRequestQueueUpdateSql();
		$qb->set('status', $qb->createNamedParameter(RequestQueue::STATUS_ABANDONED));
		$qb->limitToId($queue->getId());
		$qb->limitToStatus(RequestQueue::STATUS_STANDBY);

		if ($qb->executeStatement() === 0) {
			throw new QueueStatusException();
		}

		$queue->setStatus(RequestQueue::STATUS_ABANDONED);
	}

	/**
	 * Marks what the drain has given up on, rather than deleting it.
	 *
	 * That is a row that has spent its tries, and one queued before
	 * `$queuedBefore`: a row whose host the breaker keeps holding back is
	 * postponed without spending a try, so for an instance that has gone away
	 * the age is what ends it. Both read on an index (`social_rq_tries`,
	 * `social_rq_sc`); a row queued before its creation was recorded has none
	 * and is left to its tries.
	 *
	 * The row stays so that the post it belongs to can say a server never got
	 * it; `deleteFinished()` takes it away with the delivered ones once the
	 * retention has passed.
	 *
	 * @param int $queuedBefore timestamp, or 0 to give up on tries alone
	 *
	 * @return int rows marked
	 */
	public function abandonExhausted(int $maxTries = RequestQueueService::MAX_TRIES, int $queuedBefore = 0): int {
		$qb = $this->getRequestQueueUpdateSql();
		$qb->set('status', $qb->createNamedParameter(RequestQueue::STATUS_ABANDONED));
		$qb->limitToStatus(RequestQueue::STATUS_STANDBY);
		$qb->andWhere(
			$qb->expr()->gte('tries', $qb->createNamedParameter($maxTries, IQueryBuilder::PARAM_INT))
		);
		$abandoned = $qb->executeStatement();

		if ($queuedBefore > 0) {
			$qb = $this->getRequestQueueUpdateSql();
			$qb->set('status', $qb->createNamedParameter(RequestQueue::STATUS_ABANDONED));
			$qb->limitToStatus(RequestQueue::STATUS_STANDBY);
			$qb->andWhere($qb->expr()->isNotNull('creation'));
			$qb->andWhere($qb->expr()->lt('creation', $qb->createNamedParameter(
				new DateTime('@' . $queuedBefore), IQueryBuilder::PARAM_DATE
			)));
			$abandoned += $qb->executeStatement();
		}

		return $abandoned;
	}

	/**
	 * Whether an author still has deliveries waiting or going out.
	 *
	 * Read on `social_rq_as`: a deleted account keeps its row, and with it the
	 * key its `Delete` is signed with, until this says no.
	 */
	public function hasPendingByAuthor(string $actorId): bool {
		$qb = $this->getQueryBuilder();
		$qb->select('id')
			->from(self::TABLE_REQUEST_QUEUE)
			->where($qb->expr()->eq('author_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(
				[RequestQueue::STATUS_STANDBY, RequestQueue::STATUS_RUNNING], IQueryBuilder::PARAM_INT_ARRAY
			)))
			->setMaxResults(1);

		$cursor = $qb->executeQuery();
		$found = $cursor->fetchOne();
		$cursor->closeCursor();

		return $found !== false;
	}

	/**
	 * Removes delivered and abandoned rows whose last attempt is older than
	 * `$before`: what the author could have asked about has been kept long
	 * enough, and a queue is not an archive.
	 *
	 * @return int rows removed
	 */
	public function deleteFinished(int $before): int {
		$qb = $this->getRequestQueueDeleteSql();
		$expr = $qb->expr();
		$qb->andWhere($expr->in('status', $qb->createNamedParameter(
			[RequestQueue::STATUS_SUCCESS, RequestQueue::STATUS_ABANDONED], IQueryBuilder::PARAM_INT_ARRAY
		)));
		$qb->andWhere($expr->lt('last', $qb->createNamedParameter(
			new DateTime('@' . $before), IQueryBuilder::PARAM_DATE
		)));

		return $qb->executeStatement();
	}

	/**
	 * The requests about one object that are on standby, never attempted, and
	 * not due before some moment after `$now`.
	 *
	 * Narrowed in the query rather than after it: a post with a large fan-out
	 * has a row per inbox kept for a week after delivery, and this is asked
	 * on every delete.
	 *
	 * @return list<RequestQueue>
	 * @throws Exception
	 */
	public function getHeldByObject(string $objectIdPrim, int $now): array {
		if ($objectIdPrim === '') {
			return [];
		}

		$qb = $this->getRequestQueueSelectSql();
		$qb->limitToDBField('object_id_prim', $objectIdPrim);
		$qb->limitToStatus(RequestQueue::STATUS_STANDBY);
		$qb->andWhere($qb->expr()->eq('rq.tries', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		$qb->andWhere($qb->expr()->gt('rq.last', $qb->createNamedParameter(
			new DateTime('@' . $now), IQueryBuilder::PARAM_DATE
		)));
		$qb->orderBy('rq.id', 'asc');

		$requests = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$requests[] = $this->parseRequestQueueSelectSql($data);
		}
		$cursor->closeCursor();

		return $requests;
	}

	/**
	 * Every queued delivery of one object, as the author is entitled to see it.
	 *
	 * @return list<RequestQueue>
	 */
	public function getByObject(string $objectIdPrim): array {
		if ($objectIdPrim === '') {
			return [];
		}

		$qb = $this->getRequestQueueSelectSql();
		$qb->limitToDBField('object_id_prim', $objectIdPrim);
		$qb->orderBy('rq.id', 'asc');

		$requests = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$requests[] = $this->parseRequestQueueSelectSql($data);
		}
		$cursor->closeCursor();

		return $requests;
	}

	public function deleteByAuthor(string $actorId): void {
		$qb = $this->getRequestQueueDeleteSql();
		$qb->limitToDBField('author_prim', $qb->prim($actorId));

		$qb->executeStatement();
	}

	//	public function moveAccount(string $actorId, string $newId, string $instance): void {
	//		$qb = $this->getRequestQueueUpdateSql();
	//		$qb->set('author', $qb->createNamedParameter($newId))
	//		   ->set('author_prim', $qb->createNamedParameter($qb->prim($newId)))
	//		   ->set('instance', $qb->createNamedParameter($instance));
	//		$qb->limitToDBField('author_prim', $qb->prim($actorId));
	//
	//		$qb->executeStatement();
	//	}
}
