<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Cron\MediaSweep;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\MediaPurgeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class MediaSweepTest extends TestCase {
	private MediaPurgeService|MockObject $mediaPurgeService;
	private DocumentService|MockObject $documentService;
	private LoggerInterface|Stub $logger;
	private IJobList|Stub $jobList;
	private MediaSweep $job;

	protected function setUp(): void {
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_757_937_600);
		$this->mediaPurgeService = $this->createMock(MediaPurgeService::class);
		$this->documentService = $this->createMock(DocumentService::class);
		$this->logger = $this->createStub(LoggerInterface::class);
		$this->jobList = $this->createStub(IJobList::class);

		$this->job = new MediaSweep(
			$time, $this->mediaPurgeService, $this->documentService, $this->logger
		);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	public function testItIsADailyTimedJob(): void {
		$this->assertInstanceOf(TimedJob::class, $this->job);

		$interval = new \ReflectionProperty(TimedJob::class, 'interval');

		$this->assertSame(86400, $interval->getValue($this->job));
	}

	public function testBothPassesRunAndAreBounded(): void {
		// page after page, for a bounded time: one page a day fell behind
		// every prune that orphaned more than that
		$this->mediaPurgeService->expects($this->once())->method('sweepOrphans')
			->with(MediaSweep::DAYS, MediaSweep::BATCH, 1_757_937_600 + MediaSweep::SECONDS)->willReturn(3);
		$this->documentService->expects($this->once())->method('fillMissingMediaTypes')
			->with(MediaSweep::BATCH)->willReturn(1);
		$this->mediaPurgeService->expects($this->once())->method('purgeCacheAsConfigured')
			->with(false, 1_757_937_600 + MediaSweep::PURGE_SECONDS)->willReturn(null);

		$this->job->start($this->jobList);
	}

	/**
	 * The media cache purge is what bounds the copies of posts that are
	 * kept; it runs on every pass, whatever the two before it did.
	 */
	public function testTheMediaCachePurgeRunsEvenWhenAnEarlierPassFails(): void {
		$this->mediaPurgeService->method('sweepOrphans')
			->willThrowException(new \RuntimeException('the database said no'));
		$this->documentService->method('fillMissingMediaTypes')
			->willThrowException(new \RuntimeException('so did the disk'));
		$this->mediaPurgeService->expects($this->once())->method('purgeCacheAsConfigured')
			->willReturn(['files' => 12]);

		$this->job->start($this->jobList);
	}

	/**
	 * Neither pass is urgent and neither loses anything by waiting, but one
	 * that throws must not take the other with it.
	 */
	public function testAFailingPassIsNamedAndTheOtherStillRuns(): void {
		$this->mediaPurgeService->method('sweepOrphans')
			->willThrowException(new \RuntimeException('the database said no'));
		$this->documentService->expects($this->once())->method('fillMissingMediaTypes');
		$steps = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message, array $context) use (&$steps): void {
				$steps[] = $context['step'] ?? '';
			}
		);

		$this->job->start($this->jobList);

		$this->assertSame(['sweepOrphans'], $steps);
	}
}
