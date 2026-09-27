<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Model\RequestQueue;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\RequestQueueService;
use OCA\Social\Service\StreamQueueService;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * A process that drains the queues continuously, instead of every twelve
 * minutes.
 *
 * `Cron\Queue` reads **200 rows every 12 minutes** and delivers them one after
 * another with a 30-second timeout each, inside a 300-second budget. That is
 * about a thousand deliveries an hour at best and **ten** at worst — ten
 * unresponsive peers at 30 seconds each fill the whole budget. An instance
 * whose accounts are followed across twenty thousand servers therefore takes
 * the better part of a day to deliver one popular post, with every other post
 * queued behind it. Nextcloud runs one `cron.php` at a time, so there is no
 * parallelism to be had by adding servers.
 *
 * This is the same delivery, in a loop that does not stop. A healthy peer
 * answers in a fraction of a second, so one worker moves thousands of rows an
 * hour rather than a thousand a day; and because claiming a row is already
 * atomic — `setAsRunning()` is an `UPDATE … WHERE status = standby` that throws
 * when it loses the race — **several workers may run at once** and will not
 * collide. Run it under systemd with as many instances as the instance needs.
 *
 * Three things it is careful about.
 *
 * **It gives the row back.** A worker killed mid-delivery leaves the row
 * `running`, where nothing retries it until the stale reaper an hour later. So
 * every attempt that ends outside `ActivityService`'s own error handling puts
 * the row back on standby, and the loop reaps stale rows as it goes.
 *
 * **It sleeps when there is nothing to do.** An empty queue polled in a tight
 * loop is a busy CPU and a database asked the same question thousands of times
 * a second. An empty pass waits, and the wait grows to `IDLE_MAX` so an idle
 * instance costs nothing.
 *
 * **It stops when asked.** `--once` drains what is there and returns, which is
 * what a cron entry wants; `--max-seconds` gives systemd something to restart
 * around; and SIGTERM finishes the row in hand before returning, because a
 * delivery abandoned halfway is one the peer may have already taken.
 *
 * The cron job stays exactly as it is. An instance that will not run a daemon
 * keeps the behaviour it has, and one that will gets the throughput.
 */
class Worker extends SocialCommand {
	/** How long an empty queue waits before asking again, and the ceiling on that. */
	private const IDLE_START = 1;
	private const IDLE_MAX = 15;

	/** How often the stale-row reaper runs, in seconds of wall clock. */
	private const REAP_EVERY = 300;

	/** Whether a signal has asked this worker to finish and stop. */
	private bool $stopping = false;

	public function __construct(
		private RequestQueueService $requestQueueService,
		private StreamQueueService $streamQueueService,
		private ActivityService $activityService,
		private ConfigService $configService,
		private CacheActorService $cacheActorService,
		private IDBConnection $connection,
		private LoggerInterface $logger,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:worker')
			->setDescription('Deliver queued activities continuously, rather than once every twelve minutes')
			->addOption(
				'once', '', InputOption::VALUE_NONE,
				'drain what is queued now and return, instead of waiting for more'
			)
			->addOption(
				'max-seconds', '', InputOption::VALUE_REQUIRED,
				'stop after this long, so a supervisor can restart it; 0 runs until stopped', '0'
			)
			->addOption(
				'quiet-log', '', InputOption::VALUE_NONE,
				'do not print a line per batch'
			);
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$once = (bool)$input->getOption('once');
		$maxSeconds = max(0, (int)$input->getOption('max-seconds'));
		$deadline = ($maxSeconds > 0) ? time() + $maxSeconds : 0;
		$chatty = !$input->getOption('quiet-log');

		$this->listenForStop();

		$idle = self::IDLE_START;
		$reapedAt = 0;
		$delivered = 0;

		while (!$this->stopping) {
			if ($deadline > 0 && time() >= $deadline) {
				break;
			}

			if (time() - $reapedAt >= self::REAP_EVERY) {
				// a worker that died mid-delivery left its row `running`, where
				// nothing retries it — for either queue
				$this->requestQueueService->reapStaleRunning();
				$this->streamQueueService->reapStaleRunning();
				$this->requestQueueService->purgeFinished();
				$reapedAt = time();
			}

			// a long-running process has no "one request" to bound the
			// per-request memo, so it is emptied between batches
			$this->cacheActorService->forgetMemoised();

			$did = $this->deliverBatch() + $this->cacheBatch();
			$delivered += $did;

			if ($did > 0) {
				$idle = self::IDLE_START;
				if ($chatty) {
					$output->writeln('  ' . $did . ' item(s)');
				}
				continue;
			}

			if ($once) {
				break;
			}

			// nothing to do: wait, and wait longer each time, so an idle
			// instance is not a database asked the same question in a loop
			sleep($idle);
			$idle = min(self::IDLE_MAX, $idle * 2);
		}

		$output->writeln($delivered . ' item(s) handled' . ($this->stopping ? ' (stopped)' : ''));

		return 0;
	}

	/**
	 * One batch of deliveries.
	 *
	 * A row whose host has an open circuit breaker is not attempted, and must
	 * not count: a batch of nothing but those is skipped in milliseconds, and
	 * counting it as work made the loop `continue` without ever sleeping —
	 * re-running the standby query thousands of times a second for as long as
	 * the breaker was open, which is up to an hour.
	 *
	 * @return int how many rows were attempted
	 */
	private function deliverBatch(): int {
		$requests = $this->requestQueueService->getRequestStandby();
		if ($requests === []) {
			return 0;
		}

		$this->activityService->manageInit();
		$done = 0;

		foreach ($requests as $request) {
			if ($this->stopping) {
				break;
			}

			$request->setTimeout(ActivityService::TIMEOUT_SERVICE);
			try {
				if ($this->activityService->manageRequest($request)) {
					$done++;
				}
			} catch (Throwable $e) {
				// one row costs that row and no more; without this the rest of
				// the batch went with it and the row stayed `running`
				$this->logger->warning('[social:worker] delivery of ' . $request->getToken() . ' failed', [
					'exception' => $e, 'token' => $request->getToken(),
				]);
				$this->release($request);
				$done++;
			}
		}

		return $done;
	}

	/**
	 * One batch of thread-resolution work: the parents, authors and previews of
	 * what has arrived.
	 *
	 * @return int how many rows were attempted
	 */
	private function cacheBatch(): int {
		$total = 0;
		$items = $this->streamQueueService->getRequestStandby($total);
		$done = 0;

		foreach ($items as $item) {
			if ($this->stopping) {
				break;
			}

			try {
				$this->streamQueueService->manageStreamQueue($item);
			} catch (Throwable $e) {
				$this->logger->warning('[social:worker] could not resolve a queued item', [
					'exception' => $e,
				]);
			}
			$done++;
		}

		return $done;
	}

	private function release(RequestQueue $request): void {
		try {
			$this->requestQueueService->endRequest($request, false);
		} catch (Throwable $e) {
			// the database is what just failed; the stale reaper is the backstop
			$this->logger->warning('[social:worker] cannot return a row to standby', [
				'exception' => $e,
			]);
		}
	}

	/**
	 * Finish the row in hand, then stop.
	 *
	 * A delivery abandoned halfway is one the receiving server may already have
	 * taken, and the row would be retried against it. `pcntl` is not present on
	 * every PHP build, so a worker without it simply runs until it is killed —
	 * which is what it did before there was a signal handler.
	 */
	private function listenForStop(): void {
		if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
			return;
		}

		pcntl_async_signals(true);
		foreach ([SIGTERM, SIGINT] as $signal) {
			pcntl_signal($signal, function (): void {
				$this->stopping = true;
			});
		}
	}
}
