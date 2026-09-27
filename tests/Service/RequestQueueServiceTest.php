<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\RequestQueueRequest;
use OCA\Social\Exceptions\EmptyQueueException;
use OCA\Social\Exceptions\NoHighPriorityRequestException;
use OCA\Social\Exceptions\QueueStatusException;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Service\RequestQueueService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class RequestQueueServiceTest extends TestCase {
	private const AUTHOR = 'https://cloud.example.com/apps/social/@alice';

	private RequestQueueRequest|MockObject $requestQueueRequest;
	private RequestQueueService $service;

	protected function setUp(): void {
		$this->requestQueueRequest = $this->createMock(RequestQueueRequest::class);
		$this->service = new RequestQueueService(
			$this->requestQueueRequest,
		);
	}

	/** @return object{requests: RequestQueue[]} filled in when the queue is written */
	private function captureQueued(): object {
		$stored = new class {
			/** @var RequestQueue[] */
			public array $requests = [];
		};
		$this->requestQueueRequest->expects($this->once())
			->method('multiple')
			->willReturnCallback(function (array $requests) use ($stored): void {
				$stored->requests = $requests;
			});

		return $stored;
	}

	private function queued(int $priority, int $status = RequestQueue::STATUS_STANDBY): RequestQueue {
		$queue = new RequestQueue('{}', new InstancePath('https://remote.example/inbox', InstancePath::TYPE_INBOX, $priority), self::AUTHOR);
		$queue->setStatus($status);

		return $queue;
	}

	public function testGenerateRequestQueueCreatesOneEntryPerInstanceSharingAToken(): void {
		$note = new Note();
		$note->setId('https://cloud.example.com/apps/social/@alice/1');
		$note->setContent('hello');
		$inbox = new InstancePath('https://remote.example/users/bob/inbox', InstancePath::TYPE_INBOX, InstancePath::PRIORITY_HIGH);
		$shared = new InstancePath('https://other.example/inbox', InstancePath::TYPE_GLOBAL, InstancePath::PRIORITY_LOW);
		$stored = [];
		$this->requestQueueRequest->expects($this->once())
			->method('multiple')
			->willReturnCallback(function (array $requests) use (&$stored) {
				$stored = $requests;
			});

		$token = $this->service->generateRequestQueue([$inbox, $shared], $note, self::AUTHOR);

		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $token);
		$this->assertCount(2, $stored);
		foreach ($stored as $request) {
			$this->assertInstanceOf(RequestQueue::class, $request);
			$this->assertSame($token, $request->getToken());
			$this->assertSame(self::AUTHOR, $request->getAuthor());
			$this->assertSame(json_encode($note, JSON_UNESCAPED_SLASHES), $request->getActivity());
			$this->assertSame(RequestQueue::STATUS_STANDBY, $request->getStatus());
		}
		$this->assertSame($inbox, $stored[0]->getInstance());
		$this->assertSame(InstancePath::PRIORITY_HIGH, $stored[0]->getPriority());
		$this->assertSame($shared, $stored[1]->getInstance());
		$this->assertSame(InstancePath::PRIORITY_LOW, $stored[1]->getPriority());
	}

	/**
	 * Every mention adds an inbox, the follower fan-out adds one per instance
	 * and the boost/reply fan-out adds more: the same inbox arrives here
	 * several times for one activity, and used to become that many POSTs.
	 */
	public function testTheSameInboxIsQueuedOnlyOnce(): void {
		$shared = 'https://remote.example/inbox';
		$stored = $this->captureQueued();

		$token = $this->service->generateRequestQueueFromSource(
			[
				new InstancePath($shared, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_MEDIUM),
				new InstancePath($shared, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_MEDIUM),
				new InstancePath($shared, InstancePath::TYPE_GLOBAL, InstancePath::PRIORITY_LOW),
			],
			'{}',
			self::AUTHOR
		);

		$this->assertCount(1, $stored->requests);
		$this->assertSame($shared, $stored->requests[0]->getInstance()->getUri());
		$this->assertSame($token, $stored->requests[0]->getToken());
	}

	/**
	 * A direct mention is delivered inline; folding the follower fan-out to the
	 * same inbox into it must not demote it to the next cron run.
	 */
	public function testDeduplicationKeepsTheHighestPriority(): void {
		$shared = 'https://remote.example/inbox';
		$stored = $this->captureQueued();

		$this->service->generateRequestQueueFromSource(
			[
				new InstancePath($shared, InstancePath::TYPE_GLOBAL, InstancePath::PRIORITY_LOW),
				new InstancePath($shared, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_HIGH),
			],
			'{}',
			self::AUTHOR
		);

		$this->assertCount(1, $stored->requests);
		$this->assertSame(InstancePath::PRIORITY_HIGH, $stored->requests[0]->getPriority());
		$this->assertSame(InstancePath::TYPE_INBOX, $stored->requests[0]->getInstance()->getType());
	}

	/**
	 * `endpoints.sharedInbox` is optional: an instance that publishes none is
	 * addressed by one personal inbox per account, and those are different
	 * inboxes — uniquing them away would silently stop delivering to everybody
	 * but the first account on such a server.
	 */
	public function testPersonalInboxesOnOneHostAreEachDelivered(): void {
		$stored = $this->captureQueued();

		$this->service->generateRequestQueueFromSource(
			[
				new InstancePath('https://plain.example/users/bob/inbox', InstancePath::TYPE_INBOX, InstancePath::PRIORITY_MEDIUM),
				new InstancePath('https://plain.example/users/dan/inbox', InstancePath::TYPE_INBOX, InstancePath::PRIORITY_MEDIUM),
			],
			'{}',
			self::AUTHOR
		);

		$this->assertSame(
			['https://plain.example/users/bob/inbox', 'https://plain.example/users/dan/inbox'],
			array_map(static fn (RequestQueue $queue): string => $queue->getInstance()->getUri(), $stored->requests)
		);
	}

	public function testGenerateRequestQueueWithoutInstancesStoresNothingAndHasNoToken(): void {
		$this->requestQueueRequest->expects($this->once())->method('multiple')->with([]);

		$this->assertSame('', $this->service->generateRequestQueue([], new Note(), self::AUTHOR));
	}

	public function testGetPriorityRequestOnEmptyQueueThrows(): void {
		$this->requestQueueRequest->method('getFromToken')->with('tok')->willReturn([]);

		$this->expectException(EmptyQueueException::class);
		$this->service->getPriorityRequest('tok');
	}

	public function testTopPriorityIsAlwaysRunInline(): void {
		$top = $this->queued(InstancePath::PRIORITY_TOP);
		$this->requestQueueRequest->method('getFromToken')
			->willReturn([$top, $this->queued(InstancePath::PRIORITY_TOP)]);

		$this->assertSame($top, $this->service->getPriorityRequest('tok'));
	}

	public function testSingleHighPriorityRequestIsRunInline(): void {
		$high = $this->queued(InstancePath::PRIORITY_HIGH);
		$this->requestQueueRequest->method('getFromToken')->willReturn([$high]);

		$this->assertSame($high, $this->service->getPriorityRequest('tok'));
	}

	public function testHighPriorityFollowedByAStandbyRequestIsRunInline(): void {
		$high = $this->queued(InstancePath::PRIORITY_HIGH);
		$this->requestQueueRequest->method('getFromToken')
			->willReturn([$high, $this->queued(InstancePath::PRIORITY_LOW, RequestQueue::STATUS_STANDBY)]);

		$this->assertSame($high, $this->service->getPriorityRequest('tok'));
	}

	public function testSingleMediumPriorityRequestIsRunInline(): void {
		$medium = $this->queued(InstancePath::PRIORITY_MEDIUM);
		$this->requestQueueRequest->method('getFromToken')->willReturn([$medium]);

		$this->assertSame($medium, $this->service->getPriorityRequest('tok'));
	}

	public function testSeveralMediumPriorityRequestsAreDeferred(): void {
		$this->requestQueueRequest->method('getFromToken')
			->willReturn([$this->queued(InstancePath::PRIORITY_MEDIUM), $this->queued(InstancePath::PRIORITY_MEDIUM)]);

		$this->expectException(NoHighPriorityRequestException::class);
		$this->service->getPriorityRequest('tok');
	}

	public function testLowPriorityIsAlwaysDeferred(): void {
		$this->requestQueueRequest->method('getFromToken')->willReturn([$this->queued(InstancePath::PRIORITY_LOW)]);

		$this->expectException(NoHighPriorityRequestException::class);
		$this->service->getPriorityRequest('tok');
	}

	public function testGetRequestStandbyAppliesTheRetryBackoff(): void {
		$now = time();
		$fresh = $this->queued(InstancePath::PRIORITY_LOW)->setTries(0)->setLast($now - 1);
		$retriedRecently = $this->queued(InstancePath::PRIORITY_LOW)->setTries(3)->setLast($now - 10); // delay 96s
		$retriedLongAgo = $this->queued(InstancePath::PRIORITY_LOW)->setTries(3)->setLast($now - 120);
		$this->requestQueueRequest->method('getStandby')->willReturn([$fresh, $retriedRecently, $retriedLongAgo]);

		$total = 0;
		$ready = $this->service->getRequestStandby($total);

		$this->assertSame(3, $total);
		$this->assertSame([$fresh, $retriedLongAgo], $ready);
	}

	public function testGetRequestFromTokenSkipsTheDatabaseForAnEmptyToken(): void {
		$this->requestQueueRequest->expects($this->never())->method('getFromToken');

		$this->assertSame([], $this->service->getRequestFromToken(''));
	}

	public function testGetRequestFromTokenForwardsTheStatusFilter(): void {
		$queue = $this->queued(InstancePath::PRIORITY_LOW);
		$this->requestQueueRequest->expects($this->once())
			->method('getFromToken')
			->with('tok', RequestQueue::STATUS_STANDBY)
			->willReturn([$queue]);

		$this->assertSame([$queue], $this->service->getRequestFromToken('tok', RequestQueue::STATUS_STANDBY));
	}

	public function testInitRequestMarksTheQueueRunning(): void {
		$queue = $this->queued(InstancePath::PRIORITY_LOW);
		$this->requestQueueRequest->expects($this->once())->method('setAsRunning')->with($this->identicalTo($queue));

		$this->service->initRequest($queue);
	}

	public function testInitRequestPropagatesAStatusConflict(): void {
		$this->requestQueueRequest->method('setAsRunning')->willThrowException(new QueueStatusException());

		$this->expectException(QueueStatusException::class);
		$this->service->initRequest($this->queued(InstancePath::PRIORITY_LOW));
	}

	public function testEndRequestOnSuccessKeepsTheRequestAsDelivered(): void {
		$queue = $this->queued(InstancePath::PRIORITY_LOW);
		// kept, not deleted: it is the one record that this server got the post,
		// and the author may ask. purgeFinished() takes it away after the retention.
		$this->requestQueueRequest->expects($this->once())->method('setAsSuccess')->with($this->identicalTo($queue));
		$this->requestQueueRequest->expects($this->never())->method('delete');
		$this->requestQueueRequest->expects($this->never())->method('setAsFailure');

		$this->service->endRequest($queue, true);
	}

	public function testPurgeFinishedAppliesTheRetention(): void {
		$this->requestQueueRequest->expects($this->once())
			->method('deleteFinished')
			->with($this->callback(fn (int $before): bool
				=> abs($before - (time() - RequestQueueService::RETENTION_SECONDS)) < 5))
			->willReturn(3);

		$this->assertSame(3, $this->service->purgeFinished());
	}

	/**
	 * The rows of one post are found by the object inside the activity, so the
	 * prim is read off the JSON: the Note under a Create, the object of an
	 * Announce, the activity's own id when it has no object.
	 */
	public function testQueuedRequestsCarryThePrimOfTheObjectTheyAreAbout(): void {
		$stored = $this->captureQueued();
		$activity = json_encode([
			'id' => 'https://cloud.example.com/apps/social/@alice/1/activity',
			'type' => 'Create',
			'object' => ['id' => 'https://cloud.example.com/apps/social/@alice/1', 'type' => 'Note'],
		]);

		$this->service->generateRequestQueueFromSource(
			[new InstancePath('https://remote.example/inbox', InstancePath::TYPE_INBOX, InstancePath::PRIORITY_LOW)],
			$activity,
			self::AUTHOR
		);

		$this->assertSame(md5('https://cloud.example.com/apps/social/@alice/1'), $stored->requests[0]->getObjectIdPrim());
	}

	public function testObjectIdPrimOfReadsEveryShapeAnActivityComesIn(): void {
		$this->assertSame(md5('https://a/note'), RequestQueueService::objectIdPrimOf('{"type":"Create","object":{"id":"https://a/note"}}'));
		// an Announce names its object by id
		$this->assertSame(md5('https://a/note'), RequestQueueService::objectIdPrimOf('{"type":"Announce","object":"https://a/note"}'));
		// a bare Note, queued as itself
		$this->assertSame(md5('https://a/note'), RequestQueueService::objectIdPrimOf('{"id":"https://a/note","type":"Note"}'));
		$this->assertSame('', RequestQueueService::objectIdPrimOf('{"type":"Follow"}'));
		$this->assertSame('', RequestQueueService::objectIdPrimOf('not json'));
	}

	public function testEndRequestOnFailure(): void {
		$queue = $this->queued(InstancePath::PRIORITY_LOW);
		$this->requestQueueRequest->expects($this->once())->method('setAsFailure')->with($this->identicalTo($queue));
		$this->requestQueueRequest->expects($this->never())->method('setAsSuccess');

		$this->service->endRequest($queue, false);
	}

	public function testEndRequestSwallowsStatusConflicts(): void {
		$this->requestQueueRequest->method('setAsSuccess')->willThrowException(new QueueStatusException());

		$this->service->endRequest($this->queued(InstancePath::PRIORITY_LOW), true);
		$this->addToAssertionCount(1);
	}

	public function testDeleteRequestDelegates(): void {
		$queue = $this->queued(InstancePath::PRIORITY_LOW);
		$this->requestQueueRequest->expects($this->once())->method('delete')->with($this->identicalTo($queue));

		$this->service->deleteRequest($queue);
	}

	public function testGetRequestStandbyAbandonsRequestsPastTheRetryCap(): void {
		$now = time();
		// A request that has burned through MAX_TRIES is marked abandoned and never
		// handed back, so a dead host cannot keep it on standby forever -- marked,
		// not deleted, so the author can still see that server never got the post.
		$exhausted = $this->queued(InstancePath::PRIORITY_LOW)->setTries(RequestQueueService::MAX_TRIES)->setLast($now - 100000);
		$ready = $this->queued(InstancePath::PRIORITY_LOW)->setTries(2)->setLast($now - 60); // delay 31s, elapsed
		$this->requestQueueRequest->method('getStandby')->willReturn([$exhausted, $ready]);
		$this->requestQueueRequest->expects($this->once())->method('setAsAbandoned')->with($this->identicalTo($exhausted));
		$this->requestQueueRequest->expects($this->never())->method('delete');

		$total = 0;
		$result = $this->service->getRequestStandby($total);

		$this->assertSame(2, $total);
		$this->assertSame([$ready], $result);
		$this->assertNotContains($exhausted, $result);
	}

	public function testGetRequestStandbyHoldsARequestStillInsideItsBackoff(): void {
		// four failures wait 4^4 + 15 = 271 seconds; the old tries^4/3 schedule
		// (85 s) handed this one back already
		$waiting = $this->queued(InstancePath::PRIORITY_LOW)->setTries(4)->setLast(time() - 100);
		$this->requestQueueRequest->method('getStandby')->willReturn([$waiting]);
		$this->requestQueueRequest->expects($this->never())->method('delete');

		$total = 0;
		$this->assertSame([], $this->service->getRequestStandby($total));
		$this->assertSame(1, $total);
	}

	public function testRetryScheduleKeepsEarlyRetriesQuickAndSpansAboutTwoDays(): void {
		// Mastodon keeps trying a peer for about two days (16 attempts on
		// Sidekiq's count^4 + 15 backoff); a 12-16 hour window gave up on every
		// instance that was down for a weekend
		$total = 0;
		$previous = 0;
		for ($tries = 1; $tries < RequestQueueService::MAX_TRIES; $tries++) {
			$delay = RequestQueueService::retryDelay($tries);
			$this->assertGreaterThan($previous, $delay, 'the wait must keep growing');
			$previous = $delay;
			$total += $delay;
		}

		// the first minutes matter most for a peer that was momentarily down
		$this->assertLessThanOrEqual(60, RequestQueueService::retryDelay(1));
		$this->assertLessThanOrEqual(5 * 60, RequestQueueService::retryDelay(4));
		// ... and the tail is spread out over roughly two days in total
		$this->assertGreaterThan(3600, RequestQueueService::retryDelay(RequestQueueService::MAX_TRIES - 1));
		$this->assertGreaterThanOrEqual(1.9 * 86400, $total);
		$this->assertLessThanOrEqual(2.2 * 86400, $total);
	}

	public function testReapStaleRunningReturnsStrandedRunningToStandby(): void {
		$cutoff = null;
		$this->requestQueueRequest->expects($this->once())
			->method('resetStaleRunning')
			->willReturnCallback(function (int $before) use (&$cutoff): int {
				$cutoff = $before;

				return 4;
			});

		$reaped = $this->service->reapStaleRunning();

		$this->assertSame(4, $reaped);
		$this->assertEqualsWithDelta(time() - RequestQueueService::STALE_RUNNING_SECONDS, $cutoff, 2);
	}

	/**
	 * A row skipped because its host has an open breaker was not attempted, so
	 * it keeps `tries = 0` and would head the very next batch and be skipped
	 * again, ahead of everything that could actually be delivered.
	 */
	public function testPostponeRequestHoldsAStandbyRowBackWithoutSpendingATry(): void {
		$queue = $this->queued(InstancePath::PRIORITY_LOW);
		$until = time() + 600;
		$this->requestQueueRequest->expects($this->once())
			->method('postpone')
			->with($this->identicalTo($queue), $until);

		$this->service->postponeRequest($queue, $until);

		$this->assertSame(0, $queue->getTries());
	}

	public function testPostponeRequestDoesNothingForATimeThatHasPassed(): void {
		$this->requestQueueRequest->expects($this->never())->method('postpone');

		$this->service->postponeRequest($this->queued(InstancePath::PRIORITY_LOW), time() - 1);
	}

	/** Somebody else claimed the row; it is no longer ours to hold back. */
	public function testPostponeRequestSwallowsALostRace(): void {
		$this->requestQueueRequest->method('postpone')
			->willThrowException(new QueueStatusException());

		$this->service->postponeRequest($this->queued(InstancePath::PRIORITY_LOW), time() + 60);
		$this->addToAssertionCount(1);
	}

	public function testHighPriorityFollowedByALowerPriorityRequestIsRunInline(): void {
		$high = $this->queued(InstancePath::PRIORITY_HIGH);
		$this->requestQueueRequest->method('getFromToken')
			->willReturn([$high, $this->queued(InstancePath::PRIORITY_MEDIUM)]);

		$this->assertSame($high, $this->service->getPriorityRequest('tok'));
	}

	public function testTwoHighPriorityRequestsAreDeferred(): void {
		// Pins the getStatus()->getPriority() fix: a second HIGH request whose
		// STATUS_STANDBY is 0 must not look "lower" than PRIORITY_HIGH and wrongly
		// let the first be delivered inline.
		$this->requestQueueRequest->method('getFromToken')
			->willReturn([$this->queued(InstancePath::PRIORITY_HIGH), $this->queued(InstancePath::PRIORITY_HIGH)]);

		$this->expectException(NoHighPriorityRequestException::class);
		$this->service->getPriorityRequest('tok');
	}
}
