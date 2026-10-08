<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\RequestQueueService;
use OCA\Social\Service\StreamQueueService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

class Queue extends TimedJob {
	/**
	 * How long one cron run may spend on each queue, in seconds.
	 *
	 * QueueController gives its drain a budget because an HTTP request has to
	 * return. This job had the same problem with a longer fuse and no budget at
	 * all: it ran until the batch was done or something killed it, so a backlog
	 * of slow or unreachable inboxes could hold a cron slot open indefinitely
	 * and overlap the next run. Rows left behind stay in standby and are picked
	 * up by the following run, which is what the queue is for.
	 *
	 * One budget per queue rather than one for the run: the deliveries go
	 * first, and a backlog of them used to spend the whole of a shared deadline
	 * so that the inbound side — the parents of replies, the posts a delivery
	 * only named — got nothing, run after run. Together still well inside the
	 * 12 minute interval below, so two runs cannot overlap.
	 */
	public const REQUEST_DURATION = 240;
	public const STREAM_DURATION = 120;

	/**
	 * The most standby batches one run takes from each queue — a guard for a
	 * run whose work all comes back at once; the deadline is what normally
	 * ends it.
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
		// the delivered and abandoned rows past their retention; a cheap DELETE
		// with a WHERE, so it runs every pass rather than on a schedule of its own
		$this->requestQueueService->purgeFinished();
		// and the breaker rows of hosts that have not failed for an hour
		$this->activityService->forgetRecoveredHosts();

		// batch after batch while there is time: the rows of one batch go out
		// twenty servers at a time (`ActivityService::manageRequests()`), so
		// 200 of them take seconds rather than the whole budget, and stopping
		// after one batch left the rest of the pass idle
		$seen = [];
		for ($batch = 0; $batch < self::MAX_BATCHES && $this->time->getTime() < $deadline; $batch++) {
			$requests = [];
			foreach ($this->requestQueueService->getRequestStandby() as $request) {
				// a row this pass already handed out and could not end is not
				// a reason to spend the rest of the budget on it
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
		if ($e instanceof SocialAppConfigException) {
			// The app is misconfigured, so *every* delivery in this queue
			// will fail the same way. That used to be swallowed silently:
			// at warning, because federation is down until it is fixed.
			$this->logger->warning(
				'[Cron\\Queue] cannot deliver ' . $request->getToken()
				. ': the Social app is not configured (' . $e->getMessage() . ')',
				['exception' => $e, 'token' => $request->getToken()]
			);
		} else {
			// One row must cost that row and no more. manageRequest() ends
			// the failures it knows about, but a corrupt signing key or the
			// database going away comes out of it unhandled — and used to
			// take the rest of the batch with it.
			$this->logger->warning(
				'[Cron\\Queue] delivery of ' . $request->getToken() . ' failed: '
				. get_class($e) . ' ' . $e->getMessage(),
				['exception' => $e, 'token' => $request->getToken()]
			);
		}

		$this->releaseRequest($request);
	}

	/**
	 * Hand a row back to the queue after an attempt that ended outside
	 * ActivityService's own error handling.
	 *
	 * It was marked `running` before the attempt, and left that way it is never
	 * retried, never counted against MAX_TRIES, and only freed by the stale
	 * reaper an hour later — for an activity nobody will ever see delivered.
	 */
	private function releaseRequest(RequestQueue $request): void {
		try {
			$this->requestQueueService->endRequest($request, false);
		} catch (Throwable $e) {
			// the database is what just failed; the stale reaper is the backstop
			$this->logger->warning(
				'[Cron\\Queue] cannot return ' . $request->getToken() . ' to standby',
				['exception' => $e, 'token' => $request->getToken()]
			);
		}
	}

	private function manageStreamQueue(int $deadline) {
		// an item whose drain died mid-resolution stays `running`, and nothing
		// but this ever looks at a running row again
		$this->streamQueueService->reapStaleRunning();

		// batch after batch while there is time, as for the deliveries: one
		// batch of 200 every twelve minutes could not keep up with a busy
		// inbox. An item that failed is on standby again at once on its first
		// tries, so what this pass has already handed out is not handed out
		// twice.
		$seen = [];
		for ($batch = 0; $batch < self::MAX_BATCHES && $this->time->getTime() < $deadline; $batch++) {
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
					// as for the deliveries above: one item costs that item and no
					// more. The row itself is ended by manageStreamQueue().
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
