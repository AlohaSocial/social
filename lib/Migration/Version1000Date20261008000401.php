<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCA\Social\Cron\SearchIndex;
use OCA\Social\Tools\SearchTerms;
use OCP\BackgroundJob\IJobList;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `social_search_term`: the words of each post, for the content search.
 *
 * One row per (word, post), unique on (term, nid), which every search reads;
 * `stream_id_prim` finds a post's rows on edit or delete. `term` is as wide
 * as `SearchTerms::MAX_LENGTH`, to fit MySQL's key length. `head`, the first
 * three characters, is indexed with the nid for prefix searches, which
 * PostgreSQL cannot answer from (term, nid) outside the C collation.
 *
 * Stored posts are indexed by `Cron\SearchIndex`, queued here rather than run
 * in maintenance mode.
 */
class Version1000Date20261008000401 extends SimpleMigrationStep {
	public function __construct(
		private IJobList $jobList,
	) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('social_search_term')) {
			return null;
		}

		$table = $schema->createTable('social_search_term');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 20, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('term', Types::STRING, ['length' => SearchTerms::MAX_LENGTH, 'notnull' => true]);
		$table->addColumn('head', Types::STRING, ['length' => SearchTerms::MIN_PREFIX, 'notnull' => true, 'default' => '']);
		$table->addColumn('nid', Types::BIGINT, ['length' => 20, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('stream_id_prim', Types::STRING, ['length' => 32, 'notnull' => true, 'default' => '']);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['term', 'nid'], 'social_srch_tn');
		$table->addIndex(['head', 'nid'], 'social_srch_hn');
		$table->addIndex(['stream_id_prim'], 'social_srch_sp');

		return $schema;
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->fill();
	}

	/**
	 * Queues the backfill, which marks the index ready when it reaches the
	 * newest post; `InstallBackfills` calls this on a fresh install, which
	 * runs migrations schema-only.
	 */
	public function fill(): void {
		if (!$this->jobList->has(SearchIndex::class, null)) {
			$this->jobList->add(SearchIndex::class);
		}
	}
}
