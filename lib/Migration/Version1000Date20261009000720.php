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
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `social_list.visibility`: whether a list is its owner's alone (`private`,
 * what every list was before) or `public`, published to Bluesky as a curate
 * list with its members (`BlueskyLists`).
 */
class Version1000Date20261009000720 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable(CoreRequestBuilder::TABLE_LISTS)) {
			return null;
		}
		$table = $schema->getTable(CoreRequestBuilder::TABLE_LISTS);
		if ($table->hasColumn('visibility')) {
			return null;
		}
		$table->addColumn('visibility', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'private']);

		return $schema;
	}
}
