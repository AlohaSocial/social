<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Security\AsyncRequestSigner;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\RequestQueueService;
use OCA\Social\Service\StreamQueueService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

class Queue extends TimedJob {
	/**
	 * How long one cron run may spend on each queue, in seconds; leftover rows
	 * stay on standby for the next run. One budget per queue, so a delivery
	 * backlog cannot starve the inbound side. Together well inside the 12 minute
	 * interval, so runs cannot overlap.
	 */
	public const REQUEST_DURATION = 240;
	public const STREAM_DURATION = 120;

	/**
	 * The most standby batches one run takes from each queue; the deadline
	 * normally ends it first.
	 */
	public const MAX_BATCHES = 30;

	private ActivityService $activityService;
	private RequestQueueService $requestQueueService;
	private StreamQueueService $streamQueueService;
	private LoggerInterface $logger;

	public function __construct(
		ITimeFactory $time,
		RequestQueueService $requestQueueService,
		StreamQueueService $streamQueueService,
		ActivityService $activityService,
		LoggerInterface $logger,
		private ?CacheActorService $cacheActorService = null,
	) {
		parent::__construct($time);
		$this->setInterval(12 * 60);
		$this->requestQueueService = $requestQueueService;
		$this->streamQueueService = $streamQueueService;
		$this->activityService = $activityService;
		$this->logger = $logger;
	}

	#[\Override]
	protected function run($argument) {
		$this->manageRequestQueue($this->time->getTime() + self::REQUEST_DURATION);
		$this->manageStreamQueue($this->time->getTime() + self::STREAM_DURATION);
	}

	private function manageRequestQueue(int $deadline) {
		// Re-queue anything a dead worker left stranded mid-delivery before draining.
		$this->requestQueueService->reapStaleRunning();
		// A cheap DELETE, so it runs every pass.
		$this->requestQueueService->purgeFinished();
		// and the breaker rows of hosts that have not failed for an hour
		$this->activityService->forgetRecoveredHosts();

		// Batch after batch while there is time: a batch goes out twenty servers at
		// a time (`ActivityService::manageRequests()`) and takes seconds.
		$seen = [];
		for ($batch = 0; $batch < self::MAX_BATCHES && $this->time->getTime() < $deadline; $batch++) {
			$requests = [];
			foreach ($this->requestQueueService->getRequestStandby() as $request) {
				// A row this pass already handed out and could not end is skipped.
				$key = ($request->getId() > 0) ? 'row' . $request->getId() : 'object' . spl_object_id($request);
				if (isset($seen[$key])) {
					continue;
				}
				$seen[$key] = true;
				$request->setTimeout(ActivityService::TIMEOUT_SERVICE);
				$requests[] = $request;
			}
			if ($requests === []) {
				break;
			}

			// cron.php runs every job in one process, so drop memoised actors (and keys)
			// between batches.
			$this->cacheActorService?->forgetMemoised();
			$this->activityService->manageInit();
			$this->activityService->manageRequests(
				$requests,
				$deadline,
				fn (RequestQueue $request, Throwable $e) => $this->failed($request, $e)
			);
		}
	}

	/**
	 * A row whose delivery ended in a way `ActivityService` does not handle
	 * itself: logged, and handed back to standby.
	 */
	private function failed(RequestQueue $request, Throwable $e): void {
		$token = AsyncRequestSigner::fingerprint($request->getToken());
		if ($e instanceof SocialAppConfigException) {
			// Misconfigured, so every delivery here will fail: warn, as federation is
			// down until it is fixed.
			$this->logger->warning(
				'[Cron\\Queue] cannot deliver ' . $token
				. ': the Social app is not configured (' . $e->getMessage() . ')',
				['exception' => $e, 'token' => $token]
			);
		} else {
			// One row must cost only that row: manageRequest() does not handle a corrupt
			// signing key or a lost database.
			$this->logger->warning(
				'[Cron\\Queue] delivery of ' . $token . ' failed: '
				. get_class($e) . ' ' . $e->getMessage(),
				['exception' => $e, 'token' => $token]
			);
		}

		$this->releaseRequest($request);
	}

	/**
	 * Hands a row back to the queue after an attempt that ended outside
	 * ActivityService's error handling; left `running`, it would never be
	 * retried or counted against MAX_TRIES.
	 */
	private function releaseRequest(RequestQueue $request): void {
		try {
			$this->requestQueueService->endRequest($request, false);
		} catch (Throwable $e) {
			// the database is what just failed; the stale reaper is the backstop
			$token = AsyncRequestSigner::fingerprint($request->getToken());
			$this->logger->warning(
				'[Cron\\Queue] cannot return ' . $token . ' to standby',
				['exception' => $e, 'token' => $token]
			);
		}
	}

	private function manageStreamQueue(int $deadline) {
		// Only this ever looks at a `running` row again.
		$this->streamQueueService->reapStaleRunning();

		// Batch after batch while there is time, as for the deliveries. A failed
		// item is on standby again at once, so handed-out items are tracked.
		$seen = [];
		for ($batch = 0; $batch < self::MAX_BATCHES && $this->time->getTime() < $deadline; $batch++) {
			$this->cacheActorService?->forgetMemoised();
			$fresh = 0;
			foreach ($this->streamQueueService->getRequestStandby() as $item) {
				if ($this->time->getTime() >= $deadline) {
					return;
				}

				$key = ($item->getId() > 0) ? 'row' . $item->getId() : 'object' . spl_object_id($item);
				if (isset($seen[$key])) {
					continue;
				}
				$seen[$key] = true;
				$fresh++;

				try {
					$this->streamQueueService->manageStreamQueue($item);
				} catch (Throwable $e) {
					// One item costs only that item; manageStreamQueue() ends the row.
					$this->logger->warning(
						'[Cron\\Queue] could not resolve queued item ' . $item->getStreamId() . ': '
						. get_class($e) . ' ' . $e->getMessage(),
						['exception' => $e, 'streamId' => $item->getStreamId()]
					);
				}
			}

			if ($fresh === 0) {
				return;
			}
		}
	}
}
