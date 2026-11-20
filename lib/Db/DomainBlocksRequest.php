<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The instances one account has blocked for itself. Every read and write is
 * scoped by the blocker's actor id in SQL, which is the whole of the access
 * control on domain blocks. Not `FediverseService`, the administrator's
 * instance-wide list.
 */
class DomainBlocksRequest extends DomainBlocksRequestBuilder {
	/** Blocking an instance twice is blocking it once. */
	public function save(string $actorId, string $domain): void {
		$qb = $this->getDomainBlocksInsertSql();
		$qb->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
			->setValue('domain', $qb->createNamedParameter($domain))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		$this->insertUnlessDuplicate($qb);
	}

	/** Unblocking an instance that was not blocked is not an error. */
	public function delete(string $actorId, string $domain): void {
		$qb = $this->getDomainBlocksDeleteSql();
		$qb->where(
			$qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))),
			$qb->expr()->eq('domain', $qb->createNamedParameter($domain))
		);

		$qb->executeStatement();
	}

	/**
	 * The instances the account has blocked, newest first — the order Mastodon
	 * serves `/api/v1/domain_blocks` in.
	 *
	 * @return string[]
	 */
	public function getByActor(string $actorId, int $limit = 100): array {
		$qb = $this->getDomainBlocksSelectSql();
		$qb->andWhere($qb->expr()->eq('db.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));
		$qb->orderBy('db.id', 'desc');
		$qb->setMaxResults($limit);

		$domains = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$domains[] = $this->get('domain', $data);
		}
		$cursor->closeCursor();

		return $domains;
	}

	public function isBlocked(string $actorId, string $domain): bool {
		if ($domain === '') {
			return false;
		}

		$qb = $this->getDomainBlocksSelectSql();
		$qb->andWhere($qb->expr()->eq('db.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($qb->expr()->eq('db.domain', $qb->createNamedParameter($domain)));
		$qb->setMaxResults(1);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $data !== false;
	}

	/**
	 * Everything an account leaves behind here when it is deleted. Called from
	 * the account-deletion path, like every other deleteRelatedId().
	 */
	public function deleteRelatedId(string $actorId): void {
		$qb = $this->getDomainBlocksDeleteSql();
		$qb->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$qb->executeStatement();
	}
}
