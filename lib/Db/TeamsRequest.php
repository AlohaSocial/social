<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The accounts a team posts from, and who actually wrote each post.
 *
 * No membership is stored: the Nextcloud group *is* the membership, and it is
 * asked at the moment somebody tries to post. A copy of a group is a copy that
 * drifts, and the drift is somebody who left the organisation still able to
 * speak for it.
 *
 * @package OCA\Social\Db
 */
class TeamsRequest extends CoreRequestBuilder {
	use TArrayTools;

	/**
	 * Binds an account to a group.
	 *
	 * @return bool whether it was new
	 */
	public function create(string $actorId, string $groupId): bool {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_TEAMS)
			->setValue('actor_id', $qb->createNamedParameter($actorId))
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
			->setValue('group_id', $qb->createNamedParameter($groupId))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		try {
			$qb->executeStatement();
		} catch (DBException $e) {
			if ($e->getReason() !== DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}

			return false;
		}

		return true;
	}

	/**
	 * Every team account on this instance, oldest first.
	 *
	 * @return array<int, array{actor_id: string, group_id: string}>
	 */
	public function getAll(): array {
		$qb = $this->getQueryBuilder();
		$qb->select('actor_id', 'group_id')
			->from(self::TABLE_TEAMS)
			->orderBy('id', 'asc');

		return $this->rowsOf($qb);
	}

	/**
	 * The team accounts bound to any of these groups.
	 *
	 * One query for somebody's whole membership rather than one per group,
	 * because this is read on every page of the composer.
	 *
	 * @param string[] $groupIds
	 *
	 * @return array<int, array{actor_id: string, group_id: string}>
	 */
	public function getByGroups(array $groupIds): array {
		if ($groupIds === []) {
			return [];
		}

		$qb = $this->getQueryBuilder();
		$qb->select('actor_id', 'group_id')
			->from(self::TABLE_TEAMS)
			->where($qb->expr()->in('group_id', $qb->createNamedParameter($groupIds, IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('id', 'asc');

		return $this->rowsOf($qb);
	}

	/**
	 * The group behind one team account.
	 *
	 * @throws ItemNotFoundException when the account is not a team's
	 */
	public function groupOf(string $actorId): string {
		$qb = $this->getQueryBuilder();
		$qb->select('group_id')
			->from(self::TABLE_TEAMS)
			->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new ItemNotFoundException('not a team account');
		}

		return (string)$data['group_id'];
	}

	public function delete(string $actorId): bool {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_TEAMS)
			->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		return $qb->executeStatement() > 0;
	}

	/**
	 * Records who wrote one post from a team account.
	 *
	 * Written once and never changed: this is the audit trail, and a trail
	 * that can be rewritten is not one.
	 */
	public function recordAuthor(string $streamId, string $authorId): void {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_TEAM_POSTS)
			->setValue('stream_id_prim', $qb->createNamedParameter(md5($streamId)))
			->setValue('author_id', $qb->createNamedParameter($authorId))
			->setValue('author_id_prim', $qb->createNamedParameter($qb->prim($authorId)))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		try {
			$qb->executeStatement();
		} catch (DBException $e) {
			if ($e->getReason() !== DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			// already recorded, which is what a retried write looks like
		}
	}

	/**
	 * Who wrote each of a page of team posts.
	 *
	 * @param string[] $streamIds
	 *
	 * @return array<string, string> keyed by the hash of the post's id
	 */
	public function authorsOf(array $streamIds): array {
		if ($streamIds === []) {
			return [];
		}

		$prims = array_map('md5', $streamIds);

		$qb = $this->getQueryBuilder();
		$qb->select('stream_id_prim', 'author_id')
			->from(self::TABLE_TEAM_POSTS)
			->where($qb->expr()->in('stream_id_prim', $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)));

		$authors = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$authors[(string)$data['stream_id_prim']] = (string)$data['author_id'];
		}
		$cursor->closeCursor();

		return $authors;
	}

	/** The trail of a post goes with the post. */
	/**
	 * Which of a team's posts one person wrote: the posts stay the team's.
	 */
	public function deleteByAuthor(string $actorId): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_TEAM_POSTS)
			->where($qb->expr()->eq('author_id_prim', $qb->createNamedParameter(md5($actorId))));

		$qb->executeStatement();
	}

	public function deleteByStream(string $streamId): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_TEAM_POSTS)
			->where($qb->expr()->eq('stream_id_prim', $qb->createNamedParameter(md5($streamId))));

		$qb->executeStatement();
	}

	/**
	 * @return array<int, array{actor_id: string, group_id: string}>
	 */
	private function rowsOf(SocialQueryBuilder $qb): array {
		$rows = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$rows[] = [
				'actor_id' => (string)$data['actor_id'],
				'group_id' => (string)$data['group_id'],
			];
		}
		$cursor->closeCursor();

		return $rows;
	}
}
