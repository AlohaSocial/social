<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\RequestQueueRequest;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Service\DeliveryService;
use OCA\Social\Service\RequestQueueService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class DeliveryServiceTest extends TestCase {
	private const OBJECT = 'https://cloud.example.com/apps/social/@alice/1';

	private RequestQueueRequest|MockObject $requestQueueRequest;
	private DeliveryService $service;

	protected function setUp(): void {
		$this->requestQueueRequest = $this->createMock(RequestQueueRequest::class);
		$this->service = new DeliveryService($this->requestQueueRequest);
	}

	private function row(string $host, int $status, int $tries = 0, int $last = 0): RequestQueue {
		$queue = new RequestQueue('{}', new InstancePath("https://$host/inbox", InstancePath::TYPE_INBOX, InstancePath::PRIORITY_LOW), 'author');
		$queue->setStatus($status)->setTries($tries)->setLast($last);

		return $queue;
	}

	public function testTheQueueIsAskedByThePrimOfTheObject(): void {
		$this->requestQueueRequest->expects($this->once())
			->method('getByObject')->with(md5(self::OBJECT))->willReturn([]);

		$summary = $this->service->forObject(self::OBJECT);

		$this->assertSame(0, $summary['total']);
		$this->assertSame([], $summary['instances']);
		// the answer says how far back it can see, so a client can say so too
		$this->assertSame(RequestQueueService::RETENTION_SECONDS, $summary['retention']);
	}

	public function testRowsAreCountedByStateAndListedWorstFirst(): void {
		$this->requestQueueRequest->method('getByObject')->willReturn([
			$this->row('a.example', RequestQueue::STATUS_SUCCESS, 1, 100),
			$this->row('b.example', RequestQueue::STATUS_STANDBY, 0, 0),
			$this->row('c.example', RequestQueue::STATUS_STANDBY, 3, 200),
			$this->row('d.example', RequestQueue::STATUS_RUNNING, 0, 300),
			$this->row('e.example', RequestQueue::STATUS_ABANDONED, RequestQueueService::MAX_TRIES, 400),
			$this->row('f.example', RequestQueue::STATUS_SUCCESS, 0, 500),
		]);

		$summary = $this->service->forObject(self::OBJECT);

		$this->assertSame(2, $summary['delivered']);
		$this->assertSame(1, $summary['sending']);
		$this->assertSame(1, $summary['waiting']);
		$this->assertSame(1, $summary['failing']);
		$this->assertSame(1, $summary['abandoned']);
		$this->assertSame(6, $summary['total']);

		// what needs the author's eye comes first; ties keep the queue order
		$this->assertSame(
			['e.example', 'c.example', 'd.example', 'b.example', 'a.example', 'f.example'],
			array_column($summary['instances'], 'host')
		);
		$this->assertSame(
			['host' => 'e.example', 'state' => 'abandoned', 'tries' => RequestQueueService::MAX_TRIES, 'last' => 400],
			$summary['instances'][0]
		);
	}

	/**
	 * A delivered row has tries too when the first attempts failed; the status
	 * says what happened in the end, the tries only how hard it was.
	 */
	public function testADeliveredRowWithEarlierFailuresIsDelivered(): void {
		$this->assertSame(
			DeliveryService::STATE_DELIVERED,
			DeliveryService::stateOf($this->row('a.example', RequestQueue::STATUS_SUCCESS, 5))
		);
		$this->assertSame(
			DeliveryService::STATE_FAILING,
			DeliveryService::stateOf($this->row('a.example', RequestQueue::STATUS_STANDBY, 5))
		);
		$this->assertSame(
			DeliveryService::STATE_WAITING,
			DeliveryService::stateOf($this->row('a.example', RequestQueue::STATUS_STANDBY, 0))
		);
	}
}
