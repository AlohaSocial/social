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
 * `social_cache_actor.account_lower`: the handle, lowercased and indexed, so
 * account search and handle lookups can use an index rather than scan every
 * cached actor (see `CacheActorsRequest::searchAccounts()`). Derived from the
 * handle like `host`. A step of its own, since the squash is already
 * recorded as run on upgraded instances.
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
	 * Fills the rows written before the column existed, keyset-paged on the
	 * primary key. A row not reached yet is only missing from search.
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
