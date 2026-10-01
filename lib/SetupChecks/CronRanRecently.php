<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\SetupChecks;

use OCA\Social\Cron\Queue;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Whether the job that delivers everything this instance sends has run lately.
 *
 * Every post, follow and like leaves through `Cron\Queue`. When the server's
 * cron is not running, or is running but never reaches this job, nothing is
 * delivered and nothing says so: the author sees their post in their own
 * timeline and nowhere else. The server's own cron check only says whether
 * *any* job ran; this one asks about the job that matters here.
 */
class CronRanRecently implements ISetupCheck {
	public const DOC = Docs::ADMIN_GUIDE . '#the-delivery-job-has-not-run';

	/**
	 * How long since the last run before it is worth a word, in seconds.
	 *
	 * The job runs every twelve minutes. A single missed slot is ordinary —
	 * a long run of another job, a reboot — so this waits for five of them.
	 */
	public const MAX_AGE = 3600;

	public function __construct(
		private IL10N $l10n,
		private IJobList $jobList,
		private ITimeFactory $timeFactory,
	) {
	}

	#[\Override]
	public function getCategory(): string {
		return 'system';
	}

	#[\Override]
	public function getName(): string {
		return $this->l10n->t('Aloha Social: delivery job');
	}

	#[\Override]
	public function run(): SetupResult {
		$jobs = $this->jobList->getJobs(Queue::class, 1, 0);
		if ($jobs === []) {
			return SetupResult::error(
				$this->l10n->t('The job that delivers what this instance sends (%1$s) is not registered, so nothing posted here reaches another server. Disabling and enabling the app registers it again.', [Queue::class]),
				self::DOC
			);
		}

		$lastRun = $jobs[0]->getLastRun();
		if ($lastRun === 0) {
			return SetupResult::warning(
				$this->l10n->t('The job that delivers what this instance sends has never run. Check that background jobs are set to Cron and that the cron is running.'),
				self::DOC
			);
		}

		$age = $this->timeFactory->getTime() - $lastRun;
		if ($age > self::MAX_AGE) {
			return SetupResult::warning(
				$this->l10n->t('The job that delivers what this instance sends last ran %1$s minutes ago. It is meant to run every 12. Until it does, nothing posted here leaves the server.', [(string)intdiv($age, 60)]),
				self::DOC
			);
		}

		return SetupResult::success(
			$this->l10n->t('The delivery job last ran %1$s minutes ago.', [(string)intdiv(max(0, $age), 60)])
		);
	}
}
