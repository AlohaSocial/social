<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Listeners;

use OCA\Social\Events\PostDeletedEvent;
use OCA\Social\Events\PostEditedEvent;
use OCA\Social\Events\PostPublishedEvent;
use OCA\Social\Listeners\FileCommentsListener;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\FileCommentsService;
use OCA\Social\Tests\Helper\FakeComment;
use OCP\Comments\Events\CommentAddedEvent;
use OCP\Comments\Events\CommentDeletedEvent;
use OCP\Comments\Events\CommentUpdatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class FileCommentsListenerTest extends TestCase {
	private FileCommentsService|MockObject $service;
	private LoggerInterface|MockObject $logger;
	private FileCommentsListener $listener;

	protected function setUp(): void {
		$this->service = $this->createMock(FileCommentsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new FileCommentsListener($this->service, $this->logger);
	}

	public function testAPublishedPostIsPassedOn(): void {
		$post = new Note();
		$this->service->expects($this->once())->method('onPostPublished')->with($this->identicalTo($post));

		$this->listener->handle(new PostPublishedEvent($post));
	}

	/** A local reply edited in Social rewrites its copy on the file. */
	public function testAnEditedPostIsPassedOnAsAnEditedReply(): void {
		$post = new Note();
		$this->service->expects($this->once())->method('onReplyUpdated')->with($this->identicalTo($post));

		$this->listener->handle(new PostEditedEvent($post));
	}

	public function testADeletedPostIsPassedOn(): void {
		$post = new Note();
		$this->service->expects($this->once())->method('onDeleted')->with($this->identicalTo($post));

		$this->listener->handle(new PostDeletedEvent($post));
	}

	public function testEachCommentEventIsPassedOn(): void {
		$comment = new FakeComment();
		$this->service->expects($this->once())->method('onCommentAdded')->with($this->identicalTo($comment));
		$this->service->expects($this->once())->method('onCommentUpdated')->with($this->identicalTo($comment));
		$this->service->expects($this->once())->method('onCommentDeleted')->with($this->identicalTo($comment));

		$this->listener->handle(new CommentAddedEvent($comment));
		$this->listener->handle(new CommentUpdatedEvent($comment));
		$this->listener->handle(new CommentDeletedEvent($comment));
	}

	public function testAnUnrelatedEventIsIgnored(): void {
		$this->service->expects($this->never())->method($this->anything());

		$this->listener->handle(new Event());
	}

	/** The comment was saved and the post was published, whatever happens here. */
	public function testAFailureIsLoggedAndGoesNoFurther(): void {
		$this->service->method('onCommentAdded')->willThrowException(new \RuntimeException('down'));
		$this->logger->expects($this->once())->method('warning');

		$this->listener->handle(new CommentAddedEvent(new FakeComment()));
	}
}
