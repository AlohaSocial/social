<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\RequestQueueRequest;
use OCA\Social\Db\StreamQueueRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\RequestQueueService;
use OCA\Social\Service\StreamPruneService;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Retention for other servers' posts.
 *
 * What is asserted here is the part that decides whether anything is deleted
 * at all, and the queue sweep beside it. The prunable query itself is not:
 * it is assembled against `IQueryBuilder`, whose `expr()` returns an
 * `IExpressionBuilder` that cannot be loaded — let alone doubled — in a suite
 * that runs without a database, which is the same reason `FakeExpressions`
 * exists next door. Its protection rules are the app's most destructive piece
 * of SQL and belong in an integration test against a real schema.
 */
#[AllowMockObjectsWithoutExpectations]
class StreamPruneServiceTest extends TestCase {
	private IDBConnection|Stub $connection;
	private ConfigService|Stub $configService;
	private StreamRequest|MockObject $streamRequest;
	private RequestQueueRequest|MockObject $requestQueueRequest;
	private StreamQueueRequest|Stub $streamQueueRequest;
	private StreamPruneService $service;

	/** the `retention_days` app value */
	private string $retention = '0';
	/** the `notification_retention_days` app value */
	private string $notificationRetention = '0';

	protected function setUp(): void {
		parent::setUp();

		// every query this service builds goes through the connection, so a
		// test that reaches one fails loudly rather than deleting anything
		$this->connection = $this->createStub(IDBConnection::class);
		$this->connection->method('getQueryBuilder')
			->willThrowException(new RuntimeException('a query was built where none was expected'));

		$this->configService = $this->createStub(ConfigService::class);
		$this->configService->method('getAppValue')->willReturnCallback(
			fn (string $key): string => match ($key) {
				ConfigService::SOCIAL_RETENTION_DAYS => $this->retention,
				ConfigService::SOCIAL_NOTIFICATION_RETENTION_DAYS => $this->notificationRetention,
				default => '',
			}
		);

		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->requestQueueRequest = $this->createMock(RequestQueueRequest::class);
		$this->streamQueueRequest = $this->createStub(StreamQueueRequest::class);

		$this->service = new StreamPruneService(
			$this->connection,
			$this->configService,
			$this->streamRequest,
			$this->requestQueueRequest,
			$this->streamQueueRequest,
			new NullLogger()
		);
	}

	public function testRetentionIsReadFromTheAppValue(): void {
		$this->retention = '30';

		$this->assertSame(30, $this->service->getRetentionDays());
	}

	public function testAnInstanceThatHasNotSetOneKeepsEverything(): void {
		$this->assertSame(0, $this->service->getRetentionDays());
	}

	/**
	 * Off by default, and off means no query at all: this is the one job in
	 * the app that deletes other people's posts.
	 */
	public function testNothingIsDeletedWhileRetentionIsOff(): void {
		$this->streamRequest->expects($this->never())->method('deleteRelatedTo');

		$this->assertSame(['streams' => 0, 'documents' => 0, 'notifications' => 0], $this->service->prune());
	}

	public function testANegativeNumberOfDaysIsAlsoOff(): void {
		$this->assertSame(['streams' => 0, 'documents' => 0, 'notifications' => 0], $this->service->prune(-1));
	}

	/**
	 * A caller passing 0 explicitly means the same as the app value being 0,
	 * and must not be read as "delete everything older than now".
	 */
	public function testAskingForZeroDaysDeletesNothingRatherThanEverything(): void {
		$this->retention = '30';

		$this->assertSame(['streams' => 0, 'documents' => 0, 'notifications' => 0], $this->service->prune(0));
	}

	/**
	 * The queue sweep is not retention: exhausted deliveries and finished
	 * cache items are rows nothing will ever act on again, on every instance,
	 * and the only thing that removes them is this pass.
	 */
	public function testTheQueuesArePurgedEvenWhileRetentionIsOff(): void {
		$this->requestQueueRequest->expects($this->once())->method('abandonExhausted');
		$this->requestQueueRequest->expects($this->once())->method('deleteFinished');

		$this->service->prune();
	}

	public function testADryRunLeavesTheQueuesAlone(): void {
		$this->retention = '30';
		$this->requestQueueRequest->expects($this->never())->method('abandonExhausted');
		$this->requestQueueRequest->expects($this->never())->method('deleteFinished');
		// the prunable count is built on the connection, which this suite
		// cannot double; the sweeps are decided before it is reached
		$this->streamRequest->method('countNotificationsBefore')->willReturn(2);

		try {
			$this->service->prune(null, true);
		} catch (RuntimeException) {
		}
	}

	/**
	 * Notifications have a retention of their own, applied whether or not
	 * remote statuses are kept: they are local rows about a local user's
	 * post, read once from the bell and never again.
	 */
	public function testNotificationsPastTheirOwnRetentionGoWhileRetentionIsOff(): void {
		$this->notificationRetention = '90';
		$cutoff = null;
		$pages = [['n1', 'n2'], []];
		$this->streamRequest->method('getNotificationPrimsBefore')
			->willReturnCallback(function (\DateTime $before, int $limit) use (&$cutoff, &$pages): array {
				$cutoff = $before;
				$this->assertSame(StreamPruneService::CHUNK, $limit);

				return array_shift($pages) ?? [];
			});
		$this->streamRequest->expects($this->once())->method('deleteRelatedTo')->with(['n1', 'n2']);
		$this->streamRequest->expects($this->once())->method('deleteByPrims')->with(['n1', 'n2']);

		$this->assertSame(['streams' => 0, 'documents' => 0, 'notifications' => 2], $this->service->prune());
		$this->assertEqualsWithDelta(
			(new \DateTime('90 days ago'))->getTimestamp(), $cutoff->getTimestamp(), 5
		);
	}

	public function testNotificationRetentionCanBeTurnedOff(): void {
		$this->notificationRetention = '0';
		$this->streamRequest->expects($this->never())->method('getNotificationPrimsBefore');
		$this->streamRequest->expects($this->never())->method('deleteByPrims');

		$this->assertSame(0, $this->service->pruneNotifications());
	}

	public function testTheNotificationCeilingIsRespected(): void {
		$this->notificationRetention = '90';
		$this->streamRequest->method('getNotificationPrimsBefore')
			->willReturnCallback(static fn (\DateTime $before, int $limit): array
				=> array_map(static fn (int $i): string => 'n' . $i, range(1, $limit)));

		// 1200 asked for: two full chunks and one of 200, then stop
		$this->assertSame(1200, $this->service->pruneNotifications(false, 1200));
	}

	public function testADryRunCountsTheNotificationsAndDeletesNone(): void {
		$this->notificationRetention = '90';
		$this->streamRequest->method('countNotificationsBefore')->willReturn(7);
		$this->streamRequest->expects($this->never())->method('deleteByPrims');

		$this->assertSame(7, $this->service->pruneNotifications(true));
	}

	/**
	 * The outbound queue keeps finished rows for a while so a post can say
	 * where it got to; pruning applies that same retention and nothing
	 * shorter.
	 */
	public function testTheQueueSweepAppliesTheDeliveryRetentionAndNotSomethingShorter(): void {
		$before = 0;
		$this->requestQueueRequest->expects($this->once())->method('abandonExhausted');
		$this->requestQueueRequest->method('deleteFinished')
			->willReturnCallback(function (int $cutoff) use (&$before): int {
				$before = $cutoff;

				return 4;
			});
		$this->streamQueueRequest->method('deleteExhausted')->willReturn(2);
		$this->streamQueueRequest->method('deleteCompleted')->willReturn(3);

		$pruned = $this->service->pruneQueues();

		$this->assertSame(['requests' => 4, 'items' => 5], $pruned);
		$this->assertEqualsWithDelta(
			time() - RequestQueueService::RETENTION_SECONDS, $before, 5
		);
	}

	/**
	 * Both drains skip exhausted and completed rows, so nothing else will ever
	 * remove them — but a sweep that cannot run is not a reason to fail the
	 * cron job it is part of.
	 */
	public function testAQueueSweepThatFailsIsReportedAsHavingDoneNothing(): void {
		$this->requestQueueRequest->method('abandonExhausted')
			->willThrowException(new \Exception('the database went away'));

		$this->assertSame(['requests' => 0, 'items' => 0], $this->service->pruneQueues());
	}

	public function testTheChunkSizeIsTheOneTheDeleteIsWrittenFor(): void {
		// the delete is `IN (…)` over this many ids at a time; raising it past
		// what a driver will bind is how that query starts failing on one
		// database and not another
		$this->assertSame(500, StreamPruneService::CHUNK);
	}
}
