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
 * When each of this app's background jobs last ran, and whether that is late.
 *
 * Almost everything this app does away from a request is a cron job: posts go
 * out, stories expire, media is swept, videos are transcoded, the storage
 * figures are taken. When cron stops — a broken system timer, a
 * `cron.php` that has been failing since an upgrade — nothing in the app says
 * so. The symptom is a post that never arrives and a disk that never shrinks,
 * and the only diagnostic was `occ social:check:install`, which an
 * administrator runs after they already suspect something.
 *
 * Read from Nextcloud's own job list rather than from anything this app keeps:
 * `last_run` is what the scheduler itself writes, so it cannot drift from what
 * actually happened, and a job that was never registered — an app upgraded
 * without its migrations having run — is visibly absent rather than silently
 * zero.
 *
 * **Late is a judgement, not a failure.** A job whose interval is twelve
 * minutes and which last ran twenty minutes ago is normal on an instance whose
 * cron runs every quarter of an hour; one that last ran a day ago is not. The
 * threshold is the job's own interval times a factor, which is the only shape
 * that works when the intervals in one app run from five minutes to a day.
 */
class BackgroundHealthService {
	/**
	 * How many of its own intervals a job may go without running before it is
	 * called late.
	 *
	 * Three, because Nextcloud's cron is itself periodic: a job on a
	 * five-minute interval, on an instance whose system timer fires every
	 * fifteen, is *always* two intervals behind and has nothing wrong with it.
	 */
	private const LATE_FACTOR = 3;

	/**
	 * The jobs and what they are for, in the order an administrator would
	 * read them: what leaves the instance first, what tidies up last.
	 *
	 * The interval is stated here rather than read off the job because
	 * `TimedJob` keeps it private — and because a number that has to be
	 * written down twice is a number somebody has to keep in step, which a
	 * test asserts.
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
			// a job that has never run on an instance installed this morning
			// is not late; one that has never run on an instance whose cron is
			// broken is, and the two are told apart by there being no row at
			// all rather than by the timestamp
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
			// an older job list, or one that cannot answer: "not registered"
			// is the honest thing to show rather than a number nobody measured
			return null;
		}

		return null;
	}
}
