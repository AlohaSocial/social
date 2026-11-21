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
 * What a moderator has decided about something that is trending.
 *
 * @package OCA\Social\Db
 */
class TrendReviewRequest extends CoreRequestBuilder {
	public const KIND_TAG = 'tag';
	public const KIND_LINK = 'link';
	public const KIND_STATUS = 'status';

	public const KINDS = [self::KIND_TAG, self::KIND_LINK, self::KIND_STATUS];

	/**
	 * Records a decision, replacing whatever stood before. The unique index
	 * settles concurrent decisions, so the violation is handled rather than
	 * avoided by reading first.
	 */
	public function decide(string $kind, string $ref, bool $approved, string $moderator): void {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_TREND_REVIEW)
			->setValue('kind', $qb->createNamedParameter($kind))
			->setValue('ref', $qb->createNamedParameter($ref))
			->setValue('ref_prim', $qb->createNamedParameter(md5($ref)))
			->setValue('approved', $qb->createNamedParameter($approved, IQueryBuilder::PARAM_BOOL))
			->setValue('moderator', $qb->createNamedParameter($moderator))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		if ($this->insertUnlessDuplicate($qb)) {
			return;
		}

		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_TREND_REVIEW)
			->set('approved', $qb->createNamedParameter($approved, IQueryBuilder::PARAM_BOOL))
			->set('moderator', $qb->createNamedParameter($moderator))
			->set('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('kind', $qb->createNamedParameter($kind)))
			->andWhere($qb->expr()->eq('ref_prim', $qb->createNamedParameter(md5($ref))));

		$qb->executeStatement();
	}

	/**
	 * Everything of one kind that was rejected from trending.
	 *
	 * @return string[] the references, lowercased for a tag
	 */
	public function rejected(string $kind): array {
		$qb = $this->getQueryBuilder();
		$qb->select('ref')
			->from(self::TABLE_TREND_REVIEW)
			->where($qb->expr()->eq('kind', $qb->createNamedParameter($kind)))
			->andWhere($qb->expr()->eq('approved', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));

		$refs = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$refs[] = (string)$data['ref'];
		}
		$cursor->closeCursor();

		return $refs;
	}

	/**
	 * Every decision of one kind, newest first, for the panel.
	 *
	 * @return array<int, array{ref: string, approved: bool, moderator: string, creation: string}>
	 */
	public function decisions(string $kind, int $limit = 200): array {
		$qb = $this->getQueryBuilder();
		$qb->select('ref', 'approved', 'moderator', 'creation')
			->from(self::TABLE_TREND_REVIEW)
			->where($qb->expr()->eq('kind', $qb->createNamedParameter($kind)))
			->orderBy('id', 'desc')
			->setMaxResults(max(1, min($limit, 500)));

		$rows = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$rows[] = [
				'ref' => (string)$data['ref'],
				'approved' => (bool)$data['approved'],
				'moderator' => (string)$data['moderator'],
				'creation' => (string)$data['creation'],
			];
		}
		$cursor->closeCursor();

		return $rows;
	}

	/** Forgets a decision, so the thing trends on its own merits again. */
	public function forget(string $kind, string $ref): bool {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_TREND_REVIEW)
			->where($qb->expr()->eq('kind', $qb->createNamedParameter($kind)))
			->andWhere($qb->expr()->eq('ref_prim', $qb->createNamedParameter(md5($ref))));

		return $qb->executeStatement() > 0;
	}
}
