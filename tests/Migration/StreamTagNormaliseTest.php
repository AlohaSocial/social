<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20260925000001;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The step that brings `social_stream_tag.hashtag` into its normalised form.
 *
 * The statements run against a table held here: what matters is which rows end
 * up holding which tag, that the unique (stream_id, hashtag) index is never
 * asked to hold two equal tags of one post, and that the walk ends.
 */
#[AllowMockObjectsWithoutExpectations]
class StreamTagNormaliseTest extends TestCase {
	/** @var array<int, array{id: int, stream_id: string, hashtag: string}> the table, by id */
	private array $table = [];

	/** @var list<string> every statement run */
	private array $statements = [];

	private function tag(int $id, string $stream, string $hashtag): void {
		$this->table[$id] = ['id' => $id, 'stream_id' => $stream, 'hashtag' => $hashtag];
	}

	private function connection(string $platform): IDBConnection {
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('getDatabaseProvider')->willReturn($platform);
		$connection->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params = []): IResult {
				$this->statements[] = $sql;
				if (str_starts_with($sql, 'SELECT `id`, `stream_id`, `hashtag`')) {
					preg_match('/LIMIT (\d+)$/', $sql, $m);
					$rows = array_values(array_filter(
						$this->table,
						static fn (array $row): bool => $row['id'] > (int)$params[0]
							&& (!str_contains($sql, 'LOWER') || $row['hashtag'] !== mb_strtolower($row['hashtag']))
					));
					usort($rows, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
					$rows = array_slice($rows, 0, (int)$m[1]);
				} elseif (str_starts_with($sql, 'SELECT `stream_id`, `hashtag`')) {
					$rows = array_values(array_filter(
						$this->table, static fn (array $row): bool => in_array($row['stream_id'], $params, true)
					));
				} else {
					$this->fail('unexpected query: ' . $sql);
				}

				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn($rows);

				return $result;
			}
		);
		$connection->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params = []): int {
				$this->statements[] = $sql;
				if (str_starts_with($sql, 'DELETE')) {
					foreach ($params as $id) {
						unset($this->table[$id]);
					}

					return count($params);
				}

				$this->assertStringStartsWith('UPDATE', $sql);
				$count = substr_count($sql, 'WHEN');
				for ($i = 0; $i < $count; $i++) {
					$this->table[(int)$params[2 * $i]]['hashtag'] = $params[2 * $i + 1];
				}
				$this->assertSame(
					array_map('intval', array_slice($params, 2 * $count)),
					array_map('intval', array_column(array_chunk(array_slice($params, 0, 2 * $count), 2), 0)),
					'the IN list names the rows the CASE rewrites'
				);
				$this->assertUnique();

				return $count;
			}
		);

		return $connection;
	}

	/** What the unique (stream_id, hashtag) index would refuse. */
	private function assertUnique(): void {
		$keys = array_map(static fn (array $row): string => $row['stream_id'] . '|' . $row['hashtag'], $this->table);
		$this->assertSame(count($keys), count(array_unique($keys)), 'two rows of one post hold the same tag');
	}

	private function migrate(string $platform = IDBConnection::PLATFORM_MYSQL): void {
		(new Version1000Date20260925000001($this->connection($platform)))
			->postSchemaChange($this->createStub(IOutput::class), static fn () => null, []);
	}

	/** @return array<string, list<string>> stream => its tags, sorted */
	private function tags(): array {
		$tags = [];
		foreach ($this->table as $row) {
			$tags[$row['stream_id']][] = $row['hashtag'];
		}
		foreach ($tags as &$list) {
			sort($list);
		}
		ksort($tags);

		return $tags;
	}

	/** @return array<string, array{string}> */
	public static function providePlatforms(): array {
		return [
			'mysql' => [IDBConnection::PLATFORM_MYSQL],
			'postgres' => [IDBConnection::PLATFORM_POSTGRES],
			'sqlite' => [IDBConnection::PLATFORM_SQLITE],
		];
	}

	#[DataProvider('providePlatforms')]
	public function testEveryTagEndsUpNormalised(string $platform): void {
		$this->tag(1, 'a', 'NextCloud');
		$this->tag(2, 'a', 'fediverse');
		$this->tag(3, 'b', 'Ärger');
		$this->tag(4, 'c', 'photography');

		$this->migrate($platform);

		$this->assertSame(
			['a' => ['fediverse', 'nextcloud'], 'b' => ['ärger'], 'c' => ['photography']],
			$this->tags()
		);
	}

	/** `#NextCloud` and `#nextcloud` on one post are one tag of it, once. */
	#[DataProvider('providePlatforms')]
	public function testTwoSpellingsOfOneTagOnOnePostBecomeOneRow(string $platform): void {
		$this->tag(1, 'a', 'NextCloud');
		$this->tag(2, 'a', 'nextcloud');
		$this->tag(3, 'a', 'NEXTCLOUD');
		$this->tag(4, 'b', 'NextCloud');

		$this->migrate($platform);

		$this->assertSame(['a' => ['nextcloud'], 'b' => ['nextcloud']], $this->tags());
	}

	/** The collision may sit in a later page than the row it collides with. */
	public function testACollisionAcrossPagesIsCaughtToo(): void {
		$this->tag(1, 'a', 'NextCloud');
		for ($id = 2; $id <= Version1000Date20260925000001::BATCH + 5; $id++) {
			$this->tag($id, 'x' . $id, 'Tag' . $id);
		}
		$this->tag(Version1000Date20260925000001::BATCH + 10, 'a', 'NEXTCLOUD');

		$this->migrate();

		$this->assertSame(['nextcloud'], $this->tags()['a']);
		$this->assertSame(['tag7'], $this->tags()['x7']);
	}

	public function testAnInstanceWhoseTagsAreAllNormalisedIsLeftAlone(): void {
		$this->tag(1, 'a', 'nextcloud');
		$this->tag(2, 'b', 'fediverse');

		$this->migrate();

		$this->assertSame([], array_filter($this->statements, static fn (string $sql): bool => !str_starts_with($sql, 'SELECT')));
	}

	/**
	 * MySQL and PostgreSQL fold Unicode in `LOWER()`, so only the rows that
	 * need it are read; SQLite's folds ASCII, so there the comparison is made
	 * here, over every row.
	 */
	public function testOnlySqliteReadsEveryRow(): void {
		$this->migrate(IDBConnection::PLATFORM_MYSQL);
		$this->assertStringContainsString('`hashtag` <> LOWER(`hashtag`)', $this->statements[0]);

		$this->statements = [];
		$this->migrate(IDBConnection::PLATFORM_SQLITE);
		$this->assertStringNotContainsString('LOWER', $this->statements[0]);
	}

	public function testATagThatNormalisesToNothingIsDropped(): void {
		$this->tag(1, 'a', '#');
		$this->tag(2, 'a', 'Kept');

		$this->migrate(IDBConnection::PLATFORM_SQLITE);

		$this->assertSame(['a' => ['kept']], $this->tags());
	}
}
