<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Exceptions\QueueStatusException;
use OCA\Social\Model\StreamQueue;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Class StreamQueueRequest
 *
 * @package OCA\Social\Db
 */
class StreamQueueRequest extends StreamQueueRequestBuilder {
	/** How many standby items a single cron pass hydrates. */
	public const STANDBY_BATCH = 200;

	/** An item is abandoned after this many failed attempts. */
	public const MAX_TRIES = Backoff::INBOUND_MAX_TRIES;

	/**
	 * create a new Queue in the database.
	 *
	 * @param StreamQueue $queue
	 */
	public function create(StreamQueue $queue) {
		$qb = $this->getStreamQueueInsertSql();
		$qb->setValue('token', $qb->createNamedParameter($queue->getToken()))
			->setValue('stream_id', $qb->createNamedParameter($queue->getStreamId()))
			->setValue('type', $qb->createNamedParameter($queue->getType()))
			->setValue('status', $qb->createNamedParameter($queue->getStatus()))
			->setValue('tries', $qb->createNamedParameter($queue->getTries()));
		$qb->executeStatement();
	}

	/**
	 * return Queue from database based on the status=0
	 *
	 * @return StreamQueue[]
	 */
	public function getStandby(): array {
		// what the drain has given up on goes first: the query below cannot
		// return those rows any more, and nothing else walks this table
		$this->deleteExhausted();

		$qb = $this->getStreamQueueSelectSql();
		$qb->limitToStatus(StreamQueue::STATUS_STANDBY);
		// the backoff and the give-up threshold belong in the query: filtering
		// them in PHP means the items of one unreachable host sit in the
		// window forever and nothing behind them is ever cached
		$this->limitToQueueDue($qb, Backoff::inbound());
		$qb->orderBy('qs.id', 'asc');
		$qb->setMaxResults(self::STANDBY_BATCH);

		$requests = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$requests[] = $this->parseStreamQueueSelectSql($data);
		}
		$cursor->closeCursor();

		return $requests;
	}

	/**
	 * return Queue from database based on the token
	 *
	 * @param string $token
	 *
	 * @return StreamQueue[]
	 */
	public function getFromToken(string $token): array {
		$qb = $this->getStreamQueueSelectSql();
		$qb->limitToToken($token);

		$queue = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$queue[] = $this->parseStreamQueueSelectSql($data);
		}
		$cursor->closeCursor();

		return $queue;
	}

	/**
	 * @param StreamQueue $queue
	 *
	 * @throws QueueStatusException
	 */
	public function setAsRunning(StreamQueue &$queue) {
		$qb = $this->getStreamQueueUpdateSql();
		$qb->set('status', $qb->createNamedParameter(StreamQueue::STATUS_RUNNING))
			->set(
				'last',
				$qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
			);
		$qb->limitToId($queue->getId());
		$qb->limitToStatus(StreamQueue::STATUS_STANDBY);

		$count = $qb->executeStatement();

		if ($count === 0) {
			throw new QueueStatusException();
		}

		$queue->setStatus(StreamQueue::STATUS_RUNNING);
	}

	/**
	 * A cached item has nothing left to record, so the row goes rather than
	 * staying as a STATUS_SUCCESS row nothing ever reads or removes — which is
	 * what made this table grow without bound. The in-memory status is still
	 * set, so a caller that inspects the model afterwards sees the outcome.
	 *
	 * @throws QueueStatusException
	 */
	public function setAsSuccess(StreamQueue &$queue) {
		$qb = $this->getStreamQueueDeleteSql();
		$qb->limitToId($queue->getId());
		$qb->limitToStatus(StreamQueue::STATUS_RUNNING);

		$count = $qb->executeStatement();

		if ($count === 0) {
			throw new QueueStatusException();
		}

		$queue->setStatus(StreamQueue::STATUS_SUCCESS);
	}

	/**
	 * @param StreamQueue $queue
	 *
	 * @throws QueueStatusException
	 */
	public function setAsFailure(StreamQueue &$queue) {
		$qb = $this->getStreamQueueUpdateSql();
		$func = $qb->func();
		$expr = $qb->expr();

		$qb->set('status', $qb->createNamedParameter(StreamQueue::STATUS_STANDBY))
			->set('tries', $func->add('tries', $expr->literal(1)));
		$qb->limitToId($queue->getId());
		$qb->limitToStatus(StreamQueue::STATUS_RUNNING);

		$count = $qb->executeStatement();

		if ($count === 0) {
			throw new QueueStatusException();
		}

		// the row is back on standby with one more try against it; saying
		// STATUS_SUCCESS here made the returned model contradict the table
		$queue->setStatus(StreamQueue::STATUS_STANDBY);
	}

	/**
	 * Return every item stuck `running` since before $before to standby.
	 *
	 * A drain that died mid-item — or one whose item threw where nothing
	 * caught it — left the row `running`, and `getStandby()` only ever looks
	 * at standby rows: the item was never retried, never counted against
	 * MAX_TRIES and never removed, so the parent it was fetching never
	 * arrived. The outbound queue has had this since it had the same problem.
	 *
	 * @return int the number of items re-queued
	 */
	public function resetStaleRunning(int $before): int {
		$qb = $this->getStreamQueueUpdateSql();
		$qb->set('status', $qb->createNamedParameter(StreamQueue::STATUS_STANDBY));
		$qb->limitToStatus(StreamQueue::STATUS_RUNNING);
		$qb->andWhere(
			$qb->expr()->lt('last', $qb->createNamedParameter(
				new DateTime('@' . $before), IQueryBuilder::PARAM_DATE
			))
		);

		return $qb->executeStatement();
	}

	/**
	 * Drops the items that have exhausted their retries — kept out of
	 * getStandby() by the same threshold, so nothing else would ever remove
	 * them.
	 *
	 * @return int rows removed
	 */
	public function deleteExhausted(int $maxTries = self::MAX_TRIES): int {
		$qb = $this->getStreamQueueDeleteSql();
		$qb->andWhere(
			$qb->expr()->gte('tries', $qb->createNamedParameter($maxTries, IQueryBuilder::PARAM_INT))
		);

		return $qb->executeStatement();
	}

	/**
	 * Drops the items a previous version of the app left behind as
	 * STATUS_SUCCESS rows: they are done, nothing reads them, and nothing
	 * removed them. A cached item no longer becomes one of these.
	 *
	 * @return int rows removed
	 */
	public function deleteCompleted(): int {
		$qb = $this->getStreamQueueDeleteSql();
		$qb->limitToStatus(StreamQueue::STATUS_SUCCESS);

		return $qb->executeStatement();
	}

	/**
	 * @param StreamQueue $queue
	 */
	public function delete(StreamQueue $queue) {
		$qb = $this->getStreamQueueDeleteSql();
		$qb->limitToId($queue->getId());

		$qb->executeStatement();
	}
}
