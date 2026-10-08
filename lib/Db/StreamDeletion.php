<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Removing posts and everything that hangs off them, and the reads that
 * choose what a purge removes.
 *
 * A trait used by `StreamRequest` for the reason `StreamTimelines` gives: it
 * separates the file, not the object.
 */
trait StreamDeletion {
	/**
	 * Removes a post and everything that hangs off it.
	 *
	 * A post is not one row: its recipients (which is what puts it in a
	 * timeline), the interaction flags on it, its hashtags, its link card, the
	 * Like/Announce rows pointing at it and its cached attachments all key on
	 * it. Deleting only the `social_stream` row left every one of those behind
	 * in five tables plus the files on disk, for good — nothing else ever
	 * looks at them again.
	 *
	 * @param string $type when given, the post is only removed if it is of
	 *                     that type — and then nothing else is touched either
	 */
	public function deleteById(string $id, string $type = '') {
		$qb = $this->getStreamDeleteSql();
		$prim = $this->streamPrim($qb, $id);
		if ($prim === '') {
			return;
		}

		$qb->limitToIdPrim($prim);
		if ($type !== '') {
			$qb->limitToType($type);
		}

		$deleted = $qb->executeStatement();
		if ($type !== '' && $deleted === 0) {
			// a guarded delete that matched nothing: the post is of another
			// type and still exists, so its related rows are still in use
			return;
		}

		$this->deleteRelatedTo([$prim]);
	}

	/**
	 * Removes every post of an author, and everything that hangs off each of
	 * them. Done in batches: an account at scale has more posts than the
	 * cascade wants to name in one IN () list.
	 */
	public function deleteByAuthor(string $actorId) {
		while (true) {
			$prims = $this->getIdPrimsByAuthor($actorId, self::DELETE_BATCH);
			if ($prims === []) {
				return;
			}

			$this->deleteRelatedTo($prims);

			$qb = $this->getStreamDeleteSql();
			$qb->andWhere($qb->expr()->in(
				'id_prim',
				$qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)
			));
			if ($qb->executeStatement() === 0) {
				// nothing was removed, so the next pass would select the very
				// same rows: stop rather than spin
				return;
			}
		}
	}

	/**
	 * The authors of one instance that still have something stored here.
	 *
	 * Distinct authors rather than posts: the caller deletes an author's posts
	 * with `deleteByAuthor()`, which is already batched, so this only has to
	 * name who is left. Once an author's posts are gone they are not named
	 * again, which is what lets a purge resume where it stopped.
	 *
	 * @return string[] actor ids
	 *
	 * @throws InvalidResourceException the domain is not one
	 */
	public function getAuthorsFromDomain(string $domain, int $limit = 100): array {
		$qb = $this->getQueryBuilder();
		$qb->selectDistinct('s.attributed_to')
			->from(self::TABLE_STREAM, 's')
			->where(DomainBlocksRequestBuilder::onDomain($qb, 's.attributed_to', $domain))
			->setMaxResults($limit);

		$cursor = $qb->executeQuery();
		$authors = array_map(
			static fn (array $row): string => (string)$row['attributed_to'], $cursor->fetchAll()
		);
		$cursor->closeCursor();

		return $authors;
	}

	/**
	 * @return string[] id_prim of the posts of an author
	 */
	private function getIdPrimsByAuthor(string $actorId, int $limit): array {
		$qb = $this->getQueryBuilder();
		$qb->select('s.id_prim')
			->from(self::TABLE_STREAM, 's')
			->where($qb->expr()->eq(
				's.attributed_to_prim', $qb->createNamedParameter($qb->prim($actorId))
			))
			->setMaxResults($limit);

		$cursor = $qb->executeQuery();
		$prims = array_map(static fn (array $row): string => (string)$row['id_prim'], $cursor->fetchAll());
		$cursor->closeCursor();

		return $prims;
	}

	/**
	 * The in-app notification rows (`SocialAppNotification`) created before a
	 * cutoff, oldest first. A notification is a local row about a local user's
	 * post; it is never federated, and nothing reads it again once it has
	 * scrolled out of the notifications timeline.
	 *
	 * @return string[] id_prim
	 */
	public function getNotificationPrimsBefore(DateTime $cutoff, int $limit): array {
		$qb = $this->notificationsBeforeQuery($cutoff);
		$qb->select('s.id_prim')
			->orderBy('s.creation', 'asc')
			->setMaxResults($limit);

		$cursor = $qb->executeQuery();
		$prims = array_map(static fn (array $row): string => (string)$row['id_prim'], $cursor->fetchAll());
		$cursor->closeCursor();

		return $prims;
	}

	public function countNotificationsBefore(DateTime $cutoff): int {
		$qb = $this->notificationsBeforeQuery($cutoff);
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'count');

		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($row['count'] ?? 0);
	}

	private function notificationsBeforeQuery(DateTime $cutoff): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$qb->from(self::TABLE_STREAM, 's')
			->where($expr->eq('s.type', $qb->createNamedParameter(SocialAppNotification::TYPE)))
			->andWhere($expr->eq('s.local', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($expr->lt('s.creation', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_DATE)));

		return $qb;
	}

	/**
	 * Removes the stream rows themselves; what hangs off them is
	 * `deleteRelatedTo()`'s business and goes first, because the cascade is
	 * keyed on rows that are about to be gone.
	 *
	 * @param string[] $prims
	 */
	public function deleteByPrims(array $prims): void {
		if ($prims === []) {
			return;
		}

		$qb = $this->getStreamDeleteSql();
		$qb->andWhere($qb->expr()->in(
			'id_prim', $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)
		));
		$qb->executeStatement();
	}

	/**
	 * Removes the rows and the cached files that belong to a set of posts,
	 * addressed by their id_prim. The single place that knows what "related to
	 * a post" means.
	 *
	 * @param string[] $prims
	 *
	 * @return int how many cached attachment rows were removed
	 */
	public function deleteRelatedTo(array $prims): int {
		if ($prims === []) {
			return 0;
		}

		$documents = $this->deleteDocumentsOf($prims);

		foreach ([
			// dest and tag rows hold the prim in a column that is not named for it
			[self::TABLE_STREAM_DEST, 'stream_id'],
			[self::TABLE_STREAM_TAGS, 'stream_id'],
			[self::TABLE_STREAM_ACTIONS, 'stream_id_prim'],
			[self::TABLE_STREAM_CARDS, 'stream_id_prim'],
			[self::TABLE_STATUS_REVISIONS, 'stream_id_prim'],
			// the Like and Announce activities pointing at the post
			[self::TABLE_ACTIONS, 'object_id_prim'],
			// and the emoji reactions on it, which are a table of their own
			[self::TABLE_REACTIONS, 'object_id_prim'],
			// and its place in any album its author put it in: a collection
			// entry pointing at a post that is gone would draw a gap
			[self::TABLE_COLLECTION_ITEMS, 'stream_id_prim'],
			// and who opened it, which is a row about a post that no longer
			// exists and that nothing will ever read again
			[self::TABLE_STREAM_VIEWS, 'stream_id_prim'],
			// and the people named in its pictures
			[self::TABLE_MEDIA_TAGS, 'stream_id_prim'],
			// and which documents it carries, which is how a finished
			// transcode finds it
			[self::TABLE_STREAM_MEDIA, 'stream_id_prim'],
			// where readers had got to in it, which is a bookmark into a video
			// that no longer exists
			[self::TABLE_WATCH, 'stream_id_prim'],
			// and which member of a team wrote it, which is the trail a team
			// account keeps and has nothing left to be about
			[self::TABLE_TEAM_POSTS, 'stream_id_prim'],
			// and that it was brought over from an archive: the import skips
			// any source id it remembers, so a deleted import could never be
			// brought over again
			[self::TABLE_IMPORTED_POSTS, 'stream_id_prim'],
			// and its words, which would otherwise keep answering a search
			// for a post that is gone
			[self::TABLE_SEARCH_TERMS, 'stream_id_prim'],
		] as [$table, $field]) {
			$qb = $this->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->in(
					$field, $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)
				));
			$qb->executeStatement();
		}

		return $documents;
	}

	/**
	 * The cached attachments of a set of posts, and the ladders built from
	 * them: the files first, then the rows that name them — a row without its
	 * file is recoverable, a file without its row is not.
	 *
	 * @param string[] $prims
	 */
	private function deleteDocumentsOf(array $prims): int {
		$qb = $this->getQueryBuilder();
		$qb->select('nid', 'id_prim', 'local_copy', 'resized_copy')
			->from(self::TABLE_CACHE_DOCUMENTS)
			->where($qb->expr()->in(
				'parent_id_prim', $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)
			));

		$cursor = $qb->executeQuery();
		$rows = $cursor->fetchAll();
		$cursor->closeCursor();
		if ($rows === []) {
			return 0;
		}

		foreach ($rows as $row) {
			$this->cacheDocumentService->removeFromCache((string)$row['local_copy']);
			$this->cacheDocumentService->removeFromCache((string)$row['resized_copy']);

			// and the rungs of its ladder, which are files of this server's
			// own making that nothing else names: a document's row going away
			// without them is disk that no later run would ever free, because
			// the only thing that knew about them was the row
			foreach ($this->renditionsRequest->deleteForDocument((string)$row['nid']) as $rung) {
				$this->cacheDocumentService->removeFromCache($rung);
			}
		}

		$delete = $this->getQueryBuilder();
		$delete->delete(self::TABLE_CACHE_DOCUMENTS)
			->where($delete->expr()->in('id_prim', $delete->createNamedParameter(
				array_map(static fn (array $row): string => (string)$row['id_prim'], $rows),
				IQueryBuilder::PARAM_STR_ARRAY
			)));

		return $delete->executeStatement();
	}

	/**
	 * A post is addressed either by its uri or, from the dest table, by the
	 * prim it is stored under there — the two are not distinguishable by the
	 * signature, and reading an already-hashed id as a uri hashes it twice and
	 * silently matches nothing.
	 */
	private function streamPrim(SocialQueryBuilder $qb, string $id): string {
		$prim = $qb->prim($id);
		if ($prim !== '') {
			return $prim;
		}

		return (preg_match('/^[0-9a-f]{32}$/', $id) === 1) ? $id : '';
	}
}
