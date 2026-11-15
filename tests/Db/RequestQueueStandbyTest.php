<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use DateTime;
use OCA\Social\Db\RequestQueueRequest;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\RequestQueueService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Which queued deliveries a drain takes, and which it gives up on.
 *
 * A row whose host the circuit breaker holds back is postponed without
 * spending a try. Ordered by the try count, those rows sorted ahead of every
 * retry to a healthy host; given up on by the try count alone, the rows of an
 * instance that had gone away were kept for ever.
 */
#[AllowMockObjectsWithoutExpectations]
class RequestQueueStandbyTest extends TestCase {
	/** @var list<array{string, string}> one entry per orderBy()/addOrderBy() */
	private array $order = [];
	/** @var list<list<string>> the predicates of each statement, in the order they ran */
	private array $statements = [];
	/** @var list<string> the predicates of the statement being built */
	private array $where = [];
	private RequestQueueRequest $request;

	protected function setUp(): void {
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->builder());

		$this->request = new RequestQueueRequest(
			$connection,
			new NullLogger(),
			$this->createStub(IURLGenerator::class),
			$this->createStub(ConfigService::class),
			$this->createStub(MiscService::class)
		);
	}

	private function builder(): IQueryBuilder {
		$this->where = [];
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn(new FakeExpressions());
		$qb->method('createNamedParameter')->willReturnCallback(
			static fn ($value): string => $value instanceof DateTime ? '@' . $value->getTimestamp() : (is_array($value) ? implode(',', $value) : (string)$value)
		);
		$qb->method('getColumnName')->willReturnCallback(
			static fn (string $column, string $alias = ''): string => ($alias === '' ? '' : $alias . '.') . $column
		);
		$qb->method('createFunction')->willReturnCallback(function (string $call): IQueryFunction {
			$function = $this->createStub(IQueryFunction::class);
			$function->method('__toString')->willReturn($call);

			return $function;
		});
		foreach (['where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnCallback(function (...$predicates) use ($qb): IQueryBuilder {
				foreach ($predicates as $predicate) {
					$this->where[] = (string)$predicate;
				}

				return $qb;
			});
		}
		foreach (['orderBy', 'addOrderBy'] as $method) {
			$qb->method($method)->willReturnCallback(function ($sort, $order = null) use ($qb): IQueryBuilder {
				$this->order[] = [(string)$sort, (string)$order];

				return $qb;
			});
		}
		$qb->method('executeStatement')->willReturnCallback(function (): int {
			$this->statements[] = $this->where;

			return 0;
		});
		$qb->method('executeQuery')->willReturnCallback(function (): IResult {
			$this->statements[] = $this->where;
			$result = $this->createStub(IResult::class);
			$result->method('fetch')->willReturn(false);
			$result->method('fetchOne')->willReturn(false);

			return $result;
		});
		foreach (['select', 'from', 'update', 'set', 'setMaxResults', 'selectAlias', 'addSelect'] as $method) {
			$qb->method($method)->willReturn($qb);
		}

		return $qb;
	}

	/**
	 * Most urgent first, then never attempted, then whatever has waited
	 * longest; the never-attempted rows are told apart explicitly because
	 * NULL sorts first on MySQL and last on PostgreSQL.
	 */
	public function testTheDrainTakesTheRowThatHasWaitedLongestNotTheOneTriedLeast(): void {
		$this->request->getStandby();

		$this->assertSame([
			['rq.priority', 'desc'],
			['CASE WHEN rq.last IS NULL THEN 0 ELSE 1 END', 'asc'],
			['rq.last', 'asc'],
			['rq.id', 'asc'],
		], $this->order);
	}

	/**
	 * A row older than MAX_AGE is abandoned whatever its try count: the
	 * breaker lets one row of a dead host through per hold, and the rest
	 * would never spend their tries.
	 */
	public function testARowOlderThanTheWholeScheduleIsGivenUpOn(): void {
		$this->request->getStandby();

		$byAge = array_values(array_filter(
			$this->statements,
			static fn (array $where): bool => in_array('creation IS NOT NULL', $where, true)
		));
		$this->assertCount(1, $byAge, 'one statement gives up on rows by their age');
		$cutoff = null;
		foreach ($byAge[0] as $predicate) {
			if (preg_match('/^creation < @(\d+)$/', $predicate, $m) === 1) {
				$cutoff = (int)$m[1];
			}
		}
		$this->assertNotNull($cutoff);
		$this->assertEqualsWithDelta(time() - RequestQueueService::MAX_AGE, $cutoff, 2);
	}

	public function testWithoutAnAgeOnlyTheTriesDecide(): void {
		$this->request->abandonExhausted(RequestQueueService::MAX_TRIES);

		$this->assertCount(1, $this->statements);
	}

	/**
	 * The age has to leave every attempt a reachable peer is owed: the whole
	 * schedule, plus a cron interval (twelve minutes) per attempt.
	 */
	public function testTheAgeLimitLeavesTheWholeScheduleItsTime(): void {
		$schedule = 0;
		for ($tries = 1; $tries < RequestQueueService::MAX_TRIES; $tries++) {
			$schedule += RequestQueueService::retryDelay($tries);
		}

		$this->assertGreaterThan($schedule + RequestQueueService::MAX_TRIES * 12 * 60, RequestQueueService::MAX_AGE);
	}

	/**
	 * Whether anything is left under a token is a one-row read, not the
	 * fan-out hydrated with every signed body.
	 */
	public function testWhetherAnythingIsLeftUnderATokenReadsOneRow(): void {
		$this->assertFalse($this->request->hasToken('tok', 0));
		$this->assertSame(['token = tok', 'status = 0'], $this->statements[0]);
	}
}
