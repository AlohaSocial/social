<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\FileCommentsRequest;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * `social_file_post` and `social_file_comment` against the real tables: an
 * attachment waits for its post, the post finds its files by the md5 of its
 * id, and a reply delivered twice is one row without the database raising.
 */
class FileCommentsRequestTest extends TestCase {
	private const USER = 'integration-file-comments';
	private const POST = 'https://cloud.example/apps/social/@integration/9001';
	private const REPLY = 'https://remote.example/users/bob/statuses/9002';

	private FileCommentsRequest $request;

	protected function setUp(): void {
		parent::setUp();
		$this->request = Server::get(FileCommentsRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		$db = Server::get(IDBConnection::class);
		foreach (['social_file_post', 'social_file_comment'] as $table) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::USER)));
			$qb->executeStatement();
		}
	}

	public function testAnAttachmentWaitsForItsPostAndIsThenFoundByIt(): void {
		$this->request->addPending(4711, self::USER, '900000000000000001');

		$pending = $this->request->getPending(self::USER, ['900000000000000001', '900000000000000002']);
		$this->assertCount(1, $pending);
		$this->assertSame(4711, $pending[0]->fileId);
		$this->assertSame([], $this->request->getFilesOfPost(self::POST));

		$this->request->setPost($pending[0]->id, self::POST);

		$this->assertSame([], $this->request->getPending(self::USER, ['900000000000000001']));
		$files = $this->request->getFilesOfPost(self::POST);
		$this->assertCount(1, $files);
		$this->assertSame(self::POST, $files[0]->postId);
		$this->assertSame(self::POST, $this->request->getPostsOfFile(4711, self::USER)[0]->postId);
		$this->assertSame([], $this->request->getPostsOfFile(4711, 'somebody-else'));
	}

	public function testAReplyIsRecordedOncePerFile(): void {
		$prim = FileCommentsRequest::primOf(self::POST);
		$this->request->addComment(4711, self::USER, 501, $prim, self::REPLY, false);
		$this->request->addComment(4711, self::USER, 502, $prim, self::REPLY, false);
		$this->request->addComment(4712, self::USER, 503, $prim, self::REPLY, true);

		$rows = $this->request->getCommentsOfReply(self::REPLY);
		$this->assertCount(2, $rows);
		$this->assertSame(501, $this->request->getByComment(501)?->commentId);
		$this->assertNull($this->request->getByComment(502));
		$this->assertTrue($this->request->getByComment(503)?->outbound);
		$this->assertCount(2, $this->request->getCommentsOfPost(self::POST));
	}

	public function testDeletingThePostForgetsItsFilesAndItsComments(): void {
		$this->request->addPending(4711, self::USER, '900000000000000003');
		$this->request->setPost($this->request->getPending(self::USER, ['900000000000000003'])[0]->id, self::POST);
		$this->request->addComment(4711, self::USER, 504, FileCommentsRequest::primOf(self::POST), self::REPLY, false);

		$this->request->deletePost(self::POST);

		$this->assertSame([], $this->request->getFilesOfPost(self::POST));
		$this->assertSame([], $this->request->getCommentsOfReply(self::REPLY));
	}

	public function testAReplyOrACommentCanBeForgottenOnItsOwn(): void {
		$prim = FileCommentsRequest::primOf(self::POST);
		$this->request->addComment(4711, self::USER, 505, $prim, self::REPLY, false);
		$this->request->addComment(4711, self::USER, 506, $prim, self::REPLY . '/2', false);

		$this->request->deleteComment(505);
		$this->request->deleteReply(self::REPLY . '/2');

		$this->assertSame([], $this->request->getCommentsOfPost(self::POST));
	}
}
