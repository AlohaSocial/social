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
 * Bluesky moderation (§12): the administrator's block list of Bluesky
 * hosts and accounts, `social_atproto_blocklist`, and the labelers each
 * person subscribes to with their choice per label, `social_atproto_labeler`.
 */
class Version1000Date20261008000030 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('social_atproto_blocklist')) {
			$table = $schema->createTable('social_atproto_blocklist');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('kind', Types::STRING, ['length' => 8, 'notnull' => true]);
			$table->addColumn('value', Types::STRING, ['length' => 253, 'notnull' => true]);
			$table->addColumn('reason', Types::TEXT, ['notnull' => true, 'default' => '']);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['kind', 'value'], 'social_atpbl_kv');
			$changed = true;
		}

		if (!$schema->hasTable('social_atproto_labeler')) {
			$table = $schema->createTable('social_atproto_labeler');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('did', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('settings', Types::TEXT, ['notnull' => true, 'default' => '']);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['user_id', 'did'], 'social_atplb_ud');
			$table->addIndex(['did'], 'social_atplb_did');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
