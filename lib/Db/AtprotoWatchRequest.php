<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Atproto\Model\Watch;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The feeds this instance polls: Bluesky authors somebody here follows in
 * `social_atproto_watch`, and local accounts' notification cursors in
 * `social_atproto_notify_cursor`. Both tables have the same shape, so one
 * request serves either, told which at construction.
 */
class AtprotoWatchRequest extends CoreRequestBuilder {
	private const FIELDS = ['did', 'handle', 'cursor', 'last_sync', 'next_sync', 'failures', 'last_error', 'creation'];

	/**
	 * A row for the DID, due now; an existing row is left as it is.
	 *
	 * @return bool whether a row was added
	 */
	public function add(string $did, string $handle = '', string $table = self::TABLE_ATPROTO_WATCH): bool {
		if ($this->getByDid($did, $table) !== null) {
			return false;
		}
		$qb = $this->getQueryBuilder();
		$now = new DateTime('now');
		$qb->insert($table)
			->setValue('did', $qb->createNamedParameter($did))
			->setValue('handle', $qb->createNamedParameter($handle))
			->setValue('cursor', $qb->createNamedParameter(''))
			->setValue('next_sync', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE))
			->setValue('failures', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->setValue('last_error', $qb->createNamedParameter(''))
			->setValue('creation', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE));
		$qb->executeStatement();

		return true;
	}

	public function remove(string $did, string $table = self::TABLE_ATPROTO_WATCH): void {
		$qb = $this->getQueryBuilder();
		$qb->delete($table)->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		$qb->executeStatement();
	}

	public function getByDid(string $did, string $table = self::TABLE_ATPROTO_WATCH): ?Watch {
		$qb = $this->getQueryBuilder();
		$qb->select(...self::FIELDS)->from($table)->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return $row === false ? null : $this->watch($row);
	}

	/**
	 * The rows whose time has come, the longest overdue first.
	 *
	 * @return Watch[]
	 */
	public function getDue(int $now, int $limit, string $table = self::TABLE_ATPROTO_WATCH): array {
		$qb = $this->getQueryBuilder();
		$qb->select(...self::FIELDS)->from($table)
			->where($qb->expr()->lte('next_sync', $qb->createNamedParameter(new DateTime('@' . $now), IQueryBuilder::PARAM_DATE)))
			->orderBy('next_sync', 'asc')
			->setMaxResults($limit);

		return $this->all($qb);
	}

	/**
	 * @return Watch[]
	 */
	public function getAll(string $table = self::TABLE_ATPROTO_WATCH, int $limit = 1000): array {
		$qb = $this->getQueryBuilder();
		$qb->select(...self::FIELDS)->from($table)->orderBy('next_sync', 'asc')->setMaxResults($limit);

		return $this->all($qb);
	}

	public function count(string $table = self::TABLE_ATPROTO_WATCH): int {
		$qb = $this->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'n'))->from($table);
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/**
	 * How far behind the slowest due row is, in seconds; 0 when nothing is overdue.
	 */
	public function lag(int $now, string $table = self::TABLE_ATPROTO_WATCH): int {
		$qb = $this->getQueryBuilder();
		$qb->select('next_sync')->from($table)->orderBy('next_sync', 'asc')->setMaxResults(1);
		$result = $qb->executeQuery();
		$oldest = $result->fetchOne();
		$result->closeCursor();
		$due = AtprotoIdentityRequest::time($oldest);

		return $due > 0 ? max(0, $now - $due) : 0;
	}

	/**
	 * A read that went well: the cursor to continue from and the next time.
	 */
	public function synced(string $did, string $cursor, int $now, int $nextSync, string $table = self::TABLE_ATPROTO_WATCH): void {
		$qb = $this->getQueryBuilder();
		$qb->update($table)
			->set('cursor', $qb->createNamedParameter($cursor))
			->set('last_sync', $qb->createNamedParameter(new DateTime('@' . $now), IQueryBuilder::PARAM_DATE))
			->set('next_sync', $qb->createNamedParameter(new DateTime('@' . $nextSync), IQueryBuilder::PARAM_DATE))
			->set('failures', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->set('last_error', $qb->createNamedParameter(''))
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		$qb->executeStatement();
	}

	/**
	 * A read that failed: one more failure, the reason, and the next time.
	 */
	public function failed(string $did, string $error, int $nextSync, string $table = self::TABLE_ATPROTO_WATCH): void {
		$qb = $this->getQueryBuilder();
		$qb->update($table)
			->set('failures', $qb->createFunction('failures + 1'))
			->set('last_error', $qb->createNamedParameter(mb_substr($error, 0, 500)))
			->set('next_sync', $qb->createNamedParameter(new DateTime('@' . $nextSync), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		$qb->executeStatement();
	}

	public function setHandle(string $did, string $handle, string $table = self::TABLE_ATPROTO_WATCH): void {
		$qb = $this->getQueryBuilder();
		$qb->update($table)
			->set('handle', $qb->createNamedParameter($handle))
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		$qb->executeStatement();
	}

	/**
	 * @return Watch[]
	 */
	private function all(IQueryBuilder $qb): array {
		$watches = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$watches[] = $this->watch($row);
		}
		$result->closeCursor();

		return $watches;
	}

	private function watch(array $row): Watch {
		return new Watch(
			(string)$row['did'],
			(string)$row['handle'],
			(string)$row['cursor'],
			AtprotoIdentityRequest::time($row['last_sync']),
			AtprotoIdentityRequest::time($row['next_sync']),
			(int)$row['failures'],
			(string)$row['last_error'],
			AtprotoIdentityRequest::time($row['creation']),
		);
	}
}
