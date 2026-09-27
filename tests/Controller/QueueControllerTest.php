<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\QueueController;
use OCA\Social\Exceptions\SignatureException;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\RequestQueueService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class QueueControllerTest extends TestCase {
	private RequestQueueService|MockObject $requestQueueService;
	private ActivityService|MockObject $activityService;
	private LoggerInterface|MockObject $logger;
	private TestableQueueController $controller;

	protected function setUp(): void {
		$this->requestQueueService = $this->createMock(RequestQueueService::class);
		$this->activityService = $this->createMock(ActivityService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->controller = new TestableQueueController(
			$this->createMock(IRequest::class),
			$this->requestQueueService,
			$this->activityService,
			$this->logger
		);
	}

	public function testAnUnknownTokenGetsARealResponseInsteadOfExit(): void {
		$this->requestQueueService->method('getRequestFromToken')
			->with('tok', RequestQueue::STATUS_STANDBY)
			->willReturn([]);
		$this->activityService->expects($this->never())->method('manageInit');

		$response = $this->controller->asyncForRequest('tok');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testTheDeliveryLoopItselfCannotBeUnitTested(): void {
		$this->markTestSkipped(
			'With queued requests, asyncForRequest() detaches the HTTP response (TAsync::async()) and ends in '
			. 'exit() because the connection is already closed; that path needs an end-to-end test. The body '
			. 'drain is ActivityService::manageRequests(), tested there; what is left here is handBack(), below.'
		);
	}

	/**
	 * The catch here named SocialAppConfigException only, so a corrupt signing
	 * key or a database error left the row `running` — never retried, never
	 * counted against MAX_TRIES until the stale reaper freed it an hour later —
	 * and abandoned the rest of the batch with it.
	 */
	public function testAFailedDeliveryIsReturnedToStandbyInsteadOfLeftRunning(): void {
		$request = (new RequestQueue())->setToken('tok');
		$ended = [];
		$this->requestQueueService->expects($this->once())->method('endRequest')
			->willReturnCallback(function (RequestQueue $queue, bool $success) use (&$ended): void {
				$ended[] = [$queue->getToken(), $success];
			});
		$logged = [];
		$this->logger->expects($this->once())->method('warning')
			->willReturnCallback(function (string $message) use (&$logged): void {
				$logged[] = $message;
			});

		$this->controller->handBackOne($request, new SignatureException('cannot sign: the private key is empty'));

		$this->assertSame([['tok', false]], $ended);
		$this->assertStringContainsString('SignatureException', $logged[0]);
	}

	public function testADeliveryThatCannotEvenBeEndedIsLoggedAndSwallowed(): void {
		$this->requestQueueService->method('endRequest')
			->willThrowException(new \RuntimeException('the database is gone'));

		$this->logger->expects($this->exactly(2))->method('warning');

		// the caller is a detached worker draining a batch: it has to go on
		$this->controller->handBackOne((new RequestQueue())->setToken('tok'), new \RuntimeException('boom'));
		$this->addToAssertionCount(1);
	}
}

/**
 * asyncForRequest() ends in exit(), so the drain cannot be entered from a test;
 * this exposes what it does with a row that failed, which is where the error
 * handling lives.
 */
class TestableQueueController extends QueueController {
	public function handBackOne(RequestQueue $request, \Throwable $e): void {
		$this->handBack($request, $e);
	}
}
