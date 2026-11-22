<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Model\QuoteGrant;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The permissions this instance has given to quote its posts, one row per
 * (quoted post, quoting post): taking a quote back sends a `Reject` naming
 * the accepted `QuoteRequest`, and an author's list of quotes is read from it.
 *
 * @package OCA\Social\Db
 */
class QuoteGrantRequest extends CoreRequestBuilder {
	/**
	 * Records a grant, replacing whatever stood before. A peer's retry of the
	 * same request hits the unique index, which is handled rather than read
	 * for first, as the retry can land between a read and a write.
	 */
	public function save(QuoteGrant $grant): void {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_QUOTE_GRANTS)
			->setValue('target_id', $qb->createNamedParameter($grant->getTargetId()))
			->setValue('target_id_prim', $qb->createNamedParameter(md5($grant->getTargetId())))
			->setValue('quoting_id', $qb->createNamedParameter($grant->getQuotingId()))
			->setValue('quoting_id_prim', $qb->createNamedParameter(md5($grant->getQuotingId())))
			->setValue('actor_id', $qb->createNamedParameter($grant->getActorId()))
			->setValue('request_id', $qb->createNamedParameter($grant->getRequestId()))
			->setValue('authorization', $qb->createNamedParameter($grant->getAuthorization()))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		if ($this->insertUnlessDuplicate($qb)) {
			return;
		}

		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_QUOTE_GRANTS)
			->set('actor_id', $qb->createNamedParameter($grant->getActorId()))
			->set('request_id', $qb->createNamedParameter($grant->getRequestId()))
			->set('authorization', $qb->createNamedParameter($grant->getAuthorization()))
			->where($qb->expr()->eq('target_id_prim', $qb->createNamedParameter(md5($grant->getTargetId()))))
			->andWhere($qb->expr()->eq('quoting_id_prim', $qb->createNamedParameter(md5($grant->getQuotingId()))));

		$qb->executeStatement();
	}

	/** @return QuoteGrant[] every quote of one post, newest first */
	public function getByTarget(string $targetId, int $limit = 40, int|string $maxId = '0'): array {
		$qb = $this->getQueryBuilder();
		$this->select($qb)
			->where($qb->expr()->eq('target_id_prim', $qb->createNamedParameter(md5($targetId))))
			->orderBy('id', 'desc')
			->setMaxResults(max(1, min($limit, 40)));

		if ($maxId > 0) {
			$qb->andWhere($qb->expr()->lt('id', $qb->createNamedParameter($maxId, IQueryBuilder::PARAM_INT)));
		}

		return $this->rows($qb);
	}

	public function get(string $targetId, string $quotingId): ?QuoteGrant {
		$qb = $this->getQueryBuilder();
		$this->select($qb)
			->where($qb->expr()->eq('target_id_prim', $qb->createNamedParameter(md5($targetId))))
			->andWhere($qb->expr()->eq('quoting_id_prim', $qb->createNamedParameter(md5($quotingId))));

		return $this->rows($qb)[0] ?? null;
	}

	public function delete(string $targetId, string $quotingId): bool {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_QUOTE_GRANTS)
			->where($qb->expr()->eq('target_id_prim', $qb->createNamedParameter(md5($targetId))))
			->andWhere($qb->expr()->eq('quoting_id_prim', $qb->createNamedParameter(md5($quotingId))));

		return $qb->executeStatement() > 0;
	}

	/** Everything a deleted post leaves behind here, either way round. */
	/**
	 * The approvals one account was given for its quotes. `actor_id` holds
	 * the account; the other two columns hold posts. Not indexed: only an
	 * account's deletion asks.
	 */
	public function deleteByActor(string $actorId): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_QUOTE_GRANTS)
			->where($qb->expr()->eq('actor_id', $qb->createNamedParameter($actorId)));

		$qb->executeStatement();
	}

	public function deleteRelatedId(string $id): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_QUOTE_GRANTS)
			->where($qb->expr()->orX(
				$qb->expr()->eq('target_id_prim', $qb->createNamedParameter(md5($id))),
				$qb->expr()->eq('quoting_id_prim', $qb->createNamedParameter(md5($id)))
			));

		$qb->executeStatement();
	}

	private function select(IQueryBuilder $qb): IQueryBuilder {
		return $qb->select(
			'id', 'target_id', 'quoting_id', 'actor_id', 'request_id', 'authorization', 'creation'
		)->from(self::TABLE_QUOTE_GRANTS);
	}

	/**
	 * @return QuoteGrant[]
	 */
	private function rows(IQueryBuilder $qb): array {
		$grants = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$grant = new QuoteGrant();
			$grant->setId((int)$data['id'])
				->setTargetId((string)($data['target_id'] ?? ''))
				->setQuotingId((string)($data['quoting_id'] ?? ''))
				->setActorId((string)($data['actor_id'] ?? ''))
				->setRequestId((string)($data['request_id'] ?? ''))
				->setAuthorization((string)($data['authorization'] ?? ''));
			$grants[] = $grant;
		}
		$cursor->closeCursor();

		return $grants;
	}
}
