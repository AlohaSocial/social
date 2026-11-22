<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Model\Moderation;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The aggregates behind `stats` on `/api/v1/instance` and `usage` on
 * NodeInfo. Both walk a whole table, so `InstanceService` caches them for a
 * few minutes.
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
	 * Accounts on this instance: local actors that are not deleted and not
	 * suspended. Nextcloud users without an account, and the users of every
	 * other backend, are not accounts and are not counted.
	 */
	public function countLocalAccounts(): int {
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'count')
			->from(self::TABLE_ACTORS, 'a')
			->leftJoin(
				'a', self::TABLE_MODERATION, 'm',
				$qb->expr()->andX(
					$qb->expr()->eq('m.actor_id_prim', 'a.id_prim'),
					$qb->expr()->eq('m.level', $qb->createNamedParameter(Moderation::SUSPEND))
				)
			)
			->where($qb->expr()->isNull('a.deleted'))
			->andWhere($qb->expr()->isNull('m.actor_id_prim'));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $this->getInt('count', $data, 0);
	}

	/**
	 * Local statuses published in a window, for the weekly activity series.
	 * `published_time` is a DATETIME, so the window is bound as dates: an
	 * integer matches nothing on SQLite and fails on PostgreSQL.
	 */
	public function countLocalStatusesBetween(int $from, int $to): int {
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'count')
			->from(self::TABLE_STREAM, 's');
		$qb->setDefaultSelectAlias('s');
		$qb->limitToLocal(true);
		$qb->limitToStatusTypes();
		$qb->andWhere($qb->expr()->gte(
			's.published_time', $qb->createNamedParameter(new DateTime('@' . $from), IQueryBuilder::PARAM_DATE)
		));
		$qb->andWhere($qb->expr()->lt(
			's.published_time', $qb->createNamedParameter(new DateTime('@' . $to), IQueryBuilder::PARAM_DATE)
		));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $this->getInt('count', $data, 0);
	}

	/**
	 * Every instance this one has heard of, lower-cased and sorted; the same
	 * walk as `countRemoteDomains()`, so the peers list and its count agree.
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
	 * Grouped in the database on `social_cache_actors.host`. Rows whose host
	 * is not backfilled yet are skipped, so the count is briefly low rather
	 * than wrong.
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
