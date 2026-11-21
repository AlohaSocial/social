<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\AP;
use OCA\Social\Interfaces\Actor\PersonInterface;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Finishes detaching a deleted account from the posts that addressed it.
 *
 * Queued by `PersonInterface::delete()` when too many posts name the account
 * for the inbox request, which the peer would otherwise time out and resend
 * for ever. Not in `info.xml`, since it needs an actor argument. Like
 * `Cron\DomainPurge`, a pass does a bounded number of rows and re-queues
 * itself while any remain.
 */
class ActorCleanup extends QueuedJob {
	/**
	 * Rows per run, bounding the cron slot; what is left is the next run's.
	 */
	private const ROWS_PER_RUN = 2000;

	public function __construct(
		ITimeFactory $time,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run($argument): void {
		$actorId = is_array($argument) ? (string)($argument['actor'] ?? '') : '';
		if ($actorId === '') {
			return;
		}

		$actor = new Person();
		$actor->setId($actorId);

		try {
			/** @var PersonInterface $interface */
			$interface = AP::instance()->getInterfaceFromType(Person::TYPE);
			if ($interface->detachRecipient($actor, self::ROWS_PER_RUN)) {
				$interface->forgetRecipient($actorId);
				$this->logger->info('[Cron\\ActorCleanup] detached a deleted account', [
					'actor' => $actorId,
				]);

				return;
			}

			$this->jobList->add(self::class, ['actor' => $actorId]);
		} catch (\Throwable $e) {
			// not retried for ever: what is left is a post addressed to a gone
			// account, which the visibility filter treats as nobody
			$this->logger->warning('[Cron\\ActorCleanup] could not finish detaching an account', [
				'actor' => $actorId, 'exception' => $e,
			]);
		}
	}
}
