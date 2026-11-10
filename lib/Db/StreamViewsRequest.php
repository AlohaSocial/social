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
 * Who has opened a post.
 *
 * One row per (post, viewer), so the number an author sees is people rather
 * than visits — a reader coming back to a thread is the same person who was
 * already there.
 */
class StreamViewsRequest extends CoreRequestBuilder {
	/**
	 * Remembers that somebody opened a post.
	 *
	 * A repeat is the ordinary case and is not an error: the unique index
	 * settles it, and nothing here needs to know which of the two it was.
	 */
	/**
	 * @return bool whether this is the first time this account has opened it —
	 *              which is what decides whether a receipt goes out to the
	 *              server that holds it, so a second play is not a second view
	 */
	public function seen(string $streamId, string $actorId): bool {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_STREAM_VIEWS)
			->setValue('stream_id_prim', $qb->createNamedParameter($qb->prim($streamId)))
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		return $this->insertUnlessDuplicate($qb);
	}

	/** How many accounts have opened one post. */
	public function countFor(string $streamId): int {
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('*'), 'total')
			->from(self::TABLE_STREAM_VIEWS, 'sv')
			->where($qb->expr()->eq('sv.stream_id_prim', $qb->createNamedParameter($qb->prim($streamId))));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($data['total'] ?? 0);
	}

	/**
	 * The counts for a page of posts, in one query.
	 *
	 * A profile is twenty posts, and twenty queries to put a number under each
	 * of them is the kind of thing that is invisible in development and is the
	 * whole page in production.
	 *
	 * @param string[] $streamIds
	 * @return array<string, int> id_prim => how many, missing where none
	 */
	public function countForMany(array $streamIds): array {
		if ($streamIds === []) {
			return [];
		}

		$qb = $this->getQueryBuilder();
		$prims = array_map(static fn (string $id): string => md5($id), $streamIds);

		$qb->select('sv.stream_id_prim')
			->selectAlias($qb->func()->count('*'), 'total')
			->from(self::TABLE_STREAM_VIEWS, 'sv')
			->where($qb->expr()->in(
				'sv.stream_id_prim',
				$qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)
			))
			->groupBy('sv.stream_id_prim');

		$counts = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$counts[(string)$data['stream_id_prim']] = (int)$data['total'];
		}
		$cursor->closeCursor();

		return $counts;
	}

	/**
	 * Forgets the views of one post, when the post itself goes.
	 *
	 * Called from the cascade in `StreamRequest::deleteRelatedTo()`: a row
	 * naming a post that no longer exists is a row nothing will ever read
	 * again.
	 */
	public function deleteByStream(string $streamId): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_STREAM_VIEWS)
			->where($qb->expr()->eq('stream_id_prim', $qb->createNamedParameter($qb->prim($streamId))));

		$qb->executeStatement();
	}

	/** Forgets what one account looked at, when the account goes. */
	public function deleteByActor(string $actorId): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_STREAM_VIEWS)
			->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$qb->executeStatement();
	}
}
