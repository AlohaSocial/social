<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `social_cache_doc.cached`: when the copy now on disk was written, or null
 * where there is none -- never stored, or taken away by the media cache purge.
 *
 * The purge needs both halves and neither existing column has them. `caching`
 * is when a fetch was *started*, and is null on every attachment stored while
 * its post arrived, which is most of them; `creation` is when the row was
 * written and does not move when a purged copy is fetched again. Indexed,
 * because the purge reads the cached copies oldest first.
 *
 * Rows that hold a copy already are given the best time there is: `caching`
 * where a fetch recorded one, else `creation`, else now.
 */
class Version1000Date20261102000001 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $connection,
	) {
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('social_cache_doc')) {
			return null;
		}

		$table = $schema->getTable('social_cache_doc');
		if ($table->hasColumn('cached')) {
			return null;
		}

		$table->addColumn('cached', Types::DATETIME, ['notnull' => false]);
		$table->addIndex(['cached'], 'social_cd_cached');

		return $schema;
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$table = '*PREFIX*' . CoreRequestBuilder::TABLE_CACHE_DOCUMENTS;
		$stored = '`cached` IS NULL AND `local_copy` IS NOT NULL AND `local_copy` NOT IN (?, ?, ?, ?)';
		$sentinels = ['', Document::COPY_LOCAL_AVATAR, 'header', Document::COPY_STREAMED];

		$dated = $this->connection->executeStatement(
			'UPDATE `' . $table . '` SET `cached` = `caching` WHERE ' . $stored . ' AND `caching` IS NOT NULL',
			$sentinels
		);
		$dated += $this->connection->executeStatement(
			'UPDATE `' . $table . '` SET `cached` = `creation` WHERE ' . $stored . ' AND `creation` IS NOT NULL',
			$sentinels
		);
		$dated += $this->connection->executeStatement(
			'UPDATE `' . $table . '` SET `cached` = ? WHERE ' . $stored,
			array_merge([(new \DateTime('now'))->format('Y-m-d H:i:s')], $sentinels)
		);

		if ($dated > 0) {
			$output->info('dated ' . $dated . ' stored media cop(y/ies) for the media cache purge');
		}
	}
}
