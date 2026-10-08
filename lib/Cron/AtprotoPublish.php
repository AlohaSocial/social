<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\StreamService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One post's trip to Bluesky, queued by the listener the moment the post
 * is made, deleted or edited, so the Fediverse delivery never waits for it.
 *
 * `argument`: `action` (publish, delete, edit, or profile with an actor's
 * id) and the `id`. A failure is logged and left to the reconcile pass.
 */
class AtprotoPublish extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private Publisher $publisher,
		private StreamService $streamService,
		private CacheActorService $cacheActorService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run($argument): void {
		$action = is_array($argument) ? (string)($argument['action'] ?? '') : '';
		$id = is_array($argument) ? (string)($argument['id'] ?? '') : '';
		if ($id === '') {
			return;
		}
		try {
			if ($action === 'delete') {
				$this->publisher->deletePost($id);

				return;
			}
			if ($action === 'profile') {
				$this->publisher->publishProfile($this->cacheActorService->getFromId($id));

				return;
			}
			$post = $this->streamService->getStreamById($id);
			if ($action === 'edit') {
				$this->publisher->editPost($post);
			} else {
				$this->publisher->publishPost($post);
			}
		} catch (Throwable $e) {
			$this->logger->warning('Bluesky publication failed', ['action' => $action, 'post' => $id, 'exception' => $e]);
		}
	}
}
