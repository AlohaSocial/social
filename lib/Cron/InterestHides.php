<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Db\RecommendationSignalRequest;
use OCA\Social\Service\InterestService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Forgets what For you no longer needs: "less like this" hides older than the
 * feed's window, and the recommendation event and exposure logs
 * (`RecommendationSignalRequest::trim()`). Daily.
 */
class InterestHides extends TimedJob {
	private const INTERVAL = 86400;

	public function __construct(
		ITimeFactory $time,
		private InterestService $interestService,
		private LoggerInterface $logger,
		private ?RecommendationSignalRequest $signals = null,
	) {
		parent::__construct($time);

		$this->setInterval(self::INTERVAL);
	}

	#[\Override]
	protected function run($argument): void {
		try {
			$removed = $this->interestService->purgeHides();
			if ($removed > 0) {
				$this->logger->debug('[Cron\\InterestHides] forgot hides older than the feed', [
					'count' => $removed,
				]);
			}
		} catch (Throwable $e) {
			// retried tomorrow; a hide that outlives its post keeps nothing out
			$this->logger->warning('[Cron\\InterestHides] could not forget old hides', [
				'exception' => $e,
			]);
		}

		try {
			$this->signals?->trim($this->time->getTime());
		} catch (Throwable $e) {
			$this->logger->warning('[Cron\\InterestHides] could not trim the recommendation logs', [
				'exception' => $e,
			]);
		}
	}
}
