<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Cron\Cache;
use OCA\Social\Cron\ExpiredStories;
use OCA\Social\Cron\GifPack;
use OCA\Social\Cron\Ladder;
use OCA\Social\Cron\MediaSweep;
use OCA\Social\Cron\Queue;
use OCA\Social\Cron\ScheduledPosts;
use OCA\Social\Cron\Transcode;
use OCP\BackgroundJob\IJobList;
use Throwable;

/**
 * When each of this app's background jobs last ran, and whether that is
 * late, since a stopped cron otherwise only shows as posts that never arrive.
 *
 * Read from Nextcloud's job list (`last_run`, written by the scheduler), so a
 * job that was never registered shows as absent. Late means more than the
 * job's interval times a factor, as intervals range from minutes to a day.
 */
class BackgroundHealthService {
	/**
	 * How many of its own intervals a job may go without running before it is
	 * called late; three, because a five-minute job under a fifteen-minute
	 * system cron is always two intervals behind.
	 */
	private const LATE_FACTOR = 3;

	/**
	 * The jobs and what they are for, outbound first, tidying last. Intervals are
	 * stated here because `TimedJob` keeps them private; a test keeps them in step.
	 *
	 * @var array<class-string, array{interval: int, label: string}>
	 */
	private const JOBS = [
		Queue::class => ['interval' => 720, 'label' => 'Delivery to other servers'],
		ScheduledPosts::class => ['interval' => 300, 'label' => 'Posts scheduled for later'],
		Cache::class => ['interval' => 720, 'label' => 'Reading what arrived'],
		ExpiredStories::class => ['interval' => 3600, 'label' => 'Expiring 24-hour shorts'],
		Transcode::class => ['interval' => 900, 'label' => 'Video transcoding'],
		Ladder::class => ['interval' => 1800, 'label' => 'Video renditions'],
		GifPack::class => ['interval' => 900, 'label' => 'The GIF pack'],
		MediaSweep::class => ['interval' => 86400, 'label' => 'Media retention and storage figures'],
	];

	public function __construct(
		private IJobList $jobList,
	) {
	}

	/**
	 * @return array{
	 *     jobs: list<array{class: string, label: string, interval: int, last: int, late: bool, registered: bool}>,
	 *     late: int, worst: int
	 * }
	 */
	public function summary(): array {
		$now = time();
		$jobs = [];
		$late = 0;
		$worst = 0;

		foreach (self::JOBS as $class => $about) {
			$last = $this->lastRun($class);
			$registered = $last !== null;
			$since = ($last === null || $last === 0) ? 0 : $now - $last;
			// A job that never ran is only late when it has a row; no row means not
			// registered.
			$isLate = $registered && $last > 0 && $since > $about['interval'] * self::LATE_FACTOR;

			$jobs[] = [
				'class' => $class,
				'label' => $about['label'],
				'interval' => $about['interval'],
				'last' => (int)$last,
				'late' => $isLate,
				'registered' => $registered,
			];

			if ($isLate) {
				$late++;
				$worst = max($worst, $since);
			}
		}

		return ['jobs' => $jobs, 'late' => $late, 'worst' => $worst];
	}

	/** When this job last ran, or null where it is not registered at all. */
	private function lastRun(string $class): ?int {
		try {
			foreach ($this->jobList->getJobsIterator($class, 1, 0) as $job) {
				return $job->getLastRun();
			}
		} catch (Throwable $e) {
			// An older or unreadable job list: shown as not registered.
			return null;
		}

		return null;
	}
}
