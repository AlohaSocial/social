<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\DomainPurgeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Removes what a newly blocked instance already sent us. Queued with the
 * domain by `FediverseService::addAddress()`, so not registered in
 * `info.xml`.
 *
 * It does a bounded number of batches per run and re-queues itself while
 * anything is left; each pass finds its work by what is still stored
 * (`DomainPurgeService`), so a dead pass loses nothing. It does not re-check
 * the block: a half-purged instance is worse than a purged one.
 */
class DomainPurge extends QueuedJob {
	/**
	 * Batches per run, of `DomainPurgeService::BATCH` accounts each: a small
	 * instance finishes in one run, a large one never hogs a cron slot.
	 */
	private const STEPS_PER_RUN = 10;

	public function __construct(
		ITimeFactory $time,
		private DomainPurgeService $domainPurgeService,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run($argument): void {
		$domain = is_array($argument) ? (string)($argument['domain'] ?? '') : '';
		if ($domain === '') {
			return;
		}

		try {
			$purged = $this->domainPurgeService->purge($domain, self::STEPS_PER_RUN);
			if ($this->domainPurgeService->hasRemains($domain)) {
				$this->jobList->add(self::class, ['domain' => $domain]);
			}

			$this->logger->info('[Cron\\DomainPurge] purged a blocked instance', [
				'domain' => $domain, 'accounts' => $purged,
			]);
		} catch (\Throwable $e) {
			// a purge that throws must not be retried forever: it is logged,
			// the rows it did delete stay deleted, and an admin can finish it
			// with occ social:domain:purge
			$this->logger->warning('[Cron\\DomainPurge] could not purge ' . $domain, [
				'domain' => $domain, 'exception' => $e,
			]);
		}
	}
}
