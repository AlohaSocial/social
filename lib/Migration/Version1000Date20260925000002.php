<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `social_cache_actor.account_lower`: the handle, lowercased, and indexed.
 *
 * The account search behind the composer's mention picker, `/api/v2/search`,
 * `/api/v1/accounts/search` and the unified search matched a prefix of
 * `account` case-insensitively — on MySQL by comparing it `COLLATE
 * utf8mb4_general_ci` — and the lookup of one account by its handle compared
 * `LOWER(account)`. `account` has no index, and neither comparison could have
 * used one: every keystroke was a scan of every cached actor. The lowercased
 * copy is compared as it stands (see `CacheActorsRequest::searchAccounts()`).
 *
 * Like `host`, it is derived from the handle, and written wherever the handle
 * is. A file of its own rather than lines in the squash, which every instance
 * that has upgraded has already recorded as run.
 */
class Version1000Date20260925000002 extends SimpleMigrationStep {
	/** Rows per backfill statement: three parameters a row, inside SQLite's 32,766. */
	public const BATCH = 5000;

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('social_cache_actor')) {
			return null;
		}

		$table = $schema->getTable('social_cache_actor');
		if (!$table->hasColumn('account_lower')) {
			// the width of `account`, which it is a copy of
			$table->addColumn('account_lower', Types::STRING, ['length' => 127, 'notnull' => false, 'default' => '']);
		}
		if (!$table->hasIndex('social_ca_al')) {
			$table->addIndex(['account_lower'], 'social_ca_al');
		}

		return $schema;
	}

	/**
	 * The rows written before the column existed, keyset-paged on the primary
	 * key. A row not reached yet is only missing from a search — it is found
	 * by its id everywhere else — and one pass of the upgrade reaches all.
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// `*PREFIX*` is what the connection rewrites; there is no public API
		// that hands the prefix over as a string
		$table = '`*PREFIX*' . CoreRequestBuilder::TABLE_CACHE_ACTORS . '`';

		$after = 0;
		$done = 0;
		while (true) {
			$page = $this->connection->executeQuery(
				'SELECT `nid`, `account` FROM ' . $table
				. ' WHERE `nid` > ? AND (`account_lower` = \'\' OR `account_lower` IS NULL)'
				. ' ORDER BY `nid` ASC LIMIT ' . self::BATCH,
				[(string)$after]
			)->fetchAll();
			if ($page === []) {
				break;
			}
			$after = (int)$page[count($page) - 1]['nid'];

			$cases = '';
			$values = [];
			$nids = [];
			foreach ($page as $row) {
				$cases .= ' WHEN ? THEN ?';
				$values[] = (string)$row['nid'];
				$values[] = CacheActorsRequest::lowerAccount((string)($row['account'] ?? ''));
				$nids[] = (string)$row['nid'];
			}

			$this->connection->executeStatement(
				'UPDATE ' . $table . ' SET `account_lower` = CASE `nid`' . $cases . ' END'
				. ' WHERE `nid` IN (' . implode(', ', array_fill(0, count($nids), '?')) . ')',
				array_merge($values, $nids)
			);

			$done += count($page);
			$output->info('lowercased the handle of ' . $done . ' cached account(s)');

			if (count($page) < self::BATCH) {
				break;
			}
		}
	}
}
