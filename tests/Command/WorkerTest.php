<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Command;

use OCA\Social\Command\Worker;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\RequestQueueService;
use OCA\Social\Service\StreamQueueService;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * What one `social:worker --once` pass counts as work.
 *
 * The distinction matters because the loop only sleeps on a pass that did
 * nothing: a row whose host has an open circuit breaker is returned by the
 * standby query and skipped without being touched, so counting it kept the
 * loop running the standby query — an UPDATE and a sixteen-branch SELECT —
 * thousands of times a second for as long as the breaker was open, which is up
 * to an hour.
 */
#[AllowMockObjectsWithoutExpectations]
class WorkerTest extends TestCase {
	private RequestQueueService|MockObject $requestQueueService;
	private StreamQueueService|Stub $streamQueueService;
	private ActivityService|Stub $activityService;
	private CommandTester $tester;

	protected function setUp(): void {
		$this->requestQueueService = $this->createMock(RequestQueueService::class);
		$this->streamQueueService = $this->createStub(StreamQueueService::class);
		$this->activityService = $this->createStub(ActivityService::class);
		$this->streamQueueService->method('getRequestStandby')->willReturn([]);

		$this->tester = new CommandTester(new Worker(
			$this->requestQueueService,
			$this->streamQueueService,
			$this->activityService,
			$this->createStub(ConfigService::class),
			$this->createStub(CacheActorService::class),
			$this->createStub(IDBConnection::class),
			new NullLogger()
		));
	}

	/**
	 * One batch, then an empty queue: enough for the loop to end either way, so
	 * the count is what is being asserted rather than the worker terminating.
	 *
	 * @param RequestQueue[] $batch
	 */
	private function standbyOnce(array $batch): void {
		$passes = 0;
		$this->requestQueueService->method('getRequestStandby')
			->willReturnCallback(function () use (&$passes, $batch): array {
				return ($passes++ === 0) ? $batch : [];
			});
	}

	private function drain(): string {
		$this->assertSame(0, $this->tester->execute(['--once' => true, '--quiet-log' => true]));

		return $this->tester->getDisplay();
	}

	public function testARowSkippedForAnOpenCircuitBreakerIsNotCountedAsWork(): void {
		$this->standbyOnce([(new RequestQueue())->setToken('t1')]);
		$this->activityService->method('manageRequest')->willReturn(false);

		$this->assertStringContainsString('0 item(s) handled', $this->drain());
	}

	public function testADeliveredRowIsCounted(): void {
		$this->standbyOnce([(new RequestQueue())->setToken('t1')]);
		$this->activityService->method('manageRequest')->willReturn(true);

		$this->assertStringContainsString('1 item(s) handled', $this->drain());
	}

	/** A row that threw was attempted, and is handed back to the queue. */
	public function testARowThatFailedUnexpectedlyIsCountedAndReleased(): void {
		$this->standbyOnce([(new RequestQueue())->setToken('t1')]);
		$this->activityService->method('manageRequest')
			->willThrowException(new \RuntimeException('boom'));
		$this->requestQueueService->expects($this->once())
			->method('endRequest')
			->with($this->isInstanceOf(RequestQueue::class), false);

		$this->assertStringContainsString('1 item(s) handled', $this->drain());
	}
}
