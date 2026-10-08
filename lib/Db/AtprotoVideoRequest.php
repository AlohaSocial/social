<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Atproto\Model\VideoUpload;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The videos on their way to Bluesky's video service, one per post.
 */
class AtprotoVideoRequest extends CoreRequestBuilder {
	/**
	 * Queues a post's video, unless it is queued already.
	 *
	 * @return bool whether this call queued it
	 */
	public function add(string $postId, string $did, string $documentId): bool {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_ATPROTO_VIDEO)
			->setValue('post_id', $qb->createNamedParameter($postId))
			->setValue('post_id_prim', $qb->createNamedParameter(md5($postId)))
			->setValue('did', $qb->createNamedParameter($did))
			->setValue('document_id', $qb->createNamedParameter($documentId))
			->setValue('state', $qb->createNamedParameter(VideoUpload::QUEUED))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
			->setValue('updated', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));
		try {
			$qb->executeStatement();
		} catch (DBException $e) {
			if ($e->getReason() === DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return false;
			}
			throw $e;
		}

		return true;
	}

	public function get(string $postId): ?VideoUpload {
		$qb = $this->getQueryBuilder();
		$qb->select('*')->from(self::TABLE_ATPROTO_VIDEO)
			->where($qb->expr()->eq('post_id_prim', $qb->createNamedParameter(md5($postId))));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return is_array($row) ? self::upload($row) : null;
	}

	/**
	 * The videos still in one state, those waited on longest first.
	 *
	 * @return VideoUpload[]
	 */
	public function inState(string $state, int $limit): array {
		$qb = $this->getQueryBuilder();
		$qb->select('*')->from(self::TABLE_ATPROTO_VIDEO)
			->where($qb->expr()->eq('state', $qb->createNamedParameter($state)))
			->orderBy('updated', 'asc')
			->setMaxResults($limit);
		$uploads = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$uploads[] = self::upload($row);
		}
		$result->closeCursor();

		return $uploads;
	}

	/**
	 * Moves a video on: its state, and what the video service said.
	 */
	public function update(VideoUpload $upload): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_ATPROTO_VIDEO)
			->set('state', $qb->createNamedParameter($upload->state))
			->set('job_id', $qb->createNamedParameter($upload->jobId))
			->set('blob_cid', $qb->createNamedParameter($upload->blobCid))
			->set('attempts', $qb->createNamedParameter($upload->attempts, IQueryBuilder::PARAM_INT))
			->set('error', $qb->createNamedParameter(mb_substr($upload->error, 0, 255)))
			->set('updated', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($upload->id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function remove(string $postId): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_ATPROTO_VIDEO)
			->where($qb->expr()->eq('post_id_prim', $qb->createNamedParameter(md5($postId))));
		$qb->executeStatement();
	}

	public function removeByDid(string $did): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_ATPROTO_VIDEO)
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		$qb->executeStatement();
	}

	/**
	 * Forgets the videos that ended before a time: their posts are on
	 * Bluesky, one way or the other.
	 */
	public function pruneEnded(int $before): int {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_ATPROTO_VIDEO)
			->where($qb->expr()->in('state', $qb->createNamedParameter([VideoUpload::DONE, VideoUpload::FAILED], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->lt('updated', $qb->createNamedParameter(new DateTime('@' . $before), IQueryBuilder::PARAM_DATE)));

		return $qb->executeStatement();
	}

	private static function upload(array $row): VideoUpload {
		return new VideoUpload(
			(int)$row['id'],
			(string)$row['post_id'],
			(string)$row['did'],
			(string)$row['document_id'],
			(string)$row['state'],
			(string)$row['job_id'],
			(string)$row['blob_cid'],
			(int)$row['attempts'],
			(string)$row['error'],
			AtprotoIdentityRequest::time($row['creation']),
			AtprotoIdentityRequest::time($row['updated']),
		);
	}
}
