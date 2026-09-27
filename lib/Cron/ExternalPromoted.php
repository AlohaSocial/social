<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\DAV\CardDAV\SyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IUserManager;
use OCP\Server;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes the system address book card of a user who was just promoted from
 * external to local.
 *
 * External users are kept out of the system address book. The promotion
 * happens in a request that still knows the user on the backend it came
 * from, so the card is written here, in a request that sees the new one.
 */
class ExternalPromoted extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run($argument): void {
		$uid = is_array($argument) ? (string)($argument['uid'] ?? '') : '';
		$user = ($uid === '') ? null : $this->userManager->get($uid);
		if ($user === null || !class_exists(SyncService::class)) {
			return;
		}

		try {
			Server::get(SyncService::class)->updateUser($user);
		} catch (Throwable $e) {
			$this->logger->warning('[Cron\\ExternalPromoted] could not write the address book card', [
				'uid' => $uid,
				'exception' => $e,
			]);
		}
	}
}
