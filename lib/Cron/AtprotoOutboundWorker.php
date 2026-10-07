<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\RecordMapper\OutboundWorker as Worker;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

class AtprotoOutboundWorker extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private readonly Worker $worker,
		private readonly IdentityService $identities,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(60);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}
	#[\Override]
	protected function run($argument): void {
		if (!$this->identities->isEnabled()) {
			return;
		}
		try {
			$this->worker->run();
		} catch (\Throwable $e) {
			$this->logger->error('AT Protocol background work failed', ['exception' => $e]);
		}
	}
}
