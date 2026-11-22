<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCA\Social\Db\CoreRequestBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Backfills `social_cache_actors.host`, the server an account is on, so
 * hosts can be grouped in SQL. The host never changes for a row (a moved
 * account has a new id). Rows not yet reached carry `''`, which grouping
 * skips.
 */
class Version1000Date20260920000002 extends SimpleMigrationStep {
	/**
	 * How many rows one backfill statement carries: three placeholders a row,
	 * 15,000 in all, inside SQLite's 32,766 limit (see
	 * `Version1000Date20260917000001`).
	 */
	private const BATCH = 5000;

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	/**
	 * The columns are in `Version1000Date20221118000002`; this fills the rows.
	 */

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// The connection rewrites `*PREFIX*`; no public API returns the prefix.
		$table = '*PREFIX*' . CoreRequestBuilder::TABLE_CACHE_ACTORS;

		$total = (int)$this->connection->executeQuery(
			'SELECT COUNT(*) FROM `' . $table . '` WHERE `host` = \'\' OR `host` IS NULL'
		)->fetchOne();
		if ($total < 1) {
			return;
		}

		$output->info('filling in the server of ' . $total . ' cached account(s)');

		// Keyset on `id_prim`: rows whose handle has no `@` are written back as ''
		// and would otherwise be selected again forever.
		$done = 0;
		$after = '';
		while (true) {
			$page = $this->connection->executeQuery(
				'SELECT `id_prim`, `account` FROM `' . $table . '`'
				. ' WHERE (`host` = \'\' OR `host` IS NULL) AND `id_prim` > ?'
				. ' ORDER BY `id_prim` ASC LIMIT ' . self::BATCH,
				[$after]
			)->fetchAll();

			if ($page === []) {
				break;
			}

			$prims = [];
			$cases = '';
			$values = [];
			foreach ($page as $row) {
				$prims[] = (string)$row['id_prim'];
				$cases .= ' WHEN ? THEN ?';
				$values[] = (string)$row['id_prim'];
				$values[] = self::hostOf((string)$row['account']);
				$after = (string)$row['id_prim'];
			}

			$in = implode(', ', array_fill(0, count($prims), '?'));
			$this->connection->executeStatement(
				'UPDATE `' . $table . '` SET `host` = CASE `id_prim`' . $cases . ' END'
				. ' WHERE `id_prim` IN (' . $in . ')',
				array_merge($values, $prims)
			);

			$done += count($page);
			$output->info('… ' . $done . '/' . $total);

			if (count($page) < self::BATCH) {
				break;
			}
		}
	}

	/**
	 * The server out of a handle; '' for a handle without `@`, a local account.
	 */
	public static function hostOf(string $account): string {
		$at = strrpos($account, '@');
		if ($at === false) {
			return '';
		}

		return strtolower(substr($account, $at + 1));
	}
}
