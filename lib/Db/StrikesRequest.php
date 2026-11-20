<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Model\Strike;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The history of what moderators have decided about accounts. Append-only,
 * unlike `social_moderation`: rows are only deleted when an instance is
 * purged along with its accounts.
 */
class StrikesRequest extends CoreRequestBuilder {
	/** How many of an account's strikes a history shows. */
	public const HISTORY_LIMIT = 50;

	public function save(Strike $strike): void {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_STRIKES)
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($strike->getActorId())))
			->setValue('actor_id', $qb->createNamedParameter($strike->getActorId()))
			->setValue('action', $qb->createNamedParameter($strike->getAction()))
			->setValue('text', $qb->createNamedParameter($strike->getText()))
			->setValue('moderator', $qb->createNamedParameter($strike->getModerator()))
			->setValue('report_id', $qb->createNamedParameter($strike->getReportId(), IQueryBuilder::PARAM_INT))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		$qb->executeStatement();
	}

	/**
	 * One account's history, newest first.
	 *
	 * @return Strike[]
	 */
	public function getForActor(string $actorId, int $limit = self::HISTORY_LIMIT): array {
		$qb = $this->getQueryBuilder();
		$qb->select('id', 'actor_id', 'action', 'text', 'moderator', 'report_id', 'creation')
			->from(self::TABLE_STRIKES)
			->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->orderBy('id', 'desc')
			->setMaxResults(max(1, $limit));

		$strikes = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$strikes[] = Strike::fromRow($row);
		}
		$cursor->closeCursor();

		return $strikes;
	}

	/**
	 * How many strikes each of these accounts has, in one query for a page of
	 * the account browser. Lifted decisions are history, not strikes, and are
	 * not counted.
	 *
	 * @param string[] $actorIds
	 *
	 * @return array<string, int> actor id => how many, missing when none
	 */
	public function countForActors(array $actorIds): array {
		if ($actorIds === []) {
			return [];
		}

		$qb = $this->getQueryBuilder();
		$prims = array_map(fn (string $actorId): string => $qb->prim($actorId), $actorIds);

		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'strikes')
			->addSelect('actor_id')
			->from(self::TABLE_STRIKES)
			->where($qb->expr()->in(
				'actor_id_prim', $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)
			))
			->andWhere($qb->expr()->in(
				'action', $qb->createNamedParameter(Strike::COUNTED, IQueryBuilder::PARAM_STR_ARRAY)
			))
			->groupBy('actor_id');

		$counts = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$counts[(string)$row['actor_id']] = (int)$row['strikes'];
		}
		$cursor->closeCursor();

		return $counts;
	}

	/** Everything recorded about an account, for when the account itself goes. */
	public function deleteForActor(string $actorId): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_STRIKES)
			->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$qb->executeStatement();
	}
}
