<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use DateTimeImmutable;
use OCA\Social\Service\NotificationDeliveryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Raises the notification digests whose time has come, every five minutes
 * like `ScheduledPosts`, since a digest is due at a chosen time of day. Only
 * the users flagged by `NotificationDeliveryService` are visited.
 */
class NotificationDigests extends TimedJob {
	/** How long one run may take before leaving the rest to the next one. */
	public const MAX_DURATION = 240;

	/** The most users one run visits. */
	public const MAX_USERS = 1000;

	/**
	 * The most digests one user gets per run: one per missed time is owed after
	 * downtime, but not all at once.
	 */
	public const MAX_PER_USER = 4;

	public function __construct(
		ITimeFactory $time,
		private NotificationDeliveryService $deliveryService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(5 * 60);
	}

	#[\Override]
	protected function run($argument) {
		try {
			$raised = $this->runDue();
			if ($raised > 0) {
				$this->logger->info('[Cron\\NotificationDigests] raised ' . $raised . ' notification digests');
			}
		} catch (Throwable $e) {
			// an exception out of a job is what makes Nextcloud stop
			// scheduling it; the next run is the retry
			$this->logger->warning(
				'[Cron\\NotificationDigests] the run failed: ' . $e->getMessage(),
				['exception' => $e]
			);
		}
	}

	/**
	 * Visits every scheduled user and raises what they are owed. Each digest is
	 * cut at the time it was due, not when the job ran, so windows neither
	 * overlap nor leave gaps.
	 *
	 * @return int digests raised
	 */
	public function runDue(): int {
		$started = $this->time->getTime();
		$raised = 0;
		$visited = 0;

		foreach ($this->deliveryService->scheduledUserIds() as $userId) {
			if ($visited >= self::MAX_USERS || $this->time->getTime() - $started > self::MAX_DURATION) {
				$this->logger->info('[Cron\\NotificationDigests] stopping before the end; the rest follows on the next run');
				break;
			}
			$visited++;

			try {
				$raised += $this->digestsOwedTo($userId);
			} catch (Throwable $e) {
				$this->logger->warning(
					'[Cron\\NotificationDigests] could not raise the digest of ' . $userId . ': ' . $e->getMessage(),
					['exception' => $e]
				);
			}
		}

		return $raised;
	}

	/** @return int digests raised for this user */
	private function digestsOwedTo(string $userId): int {
		$delivery = $this->deliveryService->of($userId);
		if (!$delivery->isScheduled()) {
			// the flag outlived the setting; nothing is due
			return 0;
		}

		$now = $this->time->getTime();
		$last = $this->deliveryService->lastDigestAt($userId);
		if ($last === 0) {
			// no clock yet: start it rather than report everything since 1970
			$this->deliveryService->setLastDigestAt($userId, $now);

			return 0;
		}

		$zone = $this->deliveryService->timezoneOf($userId);
		$raised = 0;
		for ($i = 0; $i < self::MAX_PER_USER; $i++) {
			$due = $delivery->nextDigestAfter(new DateTimeImmutable('@' . $last), $zone);
			if ($due === null || $due->getTimestamp() > $now) {
				break;
			}

			if ($this->deliveryService->digestFor($userId, $due) !== null) {
				$raised++;
			}
			$last = $due->getTimestamp();
		}

		return $raised;
	}
}
