<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCA\Social\Db\CoreRequestBuilder;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Brings tables created before 0.23 to the shape a fresh install has, where
 * the squashed schema (`Version1000Date20221118000002`, which only adds) cannot:
 *
 *  - drops redundant indexes: `afoa`, `aoa` and `social_f_oa` on
 *    `social_follow`; `ipoha` on `social_stream`; `ts` on
 *    `social_stream_dest`; and the unique index duplicating the primary key
 *    on `social_stream.nid`.
 *  - widens `social_client.app_client_secret` from 63 characters for the
 *    hashed secret.
 *  - adds the default of `social_cache_doc.account`.
 *  - makes `id` the primary key of `social_stream_dest` and
 *    `social_stream_tag` on PostgreSQL.
 *
 * Every change is guarded, so this is a no-op on a current instance.
 */
class Version1000Date20261011500100 extends SimpleMigrationStep {
	/** Indexes the history dropped, by table. */
	private const STALE_INDEXES = [
		CoreRequestBuilder::TABLE_FOLLOWS => ['afoa', 'aoa', 'social_f_oa'],
		CoreRequestBuilder::TABLE_STREAM => ['ipoha'],
		CoreRequestBuilder::TABLE_STREAM_DEST => ['ts'],
	];

	private const CLIENT_SECRET_LENGTH = 127;

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		foreach (self::STALE_INDEXES as $tableName => $indexes) {
			if (!$schema->hasTable($tableName)) {
				continue;
			}
			$table = $schema->getTable($tableName);
			foreach ($indexes as $index) {
				if ($table->hasIndex($index)) {
					$table->dropIndex($index);
					$changed = true;
				}
			}
		}

		if ($schema->hasTable(CoreRequestBuilder::TABLE_STREAM)) {
			$table = $schema->getTable(CoreRequestBuilder::TABLE_STREAM);
			// created without a name, so the database chose one
			foreach ($table->getIndexes() as $index) {
				if ($index->isUnique() && !$index->isPrimary() && $index->getColumns() === ['nid']) {
					$table->dropIndex($index->getName());
					$changed = true;
				}
			}
		}

		if ($schema->hasTable(CoreRequestBuilder::TABLE_CLIENT)) {
			$table = $schema->getTable(CoreRequestBuilder::TABLE_CLIENT);
			if ($table->hasColumn('app_client_secret')
				&& ($table->getColumn('app_client_secret')->getLength() ?? 0) < self::CLIENT_SECRET_LENGTH) {
				$table->getColumn('app_client_secret')->setLength(self::CLIENT_SECRET_LENGTH);
				$changed = true;
			}
		}

		if ($schema->hasTable(CoreRequestBuilder::TABLE_CACHE_DOCUMENTS)) {
			$table = $schema->getTable(CoreRequestBuilder::TABLE_CACHE_DOCUMENTS);
			if ($table->hasColumn('account') && $table->getColumn('account')->getDefault() === null) {
				$table->getColumn('account')->setDefault('');
				$changed = true;
			}
		}

		foreach ([CoreRequestBuilder::TABLE_STREAM_DEST, CoreRequestBuilder::TABLE_STREAM_TAGS] as $tableName) {
			if (!$schema->hasTable($tableName)) {
				continue;
			}
			$table = $schema->getTable($tableName);
			if ($table->hasColumn('id') && !$table->hasPrimaryKey()) {
				$table->setPrimaryKey(['id']);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
