<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Custom handles (§4.2): a handle on a domain the person owns, beside the
 * one this server assigned, which keeps resolving. When it was last
 * checked and how many checks in a row it failed, so a handle whose record
 * went away can be shown as broken.
 */
class Version1000Date20261008000070 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('social_atproto_identity')) {
			return null;
		}
		$table = $schema->getTable('social_atproto_identity');
		if ($table->hasColumn('custom_handle')) {
			return null;
		}
		$table->addColumn('custom_handle', Types::STRING, ['length' => 253, 'notnull' => true, 'default' => '']);
		$table->addColumn('custom_handle_checked', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('custom_handle_failures', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
		$table->addIndex(['custom_handle'], 'social_atpid_custom');

		return $schema;
	}
}
