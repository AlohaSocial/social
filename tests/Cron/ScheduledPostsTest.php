<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Cron\ScheduledPosts;
use OCA\Social\Service\ScheduledStatusService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class ScheduledPostsTest extends TestCase {
	private const NOW = 1757937600;

	/** @var ScheduledStatusService&MockObject */
	private $scheduledStatusService;
	/** @var IJobList&Stub */
	private $jobList;
	/** @var LoggerInterface&Stub */
	private $logger;
	private ScheduledPosts $job;

	protected function setUp(): void {
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$this->scheduledStatusService = $this->createMock(ScheduledStatusService::class);
		$this->jobList = $this->createStub(IJobList::class);
		$this->logger = $this->createStub(LoggerInterface::class);

		$this->job = new ScheduledPosts($time, $this->scheduledStatusService, $this->logger);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	/**
	 * A post may be published up to one cron period late, so a period longer
	 * than the notice the API demands for a scheduled post would promise a
	 * precision this app cannot keep.
	 */
	public function testTheJobRunsAtLeastAsOftenAsTheShortestNoticeItAccepts(): void {
		$this->assertInstanceOf(TimedJob::class, $this->job);

		$interval = new \ReflectionProperty(TimedJob::class, 'interval');

		$this->assertLessThanOrEqual(
			ScheduledStatusService::MIN_LEAD_TIME,
			$interval->getValue($this->job)
		);
	}

	/**
	 * An instance that defers non-urgent jobs to off-peak hours must still run
	 * this one: it would otherwise publish an evening post the next morning,
	 * which is precisely the failure the feature exists to prevent. A job is
	 * time-sensitive unless it says otherwise, so what this guards against is
	 * somebody later saying otherwise.
	 */
	public function testTheJobIsTimeSensitive(): void {
		$this->assertTrue($this->job->isTimeSensitive());
	}

	public function testTheRunPublishesWhatIsDue(): void {
		$this->scheduledStatusService->expects($this->once())
			->method('publishDue')
			->willReturn(3);

		$this->job->start($this->jobList);
	}

	/**
	 * An exception out of a job is what makes Nextcloud stop scheduling it, so
	 * a database that was away for one run must not cost every scheduled post
	 * from then on.
	 */
	public function testARunThatFailsOutrightIsLoggedRatherThanThrown(): void {
		$this->scheduledStatusService->method('publishDue')
			->willThrowException(new \RuntimeException('the database is gone'));
		$logged = [];
		$this->logger->method('warning')
			->willReturnCallback(function (string $message) use (&$logged): void {
				$logged[] = $message;
			});

		$this->job->start($this->jobList);

		$this->assertCount(1, $logged);
		$this->assertStringContainsString('the database is gone', $logged[0]);
	}
}
