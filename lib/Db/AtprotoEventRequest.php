<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Atproto\Model\Event;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The firehose, as rows: the database numbers them, the daemon reads them
 * in order, and the ones past the replay window are pruned.
 */
class AtprotoEventRequest extends CoreRequestBuilder {
	/**
	 * @param string $body the DAG-CBOR frame body, without `seq`
	 * @return int the sequence number
	 */
	public function append(string $did, string $kind, string $body, int $time): int {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_ATPROTO_EVENT)
			->setValue('did', $qb->createNamedParameter($did))
			->setValue('kind', $qb->createNamedParameter($kind))
			->setValue('bytes', $qb->createNamedParameter($body, IQueryBuilder::PARAM_LOB))
			->setValue('time', $qb->createNamedParameter($time, IQueryBuilder::PARAM_INT));
		$qb->executeStatement();

		return $qb->getLastInsertId();
	}

	/**
	 * The events after $seq, oldest first.
	 *
	 * @return Event[]
	 */
	public function after(int $seq, int $limit = 200): array {
		$qb = $this->getQueryBuilder();
		$qb->select('seq', 'did', 'kind', 'bytes', 'time')
			->from(self::TABLE_ATPROTO_EVENT)
			->where($qb->expr()->gt('seq', $qb->createNamedParameter($seq, IQueryBuilder::PARAM_INT)))
			->orderBy('seq', 'asc')
			->setMaxResults($limit);
		$events = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$events[] = new Event(
				(int)$row['seq'],
				(string)$row['did'],
				(string)$row['kind'],
				AtprotoRepoRequest::bytes($row['bytes']),
				(int)$row['time'],
			);
		}
		$cursor->closeCursor();

		return $events;
	}

	/** the newest sequence number, 0 when there is none */
	public function latestSeq(): int {
		$qb = $this->getQueryBuilder();
		$qb->select($qb->func()->max('seq'))->from(self::TABLE_ATPROTO_EVENT);
		$cursor = $qb->executeQuery();
		$seq = (int)$cursor->fetchOne();
		$cursor->closeCursor();

		return $seq;
	}

	/** the oldest sequence number still held, 0 when there is none */
	public function oldestSeq(): int {
		$qb = $this->getQueryBuilder();
		$qb->select($qb->func()->min('seq'))->from(self::TABLE_ATPROTO_EVENT);
		$cursor = $qb->executeQuery();
		$seq = (int)$cursor->fetchOne();
		$cursor->closeCursor();

		return $seq;
	}

	public function countSince(int $time): int {
		$qb = $this->getQueryBuilder();
		$qb->select($qb->func()->count('seq', 'n'))
			->from(self::TABLE_ATPROTO_EVENT)
			->where($qb->expr()->gt('time', $qb->createNamedParameter($time, IQueryBuilder::PARAM_INT)));
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($row['n'] ?? 0);
	}

	/**
	 * Drops the events older than $time.
	 *
	 * @return int how many went
	 */
	public function pruneBefore(int $time): int {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_ATPROTO_EVENT)
			->where($qb->expr()->lt('time', $qb->createNamedParameter($time, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}
}
