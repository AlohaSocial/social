<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\RequestQueueRequest;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * What a finished delivery row keeps of the activity it carried.
 *
 * Every inbox row holds the whole signed document and the row is kept for a
 * week after it finishes, so a post delivered to twenty thousand servers
 * left twenty thousand copies of itself behind.
 */
#[AllowMockObjectsWithoutExpectations]
class RequestQueueFinishedBodyTest extends TestCase {
	private IQueryBuilder|MockObject $queryBuilder;
	private RequestQueueRequest $request;
	/** @var array<string, mixed> column => value, from every `set()` */
	private array $set = [];

	protected function setUp(): void {
		$this->set = [];
		$this->queryBuilder = $this->createMock(IQueryBuilder::class);
		$this->queryBuilder->method('expr')->willReturn(new FakeExpressions());
		$this->queryBuilder->method('createNamedParameter')->willReturnCallback(
			static fn ($value): mixed => $value
		);
		$this->queryBuilder->method('set')->willReturnCallback(
			function (string $column, $value): IQueryBuilder {
				$this->set[$column] = $value;

				return $this->queryBuilder;
			}
		);
		$this->queryBuilder->method('executeStatement')->willReturn(1);

		$connection = $this->createMock(IDBConnection::class);
		$connection->method('getQueryBuilder')->willReturn($this->queryBuilder);

		$this->request = new RequestQueueRequest(
			$connection,
			new NullLogger(),
			$this->createStub(IURLGenerator::class),
			$this->createStub(ConfigService::class),
			$this->createStub(MiscService::class)
		);
	}

	private function row(): RequestQueue {
		$queue = new RequestQueue(
			'{"type":"Create","signature":"…"}',
			new InstancePath('https://remote.example/inbox', InstancePath::TYPE_GLOBAL, InstancePath::PRIORITY_LOW),
			'https://social.example/@alice'
		);
		$queue->setId(7);

		return $queue;
	}

	public function testADeliveredRowDropsItsBody(): void {
		$queue = $this->row();
		$this->request->setAsSuccess($queue);

		$this->assertSame(RequestQueue::STATUS_SUCCESS, $this->set['status']);
		$this->assertSame('', $this->set['activity']);
	}

	/** `social:queue:retry` hands an abandoned row back, and it goes out with its body. */
	public function testAnAbandonedRowKeepsItsBody(): void {
		$queue = $this->row();
		$this->request->setAsAbandoned($queue);

		$this->assertSame(RequestQueue::STATUS_ABANDONED, $this->set['status']);
		$this->assertArrayNotHasKey('activity', $this->set);
	}
}
