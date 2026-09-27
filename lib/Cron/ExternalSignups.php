<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\ExternalSignupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Forgets registrations whose email was never confirmed, and invitations
 * that expired or were used up. Hourly: a registration reserves its handle
 * and its email address until it is gone.
 */
class ExternalSignups extends TimedJob {
	private const INTERVAL = 3600;

	public function __construct(
		ITimeFactory $time,
		private ExternalSignupService $signupService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);

		$this->setInterval(self::INTERVAL);
	}

	#[\Override]
	protected function run($argument): void {
		try {
			$removed = $this->signupService->purge();
			if ($removed > 0) {
				$this->logger->debug('[Cron\\ExternalSignups] forgot expired registrations and invitations', [
					'count' => $removed,
				]);
			}
		} catch (Throwable $e) {
			$this->logger->warning('[Cron\\ExternalSignups] could not forget expired registrations', [
				'exception' => $e,
			]);
		}
	}
}
