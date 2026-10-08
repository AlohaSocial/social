<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The indexes the app's hot queries cannot do without.
 *
 * This app has ninety-odd indexes across its migrations and nothing that says
 * which of them anything depends on. That is how a regression gets in: an
 * index is dropped or renamed in a migration written for another reason,
 * every test still passes, and the home timeline goes from fifty milliseconds
 * to a second and a half on an instance nobody can reproduce locally. It has
 * happened here before — `docs/Architecture.md` records the 1,661 ms home
 * timeline and what fixed it.
 *
 * A query plan test would be better and cannot be written here: `EXPLAIN` needs
 * a database with data in it, the unit suite has neither, and a plan is a
 * judgement of the planner rather than of the schema. What *can* be pinned is
 * the thing a plan depends on — that the index exists, on those columns, in
 * that order — and that is what this does. A column list is meaningful in
 * order: an index on (a, b) answers a query filtering on `a`, and one on
 * (b, a) does not.
 *
 * Adding a row here is how a future query says "this is load-bearing".
 */
class IndexCoverageTest extends TestCase {
	/**
	 * What the app reads often, and what each read needs to be answered from
	 * an index rather than from the table.
	 *
	 * @return iterable<string, array{string, string[]}>
	 */
	public static function loadBearing(): iterable {
		yield 'a page of somebody\'s timeline' => [
			'social_stream_dest', ['actor_id', 'type', 'nid'],
		];
		yield 'the posts of one account' => [
			'social_stream', ['attributed_to_prim'],
		];
		yield 'a page of one account\'s posts, newest first' => [
			'social_stream', ['attributed_to_prim', 'nid'],
		];
		yield 'the replies under a post' => [
			'social_stream', ['in_reply_to_prim'],
		];
		yield 'what a viewer did to a post' => [
			'social_stream_act', ['stream_id_prim', 'actor_id_prim'],
		];
		yield 'an account search, on every keystroke of the mention picker' => [
			'social_cache_actor', ['account_lower'],
		];
		yield 'the outbound queue a drain reads' => [
			'social_req_queue', ['status'],
		];
		yield 'the voters of the polls a sweep announces' => [
			'social_action', ['poll_prim'],
		];
		yield 'the polls that closed since the last sweep' => [
			'social_stream', ['poll_ends_at'],
		];
		yield 'the author\'s instance a domain block or a silence compares' => [
			'social_stream', ['author_host'],
		];
	}

	/**
	 * Every index any migration declares, as `table => list of column lists`.
	 *
	 * Read out of the migrations rather than out of a live schema for the same
	 * reason the rest of this directory is: there is no database here. A
	 * migration that adds an index and one that drops it are both visible, and
	 * the last word wins — which is what an instance ends up with.
	 *
	 * @return array<string, list<string[]>>
	 */
	private function declaredIndexes(): array {
		$indexes = [];
		$files = glob(__DIR__ . '/../../lib/Migration/*.php') ?: [];
		sort($files);

		foreach ($files as $file) {
			$source = (string)file_get_contents($file);

			// `$table = $schema->getTable(<name>);` … `$table->addIndex([…])`,
			// which is how every migration in this app declares one. The table
			// is whatever the nearest preceding getTable() named.
			preg_match_all(
				"/(?:getTable|createTable)\(\s*(?:'([^']+)'|[A-Za-z:\\\\_]+::TABLE_([A-Z_]+))\s*\)|add(?:Unique)?Index\(\s*\[([^\]]*)\]/",
				$source,
				$matches,
				PREG_SET_ORDER
			);

			$table = '';
			foreach ($matches as $match) {
				if (($match[1] ?? '') !== '' || ($match[2] ?? '') !== '') {
					$table = ($match[1] ?? '') !== ''
						? $match[1] : 'social_' . strtolower($match[2]);
					continue;
				}

				$columns = array_values(array_filter(array_map(
					static fn (string $column): string => trim($column, " \t\n'\""),
					explode(',', $match[3] ?? '')
				)));
				if ($table !== '' && $columns !== []) {
					$indexes[$table][] = $columns;
				}
			}
		}

		return $indexes;
	}

	/**
	 * @param string[] $columns
	 */
	#[DataProvider('loadBearing')]
	public function testTheIndexThisReadDependsOnIsStillDeclared(string $table, array $columns): void {
		$declared = $this->declaredIndexes();
		$this->assertArrayHasKey(
			$table,
			$declared,
			$table . ' has no index at all in any migration, and something reads it often'
		);

		$covered = false;
		foreach ($declared[$table] as $index) {
			// a prefix match, because an index on (a, b, c) answers a query
			// that needs (a, b) — and one on (b, a) does not answer (a, b)
			if (array_slice($index, 0, count($columns)) === $columns) {
				$covered = true;
				break;
			}
		}

		$this->assertTrue(
			$covered,
			$table . ' has no index starting with (' . implode(', ', $columns) . '). '
			. 'Something reads it that way often; if that is no longer true, '
			. 'take the row out of ' . self::class . '::loadBearing() and say why.'
		);
	}

	/**
	 * Looking an account up by its handle is **not** in the list above, and
	 * not by oversight.
	 *
	 * `CacheActorsRequest::getFromAccount()` filters with
	 * `LOWER(account) = LOWER(?)`, and no plain index answers a query that
	 * wraps its column in a function — so `social_cache_actor` is scanned on
	 * every WebFinger resolution and every mention. Adding an index would not
	 * fix it; what would is the `*_prim` idiom this app already uses for
	 * exactly this problem elsewhere, and that is a migration and a backfill
	 * rather than a line in a test.
	 */
	/** The reader itself: if it stops finding indexes, every row above passes for nothing. */
	public function testTheMigrationsAreBeingReadAtAll(): void {
		$declared = $this->declaredIndexes();

		$this->assertGreaterThan(20, array_sum(array_map('count', $declared)));
		$this->assertArrayHasKey('social_stream', $declared);
	}
}
