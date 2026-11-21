<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * How many times each video posted here was watched (`social_video_views`,
 * kept as long as the post) and who was counted today (`social_video_seen`,
 * kept for the day). Both writes use `insertIgnoreConflict()`, since a caught
 * unique violation aborts the transaction on PostgreSQL.
 */
class VideoViewsRequest extends CoreRequestBuilder {
	/**
	 * Records that a viewer was counted for a video today.
	 *
	 * @param string $viewerHash who, as a hash that already names the day
	 *
	 * @return bool whether this is their first view of it today, which is
	 *              what decides whether the count moves
	 */
	public function seen(string $streamId, string $viewerHash, int $day): bool {
		$qb = $this->getQueryBuilder();

		return $this->dbConnection->insertIgnoreConflict(self::TABLE_VIDEO_SEEN, [
			'stream_id_prim' => $qb->prim($streamId),
			'viewer_hash' => $viewerHash,
			'day' => $day,
		]) > 0;
	}

	/** Adds one to a video's count, writing its row the first time. */
	public function increment(string $streamId): void {
		if ($this->addOne($streamId) > 0) {
			return;
		}

		$qb = $this->getQueryBuilder();
		$inserted = $this->dbConnection->insertIgnoreConflict(self::TABLE_VIDEO_VIEWS, [
			'stream_id_prim' => $qb->prim($streamId),
			'views' => 1,
		]);

		// somebody else's first view wrote the row in between
		if ($inserted === 0) {
			$this->addOne($streamId);
		}
	}

	/** @return int how many rows it moved */
	private function addOne(string $streamId): int {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_VIDEO_VIEWS)
			->set('views', $qb->createFunction('views + 1'))
			->where($qb->expr()->eq('stream_id_prim', $qb->createNamedParameter($qb->prim($streamId))));

		return $qb->executeStatement();
	}

	/**
	 * The counts of a page of videos, in one query.
	 *
	 * @param string[] $streamIds
	 *
	 * @return array<string, int> id_prim => views, missing where nobody watched
	 */
	public function countsFor(array $streamIds): array {
		if ($streamIds === []) {
			return [];
		}

		$qb = $this->getQueryBuilder();
		$prims = array_values(array_unique(array_map(static fn (string $id): string => md5($id), $streamIds)));
		$qb->select('stream_id_prim', 'views')
			->from(self::TABLE_VIDEO_VIEWS)
			->where($qb->expr()->in('stream_id_prim', $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)));

		$counts = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$counts[(string)$data['stream_id_prim']] = (int)$data['views'];
		}
		$cursor->closeCursor();

		return $counts;
	}

	/**
	 * Forgets who was counted on the days before `$day`.
	 *
	 * @return int how many rows went
	 */
	public function forgetBefore(int $day): int {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_VIDEO_SEEN)
			->where($qb->expr()->lt('day', $qb->createNamedParameter($day, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}
}
