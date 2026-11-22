<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Listeners;

use OCA\Social\Events\PostDeletedEvent;
use OCA\Social\Events\PostEditedEvent;
use OCA\Social\Service\PostReferenceCache;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drops the cached link preview of a local post that was edited or deleted.
 * A failure is logged and goes no further: the cached card expires anyway.
 *
 * @template-implements IEventListener<PostEditedEvent|PostDeletedEvent>
 */
class PostReferenceListener implements IEventListener {
	public function __construct(
		private PostReferenceCache $postReferenceCache,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof PostEditedEvent && !$event instanceof PostDeletedEvent) {
			return;
		}

		try {
			$this->postReferenceCache->forgetPost($event->getPost());
		} catch (Throwable $e) {
			$this->logger->warning('could not drop the cached link preview of a post', ['exception' => $e]);
		}
	}
}
