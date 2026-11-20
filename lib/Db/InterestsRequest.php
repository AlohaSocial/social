<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Model\Interest;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * A reader's interests, and the posts they asked to see less like. Storage
 * only: `InterestScorer` computes the scores and `InterestService` writes them.
 */
class InterestsRequest extends CoreRequestBuilder {
	/**
	 * Every row the reader has; the service prunes faded ones, so the set is
	 * small enough to rank in PHP.
	 *
	 * @return Interest[]
	 */
	public function getByActor(string $actorId): array {
		$qb = $this->getQueryBuilder();
		$qb->select('hashtag', 'score', 'scored_at', 'manual', 'position', 'score_week')
			->from(self::TABLE_INTERESTS)
			->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$interests = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$interests[] = new Interest(
				(string)$row['hashtag'],
				(float)$row['score'],
				(int)$row['scored_at'],
				(int)$row['manual'] === 1,
				($row['position'] === null) ? null : (int)$row['position'],
				($row['score_week'] === null) ? null : (float)$row['score_week'],
			);
		}
		$cursor->closeCursor();

		return $interests;
	}

	/**
	 * Writes a row, whether or not it exists yet: update, else insert skipping a
	 * conflict, else update again if another request inserted in between. Never
	 * an insert that catches a unique violation, which aborts the transaction
	 * on PostgreSQL.
	 */
	public function save(string $actorId, Interest $interest): void {
		if ($this->update($actorId, $interest) > 0) {
			return;
		}

		$qb = $this->getQueryBuilder();
		$inserted = $this->dbConnection->insertIgnoreConflict(self::TABLE_INTERESTS, [
			'actor_id_prim' => $qb->prim($actorId),
			'hashtag' => $interest->getHashtag(),
			'score' => $interest->getScore(),
			'scored_at' => $interest->getScoredAt(),
			'manual' => $interest->isManual() ? 1 : 0,
			'position' => $interest->getPosition(),
			'score_week' => $interest->getScoreWeek(),
		]);

		if ($inserted === 0) {
			$this->update($actorId, $interest);
		}
	}

	/** @return int how many rows it touched */
	private function update(string $actorId, Interest $interest): int {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_INTERESTS)
			->set('score', $qb->createNamedParameter($interest->getScore()))
			->set('scored_at', $qb->createNamedParameter($interest->getScoredAt(), IQueryBuilder::PARAM_INT))
			->set('manual', $qb->createNamedParameter($interest->isManual() ? 1 : 0, IQueryBuilder::PARAM_INT))
			->set('position', $qb->createNamedParameter($interest->getPosition(), $interest->getPosition() === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT))
			->set('score_week', $qb->createNamedParameter($interest->getScoreWeek(), $interest->getScoreWeek() === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR))
			->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($qb->expr()->eq('hashtag', $qb->createNamedParameter($interest->getHashtag())));

		return $qb->executeStatement();
	}

	/** @param string[] $hashtags */
	public function deleteTags(string $actorId, array $hashtags): void {
		if ($hashtags === []) {
			return;
		}

		foreach (array_chunk($hashtags, 500) as $chunk) {
			$qb = $this->getQueryBuilder();
			$qb->delete(self::TABLE_INTERESTS)
				->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))))
				->andWhere($qb->expr()->in('hashtag', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
	}

	/** Everything the reader has, both tables: a reset, and a deleted account. */
	public function deleteRelatedId(string $actorId): void {
		foreach ([self::TABLE_INTERESTS, self::TABLE_INTEREST_HIDES] as $table) {
			$qb = $this->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));
			$qb->executeStatement();
		}
	}

	/** Keeps a post out of the reader's feed. Asking twice is not an error. */
	public function hide(string $actorId, string $nid): void {
		$qb = $this->getQueryBuilder();
		$this->dbConnection->insertIgnoreConflict(self::TABLE_INTEREST_HIDES, [
			'actor_id_prim' => $qb->prim($actorId),
			'stream_nid' => $nid,
			// A string, as not every adapter binds an untyped DateTime.
			'creation' => (new DateTime('now'))->format('Y-m-d H:i:s'),
		]);
	}

	public function unhide(string $actorId, string $nid): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_INTEREST_HIDES)
			->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($qb->expr()->eq('stream_nid', $qb->createNamedParameter($nid)));
		$qb->executeStatement();
	}

	public function isHidden(string $actorId, string $nid): bool {
		$qb = $this->getQueryBuilder();
		$qb->select('id')
			->from(self::TABLE_INTEREST_HIDES)
			->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($qb->expr()->eq('stream_nid', $qb->createNamedParameter($nid)))
			->setMaxResults(1);

		$cursor = $qb->executeQuery();
		$found = $cursor->fetch() !== false;
		$cursor->closeCursor();

		return $found;
	}

	/**
	 * The posts the reader hid, newer than the feed's window.
	 *
	 * @return string[] nids
	 */
	public function getHiddenSince(string $actorId, DateTime $since): array {
		$qb = $this->getQueryBuilder();
		$qb->select('stream_nid')
			->from(self::TABLE_INTEREST_HIDES)
			->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($qb->expr()->gte('creation', $qb->createNamedParameter($since, IQueryBuilder::PARAM_DATE)));

		$nids = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$nids[] = (string)$row['stream_nid'];
		}
		$cursor->closeCursor();

		return $nids;
	}

	/**
	 * Forgets hides older than the feed's window, which can keep nothing out.
	 *
	 * @return int how many went
	 */
	public function purgeHidesBefore(DateTime $before): int {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_INTEREST_HIDES)
			->where($qb->expr()->lt('creation', $qb->createNamedParameter($before, IQueryBuilder::PARAM_DATE)));

		return $qb->executeStatement();
	}
}
