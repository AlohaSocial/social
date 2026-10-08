<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `social_stream` indexed on (attributed_to_prim, nid).
 *
 * A profile timeline, the account's statuses through the API and the
 * author-narrowed search all filter on one author and page newest-first by
 * nid. With an index on the author alone the database reads every post that
 * author ever wrote and sorts them before it can hand over twenty — on
 * PostgreSQL visibly so for any account with a long history. With the nid
 * second in the same index the page is a range read that stops at the limit.
 *
 * The single-column `attributed_to_prim` index stays: dropping it is a
 * rebuild of the largest table in the app for a saving on writes only.
 */
class Version1000Date20261008000400 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('social_stream')) {
			return null;
		}

		$table = $schema->getTable('social_stream');
		if ($table->hasIndex('social_s_atn')) {
			return null;
		}

		$table->addIndex(['attributed_to_prim', 'nid'], 'social_s_atn');

		return $schema;
	}
}
