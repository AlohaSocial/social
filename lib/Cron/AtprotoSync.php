<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Atproto\Reader\FeedPoller;
use OCA\Social\Atproto\Reader\NotificationPoller;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads Bluesky every two minutes: the feeds of the authors somebody here
 * follows, then what Bluesky did to local accounts, as many of each as are
 * due within the request ceiling.
 */
class AtprotoSync extends TimedJob {
	private const INTERVAL = 2 * 60;

	public function __construct(
		ITimeFactory $time,
		private AtprotoConfig $config,
		private FeedPoller $poller,
		private NotificationPoller $notifications,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL);
	}

	#[\Override]
	protected function run($argument): void {
		if (!$this->config->isEnabled()) {
			return;
		}
		foreach (['feeds' => fn (): array => $this->poller->poll(), 'notifications' => fn (): array => $this->notifications->poll()] as $step => $run) {
			try {
				$result = $run();
				if (($result['stored'] ?? 0) > 0 || ($result['handled'] ?? 0) > 0) {
					$this->logger->info('Bluesky ' . $step . ' read', $result);
				}
			} catch (Throwable $e) {
				$this->logger->warning('Bluesky sync step failed: ' . $step, ['exception' => $e]);
			}
		}
	}
}
