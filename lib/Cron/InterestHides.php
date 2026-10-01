<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\InterestService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Forgets the "less like this" hides that can no longer keep anything out.
 *
 * A hide names a post so the reader's For you feed leaves it out, and
 * the feed only looks back a few days: once a post is older than that it
 * cannot come back into the feed, and the row that hid it is dead weight.
 * Once a day is plenty — a leftover row costs a line in a small table.
 */
class InterestHides extends TimedJob {
	private const INTERVAL = 86400;

	public function __construct(
		ITimeFactory $time,
		private InterestService $interestService,
		private LoggerInterface $logger,
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
	}
}
