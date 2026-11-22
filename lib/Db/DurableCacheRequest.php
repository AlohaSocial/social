<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The table behind `DurableCache` when there is no memcache: hashed keys,
 * encoded string values with an expiry. Expired rows are invisible to reads
 * before the purge removes them.
 *
 * @see \OCA\Social\Service\DurableCache
 */
class DurableCacheRequest extends CoreRequestBuilder {
	/** The stored value, or null when there is none or it has expired. */
	public function read(string $key, int $now): ?string {
		$qb = $this->getQueryBuilder();
		$qb->select('cache_value')
			->from(self::TABLE_DURABLE_CACHE)
			->where($qb->expr()->eq('cache_key', $qb->createNamedParameter($key)))
			->andWhere($qb->expr()->gt('expires', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return ($data === false || $data['cache_value'] === null) ? null : (string)$data['cache_value'];
	}

	/**
	 * Stores a value, replacing whatever the key held: an update, then an insert
	 * that skips a conflict, since concurrent writes are ordinary and a caught
	 * unique violation would abort the transaction on PostgreSQL.
	 */
	public function write(string $key, string $value, int $expires): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_DURABLE_CACHE)
			->set('cache_value', $qb->createNamedParameter($value))
			->set('expires', $qb->createNamedParameter($expires, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('cache_key', $qb->createNamedParameter($key)));

		if ($qb->executeStatement() > 0) {
			return;
		}

		$this->insertIfAbsent($key, $value, $expires);
	}

	/**
	 * Stores a value only when the key has no row at all, expired or not.
	 *
	 * @return bool whether this call wrote it
	 */
	public function insertIfAbsent(string $key, string $value, int $expires): bool {
		return $this->dbConnection->insertIgnoreConflict(self::TABLE_DURABLE_CACHE, [
			'cache_key' => $key,
			'cache_value' => $value,
			'expires' => $expires,
		]) > 0;
	}

	/** Replaces the value of a live row and leaves its expiry alone. */
	public function replaceValue(string $key, string $value, int $now): bool {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_DURABLE_CACHE)
			->set('cache_value', $qb->createNamedParameter($value))
			->where($qb->expr()->eq('cache_key', $qb->createNamedParameter($key)))
			->andWhere($qb->expr()->gt('expires', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement() > 0;
	}

	public function delete(string $key): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_DURABLE_CACHE)
			->where($qb->expr()->eq('cache_key', $qb->createNamedParameter($key)));

		$qb->executeStatement();
	}

	/** Deletes one cache row only when it has expired. */
	public function deleteExpired(string $key, int $now): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_DURABLE_CACHE)
			->where($qb->expr()->eq('cache_key', $qb->createNamedParameter($key)))
			->andWhere($qb->expr()->lte('expires', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/**
	 * Deletes what has expired.
	 *
	 * @return int how many rows went
	 */
	public function purge(int $now): int {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_DURABLE_CACHE)
			->where($qb->expr()->lte('expires', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}
}
