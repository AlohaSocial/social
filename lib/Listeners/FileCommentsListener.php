<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Listeners;

use OCA\Social\Events\PostDeletedEvent;
use OCA\Social\Events\PostEditedEvent;
use OCA\Social\Events\PostPublishedEvent;
use OCA\Social\Service\FileCommentsService;
use OCP\Comments\Events\CommentAddedEvent;
use OCP\Comments\Events\CommentDeletedEvent;
use OCP\Comments\Events\CommentUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps the comments on a file and the replies to the post made from it in
 * step, in both directions.
 *
 * A failure is logged and goes no further: the post was published and the
 * comment was saved whatever happened here.
 *
 * @template-implements IEventListener<\OCP\EventDispatcher\Event>
 */
class FileCommentsListener implements IEventListener {
	public function __construct(
		private FileCommentsService $fileCommentsService,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		try {
			if ($event instanceof PostPublishedEvent) {
				$this->fileCommentsService->onPostPublished($event->getPost());
			} elseif ($event instanceof PostEditedEvent) {
				$this->fileCommentsService->onReplyUpdated($event->getPost());
			} elseif ($event instanceof PostDeletedEvent) {
				$this->fileCommentsService->onDeleted($event->getPost());
			} elseif ($event instanceof CommentAddedEvent) {
				$this->fileCommentsService->onCommentAdded($event->getComment());
			} elseif ($event instanceof CommentUpdatedEvent) {
				$this->fileCommentsService->onCommentUpdated($event->getComment());
			} elseif ($event instanceof CommentDeletedEvent) {
				$this->fileCommentsService->onCommentDeleted($event->getComment());
			}
		} catch (Throwable $e) {
			$this->logger->warning('[FileCommentsListener] could not keep a file\'s comments in step', [
				'event' => $event::class,
				'exception' => $e,
			]);
		}
	}
}
