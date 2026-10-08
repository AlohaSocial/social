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
 * One row per (word, post). Unique on (term, nid), which is both what makes
 * indexing a post twice a no-op and the index every search reads: one word
 * is an equality on its leading column, read in nid order and cut at the
 * page. `stream_id_prim` is how the rows of a post are found again when it is
 * edited or deleted, which is keyed on that everywhere else in the app.
 *
 * `term` is as wide as `SearchTerms::MAX_LENGTH` and no wider, so the index
 * stays inside MySQL's key length; a longer word is stored cut. `head` is its
 * first three characters, indexed with the nid, for the search that ends in
 * the beginning of a word: PostgreSQL cannot answer `LIKE 'zeb%'` from the
 * (term, nid) index outside the C collation, and an equality on the head it
 * can, in nid order (see `SearchTerms::head()`).
 *
 * The posts already stored are added by `Cron\SearchIndex`, queued here and
 * not run here: tokenising every post of a large instance is not something
 * to do with it in maintenance mode, and the search keeps its bounded scan
 * until the job has reached the newest post.
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
		if (!$this->jobList->has(SearchIndex::class, null)) {
			$this->jobList->add(SearchIndex::class);
		}
	}
}
