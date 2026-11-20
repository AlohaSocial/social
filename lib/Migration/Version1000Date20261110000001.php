<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCA\Social\Db\CacheDocumentsRequestBuilder;
use OCA\Social\Db\CoreRequestBuilder;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `social_cache_doc.local_copy_prim` and `resized_copy_prim`: the indexed md5
 * of each copy name, so `/media/{uuid}` finds its row without a scan.
 * Existing rows are filled in PHP (SQLite has no md5()), in keyset pages of
 * 500 by `nid`; a rerun writes nothing.
 */
class Version1000Date20261110000001 extends SimpleMigrationStep {
	private const TABLE = CoreRequestBuilder::TABLE_CACHE_DOCUMENTS;
	private const BATCH = 500;

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
		if (!$schema->hasTable(self::TABLE)) {
			return null;
		}

		$table = $schema->getTable(self::TABLE);
		$changed = false;
		foreach (['local_copy_prim' => 'social_cd_lcp', 'resized_copy_prim' => 'social_cd_rcp'] as $column => $index) {
			if (!$table->hasColumn($column)) {
				$table->addColumn($column, Types::STRING, ['notnull' => false, 'default' => '', 'length' => 32]);
				$changed = true;
			}
			if (!$table->hasIndex($index)) {
				$table->addIndex([$column], $index);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$table = '*PREFIX*' . self::TABLE;

		$keyed = 0;
		$after = 0;
		while (true) {
			// Only the keyset in SQL, a primary-key range everywhere; which rows lack a
			// key is decided here.
			$page = $this->connection->executeQuery(
				'SELECT `nid`, `local_copy`, `resized_copy`, `local_copy_prim`, `resized_copy_prim`'
				. ' FROM `' . $table . '` WHERE `nid` > ' . $after . ' ORDER BY `nid` ASC LIMIT ' . self::BATCH
			)->fetchAll();
			if ($page === []) {
				break;
			}

			$local = '';
			$resized = '';
			$nids = [];
			foreach ($page as $row) {
				$nid = (int)$row['nid'];
				$after = max($after, $nid);
				$localPrim = CacheDocumentsRequestBuilder::copyPrim((string)($row['local_copy'] ?? ''));
				$resizedPrim = CacheDocumentsRequestBuilder::copyPrim((string)($row['resized_copy'] ?? ''));
				if ($localPrim === (string)($row['local_copy_prim'] ?? '')
					&& $resizedPrim === (string)($row['resized_copy_prim'] ?? '')) {
					continue;
				}
				$nids[] = $nid;
				$local .= ' WHEN ' . $nid . ' THEN ' . $this->connection->quote($localPrim);
				$resized .= ' WHEN ' . $nid . ' THEN ' . $this->connection->quote($resizedPrim);
			}
			if ($nids !== []) {
				$keyed += $this->connection->executeStatement(
					'UPDATE `' . $table . '` SET'
					. ' `local_copy_prim` = CASE `nid`' . $local . ' ELSE `local_copy_prim` END,'
					. ' `resized_copy_prim` = CASE `nid`' . $resized . ' ELSE `resized_copy_prim` END'
					. ' WHERE `nid` IN (' . implode(', ', $nids) . ')'
				);
			}

			if (count($page) < self::BATCH) {
				break;
			}
		}

		if ($keyed > 0) {
			$output->info('keyed ' . $keyed . ' media document(s) by their copy names');
		}
	}
}
