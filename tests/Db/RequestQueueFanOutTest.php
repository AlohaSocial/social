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
 * How the fan-out of one activity is written.
 *
 * It happens inside the web request that made the post, and it is one row per
 * inbox: a local account followed from twenty thousand instances used to pay
 * twenty thousand separate INSERTs, each prepared again and each committed on
 * its own, before the post was handed back to its author.
 */
#[AllowMockObjectsWithoutExpectations]
class RequestQueueFanOutTest extends TestCase {
	private IQueryBuilder|MockObject $queryBuilder;
	private IDBConnection|MockObject $connection;
	private RequestQueueRequest $request;

	protected function setUp(): void {
		$this->queryBuilder = $this->createMock(IQueryBuilder::class);
		$this->queryBuilder->method('createParameter')->willReturnCallback(
			static fn (string $name): string => ':' . $name
		);

		$this->connection = $this->createMock(IDBConnection::class);
		$this->connection->method('getQueryBuilder')->willReturn($this->queryBuilder);

		$this->request = new RequestQueueRequest(
			$this->connection,
			new NullLogger(),
			$this->createStub(IURLGenerator::class),
			$this->createStub(ConfigService::class),
			$this->createStub(MiscService::class)
		);
	}

	/**
	 * @return RequestQueue[]
	 */
	private function fanOut(int $inboxes): array {
		$queues = [];
		for ($i = 0; $i < $inboxes; $i++) {
			$queues[] = new RequestQueue(
				'{"type":"Create"}',
				new InstancePath(
					'https://remote' . $i . '.example/inbox',
					InstancePath::TYPE_GLOBAL,
					InstancePath::PRIORITY_LOW
				),
				'https://social.example/@alice'
			);
		}

		return $queues;
	}

	public function testTheWholeFanOutIsWrittenFromOneStatementInOneTransaction(): void {
		$this->queryBuilder->expects($this->once())->method('insert');
		// ten columns, named once: the values are bound per row
		$this->queryBuilder->expects($this->exactly(10))->method('setValue');
		$this->queryBuilder->expects($this->exactly(10 * 20))->method('setParameter');
		$this->queryBuilder->expects($this->exactly(20))->method('executeStatement');
		$this->connection->expects($this->once())->method('beginTransaction');
		$this->connection->expects($this->once())->method('commit');

		$this->request->multiple($this->fanOut(20));
	}

	/**
	 * A held fan-out is written held. Inserted due and postponed after, a
	 * drain that ran in between would deliver the post before its video was
	 * converted, which is what the hold is there to prevent.
	 */
	public function testAHeldRowIsWrittenWithItsTimeAndAFreshOneWithNone(): void {
		$queues = $this->fanOut(2);
		$queues[0]->setLast(2000000000);

		$bound = [];
		$this->queryBuilder->method('setParameter')->willReturnCallback(
			function (string $name, mixed $value, mixed $type = null) use (&$bound) {
				if ($name === 'last') {
					$bound[] = [$value, $type];
				}

				return $this->queryBuilder;
			}
		);

		$this->request->multiple($queues);

		$this->assertCount(2, $bound);
		$this->assertInstanceOf(\DateTime::class, $bound[0][0]);
		$this->assertSame(2000000000, $bound[0][0]->getTimestamp());
		$this->assertSame(IQueryBuilder::PARAM_DATE, $bound[0][1]);
		$this->assertSame([null, IQueryBuilder::PARAM_NULL], $bound[1]);
	}

	/** A fan-out larger than one chunk is committed in chunks, not in one lock. */
	public function testAVeryLargeFanOutIsCommittedInChunks(): void {
		$rows = RequestQueueRequest::INSERT_CHUNK + 1;
		$this->queryBuilder->expects($this->exactly($rows))->method('executeStatement');
		$this->connection->expects($this->exactly(2))->method('beginTransaction');
		$this->connection->expects($this->exactly(2))->method('commit');

		$this->request->multiple($this->fanOut($rows));
	}

	public function testNothingIsWrittenForAnEmptyFanOut(): void {
		$this->queryBuilder->expects($this->never())->method('insert');
		$this->connection->expects($this->never())->method('beginTransaction');

		$this->request->multiple([]);
	}

	public function testAFailedRowRollsItsChunkBackInsteadOfLeavingItOpen(): void {
		$this->queryBuilder->method('executeStatement')
			->willThrowException(new \RuntimeException('deadlock'));
		$this->connection->expects($this->once())->method('rollBack');
		$this->connection->expects($this->never())->method('commit');

		$this->expectException(\RuntimeException::class);
		$this->request->multiple($this->fanOut(2));
	}
}
