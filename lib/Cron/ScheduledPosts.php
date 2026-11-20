<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\ScheduledStatusService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Publishes the posts whose time has come. A job of its own because it is the
 * only one whose lateness a user notices.
 */
class ScheduledPosts extends TimedJob {
	/**
	 * Five minutes, matching `ScheduledStatusService::MIN_LEAD_TIME`, so a post
	 * is never later than the notice the API demanded for it. Deliberately not
	 * marked time-insensitive, which would let off-peak scheduling delay posts
	 * for hours.
	 */
	public function __construct(
		ITimeFactory $time,
		private ScheduledStatusService $scheduledStatusService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(5 * 60);
	}

	#[\Override]
	protected function run($argument) {
		try {
			$published = $this->scheduledStatusService->publishDue();
			if ($published > 0) {
				$this->logger->info('[Cron\\ScheduledPosts] published ' . $published . ' scheduled posts');
			}
		} catch (Throwable $e) {
			// publishDue() survives one post failing, so this is the database
			// or container failing; logged, not thrown, as a throwing job stops
			// being scheduled. The next run retries.
			$this->logger->warning(
				'[Cron\\ScheduledPosts] the run failed: ' . $e->getMessage(),
				['exception' => $e]
			);
		}
	}
}
