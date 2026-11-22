<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\BlocklistSubscriptionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Re-reads the block lists this instance follows, daily, as a list changes a
 * few times a week. Does nothing until an admin turns a source on.
 */
class BlocklistSync extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private ?BlocklistSubscriptionService $subscriptionService = null,
		private ?LoggerInterface $logger = null,
	) {
		parent::__construct($time);

		$this->setInterval(24 * 3600);
	}

	/**
	 * @param mixed $argument
	 */
	#[\Override]
	protected function run($argument) {
		if ($this->subscriptionService === null || !$this->subscriptionService->anyEnabled()) {
			return;
		}

		try {
			$results = $this->subscriptionService->fetchEnabled();
		} catch (Throwable $e) {
			$this->logger?->warning('could not refresh the subscribed block lists', ['exception' => $e]);

			return;
		}

		foreach ($results as $id => $result) {
			// At info: it changes what the instance federates with.
			$this->logger?->info('a subscribed block list was applied', [
				'source' => $id,
			] + $result);
		}
	}
}
