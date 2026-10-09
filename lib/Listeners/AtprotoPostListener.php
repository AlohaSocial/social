<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Listeners;

use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Cron\AtprotoPublish;
use OCA\Social\Events\PostDeletedEvent;
use OCA\Social\Events\PostEditedEvent;
use OCA\Social\Events\PostPublishedEvent;
use OCA\Social\Model\ActivityPub\Stream;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Queues the Bluesky side of a post the moment it is made, edited or
 * deleted. Only queues: the request that made the post is not the place to
 * sign a commit, and the Fediverse delivery must not wait for Bluesky.
 *
 * @template-implements IEventListener<PostPublishedEvent|PostDeletedEvent|PostEditedEvent>
 */
class AtprotoPostListener implements IEventListener {
	public function __construct(
		private AtprotoConfig $config,
		private IJobList $jobList,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$this->config->isEnabled()) {
			return;
		}
		if ($event instanceof PostPublishedEvent) {
			// a direct message goes to the people it names on Bluesky as one
			$post = $event->getPost();
			$this->queue($post->getVisibility() === Stream::TYPE_DIRECT ? 'message' : 'publish', $post->getId());
		} elseif ($event instanceof PostEditedEvent) {
			$this->queue('edit', $event->getPost()->getId());
		} elseif ($event instanceof PostDeletedEvent) {
			$this->queue('delete', $event->getPost()->getId());
		}
	}

	private function queue(string $action, string $id): void {
		if ($id === '') {
			return;
		}
		$this->jobList->add(AtprotoPublish::class, ['action' => $action, 'id' => $id]);
	}
}
