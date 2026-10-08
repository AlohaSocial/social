<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\Backoff;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\HashtagsRequest;
use OCA\Social\Db\RequestQueueRequest;
use OCA\Social\Db\SocialQueryBuilder;
use OCA\Social\Db\StreamQueueRequest;
use OCA\Social\Tools\IExtendedQueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The three conditions that are assembled in a loop.
 *
 * Each of them is one alternative per retry count — "tried once and long
 * enough ago, or tried twice and longer ago, or…" — and each used to be built
 * by creating an empty `orX()` and adding to it. Nextcloud 36 logs that as
 * deprecated and says it will throw, and an empty composite renders as
 * nothing, which would quietly turn "the rows that are due" into every row in
 * the table. What is pinned here is that the parts are passed to `orX()`
 * rather than added afterwards, and that the condition still says what it did.
 *
 * `FakeExpressions` refuses an empty composite outright, so a return to the
 * old shape fails these tests rather than only changing their output.
 */
#[AllowMockObjectsWithoutExpectations]
class LoopBuiltPredicatesTest extends TestCase {
	/** @var string[] every condition the query was given */
	private array $where = [];

	protected function setUp(): void {
		$this->where = [];
	}

	private function queryBuilder(): IExtendedQueryBuilder&MockObject {
		$qb = $this->createMock(IExtendedQueryBuilder::class);
		$qb->method('expr')->willReturn(new FakeExpressions());
		$qb->method('getType')->willReturn(IExtendedQueryBuilder::SELECT);
		$qb->method('createNamedParameter')->willReturnCallback(
			static fn ($value): string => is_object($value) ? 'date' : (string)$value
		);
		$qb->method('andWhere')->willReturnCallback(
			function (...$conditions) use (&$qb): IExtendedQueryBuilder {
				foreach ($conditions as $condition) {
					$this->where[] = (string)$condition;
				}

				return $qb;
			}
		);

		return $qb;
	}

	private function socialQueryBuilder(): SocialQueryBuilder&MockObject {
		$qb = $this->createMock(SocialQueryBuilder::class);
		$qb->method('expr')->willReturn(new FakeExpressions());
		$qb->method('createNamedParameter')->willReturnCallback(
			static fn ($value): string => (string)$value
		);
		$qb->method('andWhere')->willReturnCallback(
			function (...$conditions) use (&$qb): SocialQueryBuilder {
				foreach ($conditions as $condition) {
					$this->where[] = (string)$condition;
				}

				return $qb;
			}
		);

		return $qb;
	}

	/** Calls a method the class does not offer publicly. */
	private function invoke(string $class, string $method, array $arguments): void {
		$reflection = new ReflectionClass($class);
		$instance = $reflection->newInstanceWithoutConstructor();
		$reflection->getMethod($method)->invoke($instance, ...$arguments);
	}

	public function testTheQueueDueConditionIsOneAlternativePerTry(): void {
		$this->invoke(CoreRequestBuilder::class, 'limitToQueueDue', [$this->queryBuilder(), new Backoff(3, 0, 3)]);

		$this->assertCount(1, $this->where);
		// three tries, so three alternatives joined by OR
		$this->assertSame(3, substr_count($this->where[0], 'tries = '));
		$this->assertSame(2, substr_count($this->where[0], ' OR ('));
		$this->assertStringContainsString('last IS NULL', $this->where[0]);
	}

	public function testEachQueueHasOneAlternativePerTryBelowItsThreshold(): void {
		$this->invoke(RequestQueueRequest::class, 'limitToQueueDue', [$this->queryBuilder(), Backoff::outbound()]);
		$this->invoke(StreamQueueRequest::class, 'limitToQueueDue', [$this->queryBuilder(), Backoff::inbound()]);

		$this->assertCount(2, $this->where);
		$this->assertSame(Backoff::OUTBOUND_MAX_TRIES, substr_count($this->where[0], 'tries = '));
		$this->assertSame(Backoff::INBOUND_MAX_TRIES, substr_count($this->where[1], 'tries = '));
	}

	public function testTheActorSyncConditionIsOneAlternativePerFailureCount(): void {
		$this->invoke(
			CacheActorsRequest::class,
			'limitToSyncDue',
			[$this->socialQueryBuilder(), 1_000_000, true]
		);

		$this->assertCount(1, $this->where);
		$this->assertSame(
			CacheActorsRequest::SYNC_MAX_FAILURES,
			substr_count($this->where[0], 'ca.sync_failures = ')
		);
	}

	/**
	 * The fourth of these conditions — "has a trend in any of the windows" —
	 * is built from a constant rather than a loop bound, and its query needs a
	 * database to drive. What can be said without one is that the constant it
	 * loops over is not empty, which is the only way that site could reach
	 * `orX()` with nothing.
	 */
	public function testTheTrendWindowsAreNotAnEmptyList(): void {
		$this->assertNotEmpty(HashtagsRequest::TREND_COLUMNS);
	}

	/**
	 * The degenerate case: a caller asking for no tries at all. It cannot
	 * happen from the app's own callers, which pass a constant, but it is the
	 * case that used to produce the empty composite, and what it must not do
	 * is hand back a condition that matches every row.
	 */
	public function testNoTriesIsRefusedRatherThanMatchingEverything(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->invoke(CoreRequestBuilder::class, 'limitToQueueDue', [$this->queryBuilder(), new Backoff(3, 0, 0)]);
	}
}
