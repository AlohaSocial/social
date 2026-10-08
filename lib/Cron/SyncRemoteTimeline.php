<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\StreamService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads one remote account's outbox and stores what is new in it.
 *
 * Queued with the actor id by `RemoteFetchQueue::syncTimeline()` when the
 * first page of the account's profile is opened, so — like `DomainPurge` —
 * it is not registered in `info.xml`. The profile answered with what was
 * stored; the next look at it has the synced posts. The accounts somebody
 * here follows are synced by `Cron\Cache` regardless.
 */
class SyncRemoteTimeline extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private CacheActorService $cacheActorService,
		private StreamService $streamService,
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
			$this->streamService->syncRemoteTimeline($actor);
		} catch (Throwable $e) {
			// the profile shows what is stored either way, and the next look
			// at it past the interval asks again
			$this->logger->info('[Cron\\SyncRemoteTimeline] could not sync ' . $id, [
				'actor' => $id, 'exception' => $e,
			]);
		}
	}
}
