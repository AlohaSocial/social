<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\StoryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Deletes the stories (shorts) whose day is up, rows and files. Every read
 * already filters on `expires_at`; this makes what is stored match what is
 * shown. Bounded per run; what is left is the next run's.
 */
class ExpiredStories extends TimedJob {
	/**
	 * Hourly: a story lives a day, and the read filter has already hidden it.
	 */
	private const INTERVAL = 3600;

	public function __construct(
		ITimeFactory $time,
		private StoryService $storyService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);

		$this->setInterval(self::INTERVAL);
	}

	#[\Override]
	protected function run($argument): void {
		try {
			$removed = $this->storyService->purgeExpired();
			if ($removed > 0) {
				$this->logger->debug('[Cron\\ExpiredStories] removed expired stories', [
					'count' => $removed,
				]);
			}
		} catch (Throwable $e) {
			// a sweep that fails is retried on the next pass; the read filter
			// means nothing expired is visible in the meantime
			$this->logger->warning('[Cron\\ExpiredStories] could not remove expired stories', [
				'exception' => $e,
			]);
		}
	}
}
