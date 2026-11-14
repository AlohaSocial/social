<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\QueueController;
use OCA\Social\Exceptions\SignatureException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Security\AsyncRequestSigner;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\RequestQueueService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class QueueControllerTest extends TestCase {
	private RequestQueueService|MockObject $requestQueueService;
	private ActivityService|MockObject $activityService;
	private LoggerInterface|MockObject $logger;
	private TestableQueueController $controller;
	private AsyncRequestSigner $signer;
	private CurlService|MockObject $curlService;
	/** What the request carries in the signature header. */
	private string $signature = '';
	/** What the request carries in the round header. */
	private string $round = '';

	protected function setUp(): void {
		$this->requestQueueService = $this->createMock(RequestQueueService::class);
		$this->activityService = $this->createMock(ActivityService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$crypto = $this->createStub(ICrypto::class);
		$crypto->method('calculateHMAC')
			->willReturnCallback(static fn (string $message): string => hash_hmac('sha512', $message, 'secret', true));
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(static fn (): int => time());
		$this->signer = new AsyncRequestSigner($crypto, $time);
		$request = $this->createStub(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			fn (string $name): string => match ($name) {
				AsyncRequestSigner::HEADER => $this->signature,
				AsyncRequestSigner::ROUND_HEADER => $this->round,
				default => '',
			}
		);
		$this->curlService = $this->createMock(CurlService::class);
		$this->signature = $this->signer->sign('tok');
		$this->controller = new TestableQueueController(
			$request,
			$this->requestQueueService,
			$this->activityService,
			$this->logger,
			$this->signer,
			$this->curlService
		);
	}

	/**
	 * The route is public and a token is written to the log on every failed
	 * delivery, so holding one must not be enough to have a worker spend a
	 * minute and a half delivering.
	 */
	public function testACallThisServerDidNotSignIsRefusedBeforeTheQueueIsRead(): void {
		foreach (['', 'deadbeef', $this->signer->sign('another-token')] as $signature) {
			$this->signature = $signature;
			$this->requestQueueService->expects($this->never())->method('getRequestFromToken');

			$response = $this->controller->asyncForRequest('tok');

			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
			$this->assertTrue($response->isThrottled(), 'a refusal counts against the caller');
		}
	}

	public function testAnUnknownTokenGetsARealResponseInsteadOfExit(): void {
		$this->requestQueueService->method('getRequestFromToken')
			->with('tok', RequestQueue::STATUS_STANDBY)
			->willReturn([]);
		$this->activityService->expects($this->never())->method('manageInit');

		$response = $this->controller->asyncForRequest('tok');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	/**
	 * A post waiting for its video is held by `last`; the async drain must not
	 * deliver it early just because somebody asked for its token.
	 */
	public function testRowsThatAreHeldBackAreNotDrained(): void {
		$held = new RequestQueue('{}', new InstancePath('https://remote.example/inbox', InstancePath::TYPE_INBOX), 'alice');
		$held->setLast(time() + 600);
		$this->requestQueueService->method('getRequestFromToken')->willReturn([$held]);
		$this->activityService->expects($this->never())->method('manageRequests');

		$response = $this->controller->asyncForRequest('tok');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testTheDeliveryLoopItselfCannotBeUnitTested(): void {
		$this->markTestSkipped(
			'With queued requests, asyncForRequest() detaches the HTTP response (TAsync::async()) and ends in '
			. 'exit() because the connection is already closed; that path needs an end-to-end test. What it '
			. 'delivers is drain(), and what fails is handBack(), both below.'
		);
	}

	/**
	 * A post's fan-out is delivered here by a web worker, one per post. The
	 * whole of it, for up to ninety seconds, held a PHP-FPM worker and a
	 * database connection per post; a few posts a minute from accounts with
	 * many follower hosts and the web server stopped answering. One wave is
	 * delivered here, briefly, and the rest is left on standby for
	 * `Cron\Queue`.
	 */
	public function testTheAsyncDrainDeliversOneBoundedWaveAndLeavesTheRestToCron(): void {
		$requests = [];
		for ($i = 0; $i < QueueController::ASYNC_ROWS + 25; $i++) {
			$requests[] = new RequestQueue('{}', new InstancePath('https://host' . $i . '.example/inbox', InstancePath::TYPE_INBOX), 'alice');
		}
		$this->activityService->expects($this->once())->method('manageInit');
		$this->activityService->expects($this->once())->method('manageRequests')->willReturnCallback(
			function (array $queues, int $deadline) use ($requests): int {
				$this->assertSame(array_slice($requests, 0, QueueController::ASYNC_ROWS), $queues);
				$this->assertLessThanOrEqual(time() + QueueController::MAX_DURATION, $deadline);

				return count($queues);
			}
		);

		$this->controller->drainNow($requests);

		$this->assertLessThanOrEqual(20, QueueController::MAX_DURATION, 'a worker is held for seconds, not minutes');
		$this->assertLessThanOrEqual(40, QueueController::ASYNC_ROWS);
		foreach (array_slice($requests, QueueController::ASYNC_ROWS) as $left) {
			$this->assertSame(5, $left->getTimeout(), 'what is left is not touched, and stays on standby');
		}
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
		$context = [];
		$this->logger->expects($this->once())->method('warning')
			->willReturnCallback(function (string $message, array $with) use (&$logged, &$context): void {
				$logged[] = $message;
				$context = $with;
			});

		$this->controller->handBackOne($request, new SignatureException('cannot sign: the private key is empty'));

		$this->assertSame([['tok', false]], $ended);
		$this->assertStringContainsString('SignatureException', $logged[0]);
		$this->assertStringContainsString(AsyncRequestSigner::fingerprint('tok'), $logged[0]);
		$this->assertStringNotContainsString('tok ', $logged[0]);
		$this->assertSame(AsyncRequestSigner::fingerprint('tok'), $context['token']);
	}

	public function testADeliveryThatCannotEvenBeEndedIsLoggedAndSwallowed(): void {
		$this->requestQueueService->method('endRequest')
			->willThrowException(new \RuntimeException('the database is gone'));

		$this->logger->expects($this->exactly(2))->method('warning');

		// the caller is a detached worker draining a batch: it has to go on
		$this->controller->handBackOne((new RequestQueue())->setToken('tok'), new \RuntimeException('boom'));
		$this->addToAssertionCount(1);
	}
	private function row(int $tries = 0, int $last = 0): RequestQueue {
		$row = new RequestQueue('{}', new InstancePath('https://host.example/inbox', InstancePath::TYPE_INBOX), 'alice');
		$row->setTries($tries);
		$row->setLast($last);

		return $row;
	}

	/**
	 * The round is part of what is signed: a signature for the first round
	 * does not start the fifth, and a round past the last is refused however
	 * it is signed.
	 */
	public function testARoundIsRefusedUnlessItsOwnNumberWasSigned(): void {
		$this->requestQueueService->expects($this->never())->method('getRequestFromToken');
		$cases = [
			['5', $this->signer->sign('tok')],
			['5', $this->signer->sign('tok', 4)],
			[(string)QueueController::MAX_ROUNDS, $this->signer->sign('tok', QueueController::MAX_ROUNDS)],
			['-1', $this->signer->sign('tok')],
			['two', $this->signer->sign('tok')],
		];
		foreach ($cases as [$round, $signature]) {
			$this->round = $round;
			$this->signature = $signature;

			$response = $this->controller->asyncForRequest('tok');

			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus(), 'round ' . $round);
		}
	}

	public function testASignedFollowUpRoundIsServed(): void {
		$this->round = '3';
		$this->signature = $this->signer->sign('tok', 3);
		$this->requestQueueService->expects($this->once())->method('getRequestFromToken')->willReturn([]);

		$this->assertSame(Http::STATUS_OK, $this->controller->asyncForRequest('tok')->getStatus());
	}

	/**
	 * A post followed from 150 servers: the first round reaches 40 of them,
	 * and the rest is no longer left for a cron that runs every twelve
	 * minutes -- the round starts the next one.
	 */
	public function testARoundThatLeavesUntriedRowsStartsTheNext(): void {
		$this->requestQueueService->method('getRequestFromToken')->willReturn([$this->row(), $this->row(1)]);
		$this->curlService->expects($this->once())->method('asyncWithToken')->with('tok', 1);

		$this->controller->nextRoundNow('tok', 0);
	}

	/**
	 * What failed in a round waits for the cron's backoff, and what is held
	 * back waits for its time: neither starts another round, so a token
	 * whose remaining hosts are down does not spin.
	 */
	public function testOnlyFailedOrHeldRowsStartNoRound(): void {
		$this->requestQueueService->method('getRequestFromToken')
			->willReturn([$this->row(1), $this->row(3), $this->row(0, time() + 600)]);
		$this->curlService->expects($this->never())->method('asyncWithToken');

		$this->controller->nextRoundNow('tok', 0);
	}

	public function testTheLastRoundLeavesTheRestToTheCron(): void {
		$this->requestQueueService->method('getRequestFromToken')->willReturn([$this->row()]);
		$this->curlService->expects($this->never())->method('asyncWithToken');

		$this->controller->nextRoundNow('tok', QueueController::MAX_ROUNDS - 1);
	}

	public function testANextRoundThatCannotBeStartedIsLoggedAndLeftToTheCron(): void {
		$this->requestQueueService->method('getRequestFromToken')->willReturn([$this->row()]);
		$this->curlService->method('asyncWithToken')->willThrowException(new SocialAppConfigException('no url'));
		$this->logger->expects($this->once())->method('warning');

		$this->controller->nextRoundNow('tok', 2);
	}

	/**
	 * The first round delivers every due row, the one tried before included;
	 * a follow-up round only rows never tried, as the tried ones failed in a
	 * round before it.
	 */
	public function testAFollowUpRoundTakesOnlyRowsNeverTried(): void {
		$fresh = $this->row();
		$failed = $this->row(1);
		$held = $this->row(0, time() + 600);
		$this->requestQueueService->method('getRequestFromToken')
			->with('tok', RequestQueue::STATUS_STANDBY)
			->willReturn([$fresh, $failed, $held]);

		$this->assertSame([$fresh, $failed], $this->controller->dueNow('tok', 0));
		$this->assertSame([$fresh], $this->controller->dueNow('tok', 1));
	}

	public function testTheRoundsReachSomeHundredsOfInboxes(): void {
		$this->assertGreaterThanOrEqual(150, QueueController::ASYNC_ROWS * QueueController::MAX_ROUNDS);
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

	/** @param list<RequestQueue> $requests */
	public function drainNow(array $requests): void {
		$this->drain($requests);
	}

	public function nextRoundNow(string $token, int $round): void {
		$this->nextRound($token, $round);
	}

	/** @return list<RequestQueue> */
	public function dueNow(string $token, int $round): array {
		return $this->due($token, $round);
	}
}
