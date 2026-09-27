<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20260920000002;
use OCP\DB\IResult;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * The `social_cache_actors.host` backfill, run against a table held in PHP.
 *
 * The connection below reads the three shapes of statement the step sends —
 * the count, a page, the batched update — and applies them to `$rows`, so
 * the loop runs exactly as it would against a database, including the rows
 * the parser cannot place and writes back as `''`.
 */
#[AllowMockObjectsWithoutExpectations]
class CacheActorHostBackfillTest extends TestCase {
	/** @var array<string, array{account: string, host: string}> id_prim => row */
	private array $rows = [];
	private int $pages = 0;

	private function connection(): FakeConnection {
		$test = $this;

		return new class($test) extends FakeConnection {
			public function __construct(
				private CacheActorHostBackfillTest $test,
			) {
				parent::__construct();
			}

			public function executeQuery(string $sql, ?array $params = null, $types = null): IResult {
				return $this->test->answer($sql, $params ?? []);
			}

			public function executeStatement($sql, ?array $params = null, ?array $types = null): int {
				return $this->test->apply((string)$sql, $params ?? []);
			}
		};
	}

	public function answer(string $sql, array $params): IResult {
		$empty = array_filter($this->rows, static fn (array $row): bool => $row['host'] === '');
		ksort($empty, SORT_STRING);
		$result = $this->createMock(IResult::class);

		if (str_starts_with($sql, 'SELECT COUNT(*)')) {
			$result->method('fetchOne')->willReturn(count($empty));

			return $result;
		}

		$this->pages++;
		if ($this->pages > 10) {
			$this->fail('the backfill keeps asking for pages: it does not advance');
		}

		$after = (string)($params[0] ?? '');
		preg_match('/LIMIT (\d+)/', $sql, $limit);
		$page = [];
		foreach ($empty as $prim => $row) {
			if (str_contains($sql, '`id_prim` > ?') && strcmp((string)$prim, $after) <= 0) {
				continue;
			}
			$page[] = ['id_prim' => (string)$prim, 'account' => $row['account']];
			if (count($page) >= (int)$limit[1]) {
				break;
			}
		}
		$result->method('fetchAll')->willReturn($page);

		return $result;
	}

	public function apply(string $sql, array $params): int {
		$cases = substr_count($sql, 'WHEN ?');
		for ($i = 0; $i < $cases; $i++) {
			$this->rows[(string)$params[$i * 2]]['host'] = (string)$params[$i * 2 + 1];
		}

		return $cases;
	}

	private function backfill(): void {
		(new Version1000Date20260920000002($this->connection()))
			->postSchemaChange($this->createStub(IOutput::class), fn () => null, []);
	}

	public function testEveryRowWithAHandleGetsItsServer(): void {
		for ($i = 0; $i < 12; $i++) {
			$this->rows[sprintf('%032d', $i)] = ['account' => 'user' . $i . '@Example.org', 'host' => ''];
		}

		$this->backfill();

		foreach ($this->rows as $row) {
			$this->assertSame('example.org', $row['host']);
		}
	}

	/**
	 * More rows without an `@` than one page holds: each is written back as
	 * `''`, so it still matches the page query. The step has to get past them
	 * rather than read the same page again for ever.
	 */
	public function testAFullPageOfHandlesWithoutAServerDoesNotLoopForever(): void {
		for ($i = 0; $i < 5000 + 3; $i++) {
			$this->rows[sprintf('a%031d', $i)] = ['account' => '', 'host' => ''];
		}
		$this->rows['z' . str_repeat('0', 31)] = ['account' => 'late@remote.example', 'host' => ''];

		$this->backfill();

		$this->assertSame('remote.example', $this->rows['z' . str_repeat('0', 31)]['host']);
		$this->assertLessThanOrEqual(3, $this->pages);
	}
}
