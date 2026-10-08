<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The delivery circuit breaker's state, one row per failing host.
 *
 * In the database rather than a cache that may not exist: without a memcache
 * the cache held nothing, and every delivery pass paid a full timeout per
 * dead peer to find out again what the last pass already knew. See
 * `HostBreaker::openUntil()` for how it is read.
 */
class HostBreakerRequest extends CoreRequestBuilder {
	/**
	 * The hosts that failed since `$since`, with when each is worth asking
	 * again and how many failures in a row it has.
	 *
	 * One query per drain rather than one per row: a pass looks every row's
	 * host up in this before it spends a timeout on it.
	 *
	 * @return array<string, array{strikes: int, open_until: int, last_failure: int}> host => state
	 */
	public function failingSince(int $since): array {
		$qb = $this->getQueryBuilder();
		$qb->select('host', 'strikes', 'open_until', 'last_failure')
			->from(self::TABLE_HOST_BREAKER)
			->where($qb->expr()->gt('last_failure', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)));

		$hosts = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$hosts[(string)$row['host']] = [
				'strikes' => (int)$row['strikes'],
				'open_until' => (int)$row['open_until'],
				'last_failure' => (int)$row['last_failure'],
			];
		}
		$cursor->closeCursor();

		return $hosts;
	}

	/** Records a failure: the host is left alone until `$openUntil`. */
	public function open(string $host, int $strikes, int $openUntil, int $now): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_HOST_BREAKER)
			->set('strikes', $qb->createNamedParameter($strikes, IQueryBuilder::PARAM_INT))
			->set('open_until', $qb->createNamedParameter($openUntil, IQueryBuilder::PARAM_INT))
			->set('last_failure', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('host_prim', $qb->createNamedParameter(md5($host))));

		if ($qb->executeStatement() > 0) {
			return;
		}

		// another process may have written the row since; the database skips
		// the insert then, and its failure count stands
		$this->dbConnection->insertIgnoreConflict(self::TABLE_HOST_BREAKER, [
			'host_prim' => md5($host),
			'host' => mb_substr($host, 0, 255),
			'strikes' => $strikes,
			'open_until' => $openUntil,
			'last_failure' => $now,
		]);
	}

	/** A host that answered is not failing, whatever it did before. */
	public function close(string $host): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_HOST_BREAKER)
			->where($qb->expr()->eq('host_prim', $qb->createNamedParameter(md5($host))));
		$qb->executeStatement();
	}

	/**
	 * Forgets the hosts whose last failure is older than `$before`: their
	 * strikes no longer count, and a row for them is only clutter.
	 *
	 * @return int rows removed
	 */
	public function forgetBefore(int $before): int {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_HOST_BREAKER)
			->where($qb->expr()->lte('last_failure', $qb->createNamedParameter($before, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}
}
