<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Listeners;

use OCA\Social\Atproto\RecordMapper\OutboundQueue;
use OCA\Social\Events\PostDeletedEvent;
use OCA\Social\Events\PostPublishedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/** @implements IEventListener<Event> */
class AtprotoPostListener implements IEventListener {
	public function __construct(
		private readonly OutboundQueue $queue,
		private readonly LoggerInterface $logger,
	) {
	}
	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof PostPublishedEvent && !$event instanceof PostDeletedEvent) {
			return;
		}
		try {
			$post = $event->getPost();
			if ($event instanceof PostDeletedEvent) {
				$this->queue->queueDelete((string)$post->getNid());
			} elseif ($post->isLocal() && $post->getVisibility() === 'public' && $post->addressesPublic() && ($post->getDetails(\OCA\Social\Model\Details::PUBLICATION)['atproto'] ?? true)) {
				$this->queue->queuePost((string)$post->getNid());
			} elseif ($post->isLocal()) {
				$this->queue->queueDelete((string)$post->getNid());
			}
		} catch (\Throwable $e) {
			$this->logger->error('AT Protocol could not queue a Social post', ['exception' => $e]);
		}
	}
}
