<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use ArrayIterator;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\SocialQueryBuilder;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryFunction;
use PDO;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Traversable;

/**
 * The inboxes a post is delivered to.
 *
 * The statement groups the followers by their delivery inbox, the shared one
 * where there is one: grouped as distinct (shared inbox, inbox) pairs it was
 * a row per follower. A follower on a server without a shared inbox is still
 * a row of their own, so an account with ten thousand such followers hands
 * back ten thousand rows. Each one used to be checked against the inboxes
 * kept so far with `in_array()`, which is fifty million comparisons on every
 * post that account writes. The statement itself is run against a database
 * in tests/Integration/Db/FollowLifecycleTest.
 */
#[AllowMockObjectsWithoutExpectations]
class FollowerInboxesTest extends TestCase {
	/** @var list<string> what the statement selected and grouped by */
	private array $selected = [];
	/** @var list<string> */
	private array $grouped = [];
	/** @var list<string> */
	private array $distinct = [];

	/**
	 * The rows the statement hands back for these followers, ungrouped: each
	 * as COALESCE(NULLIF(shared_inbox, ''), inbox) makes it.
	 *
	 * @param list<array{shared_inbox: string, inbox: string}> $followers
	 *
	 * @return list<array{delivery_inbox: string}>
	 */
	private static function rowsOf(array $followers): array {
		return array_map(
			static fn (array $follower): array => ['delivery_inbox' => $follower['shared_inbox'] !== '' ? $follower['shared_inbox'] : $follower['inbox']],
			$followers
		);
	}

	/**
	 * @param list<array{delivery_inbox: string}> $rows what the statement returns
	 *
	 * @return string[]
	 */
	private function inboxesFrom(array $rows): array {
		$result = new class($rows) implements IResult {
			private ArrayIterator $rows;

			public function __construct(array $rows) {
				$this->rows = new ArrayIterator($rows);
			}

			public function closeCursor(): bool {
				return true;
			}

			public function fetch(int $fetchMode = PDO::FETCH_ASSOC) {
				if (!$this->rows->valid()) {
					return false;
				}
				$row = $this->rows->current();
				$this->rows->next();

				return $row;
			}

			public function fetchAssociative(): array|false {
				return $this->fetch();
			}

			public function fetchNumeric(): array|false {
				$row = $this->fetch();

				return $row === false ? false : array_values($row);
			}

			public function fetchOne() {
				$row = $this->fetch();

				return $row === false ? false : reset($row);
			}

			public function fetchAll(int $fetchMode = PDO::FETCH_ASSOC): array {
				return $this->fetchAllAssociative();
			}

			public function fetchAllAssociative(): array {
				$all = [];
				while (($row = $this->fetch()) !== false) {
					$all[] = $row;
				}

				return $all;
			}

			public function fetchAllNumeric(): array {
				return array_map('array_values', $this->fetchAllAssociative());
			}

			public function fetchFirstColumn(): array {
				return array_map('reset', $this->fetchAllAssociative());
			}

			public function fetchColumn() {
				return $this->fetchOne();
			}

			public function rowCount(): int {
				return $this->rows->count();
			}

			public function iterateNumeric(): Traversable {
				return new ArrayIterator($this->fetchAllNumeric());
			}

			public function iterateAssociative(): Traversable {
				return new ArrayIterator($this->fetchAllAssociative());
			}
		};

		$qb = $this->createStub(SocialQueryBuilder::class);
		foreach (['from', 'innerJoin', 'where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('getColumnName')->willReturnCallback(static fn (string $column, string $alias = ''): string => $alias . '.' . $column);
		$qb->method('createFunction')->willReturnCallback(function (string $call): IQueryFunction {
			$function = $this->createStub(IQueryFunction::class);
			$function->method('__toString')->willReturn($call);

			return $function;
		});
		$qb->method('selectAlias')->willReturnCallback(function ($select, string $alias) use ($qb) {
			$this->selected[] = $select . ' AS ' . $alias;

			return $qb;
		});
		$qb->method('selectDistinct')->willReturnCallback(function ($select) use ($qb) {
			$this->distinct[] = (string)$select;

			return $qb;
		});
		$qb->method('groupBy')->willReturnCallback(function (...$by) use ($qb) {
			foreach ($by as $column) {
				$this->grouped[] = (string)$column;
			}

			return $qb;
		});
		$qb->method('expr')->willReturn(new FakeExpressions());
		$qb->method('prim')->willReturnCallback(static fn (string $id): string => md5($id));
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value): string => "'" . (string)$value . "'");
		$qb->method('executeQuery')->willReturn($result);

		$request = $this->getMockBuilder(FollowsRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getQueryBuilder'])
			->getMock();
		$request->method('getQueryBuilder')->willReturn($qb);

		return $request->getFollowerInboxes('https://cloud.example/@alice');
	}

	/**
	 * A hundred thousand followers on a few hundred servers are a few hundred
	 * rows: the delivery inbox is worked out and made distinct in the query.
	 * DISTINCT, not GROUP BY the expression, which MariaDB refuses under
	 * ONLY_FULL_GROUP_BY.
	 */
	public function testTheDatabaseGivesEachDeliveryInboxOnce(): void {
		$this->inboxesFrom([]);

		$this->assertSame(["COALESCE(NULLIF(ca.shared_inbox, ''), ca.inbox) AS delivery_inbox"], $this->distinct);
		$this->assertSame([], $this->grouped);
		$this->assertSame([], $this->selected);
	}

	public function testASharedInboxIsDeliveredToOnceAndAPersonalOneWhereThereIsNone(): void {
		$this->assertSame(
			['https://mastodon.example/inbox', 'https://small.example/users/carol/inbox'],
			$this->inboxesFrom(self::rowsOf([
				['shared_inbox' => 'https://mastodon.example/inbox', 'inbox' => 'https://mastodon.example/users/a/inbox'],
				['shared_inbox' => 'https://mastodon.example/inbox', 'inbox' => 'https://mastodon.example/users/b/inbox'],
				['shared_inbox' => '', 'inbox' => 'https://small.example/users/carol/inbox'],
				['shared_inbox' => '', 'inbox' => ''],
			]))
		);
	}

	/**
	 * Ten thousand followers, each on a server of their own with no shared
	 * inbox, and every one of them on a second row too: ten thousand inboxes
	 * out of twenty thousand rows, in the order they came, each once. Telling
	 * them apart is a lookup by key; with `in_array()` it was quadratic, and
	 * the bound here is the generous one that only that would miss.
	 */
	public function testTenThousandFollowersAreToldApartInLinearTime(): void {
		$rows = [];
		for ($i = 0; $i < 10000; $i++) {
			$rows[] = ['delivery_inbox' => 'https://host' . $i . '.example/users/someone/inbox'];
		}
		$rows = array_merge($rows, $rows);

		$started = hrtime(true);
		$inboxes = $this->inboxesFrom($rows);
		$elapsed = (hrtime(true) - $started) / 1e9;

		$this->assertCount(10000, $inboxes);
		$this->assertSame('https://host0.example/users/someone/inbox', $inboxes[0]);
		$this->assertSame('https://host9999.example/users/someone/inbox', $inboxes[9999]);
		$this->assertLessThan(0.15, $elapsed, 'telling 10,000 inboxes apart took ' . round($elapsed, 3) . ' s');
	}
}
