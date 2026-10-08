<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use DateTime;
use OCA\Social\Db\Backoff;
use OCA\Social\Db\StreamQueueRequest;
use OCA\Social\Service\RequestQueueService;
use OCA\Social\Tools\IExtendedQueryBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * One schedule per queue, read by the query and by PHP alike.
 */
#[AllowMockObjectsWithoutExpectations]
class BackoffTest extends TestCase {
	public function testTheOutboundScheduleIsMastodons(): void {
		$this->assertSame(0, Backoff::outbound()->delay(0));
		$this->assertSame(16, Backoff::outbound()->delay(1));
		$this->assertSame(271, Backoff::outbound()->delay(4));
		$this->assertSame(50_640, Backoff::outbound()->delay(15));
		$this->assertSame(16, Backoff::outbound()->maxTries());
		$this->assertSame(RequestQueueService::MAX_TRIES, Backoff::outbound()->maxTries());
	}

	public function testTheInboundScheduleIsAThirdOfTheFourthPower(): void {
		for ($tries = 0; $tries < 10; $tries++) {
			$this->assertSame((int)floor($tries ** 4 / 3), Backoff::inbound()->delay($tries), 'tries ' . $tries);
		}
		$this->assertSame(StreamQueueRequest::MAX_TRIES, Backoff::inbound()->maxTries());
	}

	/**
	 * The cutoffs the query compares `last` with are `now - delay(n)`, bound
	 * as dates because the column is a DATETIME.
	 */
	public function testTheQueryUsesTheSameDelays(): void {
		$now = 1_760_000_000;
		$dates = [];
		$qb = $this->createMock(IExtendedQueryBuilder::class);
		$qb->method('expr')->willReturn(new FakeExpressions());
		$qb->method('createNamedParameter')->willReturnCallback(
			static function ($value, $type = null) use (&$dates): string {
				if ($value instanceof DateTime) {
					self::assertSame(IQueryBuilder::PARAM_DATE, $type);
					$dates[] = $value->getTimestamp();

					return 'date';
				}

				return (string)$value;
			}
		);
		$qb->method('andWhere')->willReturnSelf();

		$backoff = Backoff::outbound();
		$backoff->limitToDue($qb, 'rq.', $now);

		$expected = [];
		for ($tries = 0; $tries < $backoff->maxTries(); $tries++) {
			$expected[] = $now - $backoff->delay($tries);
		}
		$this->assertSame($expected, $dates);
	}
}
