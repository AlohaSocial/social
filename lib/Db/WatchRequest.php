<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use DateTimeZone;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Where a reader stopped watching.
 *
 * Two reads: where this person got to in this video, asked once when a page
 * opens, and what they were in the middle of, which is the "continue watching"
 * row. One row per (post, viewer), so it is a bookmark rather than a history of
 * every play.
 *
 * @package OCA\Social\Db
 */
class WatchRequest extends CoreRequestBuilder {
	/** Past this, a video has been watched rather than left half-finished. */
	public const FINISHED_RATIO = 0.95;

	/** Below this, somebody opened it and changed their mind. */
	public const STARTED_SECONDS = 10;

	/** Moves the bookmark, or writes one. */
	public function remember(string $streamId, string $actorId, int $position, int $duration): void {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_WATCH)
			->setValue('stream_id_prim', $qb->createNamedParameter($qb->prim($streamId)))
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
			->setValue('position', $qb->createNamedParameter($position, IQueryBuilder::PARAM_INT))
			->setValue('duration', $qb->createNamedParameter($duration, IQueryBuilder::PARAM_INT))
			->setValue('last_update', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		if ($this->insertUnlessDuplicate($qb)) {
			return;
		}

		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_WATCH)
			->set('position', $qb->createNamedParameter($position, IQueryBuilder::PARAM_INT))
			->set('duration', $qb->createNamedParameter($duration, IQueryBuilder::PARAM_INT))
			->set('last_update', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('stream_id_prim', $qb->createNamedParameter($qb->prim($streamId))))
			->andWhere($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$qb->executeStatement();
	}

	/**
	 * Where this person got to, or 0 for a video they have not opened.
	 */
	public function positionOf(string $streamId, string $actorId): int {
		$qb = $this->getQueryBuilder();
		$qb->select('position')
			->from(self::TABLE_WATCH)
			->where($qb->expr()->eq('stream_id_prim', $qb->createNamedParameter($qb->prim($streamId))))
			->andWhere($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->setMaxResults(1);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return ($data === false) ? 0 : (int)($data['position'] ?? 0);
	}

	/**
	 * What this person was in the middle of, newest first.
	 *
	 * Neither the ones they barely started nor the ones they finished: a
	 * "continue watching" row that offers back a video somebody watched to the
	 * end is a row nobody presses twice.
	 *
	 * @return string[] the prims of the posts
	 */
	public function unfinished(string $actorId, int $limit = 20): array {
		return array_column($this->unfinishedProgress($actorId, $limit), 'prim');
	}

	/**
	 * The same, each with where the person got to: what draws the bar under
	 * a video they began.
	 *
	 * @return list<array{prim: string, position: int, duration: int, updated: int}> `updated` a timestamp, 0 when it is not known
	 */
	public function unfinishedProgress(string $actorId, int $limit = 20): array {
		$qb = $this->getQueryBuilder();
		$qb->select('stream_id_prim', 'position', 'duration', 'last_update')
			->from(self::TABLE_WATCH)
			->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($qb->expr()->gte('position', $qb->createNamedParameter(self::STARTED_SECONDS, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('duration', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('last_update', 'desc')
			->setMaxResults(max(1, min($limit, 40)));

		$rows = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$rows[] = ['prim' => (string)$data['stream_id_prim']] + self::progressFrom($data);
		}
		$cursor->closeCursor();

		return $rows;
	}

	/**
	 * Where this person got to in this video, with how long it runs and when
	 * they were last in it: what a player resumes from.
	 *
	 * @return array{position: int, duration: int, updated: int}|null null for a video they have not opened
	 */
	public function progressOf(string $streamId, string $actorId): ?array {
		$qb = $this->getQueryBuilder();
		$qb->select('position', 'duration', 'last_update')
			->from(self::TABLE_WATCH)
			->where($qb->expr()->eq('stream_id_prim', $qb->createNamedParameter($qb->prim($streamId))))
			->andWhere($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->setMaxResults(1);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return ($data === false) ? null : self::progressFrom($data);
	}

	/**
	 * @param array<string, mixed> $data a row
	 *
	 * @return array{position: int, duration: int, updated: int} `updated` a timestamp, 0 when it is not known
	 */
	private static function progressFrom(array $data): array {
		$updated = 0;
		if (($data['last_update'] ?? null) !== null) {
			try {
				$updated = (new DateTime((string)$data['last_update'], new DateTimeZone('UTC')))->getTimestamp();
			} catch (\Exception $e) {
			}
		}

		return [
			'position' => (int)$data['position'],
			'duration' => (int)$data['duration'],
			'updated' => $updated,
		];
	}

	/** Forgets one, for somebody taking a video off their own list. */
	public function forget(string $streamId, string $actorId): bool {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_WATCH)
			->where($qb->expr()->eq('stream_id_prim', $qb->createNamedParameter($qb->prim($streamId))))
			->andWhere($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		return $qb->executeStatement() > 0;
	}

	/** Everything an account or a post leaves behind here. */
	public function deleteRelatedId(string $id): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_WATCH)
			->where($qb->expr()->orX(
				$qb->expr()->eq('stream_id_prim', $qb->createNamedParameter($qb->prim($id))),
				$qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($id)))
			));

		$qb->executeStatement();
	}
}
