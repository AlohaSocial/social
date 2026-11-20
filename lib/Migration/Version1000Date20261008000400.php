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
 * `social_stream` indexed on (attributed_to_prim, nid), so one author's posts
 * newest first (profiles, statuses, author-narrowed search) are a range read
 * that stops at the limit. The single-column index stays: dropping it would
 * rebuild the largest table for a small write saving.
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
