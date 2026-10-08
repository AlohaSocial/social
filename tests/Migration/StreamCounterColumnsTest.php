<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20221118000002;
use OCA\Social\Migration\Version1000Date20261008000600;
use OCP\DB\IResult;
use OCP\DB\Types;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PDO;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * The four counter columns of `social_stream`, and the backfill that fills
 * them from `details`.
 *
 * The backfill's statements run against an in-memory SQLite table, so what is
 * checked is what they do to rows rather than how they are spelled.
 */
#[AllowMockObjectsWithoutExpectations]
class StreamCounterColumnsTest extends TestCase {
	private const COLUMNS = ['count_replies', 'count_likes', 'count_boosts', 'count_dislikes'];

	private PDO $pdo;
	private int $pages = 0;

	public function testEachCounterIsANotNullIntegerDefaultingToZero(): void {
		$schema = MigrationReplay::run(
			[Version1000Date20221118000002::class],
			[IAppConfig::class => $this->createStub(IAppConfig::class)]
		);
		MigrationReplay::run([Version1000Date20261008000600::class], [], $schema);

		foreach (self::COLUMNS as $column) {
			[$type, $options] = $schema->getTable('social_stream')->addedColumn($column);
			$this->assertSame(Types::INTEGER, $type);
			$this->assertTrue($options['notnull']);
			$this->assertSame(0, $options['default']);
		}
	}

	public function testRunTwiceItAsksForNothingTheSecondTime(): void {
		$schema = MigrationReplay::run(
			[Version1000Date20221118000002::class],
			[IAppConfig::class => $this->createStub(IAppConfig::class)]
		);
		MigrationReplay::run([Version1000Date20261008000600::class], [], $schema);
		$once = $schema->shape();

		MigrationReplay::run([Version1000Date20261008000600::class], [], $schema);

		$this->assertSame($once, $schema->shape());
	}

	private function table(): void {
		$this->pdo = new PDO('sqlite::memory:');
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->pdo->exec(
			'CREATE TABLE social_stream (nid INTEGER PRIMARY KEY, details TEXT,'
			. ' count_replies INTEGER NOT NULL DEFAULT 0, count_likes INTEGER NOT NULL DEFAULT 0,'
			. ' count_boosts INTEGER NOT NULL DEFAULT 0, count_dislikes INTEGER NOT NULL DEFAULT 0)'
		);
	}

	private function row(int $nid, mixed $details): void {
		$insert = $this->pdo->prepare('INSERT INTO social_stream (nid, details) VALUES (?, ?)');
		$insert->execute([$nid, is_string($details) || $details === null ? $details : json_encode($details)]);
	}

	/** @return array<string, int> */
	private function counts(int $nid): array {
		$select = $this->pdo->prepare('SELECT ' . implode(', ', self::COLUMNS) . ' FROM social_stream WHERE nid = ?');
		$select->execute([$nid]);

		return array_map('intval', $select->fetch(PDO::FETCH_ASSOC));
	}

	private function backfill(): void {
		$pdo = $this->pdo;
		$test = $this;
		$connection = new class($pdo, $test) extends FakeConnection {
			public function __construct(
				private PDO $pdo,
				private StreamCounterColumnsTest $test,
			) {
				parent::__construct();
			}

			public function executeQuery(string $sql, ?array $params = null, $types = null): IResult {
				$this->test->page();
				$statement = $this->pdo->prepare(str_replace('*PREFIX*', '', $sql));
				$statement->execute($params ?? []);

				return $this->test->rowsAsResult($statement->fetchAll(PDO::FETCH_ASSOC));
			}

			public function executeStatement($sql, ?array $params = null, ?array $types = null): int {
				$statement = $this->pdo->prepare(str_replace('*PREFIX*', '', (string)$sql));
				$statement->execute($params ?? []);

				return $statement->rowCount();
			}
		};

		(new Version1000Date20261008000600($connection))
			->postSchemaChange($this->createStub(IOutput::class), static fn () => null, []);
	}

	public function page(): void {
		if (++$this->pages > 20) {
			$this->fail('the backfill keeps asking for pages: it does not advance');
		}
	}

	/** @param list<array<string, mixed>> $rows */
	public function rowsAsResult(array $rows): IResult {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);

		return $result;
	}

	#[RequiresPhpExtension('pdo_sqlite')]
	public function testTheCountersMoveOutOfTheJson(): void {
		$this->table();
		$this->row(1, ['replies' => 2, 'likes' => 5, 'boosts' => 1, 'remote_likes' => 4]);
		$this->row(2, ['dislikes' => '3', 'mentions' => []]);
		$this->row(3, []);

		$this->backfill();

		$this->assertSame(['count_replies' => 2, 'count_likes' => 5, 'count_boosts' => 1, 'count_dislikes' => 0], $this->counts(1));
		$this->assertSame(['count_replies' => 0, 'count_likes' => 0, 'count_boosts' => 0, 'count_dislikes' => 3], $this->counts(2));
		$this->assertSame(['count_replies' => 0, 'count_likes' => 0, 'count_boosts' => 0, 'count_dislikes' => 0], $this->counts(3));
	}

	/**
	 * Whatever the JSON holds, what reaches a column is a non-negative int a
	 * signed 32-bit INTEGER can take.
	 */
	#[RequiresPhpExtension('pdo_sqlite')]
	public function testOnlyBelievableNumbersAreWritten(): void {
		$this->table();
		$this->row(1, ['likes' => -4, 'boosts' => 'many', 'replies' => [3], 'dislikes' => 1e12]);
		$this->row(2, 'not json');
		$this->row(3, null);

		$this->backfill();

		$this->assertSame(
			['count_replies' => 0, 'count_likes' => 0, 'count_boosts' => 0, 'count_dislikes' => Version1000Date20261008000600::MAX],
			$this->counts(1)
		);
		$this->assertSame(0, array_sum($this->counts(2)));
		$this->assertSame(0, array_sum($this->counts(3)));
	}

	#[RequiresPhpExtension('pdo_sqlite')]
	public function testEveryPageIsReached(): void {
		$this->table();
		$total = Version1000Date20261008000600::BATCH * 2 + 7;
		for ($nid = 1; $nid <= $total; $nid++) {
			// most rows carry nothing to move, so a page can write nothing
			// and the walk still has to go on to the next one
			$this->row($nid, ($nid % 500 === 0 || $nid === $total) ? ['likes' => $nid] : []);
		}

		$this->backfill();

		$this->assertSame(500, $this->counts(500)['count_likes']);
		$this->assertSame($total, $this->counts($total)['count_likes']);
		$this->assertSame(3, $this->pages);
	}

	/**
	 * Run again, the step leaves a counter it filled alone: by then the column
	 * is what moves and the JSON is a number from the day of the upgrade.
	 */
	#[RequiresPhpExtension('pdo_sqlite')]
	public function testARowAlreadyFilledIsNotOverwrittenWithTheStaleJson(): void {
		$this->table();
		$this->row(1, ['likes' => 5]);
		$this->backfill();
		$this->pdo->exec('UPDATE social_stream SET count_likes = 9 WHERE nid = 1');

		$this->backfill();

		$this->assertSame(9, $this->counts(1)['count_likes']);
	}
}
