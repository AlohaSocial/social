<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Db\AtprotoRequest;
use OCA\Social\Model\Atproto\AtprotoWatch;
use OCA\Social\Service\Atproto\AtprotoIngress;
use OCA\Social\Service\ConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the AT-Proto accounts local accounts follow.
 *
 * A pass takes the watches whose `next_sync` has come — which is every
 * actor's own interval, pushed out on a failure by its own backoff — so an
 * actor nobody follows costs nothing and a PDS that is down costs one
 * request per backoff step rather than one per cron run. With nothing to
 * read the pass is one query and one setting, and an instance with AT-Proto
 * switched off does not even that.
 *
 * This job only decides *when* and *whether*: the reading, the mapping and
 * the storing are `AtprotoIngress`, which is where an error is caught and
 * turned into the failure count this class writes back.
 */
class AtprotoSync extends TimedJob {
	/** watches one pass reads before it leaves the rest for the next one */
	private const BATCH = 25;

	public function __construct(
		ITimeFactory $time,
		private ?AtprotoRequest $atprotoRequest = null,
		private ?AtprotoIngress $ingress = null,
		private ?ConfigService $configService = null,
		private ?LoggerInterface $logger = null,
	) {
		parent::__construct($time);

		// per minute: what decides how often an actor is read is the watch's
		// own `next_sync`, not this interval — this is only how soon a watch
		// that has come due is picked up
		$this->setInterval(60);
	}

	/**
	 * @param mixed $argument
	 */
	#[\Override]
	protected function run($argument) {
		if ($this->atprotoRequest === null || $this->ingress === null || $this->configService === null) {
			return;
		}

		if ($this->configService->getAppValue(ConfigService::SOCIAL_ATPROTO_ENABLED) !== '1') {
			return;
		}

		$interval = max(
			AtprotoWatch::MIN_INTERVAL,
			(int)$this->configService->getAppValue(ConfigService::SOCIAL_ATPROTO_SYNC_INTERVAL)
		);
		$now = $this->time->getTime();

		foreach ($this->atprotoRequest->getDueWatches($now, self::BATCH) as $watch) {
			$this->syncOne($watch, $interval);
		}
	}

	/**
	 * One actor's pass, and what it says about the next one.
	 *
	 * A failure never stops the rest of the batch: the watches are read in
	 * the order they came due, and one dead PDS would otherwise hold up every
	 * account behind it until it came back.
	 */
	private function syncOne(AtprotoWatch $watch, int $interval): void {
		$now = $this->time->getTime();

		try {
			$imported = $this->ingress->sync($watch);

			$watch
				->setLastSync($now)
				->setNextSync($now + $interval)
				->setFailures(0)
				->setImported($watch->getImported() + $imported)
				->setLastError('');

			if ($imported > 0) {
				$this->logger?->info('posts read from AT-Proto', [
					'handle' => $watch->getHandle(),
					'did' => $watch->getDid(),
					'imported' => $imported,
				]);
			}
		} catch (Throwable $e) {
			$failures = $watch->getFailures() + 1;
			$watch
				->setLastSync($now)
				->setFailures($failures)
				->setNextSync($now + max($interval, $this->backoff($failures)))
				->setLastError($e->getMessage());

			$this->logger?->info('could not read an AT-Proto account', [
				'handle' => $watch->getHandle(),
				'did' => $watch->getDid(),
				'failures' => $failures,
				'exception' => $e,
			]);
		}

		$this->atprotoRequest?->saveWatch($watch);
	}

	/**
	 * How long to wait after the nth failure in a row: a minute, two, four
	 * … up to a day, so a PDS that is gone for good stops being asked about
	 * hourly instead of never.
	 */
	private function backoff(int $failures): int {
		return min(86400, AtprotoWatch::MIN_INTERVAL << min(max($failures - 1, 0), 10));
	}
}
