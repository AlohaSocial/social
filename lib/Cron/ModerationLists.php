<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\AccountService;
use OCA\Social\Service\ModerationList\ModerationListService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps the lists of accounts to mute or block people subscribe to in step:
 * every few hours, each subscriber's lists read again
 * (`ModerationListService::refresh()`).
 */
class ModerationLists extends TimedJob {
	private const INTERVAL = 6 * 3600;

	public function __construct(
		ITimeFactory $time,
		private ModerationListService $lists,
		private AccountService $accounts,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL);
	}

	#[\Override]
	protected function run($argument): void {
		foreach ($this->lists->subscribers() as $userId) {
			try {
				$this->lists->refresh($this->accounts->getActorFromUserId($userId));
			} catch (Throwable $e) {
				$this->logger->info('Moderation lists not refreshed', ['user' => $userId, 'exception' => $e]);
			}
		}
	}
}
