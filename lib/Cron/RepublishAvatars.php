<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Service\AccountService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends the actors of local accounts whose username differs from their login
 * to their followers' servers again, so those re-read the icon address,
 * which names the username rather than the login. `PAGE` accounts a run,
 * each run queueing the next; queued once by
 * `Migration\RepublishAvatarAddresses`, so not registered in `info.xml`.
 */
class RepublishAvatars extends QueuedJob {
	public const PAGE = 50;

	public function __construct(
		ITimeFactory $time,
		private ActorsRequest $actorsRequest,
		private AccountService $accountService,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run($argument): void {
		$after = is_array($argument) ? (string)($argument['after'] ?? '') : '';
		$page = $this->actorsRequest->getPage(self::PAGE, $after);

		foreach ($page as $actor) {
			$after = ActorsRequest::rowPrimOf($actor);
			$userId = $actor->getUserId();
			if ($actor->getDeleted() > 0 || $userId === ''
				|| str_starts_with($userId, 'team/') || str_starts_with($userId, 'channel/')
				|| strtolower($userId) === strtolower($actor->getPreferredUsername())) {
				continue;
			}

			try {
				$this->accountService->republishActor($actor);
			} catch (Throwable $e) {
				// one account that cannot be told is no reason to leave the rest
				$this->logger->warning('could not tell the followers the new avatar address', [
					'actor' => $actor->getId(), 'exception' => $e,
				]);
			}
		}

		if (count($page) === self::PAGE) {
			$this->jobList->add(self::class, ['after' => $after]);
		}
	}
}
