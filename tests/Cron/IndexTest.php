<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Cron\Index;
use OCA\Social\Service\IndexService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class IndexTest extends TestCase {
	private IndexService|MockObject $indexService;
	private LoggerInterface|MockObject $logger;
	private Index $job;

	protected function setUp(): void {
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_757_937_600);
		$this->indexService = $this->createMock(IndexService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->job = new Index($time, $this->indexService, $this->logger);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	public function testItIsARegularBoundedTimedJob(): void {
		$this->assertInstanceOf(TimedJob::class, $this->job);
		$interval = new \ReflectionProperty(TimedJob::class, 'interval');
		$this->assertSame(5 * 60, $interval->getValue($this->job));

		$this->indexService->expects($this->once())->method('repairNextChunk')->willReturn(500);
		$this->logger->expects($this->once())->method('info')
			->with($this->stringContains('repaired stream side indexes'), ['count' => 500]);
		$this->job->start($this->createStub(IJobList::class));
	}

	public function testAnEmptyPassDoesNotProduceAnInformationLog(): void {
		$this->indexService->expects($this->once())->method('repairNextChunk')->willReturn(0);
		$this->logger->expects($this->never())->method('info');
		$this->job->start($this->createStub(IJobList::class));
	}

	public function testAServiceFailureIsLoggedForTheNextScheduledRetry(): void {
		$failure = new RuntimeException('query failed');
		$this->indexService->expects($this->once())->method('repairNextChunk')->willThrowException($failure);
		$this->logger->expects($this->once())->method('error')
			->with($this->stringContains('repair pass failed'), ['exception' => $failure]);
		$this->job->start($this->createStub(IJobList::class));
	}
}
