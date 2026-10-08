<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Atproto\Reader\FeedPoller;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads Bluesky: the feeds of the authors somebody here follows, every two
 * minutes, as many watches as are due within the request ceiling.
 */
class AtprotoSync extends TimedJob {
	private const INTERVAL = 2 * 60;

	public function __construct(
		ITimeFactory $time,
		private AtprotoConfig $config,
		private FeedPoller $poller,
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
		try {
			$result = $this->poller->poll();
			if ($result['stored'] > 0) {
				$this->logger->info('Bluesky feeds read', $result);
			}
		} catch (Throwable $e) {
			$this->logger->warning('Bluesky feed sync failed', ['exception' => $e]);
		}
	}
}
