<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The two aggregates behind `stats` on `/api/v1/instance` and `usage` on
 * NodeInfo. Both walk a whole table, so `InstanceService` remembers their
 * result for a few minutes rather than asking on every hit — that endpoint is
 * the first request every client makes.
 */
class InstanceStatsRequest extends CoreRequestBuilder {
	use TArrayTools;

	/**
	 * Posts written on this instance: local Notes and Questions, whatever
	 * their visibility (Mastodon's `status_count` counts them all).
	 */
	public function countLocalStatuses(): int {
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'count')
			->from(self::TABLE_STREAM, 's');
		$qb->setDefaultSelectAlias('s');
		$qb->limitToLocal(true);
		$qb->limitToStatusTypes();

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $this->getInt('count', $data, 0);
	}

	/**
	 * Local statuses published in a window, for the weekly activity series.
	 *
	 * `published_time` is a DATETIME, so the window is bound as dates: an
	 * integer compared against it matches nothing on SQLite and is an error
	 * on PostgreSQL.
	 */
	public function countLocalStatusesBetween(int $from, int $to): int {
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'count')
			->from(self::TABLE_STREAM, 's');
		$qb->setDefaultSelectAlias('s');
		$qb->limitToLocal(true);
		$qb->limitToStatusTypes();
		$qb->andWhere($qb->expr()->gte(
			's.published_time', $qb->createNamedParameter((new DateTime())->setTimestamp($from), IQueryBuilder::PARAM_DATE)
		));
		$qb->andWhere($qb->expr()->lt(
			's.published_time', $qb->createNamedParameter((new DateTime())->setTimestamp($to), IQueryBuilder::PARAM_DATE)
		));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $this->getInt('count', $data, 0);
	}

	/**
	 * Every instance this one has heard of, lower-cased and sorted.
	 *
	 * The same walk `countRemoteDomains()` does, kept as one implementation:
	 * `/api/v1/instance/peers` answers this list and the stats answer its size,
	 * and two walks would be two answers to the same question.
	 *
	 * @return string[]
	 */
	public function getRemoteDomains(): array {
		$hosts = $this->remoteHosts();
		ksort($hosts);

		return array_keys($hosts);
	}

	public function countRemoteDomains(): int {
		return count($this->remoteHosts());
	}

	/**
	 * The remote instances this one has heard of, each with how many of its
	 * accounts are cached here.
	 *
	 * The same scan as the domain list — the host is read off the account
	 * handle in PHP because there is no host column to group on — so the
	 * count comes for free.
	 *
	 * @return array<string, int> host => cached accounts, most first
	 */
	public function remoteHostCounts(): array {
		$hosts = $this->remoteHosts();
		arsort($hosts);

		return $hosts;
	}

	/**
	 * @return array<string, int> host => how many cached accounts are on it
	 *
	 * Grouped in the database on `social_cache_actors.host`, which exists for
	 * this: the four callers used to read every cached actor row and split the
	 * handle in PHP, which on an instance that has been federating for a year
	 * is hundreds of thousands of rows fetched to produce a list of a few
	 * thousand hosts.
	 *
	 * A row whose host has not been backfilled yet is skipped rather than
	 * guessed at, so the count is briefly low rather than wrong; one pass of
	 * the upgrade's backfill fixes it.
	 */
	private function remoteHosts(): array {
		$qb = $this->getQueryBuilder();
		$qb->select('ca.host')
			->selectAlias($qb->createFunction('COUNT(*)'), 'accounts')
			->from(self::TABLE_CACHE_ACTORS, 'ca')
			->andWhere($qb->expr()->neq('ca.host', $qb->createNamedParameter('')))
			->groupBy('ca.host');
		$qb->setDefaultSelectAlias('ca');
		$qb->limitToLocal(false);

		$hosts = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$host = strtolower((string)($data['host'] ?? ''));
			if ($host !== '') {
				$hosts[$host] = (int)($data['accounts'] ?? 0);
			}
		}
		$cursor->closeCursor();

		return $hosts;
	}
}
