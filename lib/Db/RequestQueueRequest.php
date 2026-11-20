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
	 * Writes the whole fan-out of one activity with one prepared statement,
	 * committed in chunks, since it runs in the web request that made the post.
	 *
	 * A row with a `last` is inserted with it, so a held fan-out is never due
	 * between insert and update.
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
		// Mark what the drain gave up on first, so the author sees that a server
		// never got their post rather than the row vanishing.
		$this->abandonExhausted($backoff->maxTries(), time() - RequestQueueService::MAX_AGE);

		$qb = $this->getRequestQueueSelectSql();
		$qb->limitToStatus(RequestQueue::STATUS_STANDBY);
		// Backoff and give-up threshold in the query, so one dead instance cannot
		// fill the window with rows that are not due.
		$this->limitToQueueDue($qb, $backoff);
		// Most urgent first, then never attempted, then longest waiting since the
		// last attempt or hold (a held row keeps `tries = 0`, so tries do not
		// measure waiting). NULL sorts first on MySQL and SQLite and last on
		// PostgreSQL, so never-attempted rows are told apart explicitly.
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
	 * is failing: how long this instance has been stuck. The table records the
	 * last try, not the creation.
	 */
	public function oldestFailingAttempt(): int {
		// `last` is a datetime, NULL when never attempted; comparing it with 0
		// would make PostgreSQL read "0" as a date.
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->func()->min('last'), 'oldest')
			->from(self::TABLE_REQUEST_QUEUE)
			->where($qb->expr()->eq('status', $qb->createNamedParameter(RequestQueue::STATUS_STANDBY, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('tries', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('last'));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		// Parsed as a hydrated row's datetime is, whatever the database's format.
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
	 * still waiting. The longest retry wait is fourteen hours, so these are rows
	 * no drain came back for. Never-attempted rows have no `last` and are not
	 * counted.
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
		// Delivered and abandoned rows have tries too.
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
	 * The deliveries this instance has given up on, the most recently abandoned
	 * first. They are kept `RETENTION_SECONDS` past their last attempt, so an
	 * administrator can still be told.
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
	 * urgent first; with `$neverTried`, only rows not yet attempted.
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
	 * Marks a delivered row and drops its body: the row is kept for
	 * `RequestQueueService::RETENTION_SECONDS` as proof of delivery, but nothing
	 * re-sends it, and the per-inbox bodies were the bulk of the table. An
	 * abandoned row keeps its body for `social:queue:retry`.
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
	 * Moves a standby request out of the due window until `$until`. A future
	 * `last` holds it back (`limitToQueueDue()`) and sorts it last
	 * (`getStandby()`) without spending a try.
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
	 * Hands a held request its final body and makes it due at once (`last`
	 * NULL, as for a fresh row).
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
	 * Returns every request stuck `running` since before $before to standby,
	 * counted as a failed attempt, so a delivery that kills its worker is not
	 * retried for ever.
	 *
	 * @return int the number of requests re-queued
	 * @throws Exception
	 */
	public function resetStaleRunning(int $before): int {
		$qb = $this->getRequestQueueUpdateSql();
		$qb->set('status', $qb->createNamedParameter(RequestQueue::STATUS_STANDBY));
		$qb->set('tries', $qb->func()->add('tries', $qb->expr()->literal(1)));
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
	 * Marks what the drain has given up on: rows that spent their tries, and
	 * rows queued before `$queuedBefore` (a host the breaker keeps holding back
	 * spends no tries). Both use an index (`social_rq_tries`, `social_rq_sc`).
	 * The rows stay until `deleteFinished()`, so the post can say a server never
	 * got it.
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
	 * Which of these authors still have deliveries waiting or going out, a
	 * query per 500 of them, on `social_rq_as`. A deleted account is kept until
	 * this leaves it out, since its key signs its `Delete`.
	 *
	 * @param string[] $actorIds
	 * @return array<string, true> actor id => true
	 */
	public function authorsWithPending(array $actorIds): array {
		$found = [];
		foreach (array_chunk(array_values(array_unique($actorIds)), 500) as $chunk) {
			$qb = $this->getQueryBuilder();
			$byPrim = [];
			foreach ($chunk as $actorId) {
				$byPrim[$qb->prim($actorId)] = $actorId;
			}
			$qb->selectDistinct('author_prim')
				->from(self::TABLE_REQUEST_QUEUE)
				->where($qb->expr()->in('author_prim', $qb->createNamedParameter(array_keys($byPrim), IQueryBuilder::PARAM_STR_ARRAY)))
				->andWhere($qb->expr()->in('status', $qb->createNamedParameter(
					[RequestQueue::STATUS_STANDBY, RequestQueue::STATUS_RUNNING], IQueryBuilder::PARAM_INT_ARRAY
				)));

			$cursor = $qb->executeQuery();
			while (($prim = $cursor->fetchOne()) !== false) {
				if (isset($byPrim[(string)$prim])) {
					$found[$byPrim[(string)$prim]] = true;
				}
			}
			$cursor->closeCursor();
		}

		return $found;
	}

	/**
	 * Removes delivered and abandoned rows whose last attempt is older than
	 * `$before`.
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
	 * not due before some moment after `$now`; narrowed in the query because
	 * this runs on every delete.
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
}
