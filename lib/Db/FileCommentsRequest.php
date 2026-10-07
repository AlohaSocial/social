<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Model\Files\FileComment;
use OCA\Social\Model\Files\FilePost;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Which files a post came from, and which comments stand for which replies.
 *
 * Both tables are keyed on the md5 of an ActivityPub id, as `social_stream`
 * is, so a reply arriving in the inbox is matched on an index.
 */
class FileCommentsRequest extends CoreRequestBuilder {
	/** How long an attachment from Files may wait for its post. */
	public const PENDING_TTL = 172800;

	/** The key a post or a reply is stored under: SocialCoreQueryBuilder::prim()'s rule. */
	public static function primOf(string $id): string {
		if ($id === '' || !str_starts_with($id, 'http')) {
			return '';
		}

		return md5($id);
	}

	/**
	 * Notes that a file was copied into an attachment, before it is posted.
	 *
	 * Also clears the rows of attachments that were never posted, which is
	 * the only place they are ever looked at.
	 */
	public function addPending(int $fileId, string $userId, string $docNid): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_FILE_POSTS)
			->where($qb->expr()->eq('post_id_prim', $qb->createNamedParameter('')))
			->andWhere($qb->expr()->lt('creation', $qb->createNamedParameter(time() - self::PENDING_TTL, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_FILE_POSTS)
			->setValue('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT))
			->setValue('user_id', $qb->createNamedParameter($userId))
			->setValue('doc_nid', $qb->createNamedParameter($docNid))
			->setValue('post_id', $qb->createNamedParameter(''))
			->setValue('post_id_prim', $qb->createNamedParameter(''))
			->setValue('creation', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT));
		$qb->executeStatement();
	}

	/**
	 * The attachments from Files of one user that are still waiting for a post.
	 *
	 * @param string[] $docNids
	 * @return FilePost[]
	 */
	public function getPending(string $userId, array $docNids): array {
		if ($docNids === []) {
			return [];
		}

		$qb = $this->getQueryBuilder();
		$qb->select('id', 'file_id', 'user_id', 'doc_nid', 'post_id')
			->from(self::TABLE_FILE_POSTS)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('post_id_prim', $qb->createNamedParameter('')))
			->andWhere($qb->expr()->in('doc_nid', $qb->createNamedParameter($docNids, IQueryBuilder::PARAM_STR_ARRAY)));

		return $this->filePosts($qb);
	}

	public function setPost(int $id, string $postId): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_FILE_POSTS)
			->set('post_id', $qb->createNamedParameter($postId))
			->set('post_id_prim', $qb->createNamedParameter(self::primOf($postId)))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * The files a post was made from; none for almost every post.
	 *
	 * @return FilePost[]
	 */
	public function getFilesOfPost(string $postId): array {
		$qb = $this->getQueryBuilder();
		$prim = self::primOf($postId);
		if ($prim === '') {
			return [];
		}

		$qb->select('id', 'file_id', 'user_id', 'doc_nid', 'post_id')
			->from(self::TABLE_FILE_POSTS)
			->where($qb->expr()->eq('post_id_prim', $qb->createNamedParameter($prim)));

		return $this->filePosts($qb);
	}

	/**
	 * The posts a file was attached to by one user, newest first.
	 *
	 * @return FilePost[]
	 */
	public function getPostsOfFile(int $fileId, string $userId): array {
		$qb = $this->getQueryBuilder();
		$qb->select('id', 'file_id', 'user_id', 'doc_nid', 'post_id')
			->from(self::TABLE_FILE_POSTS)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->neq('post_id_prim', $qb->createNamedParameter('')))
			->orderBy('id', 'desc');

		return $this->filePosts($qb);
	}

	/** Forgets which files a post was made from, and every comment copied for it. */
	public function deletePost(string $postId): void {
		$qb = $this->getQueryBuilder();
		$prim = self::primOf($postId);
		if ($prim === '') {
			return;
		}

		$qb->delete(self::TABLE_FILE_POSTS)
			->where($qb->expr()->eq('post_id_prim', $qb->createNamedParameter($prim)));
		$qb->executeStatement();

		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_FILE_COMMENTS)
			->where($qb->expr()->eq('post_id_prim', $qb->createNamedParameter($prim)));
		$qb->executeStatement();
	}

	/**
	 * Records a reply and its comment on one file.
	 *
	 * A second row for the same reply on the same file is ignored: an inbox
	 * may deliver the same Create twice, and the first copy stands.
	 */
	public function addComment(int $fileId, string $userId, int $commentId, string $postIdPrim, string $replyId, bool $outbound): void {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_FILE_COMMENTS)
			->setValue('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT))
			->setValue('user_id', $qb->createNamedParameter($userId))
			->setValue('comment_id', $qb->createNamedParameter($commentId, IQueryBuilder::PARAM_INT))
			->setValue('post_id_prim', $qb->createNamedParameter($postIdPrim))
			->setValue('reply_id', $qb->createNamedParameter($replyId))
			->setValue('reply_id_prim', $qb->createNamedParameter(self::primOf($replyId)))
			->setValue('outbound', $qb->createNamedParameter($outbound ? 1 : 0, IQueryBuilder::PARAM_INT))
			->setValue('creation', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT));

		try {
			$qb->executeStatement();
		} catch (DBException $e) {
			if ($e->getReason() !== DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
		}
	}

	/**
	 * The comments that stand for one reply, one per file.
	 *
	 * @return FileComment[]
	 */
	public function getCommentsOfReply(string $replyId): array {
		$qb = $this->getQueryBuilder();
		$prim = self::primOf($replyId);
		if ($prim === '') {
			return [];
		}

		$qb->select('file_id', 'user_id', 'comment_id', 'post_id_prim', 'reply_id', 'outbound')
			->from(self::TABLE_FILE_COMMENTS)
			->where($qb->expr()->eq('reply_id_prim', $qb->createNamedParameter($prim)));

		return $this->fileComments($qb);
	}

	/** The reply a comment stands for, or null for a comment that is only a comment. */
	public function getByComment(int $commentId): ?FileComment {
		$qb = $this->getQueryBuilder();
		$qb->select('file_id', 'user_id', 'comment_id', 'post_id_prim', 'reply_id', 'outbound')
			->from(self::TABLE_FILE_COMMENTS)
			->where($qb->expr()->eq('comment_id', $qb->createNamedParameter($commentId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		return $this->fileComments($qb)[0] ?? null;
	}

	/**
	 * The comments copied for a post, before {@see deletePost()} forgets them.
	 *
	 * @return FileComment[]
	 */
	public function getCommentsOfPost(string $postId): array {
		$qb = $this->getQueryBuilder();
		$prim = self::primOf($postId);
		if ($prim === '') {
			return [];
		}

		$qb->select('file_id', 'user_id', 'comment_id', 'post_id_prim', 'reply_id', 'outbound')
			->from(self::TABLE_FILE_COMMENTS)
			->where($qb->expr()->eq('post_id_prim', $qb->createNamedParameter($prim)));

		return $this->fileComments($qb);
	}

	public function deleteReply(string $replyId): void {
		$qb = $this->getQueryBuilder();
		$prim = self::primOf($replyId);
		if ($prim === '') {
			return;
		}

		$qb->delete(self::TABLE_FILE_COMMENTS)
			->where($qb->expr()->eq('reply_id_prim', $qb->createNamedParameter($prim)));
		$qb->executeStatement();
	}

	public function deleteComment(int $commentId): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_FILE_COMMENTS)
			->where($qb->expr()->eq('comment_id', $qb->createNamedParameter($commentId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/** @return FilePost[] */
	private function filePosts(IQueryBuilder $qb): array {
		$rows = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$rows[] = new FilePost(
				(int)$data['id'],
				(int)$data['file_id'],
				(string)$data['user_id'],
				(string)$data['doc_nid'],
				(string)($data['post_id'] ?? ''),
			);
		}
		$cursor->closeCursor();

		return $rows;
	}

	/** @return FileComment[] */
	private function fileComments(IQueryBuilder $qb): array {
		$rows = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$rows[] = new FileComment(
				(int)$data['file_id'],
				(string)$data['user_id'],
				(int)$data['comment_id'],
				(string)$data['post_id_prim'],
				(string)($data['reply_id'] ?? ''),
				(int)$data['outbound'] === 1,
			);
		}
		$cursor->closeCursor();

		return $rows;
	}
}
