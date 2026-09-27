<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Cron\Queue;
use OCA\Social\Exceptions\SignatureException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Model\StreamQueue;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\RequestQueueService;
use OCA\Social\Service\StreamQueueService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class QueueTest extends TestCase {
	private const NOW = 1700000000;

	/** @var RequestQueueService&MockObject */
	private $requestQueueService;
	/** @var StreamQueueService&MockObject */
	private $streamQueueService;
	/** @var ActivityService&MockObject */
	private $activityService;
	/** @var IJobList&Stub */
	private $jobList;
	/** @var LoggerInterface&MockObject */
	private $logger;
	private Queue $job;

	protected function setUp(): void {
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$this->requestQueueService = $this->createMock(RequestQueueService::class);
		$this->streamQueueService = $this->createMock(StreamQueueService::class);
		$this->activityService = $this->createMock(ActivityService::class);
		$this->jobList = $this->createStub(IJobList::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->job = new Queue(
			$time,
			$this->requestQueueService,
			$this->streamQueueService,
			$this->activityService,
			$this->logger
		);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	public function testIsATimedJobRunningEveryTwelveMinutes(): void {
		$this->assertInstanceOf(TimedJob::class, $this->job);

		$interval = new \ReflectionProperty(TimedJob::class, 'interval');

		$this->assertSame(12 * 60, $interval->getValue($this->job));
	}

	/**
	 * The mocked drain: hands every row to `$deliver`, and a row it throws for
	 * to the failure callback the job passed — which is what
	 * `ActivityService::manageRequests()` does with a failure it does not end
	 * itself.
	 *
	 * @param callable(RequestQueue): void $deliver
	 */
	private function draining(callable $deliver): void {
		$this->activityService->method('manageRequests')
			->willReturnCallback(function (array $requests, int $deadline, callable $failed) use ($deliver): int {
				foreach ($requests as $request) {
					try {
						$deliver($request);
					} catch (\Throwable $e) {
						$failed($request, $e);
					}
				}

				return count($requests);
			});
	}

	public function testStandbyRequestsAreSentWithTheServiceTimeout(): void {
		$first = (new RequestQueue())->setToken('t1');
		$second = (new RequestQueue())->setToken('t2');
		$this->requestQueueService->method('getRequestStandby')->willReturn([$first, $second]);
		$this->streamQueueService->method('getRequestStandby')->willReturn([]);
		$this->activityService->expects($this->atLeastOnce())->method('manageInit');
		$managed = [];
		$this->activityService->expects($this->once())->method('manageRequests')
			->willReturnCallback(function (array $requests, int $deadline) use (&$managed): int {
				foreach ($requests as $request) {
					$this->assertSame(ActivityService::TIMEOUT_SERVICE, $request->getTimeout());
					$managed[] = $request->getToken();
				}
				$this->assertGreaterThan(time(), $deadline);

				return count($requests);
			});

		$this->job->start($this->jobList);

		$this->assertSame(['t1', 't2'], $managed);
	}

	/**
	 * A batch of 200 goes out twenty servers at a time and takes seconds, so
	 * a run with time left takes the next one rather than idling out the rest
	 * of its budget — and stops when nothing new is due.
	 */
	public function testTheRunTakesBatchAfterBatchUntilNothingNewIsDue(): void {
		$batches = [
			[(new RequestQueue())->setId(1), (new RequestQueue())->setId(2)],
			[(new RequestQueue())->setId(3)],
			// a row the first batch could not end, handed out again
			[(new RequestQueue())->setId(2)],
		];
		$this->requestQueueService->method('getRequestStandby')
			->willReturnOnConsecutiveCalls(...$batches);
		$this->streamQueueService->method('getRequestStandby')->willReturn([]);
		$handed = [];
		$this->activityService->expects($this->exactly(2))->method('manageRequests')
			->willReturnCallback(function (array $requests) use (&$handed): int {
				$handed[] = array_map(fn (RequestQueue $r): int => $r->getId(), $requests);

				return count($requests);
			});

		$this->job->start($this->jobList);

		$this->assertSame([[1, 2], [3]], $handed);
	}

	/** A queue that refills as fast as it drains still ends the run. */
	public function testARunTakesNoMoreThanTheBatchCeiling(): void {
		$next = 0;
		$this->requestQueueService->method('getRequestStandby')
			->willReturnCallback(function () use (&$next): array {
				return [(new RequestQueue())->setId(++$next)];
			});
		$this->streamQueueService->method('getRequestStandby')->willReturn([]);
		$this->activityService->expects($this->exactly(Queue::MAX_BATCHES))->method('manageRequests')
			->willReturn(1);

		$this->job->start($this->jobList);
	}

	/**
	 * The catch used to name SocialAppConfigException and nothing else, but
	 * a delivery also lets a SignatureException out (openssl_sign on an
	 * empty or corrupt private key) and an \OCP\DB\Exception escape the calls
	 * that end the row. The row is already `running` by then, so it was neither
	 * retried nor counted against MAX_TRIES until the stale reaper freed it an
	 * hour later — and every row left in the 200-row batch was skipped.
	 */
	public function testARowThatFailsInAnUnexpectedWayCostsOnlyThatRow(): void {
		$bad = (new RequestQueue())->setToken('bad');
		$good = (new RequestQueue())->setToken('good');
		$this->requestQueueService->method('getRequestStandby')->willReturn([$bad, $good]);
		$this->streamQueueService->method('getRequestStandby')->willReturn([]);
		$sent = [];
		$this->draining(function (RequestQueue $request) use (&$sent): void {
			if ($request->getToken() === 'bad') {
				throw new SignatureException('cannot sign: the private key is empty');
			}
			$sent[] = $request->getToken();
		});

		// and the row goes back to standby, so it is retried and eventually
		// exhausts its tries instead of sitting `running` forever
		$ended = [];
		$this->requestQueueService->expects($this->once())->method('endRequest')
			->willReturnCallback(function (RequestQueue $request, bool $success) use (&$ended): void {
				$ended[] = [$request->getToken(), $success];
			});

		$logged = [];
		$this->logger->expects($this->once())->method('warning')
			->willReturnCallback(function (string $message) use (&$logged): void {
				$logged[] = $message;
			});

		$this->job->start($this->jobList);

		$this->assertSame(['good'], $sent, 'the rest of the batch is still delivered');
		$this->assertSame([['bad', false]], $ended);
		$this->assertStringContainsString('bad', $logged[0]);
		$this->assertStringContainsString('SignatureException', $logged[0]);
	}

	public function testAFailureToReleaseARowIsLoggedAndDoesNotStopTheQueue(): void {
		$bad = (new RequestQueue())->setToken('bad');
		$good = (new RequestQueue())->setToken('good');
		$this->requestQueueService->method('getRequestStandby')->willReturn([$bad, $good]);
		$this->streamQueueService->method('getRequestStandby')->willReturn([]);
		$sent = [];
		$this->draining(function (RequestQueue $request) use (&$sent): void {
			if ($request->getToken() === 'bad') {
				throw new \RuntimeException('boom');
			}
			$sent[] = $request->getToken();
		});
		$this->requestQueueService->method('endRequest')
			->willThrowException(new \RuntimeException('the database is gone'));

		$this->logger->expects($this->exactly(2))->method('warning');

		$this->job->start($this->jobList);

		$this->assertSame(['good'], $sent);
	}

	public function testAMisconfiguredRequestDoesNotStopTheQueue(): void {
		$bad = (new RequestQueue())->setToken('bad');
		$good = (new RequestQueue())->setToken('good');
		$this->requestQueueService->method('getRequestStandby')->willReturn([$bad, $good]);
		$this->streamQueueService->method('getRequestStandby')->willReturn([]);
		$sent = [];
		$this->draining(function (RequestQueue $request) use (&$sent): void {
			if ($request->getToken() === 'bad') {
				throw new SocialAppConfigException();
			}
			$sent[] = $request->getToken();
		});

		// the catch used to be empty: a misconfigured app dropped every
		// delivery without a line anywhere
		$logged = [];
		$this->logger->expects($this->once())->method('warning')
			->willReturnCallback(function (string $message, array $context = []) use (&$logged): void {
				$logged[] = $message;
			});

		$this->job->start($this->jobList);

		$this->assertSame(['good'], $sent);
		$this->assertCount(1, $logged);
		$this->assertStringContainsString('bad', $logged[0]);
		$this->assertStringContainsString('not configured', $logged[0]);
	}

	public function testStandbyStreamItemsAreProcessedAfterTheRequests(): void {
		$this->requestQueueService->method('getRequestStandby')->willReturn([]);
		$items = [$this->createMock(StreamQueue::class), $this->createMock(StreamQueue::class)];
		$this->streamQueueService->method('getRequestStandby')->willReturn($items);
		$processed = [];
		$this->streamQueueService->expects($this->exactly(2))->method('manageStreamQueue')
			->willReturnCallback(function (StreamQueue $item) use (&$processed): void {
				$processed[] = $item;
			});

		$this->job->start($this->jobList);

		$this->assertSame($items, $processed);
	}

	/**
	 * The loop had no per-item handling at all, so one item that threw ended
	 * the whole pass — with its own row left `running`, where until now
	 * nothing ever looked at it again.
	 */
	public function testAStreamItemThatThrowsCostsOnlyThatItem(): void {
		$bad = new StreamQueue('tok', StreamQueue::TYPE_CACHE, 'bad');
		$good = new StreamQueue('tok', StreamQueue::TYPE_CACHE, 'good');
		$this->requestQueueService->method('getRequestStandby')->willReturn([]);
		$this->streamQueueService->method('getRequestStandby')->willReturn([$bad, $good]);
		$resolved = [];
		$this->streamQueueService->method('manageStreamQueue')
			->willReturnCallback(function (StreamQueue $item) use (&$resolved): void {
				if ($item->getStreamId() === 'bad') {
					throw new \RuntimeException('boom');
				}
				$resolved[] = $item->getStreamId();
			});
		$this->logger->expects($this->once())->method('warning');

		$this->job->start($this->jobList);

		$this->assertSame(['good'], $resolved);
	}

	public function testStrandedRunningStreamItemsAreReapedBeforeTheBatch(): void {
		$this->requestQueueService->method('getRequestStandby')->willReturn([]);
		$this->streamQueueService->method('getRequestStandby')->willReturn([]);
		$this->streamQueueService->expects($this->once())->method('reapStaleRunning');

		$this->job->start($this->jobList);
	}

	public function testRunIsSkippedWhenTheLastRunIsTooRecent(): void {
		$this->job->setLastRun(self::NOW - 60);
		$this->requestQueueService->expects($this->never())->method('getRequestStandby');
		$this->streamQueueService->expects($this->never())->method('getRequestStandby');

		$this->job->start($this->jobList);
	}
}
