<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tools\Nid;
use OCP\DB\QueryBuilder\IQueryBuilder;
use Throwable;

/**
 * Removing posts and everything that hangs off them, and the reads that
 * choose what a purge removes. A trait of `StreamRequest`, splitting the
 * file, not the object (see `StreamTimelines`).
 */
trait StreamDeletion {
	/**
	 * Removes a post and everything that hangs off it: recipients, interaction
	 * flags, hashtags, link card, the Like/Announce rows pointing at it and its
	 * cached attachments.
	 *
	 * @param string $type when given, the post is only removed if it is of
	 *                     that type — and then nothing else is touched either
	 */
	public function deleteById(string $id, string $type = '') {
		$status = null;
		try {
			$candidate = $this->getStreamById($id, false, ACore::FORMAT_LOCAL);
			if (in_array($candidate->getType(), [Note::TYPE, Question::TYPE], true)
				&& in_array($candidate->getVisibility(), [Stream::TYPE_PUBLIC, Stream::TYPE_UNLISTED, Stream::TYPE_FOLLOWERS, Stream::TYPE_DIRECT], true)) {
				$status = $candidate;
			}
		} catch (Throwable) {
			// A deletion still has to proceed when a status cannot be decorated.
		}

		$qb = $this->getStreamDeleteSql();
		$prim = $this->streamPrim($qb, $id);
		if ($prim === '') {
			return;
		}

		$qb->limitToIdPrim($prim);
		if ($type !== '') {
			$qb->limitToType($type);
		}

		$this->markTrendHoursOf([$prim]);
		$deleted = $qb->executeStatement();
		if ($type !== '' && $deleted === 0) {
			// The post is of another type and still exists; its related rows stay.
			return;
		}
		if ($deleted > 0 && $status !== null) {
			$recipients = $status->getVisibility() === Stream::TYPE_PUBLIC
				? []
				: $this->streamDestRequest->recipientPrims($status->getId());
			$this->statusDeletionEventsRequest->record($status, $recipients);
		}

		$this->deleteRelatedTo([$prim]);
	}

	/**
	 * Removes every post of an author, and everything that hangs off each, in
	 * batches to keep the IN () lists bounded.
	 */
	public function deleteByAuthor(string $actorId) {
		while (true) {
			$prims = $this->getIdPrimsByAuthor($actorId, self::DELETE_BATCH);
			if ($prims === []) {
				return;
			}

			$this->markTrendHoursOf($prims);
			$this->deleteRelatedTo($prims);

			$qb = $this->getStreamDeleteSql();
			$qb->andWhere($qb->expr()->in(
				'id_prim',
				$qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)
			));
			if ($qb->executeStatement() === 0) {
				// Nothing removed: the next pass would select the same rows.
				return;
			}
		}
	}

	/**
	 * The authors of one instance that still have something stored here. An
	 * author whose posts are gone is not named again, so a purge can resume.
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
	 * The id and nid of an author's public and unlisted posts, the ones a link
	 * preview may show, a page at a time above a nid.
	 *
	 * @return list<array{id: string, nid: int|string}>
	 */
	public function getOpenPostAddressesByAuthor(string $actorId, int $limit, int|string $afterNid = 0): array {
		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$qb->select('s.id', 's.nid')
			->from(self::TABLE_STREAM, 's')
			->where($expr->eq('s.attributed_to_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($expr->in(
				's.visibility',
				$qb->createNamedParameter([Stream::TYPE_PUBLIC, Stream::TYPE_UNLISTED], IQueryBuilder::PARAM_STR_ARRAY)
			))
			->andWhere($expr->gt('s.nid', $qb->createNamedParameter((string)$afterNid)))
			->orderBy('s.nid', 'asc')
			->setMaxResults($limit);

		$cursor = $qb->executeQuery();
		$posts = array_map(
			static fn (array $row): array => ['id' => (string)$row['id'], 'nid' => Nid::fromStorage($row['nid'])],
			$cursor->fetchAll()
		);
		$cursor->closeCursor();

		return $posts;
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
	 * cutoff, oldest first; local, never federated, and unread once out of the
	 * notifications timeline.
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
	 * Removes the stream rows themselves; `deleteRelatedTo()` must run first, as
	 * it is keyed on these rows.
	 *
	 * @param string[] $prims
	 */
	public function deleteByPrims(array $prims): void {
		if ($prims === []) {
			return;
		}

		$this->markTrendHoursOf($prims);
		$qb = $this->getStreamDeleteSql();
		$qb->andWhere($qb->expr()->in(
			'id_prim', $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)
		));
		$qb->executeStatement();
	}

	/**
	 * Removes the rows and cached files that belong to a set of posts, by
	 * id_prim: the single place that knows what "related to a post" means.
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
			[self::TABLE_ATPROTO_CHAT_MESSAGE, 'stream_id_prim'],
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
			// Its place in any album.
			[self::TABLE_COLLECTION_ITEMS, 'stream_id_prim'],
			// Who opened it.
			[self::TABLE_STREAM_VIEWS, 'stream_id_prim'],
			// The people tagged in its photos.
			[self::TABLE_MEDIA_TAGS, 'stream_id_prim'],
			// Which documents it carries, which is how a finished transcode finds it.
			[self::TABLE_STREAM_MEDIA, 'stream_id_prim'],
			// Where readers had got to in it.
			[self::TABLE_WATCH, 'stream_id_prim'],
			// and how many times it was watched, and by whom today
			[self::TABLE_VIDEO_VIEWS, 'stream_id_prim'],
			[self::TABLE_VIDEO_SEEN, 'stream_id_prim'],
			// Which team member wrote it.
			[self::TABLE_TEAM_POSTS, 'stream_id_prim'],
			// That it was imported: the import skips remembered source ids, so a
			// deleted import could otherwise never be imported again.
			[self::TABLE_IMPORTED_POSTS, 'stream_id_prim'],
			// Its search terms.
			[self::TABLE_SEARCH_TERMS, 'stream_id_prim'],
			// Quote approvals it was the quoted or quoting post of.
			[self::TABLE_QUOTE_GRANTS, 'target_id_prim'],
			[self::TABLE_QUOTE_GRANTS, 'quoting_id_prim'],
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
	 * The cached attachments of a set of posts, and the ladders built from them:
	 * files first, then rows, since a row without its file is recoverable and a
	 * file without its row is not.
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

			// The ladder rungs: only the document row knows them, so they go first.
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
	 * A post is addressed by its uri or, from the dest table, by its prim; the
	 * signature cannot tell them apart, and hashing a prim again matches nothing.
	 */
	private function streamPrim(SocialQueryBuilder $qb, string $id): string {
		$prim = $qb->prim($id);
		if ($prim !== '') {
			return $prim;
		}

		return (preg_match('/^[0-9a-f]{32}$/', $id) === 1) ? $id : '';
	}
}
