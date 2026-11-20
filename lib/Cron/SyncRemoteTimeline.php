<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\RemoteFetchQueue;
use OCA\Social\Service\StreamService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads one remote account's outbox and stores what is new in it. Queued by
 * `RemoteFetchQueue::syncTimeline()` when the account's profile is opened, so
 * not registered in `info.xml`; followed accounts are synced by `Cron\Cache`.
 */
class SyncRemoteTimeline extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private CacheActorService $cacheActorService,
		private StreamService $streamService,
		private RemoteFetchQueue $remoteFetchQueue,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run($argument): void {
		$id = is_array($argument) ? (string)($argument['actor'] ?? '') : '';
		if ($id === '') {
			return;
		}

		try {
			$actor = $this->cacheActorService->getFromId($id);
			$pageUrl = '';
			$offset = 0;
			if (($argument['continuation'] ?? '') === '1') {
				$cursor = $this->remoteFetchQueue->timelineCursor($id);
				if (!is_array($cursor) || !is_string($cursor['url'] ?? null) || $cursor['url'] === '') {
					$this->remoteFetchQueue->finishTimelinePage($id, false, null);
					return;
				}
				$pageUrl = $cursor['url'];
				$offset = max(0, (int)($cursor['offset'] ?? 0));
			}
			$refreshOnly = ($argument['refresh_only'] ?? '') === '1';
			$result = $this->streamService->syncRemoteTimelinePage($actor, $pageUrl, $offset);
			$this->remoteFetchQueue->finishTimelinePage(
				$id,
				$result['success'],
				$result['next'],
				!$refreshOnly
			);
		} catch (Throwable $e) {
			$this->remoteFetchQueue->finishTimelinePage($id, false, null);
			// the profile shows what is stored either way, and the next look
			// at it past the interval asks again
			$this->logger->info('[Cron\\SyncRemoteTimeline] could not sync ' . $id, [
				'actor' => $id, 'exception' => $e,
			]);
		}
	}
}
