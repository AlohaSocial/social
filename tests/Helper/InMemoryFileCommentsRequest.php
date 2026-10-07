<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Helper;

use OCA\Social\Db\FileCommentsRequest;
use OCA\Social\Model\Files\FileComment;
use OCA\Social\Model\Files\FilePost;

/**
 * `social_file_post` and `social_file_comment` as two arrays, keeping what
 * each statement means: a second row for a reply on the same file is
 * skipped, as the unique index skips it.
 */
final class InMemoryFileCommentsRequest extends FileCommentsRequest {
	/** @var array<int, array{file_id: int, user_id: string, doc_nid: string, post_id: string}> */
	public array $posts = [];
	/** @var list<FileComment> */
	public array $comments = [];
	private int $nextId = 1;

	/** @noinspection PhpMissingParentConstructorInspection */
	public function __construct() {
	}

	#[\Override]
	public function addPending(int $fileId, string $userId, string $docNid): void {
		$this->posts[$this->nextId++] = ['file_id' => $fileId, 'user_id' => $userId, 'doc_nid' => $docNid, 'post_id' => ''];
	}

	#[\Override]
	public function getPending(string $userId, array $docNids): array {
		return $this->filePosts(fn (array $row): bool => $row['user_id'] === $userId
			&& $row['post_id'] === ''
			&& in_array($row['doc_nid'], $docNids, true));
	}

	#[\Override]
	public function setPost(int $id, string $postId): void {
		$this->posts[$id]['post_id'] = $postId;
	}

	#[\Override]
	public function getFilesOfPost(string $postId): array {
		return $postId === '' ? [] : $this->filePosts(fn (array $row): bool => $row['post_id'] === $postId);
	}

	#[\Override]
	public function getPostsOfFile(int $fileId, string $userId): array {
		return array_reverse($this->filePosts(fn (array $row): bool => $row['file_id'] === $fileId
			&& $row['user_id'] === $userId
			&& $row['post_id'] !== ''));
	}

	#[\Override]
	public function deletePost(string $postId): void {
		$this->posts = array_filter($this->posts, fn (array $row): bool => $row['post_id'] !== $postId);
		$prim = self::primOf($postId);
		$this->comments = array_values(array_filter($this->comments, fn (FileComment $c): bool => $c->postIdPrim !== $prim));
	}

	#[\Override]
	public function addComment(int $fileId, string $userId, int $commentId, string $postIdPrim, string $replyId, bool $outbound): void {
		foreach ($this->comments as $comment) {
			if ($comment->replyId === $replyId && $comment->fileId === $fileId) {
				return;
			}
		}
		$this->comments[] = new FileComment($fileId, $userId, $commentId, $postIdPrim, $replyId, $outbound);
	}

	#[\Override]
	public function getCommentsOfReply(string $replyId): array {
		return array_values(array_filter($this->comments, fn (FileComment $c): bool => $c->replyId === $replyId));
	}

	#[\Override]
	public function getByComment(int $commentId): ?FileComment {
		foreach ($this->comments as $comment) {
			if ($comment->commentId === $commentId) {
				return $comment;
			}
		}

		return null;
	}

	#[\Override]
	public function getCommentsOfPost(string $postId): array {
		$prim = self::primOf($postId);

		return array_values(array_filter($this->comments, fn (FileComment $c): bool => $c->postIdPrim === $prim));
	}

	#[\Override]
	public function deleteReply(string $replyId): void {
		$this->comments = array_values(array_filter($this->comments, fn (FileComment $c): bool => $c->replyId !== $replyId));
	}

	#[\Override]
	public function deleteComment(int $commentId): void {
		$this->comments = array_values(array_filter($this->comments, fn (FileComment $c): bool => $c->commentId !== $commentId));
	}

	/** @return FilePost[] */
	private function filePosts(callable $match): array {
		$rows = [];
		foreach ($this->posts as $id => $row) {
			if ($match($row)) {
				$rows[] = new FilePost($id, $row['file_id'], $row['user_id'], $row['doc_nid'], $row['post_id']);
			}
		}

		return $rows;
	}
}
