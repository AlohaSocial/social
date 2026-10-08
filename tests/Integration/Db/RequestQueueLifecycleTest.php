<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\RequestQueueRequest;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Service\DeliveryService;
use OCA\Social\Service\RequestQueueService;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The federation delivery queue against the real social_req_queue table: a queued
 * activity goes standby → running → delivered on success and is kept that way
 * for the retention so the author can ask where the post got to, failures count
 * up and are retried until the queue abandons them (kept too, marked), and a
 * worker that died mid-delivery has its RUNNING rows reaped back to standby.
 * This is the machinery whose earlier regressions (rows kept forever, deliveries
 * silently lost) only show on a real database.
 */
class RequestQueueLifecycleTest extends TestCase {
	private const AUTHOR = 'https://cloud.example.org/qtest/users/author';
	private const INBOX = 'https://remote.example/qtest/inbox';

	private RequestQueueService $service;
	private RequestQueueRequest $request;
	/** the id of the Note the last enqueue() queued */
	private string $lastObjectId = '';

	protected function setUp(): void {
		parent::setUp();
		$this->service = Server::get(RequestQueueService::class);
		$this->request = Server::get(RequestQueueRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		$this->request->deleteByAuthor(self::AUTHOR);
	}

	private function enqueue(): string {
		$note = new Note();
		$note->setId(self::AUTHOR . '/notes/' . bin2hex(random_bytes(4)));
		$this->lastObjectId = $note->getId();

		return $this->service->generateRequestQueue(
			[new InstancePath(self::INBOX, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_LOW)],
			$note,
			self::AUTHOR
		);
	}

	/**
	 * `last` is a datetime column, not a timestamp. SQLite compares it against
	 * an integer without complaint and hands back something `(int)` turns into
	 * a plausible-looking zero; PostgreSQL and MySQL reject the comparison
	 * outright. The figure the federation card prints is therefore only
	 * trustworthy against a real database, which is why it is asserted here.
	 */
	public function testTheOldestFailingAttemptComesBackAsATimestamp(): void {
		// the figure is the minimum over the whole queue, so another test's
		// leftover row may be older than ours: what is asserted is that it is
		// a real moment in the recent past rather than zero or the epoch
		$before = time();
		$baseline = $this->request->oldestFailingAttempt();

		$token = $this->enqueue();
		$queued = $this->service->getRequestFromToken($token, RequestQueue::STATUS_STANDBY);
		$this->service->initRequest($queued[0]);
		$this->service->endRequest($queued[0], false);

		$oldest = $this->request->oldestFailingAttempt();
		$this->assertGreaterThan(0, $oldest, 'a failing delivery has been attempted');
		$this->assertLessThanOrEqual(time() + 1, $oldest, 'an attempt cannot be in the future');

		if ($baseline === 0) {
			$this->assertGreaterThanOrEqual($before - 1, $oldest, 'ours is the only one failing');
		}
	}

	public function testSuccessfulDeliveryIsKeptAsDeliveredUntilTheRetentionPasses(): void {
		$token = $this->enqueue();

		$requests = $this->service->getRequestFromToken($token, RequestQueue::STATUS_STANDBY);
		$this->assertCount(1, $requests);
		$this->assertSame(md5($this->lastObjectId), $requests[0]->getObjectIdPrim(), 'the row names the post it is about');

		$queue = $requests[0];
		$this->service->initRequest($queue);
		$this->assertSame([], $this->service->getRequestFromToken($token, RequestQueue::STATUS_STANDBY), 'running, not standby');

		$this->service->endRequest($queue, true);
		$kept = $this->service->getRequestFromToken($token);
		$this->assertCount(1, $kept, 'a delivered request is kept: it is the record that this server got the post');
		$this->assertSame(RequestQueue::STATUS_SUCCESS, $kept[0]->getStatus());
		$this->assertSame('', $kept[0]->getActivity(), 'the record is kept, the signed body is not');

		// not yet: the retention has not passed
		$this->service->purgeFinished();
		$this->assertCount(1, $this->service->getRequestFromToken($token));

		// once it has, the queue is a queue again
		$this->assertGreaterThan(0, $this->request->deleteFinished(time() + 60));
		$this->assertSame([], $this->service->getRequestFromToken($token));
	}

	public function testTheAuthorCanAskWhereThePostGotTo(): void {
		$token = $this->enqueue();
		$queue = $this->service->getRequestFromToken($token, RequestQueue::STATUS_STANDBY)[0];
		$this->service->initRequest($queue);
		$this->service->endRequest($queue, true);

		$summary = Server::get(DeliveryService::class)->forObject($this->lastObjectId);

		$this->assertSame(1, $summary['delivered']);
		$this->assertSame(1, $summary['total']);
		$this->assertSame('remote.example', $summary['instances'][0]['host']);
		$this->assertSame(DeliveryService::STATE_DELIVERED, $summary['instances'][0]['state']);
		// a post that was never queued has nothing to say, rather than somebody else's rows
		$this->assertSame(0, Server::get(DeliveryService::class)->forObject(self::AUTHOR . '/notes/never')['total']);
	}

	public function testAFailureGoesBackToStandbyWithOneMoreTry(): void {
		$token = $this->enqueue();
		$queue = $this->service->getRequestFromToken($token, RequestQueue::STATUS_STANDBY)[0];

		$this->service->initRequest($queue);
		$this->service->endRequest($queue, false);

		$again = $this->service->getRequestFromToken($token, RequestQueue::STATUS_STANDBY);
		$this->assertCount(1, $again);
		$this->assertSame(1, $again[0]->getTries(), 'the failure was counted');
	}

	public function testADeadHostIsAbandonedAfterMaxTries(): void {
		$token = $this->enqueue();

		for ($i = 0; $i < RequestQueueService::MAX_TRIES; $i++) {
			$requests = $this->service->getRequestFromToken($token, RequestQueue::STATUS_STANDBY);
			$this->assertCount(1, $requests, 'retry ' . $i . ' still standby');
			$this->service->initRequest($requests[0]);
			$this->service->endRequest($requests[0], false);
		}

		// listing the standby queue is what abandons exhausted requests
		$this->service->getRequestStandby();
		$this->assertSame(
			[],
			$this->service->getRequestFromToken($token, RequestQueue::STATUS_STANDBY),
			'after MAX_TRIES the request is abandoned, not retried forever'
		);
		$left = $this->service->getRequestFromToken($token);
		$this->assertCount(1, $left, 'abandoned is a state the author can see, not a deletion');
		$this->assertSame(RequestQueue::STATUS_ABANDONED, $left[0]->getStatus());
		$this->assertSame(RequestQueueService::MAX_TRIES, $left[0]->getTries());

		// and it is no longer anybody's failing delivery
		$this->assertNotContains(
			$left[0]->getId(),
			array_map(fn (RequestQueue $r): int => $r->getId(), $this->request->getFailing()),
			'given up on is not still failing'
		);
	}

	public function testAStaleRunningRowIsReapedBackToStandby(): void {
		$token = $this->enqueue();
		$queue = $this->service->getRequestFromToken($token, RequestQueue::STATUS_STANDBY)[0];
		$this->service->initRequest($queue);

		// a fresh RUNNING row is left alone — the worker may still be delivering
		$this->service->reapStaleRunning();
		$this->assertSame([], $this->service->getRequestFromToken($token, RequestQueue::STATUS_STANDBY));

		// backdate it past the stale threshold, as if the worker died mid-delivery
		$connection = Server::get(\OCP\IDBConnection::class);
		$qb = $connection->getQueryBuilder();
		$qb->update('social_req_queue')
			->set('last', $qb->createNamedParameter(
				new \DateTime('@' . (time() - RequestQueueService::STALE_RUNNING_SECONDS - 60)),
				\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_DATE
			))
			->where($qb->expr()->eq('author', $qb->createNamedParameter(self::AUTHOR)));
		$qb->executeStatement();

		$this->assertGreaterThan(0, $this->service->reapStaleRunning());
		$this->assertCount(
			1,
			$this->service->getRequestFromToken($token, RequestQueue::STATUS_STANDBY),
			'a delivery whose worker died is retried instead of silently lost'
		);
	}

	// what the administration page reads off this table

	public function testTheQueueCountsItselfByState(): void {
		$token = $this->enqueue();
		$queue = $this->service->getRequestFromToken($token, RequestQueue::STATUS_STANDBY)[0];

		$before = $this->request->countByStatus();
		$this->service->initRequest($queue);
		$after = $this->request->countByStatus();

		$this->assertSame(
			($before[RequestQueue::STATUS_STANDBY] ?? 0) - 1,
			$after[RequestQueue::STATUS_STANDBY] ?? 0
		);
		$this->assertSame(
			($before[RequestQueue::STATUS_RUNNING] ?? 0) + 1,
			$after[RequestQueue::STATUS_RUNNING] ?? 0
		);
	}

	public function testFailingDeliveriesComeBackWorstFirst(): void {
		$fresh = $this->service->getRequestFromToken($this->enqueue())[0];
		$struggling = $this->service->getRequestFromToken($this->enqueue())[0];

		// one attempt that failed, then another
		$this->service->initRequest($struggling);
		$this->service->endRequest($struggling, false);
		$this->service->initRequest($struggling);
		$this->service->endRequest($struggling, false);

		// the table is shared with whatever else this instance is trying to
		// deliver, so pick out our own row rather than assuming a position
		$failing = $this->request->getFailing();
		$ours = array_values(array_filter(
			$failing,
			fn (RequestQueue $request): bool => $request->getId() === $struggling->getId()
		));

		$this->assertCount(1, $ours);
		$this->assertSame(2, $ours[0]->getTries());
		$this->assertSame(self::INBOX, $ours[0]->getInstance()->getUri(), 'the address survives the round trip');

		$this->assertNotContains(
			$fresh->getId(),
			array_map(fn (RequestQueue $request): int => $request->getId(), $failing),
			'a delivery that never failed is not failing'
		);

		$tries = array_map(fn (RequestQueue $request): int => $request->getTries(), $failing);
		$sorted = $tries;
		rsort($sorted);
		$this->assertSame($sorted, $tries, 'worst first, so the top of the table is the worst news');
	}

	/**
	 * What the drain has given up on used to appear in nothing at all: it left
	 * the failing set the moment it was abandoned, and no other read looked
	 * for it. The rows are there for seven days, which is the window an
	 * administrator has to notice a peer has been lost.
	 */
	public function testTheDeliveriesGivenUpOnCanBeReadBack(): void {
		$failing = $this->service->getRequestFromToken($this->enqueue())[0];
		$this->service->initRequest($failing);
		$this->service->endRequest($failing, false);

		$abandoned = $this->service->getRequestFromToken($this->enqueue())[0];
		$this->service->abandonRequest($abandoned);

		$ids = array_map(fn (RequestQueue $request): int => $request->getId(), $this->request->getAbandoned());

		$this->assertContains($abandoned->getId(), $ids);
		$this->assertNotContains(
			$failing->getId(), $ids, 'a delivery still being retried has not been given up on'
		);
		$this->assertNotContains(
			$abandoned->getId(),
			array_map(fn (RequestQueue $request): int => $request->getId(), $this->request->getFailing()),
			'and the two sets do not overlap, or the same row would be counted twice'
		);
	}

	public function testTheFailureThresholdIsRespected(): void {
		$queue = $this->service->getRequestFromToken($this->enqueue())[0];
		$this->service->initRequest($queue);
		$this->service->endRequest($queue, false);

		$this->assertNotContains(
			$queue->getId(),
			array_map(fn (RequestQueue $r): int => $r->getId(), $this->request->getFailing(5)),
			'one failure is not five'
		);
	}
}
