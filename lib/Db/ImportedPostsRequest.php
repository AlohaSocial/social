<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * What an account has already brought over, and what it became here.
 *
 * The importer asks this twice per run: once for everything the archive
 * names, so that a second run of the same file writes nothing, and once per
 * post it writes, to remember it. A reply asks a third time, because the only
 * place an imported post's original id is still written down is here.
 */
class ImportedPostsRequest extends CoreRequestBuilder {
	/**
	 * Remembers that a post was brought over.
	 *
	 * A repeat is not an error: two imports racing each other is a person
	 * pressing a button twice, and the unique index is what settles it.
	 */
	public function remember(string $actorId, string $sourceId, string $streamId): void {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_IMPORTED_POSTS)
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
			->setValue('source_id', $qb->createNamedParameter($sourceId))
			->setValue('source_id_prim', $qb->createNamedParameter(md5($sourceId)))
			->setValue('stream_id_prim', $qb->createNamedParameter($qb->prim($streamId)))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		try {
			$qb->executeStatement();
		} catch (DBException $e) {
			if ($e->getReason() !== DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
		}
	}

	/**
	 * Which of these original ids this account has already brought over, and
	 * what each became.
	 *
	 * One query for the whole archive rather than one per post: an import is
	 * thousands of items, and the answer decides whether any work is done at
	 * all for each of them.
	 *
	 * @param string[] $sourceIds
	 * @return array<string, string> original id => the local post's id_prim
	 */
	public function knownAmong(string $actorId, array $sourceIds): array {
		if ($sourceIds === []) {
			return [];
		}

		$qb = $this->getQueryBuilder();
		$byPrim = [];
		foreach ($sourceIds as $sourceId) {
			$sourceId = (string)$sourceId;
			if ($sourceId !== '') {
				$byPrim[md5($sourceId)] = $sourceId;
			}
		}

		if ($byPrim === []) {
			return [];
		}

		$qb->select('ip.source_id_prim', 'ip.stream_id_prim')
			->from(self::TABLE_IMPORTED_POSTS, 'ip')
			->where($qb->expr()->eq('ip.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($qb->expr()->in(
				'ip.source_id_prim',
				$qb->createNamedParameter(array_keys($byPrim), IQueryBuilder::PARAM_STR_ARRAY)
			));

		$known = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$sourceId = $byPrim[(string)$data['source_id_prim']] ?? '';
			if ($sourceId !== '') {
				$known[$sourceId] = (string)$data['stream_id_prim'];
			}
		}
		$cursor->closeCursor();

		return $known;
	}

	/**
	 * Whether a post of this account was brought over rather than written
	 * here.
	 */
	public function isImported(string $actorId, string $streamId): bool {
		$qb = $this->getQueryBuilder();
		$qb->select('ip.id')
			->from(self::TABLE_IMPORTED_POSTS, 'ip')
			->where($qb->expr()->eq('ip.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($qb->expr()->eq('ip.stream_id_prim', $qb->createNamedParameter($qb->prim($streamId))))
			->setMaxResults(1);

		$cursor = $qb->executeQuery();
		$found = $cursor->fetch() !== false;
		$cursor->closeCursor();

		return $found;
	}

	/** How many posts this account has brought over, all told. */
	public function countFor(string $actorId): int {
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('*'), 'total')
			->from(self::TABLE_IMPORTED_POSTS, 'ip')
			->where($qb->expr()->eq('ip.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($data['total'] ?? 0);
	}

	/**
	 * Forgets what one account brought over.
	 *
	 * Called when the account itself is removed: the rows name posts that are
	 * going with it.
	 */
	public function deleteByActor(string $actorId): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_IMPORTED_POSTS)
			->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$qb->executeStatement();
	}
}
