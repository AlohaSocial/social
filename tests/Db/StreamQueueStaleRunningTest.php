<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\StreamQueueRequest;
use OCA\Social\Model\StreamQueue;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * An item whose processing takes the process down never reaches
 * `setAsFailure()`. It is found `running` later and put back on standby, and
 * that attempt has to count, or the item comes back for ever.
 */
#[AllowMockObjectsWithoutExpectations]
class StreamQueueStaleRunningTest extends TestCase {
	public function testAStaleRunningItemGoesBackToStandbyWithOneMoreTry(): void {
		$set = [];
		$queryBuilder = $this->createMock(IQueryBuilder::class);
		$queryBuilder->method('expr')->willReturn(new class extends FakeExpressions {
			public function literal(mixed $value): string {
				return (string)$value;
			}
		});
		$queryBuilder->method('createNamedParameter')->willReturnCallback(
			static fn ($value): mixed => $value instanceof \DateTime ? $value->format('c') : $value
		);
		$increment = $this->createStub(IQueryFunction::class);
		$functions = $this->createMock(IFunctionBuilder::class);
		$functions->expects($this->once())->method('add')->with('tries', $this->anything())
			->willReturn($increment);
		$queryBuilder->method('func')->willReturn($functions);
		$queryBuilder->method('set')->willReturnCallback(
			function (string $column, $value) use (&$set, &$queryBuilder): IQueryBuilder {
				$set[$column] = $value;

				return $queryBuilder;
			}
		);
		$queryBuilder->method('executeStatement')->willReturn(2);

		$connection = $this->createMock(IDBConnection::class);
		$connection->method('getQueryBuilder')->willReturn($queryBuilder);

		$request = new StreamQueueRequest(
			$connection,
			new NullLogger(),
			$this->createStub(IURLGenerator::class),
			$this->createStub(ConfigService::class),
			$this->createStub(MiscService::class)
		);

		$this->assertSame(2, $request->resetStaleRunning(time() - 3600));
		$this->assertSame(StreamQueue::STATUS_STANDBY, $set['status']);
		$this->assertSame($increment, $set['tries']);
	}
}
