<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Cron\Queue;
use OCA\Social\Cron\ScheduledPosts;
use OCA\Social\Service\BackgroundHealthService;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Whether the work that happens away from a request is happening.
 *
 * When cron stops, the symptom is a post that never arrives and a disk that
 * never shrinks; nothing in the app said so. What is asserted here is mostly
 * the judgement — what counts as late, and what an instance installed this
 * morning looks like, because a page that cries wolf on a fresh install is a
 * page an administrator learns to ignore.
 */
class BackgroundHealthServiceTest extends TestCase {
	private IJobList|Stub $jobList;
	private BackgroundHealthService $service;

	/** @var array<class-string, int> the jobs that are registered, and their last run */
	private array $registered = [];
	/** true when the job list cannot answer at all */
	private bool $broken = false;

	protected function setUp(): void {
		parent::setUp();

		$this->jobList = $this->createStub(IJobList::class);
		$this->jobList->method('getJobsIterator')->willReturnCallback(
			function (?string $class): iterable {
				if ($this->broken) {
					throw new RuntimeException('an older job list');
				}

				if (!array_key_exists($class, $this->registered)) {
					return [];
				}

				$job = $this->createStub(IJob::class);
				$job->method('getLastRun')->willReturn($this->registered[$class]);

				return [$job];
			}
		);

		$this->service = new BackgroundHealthService($this->jobList);
	}

	/** @return array<string, array<string, mixed>> the rows, keyed by class */
	private function rows(): array {
		$rows = [];
		foreach ($this->service->summary()['jobs'] as $row) {
			$rows[$row['class']] = $row;
		}

		return $rows;
	}

	public function testEveryJobThisAppSchedulesIsAccountedFor(): void {
		$summary = $this->service->summary();

		$this->assertCount(8, $summary['jobs']);
		$this->assertArrayHasKey(Queue::class, $this->rows());
	}

	public function testAJobThatRanRecentlyIsNotLate(): void {
		$this->registered[Queue::class] = time() - 60;

		$this->assertFalse($this->rows()[Queue::class]['late']);
		$this->assertSame(0, $this->service->summary()['late']);
	}

	/**
	 * Nextcloud's cron is itself periodic: a job on a five-minute interval, on
	 * an instance whose system timer fires every fifteen, is always two
	 * intervals behind and has nothing wrong with it.
	 */
	public function testAJobIsNotLateForBeingOneIntervalBehind(): void {
		$this->registered[ScheduledPosts::class] = time() - 600;

		$this->assertFalse($this->rows()[ScheduledPosts::class]['late']);
	}

	public function testAJobThatHasNotRunInDaysIsLate(): void {
		$this->registered[Queue::class] = time() - 86400;

		$row = $this->rows()[Queue::class];
		$this->assertTrue($row['late']);
		$this->assertSame(1, $this->service->summary()['late']);
		$this->assertGreaterThanOrEqual(86400, $this->service->summary()['worst']);
	}

	/**
	 * A job that has never run on an instance installed this morning is not
	 * late, and a page that cries wolf on a fresh install is one an
	 * administrator learns to ignore. The two cases are told apart by there
	 * being no row at all rather than by the timestamp.
	 */
	public function testAJobThatIsNotRegisteredIsNotReportedAsLate(): void {
		$summary = $this->service->summary();

		$this->assertSame(0, $summary['late']);
		$this->assertFalse($this->rows()[Queue::class]['registered']);
	}

	public function testAJobRegisteredButNeverRunIsNotLateEither(): void {
		$this->registered[Queue::class] = 0;

		$row = $this->rows()[Queue::class];
		$this->assertTrue($row['registered']);
		$this->assertSame(0, $row['last']);
		$this->assertFalse($row['late']);
	}

	/** A number nobody measured is worse than saying nothing. */
	public function testAJobListThatCannotAnswerSaysSoRatherThanGuessing(): void {
		$this->broken = true;

		$summary = $this->service->summary();

		$this->assertSame(0, $summary['late']);
		foreach ($summary['jobs'] as $row) {
			$this->assertFalse($row['registered']);
		}
	}

	/**
	 * The interval is written down here as well as on the job, and a number
	 * written twice is one somebody has to keep in step.
	 */
	public function testTheStatedIntervalsMatchTheJobs(): void {
		$intervals = [];
		foreach ($this->service->summary()['jobs'] as $row) {
			$intervals[$row['class']] = $row['interval'];
		}

		foreach ($intervals as $class => $interval) {
			$source = (string)file_get_contents(
				dirname(__DIR__, 2) . '/lib/Cron/' . (new \ReflectionClass($class))->getShortName() . '.php'
			);
			preg_match('/setInterval\(([^)]+)\)/', $source, $match);
			$stated = str_replace(['self::INTERVAL', ' '], '', $match[1] ?? '');
			if ($stated === '') {
				// the job states it as a constant; read that instead
				preg_match('/INTERVAL = ([0-9*\s]+);/', $source, $constant);
				$stated = str_replace(' ', '', $constant[1] ?? '');
			}

			$this->assertSame(
				$interval,
				(int)eval('return ' . $stated . ';'),
				$class . ' runs on a different interval than this service says'
			);
		}
	}
}
