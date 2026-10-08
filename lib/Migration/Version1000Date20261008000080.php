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
 * Moving a Bluesky account (§13): `social_atproto_move`, one row per move of
 * an account away from this server, with the other PDS, the step it got to,
 * what went wrong, and while it runs the session on the other PDS, sealed.
 */
class Version1000Date20261008000080 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('social_atproto_move')) {
			return null;
		}
		$table = $schema->createTable('social_atproto_move');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('did', Types::STRING, ['length' => 64, 'notnull' => true]);
		$table->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
		$table->addColumn('direction', Types::STRING, ['length' => 8, 'notnull' => true]);
		$table->addColumn('pds', Types::STRING, ['length' => 512, 'notnull' => true]);
		$table->addColumn('pds_did', Types::STRING, ['length' => 256, 'notnull' => true]);
		$table->addColumn('handle', Types::STRING, ['length' => 253, 'notnull' => true]);
		$table->addColumn('step', Types::STRING, ['length' => 16, 'notnull' => true]);
		$table->addColumn('state', Types::STRING, ['length' => 16, 'notnull' => true]);
		$table->addColumn('session', Types::TEXT, ['notnull' => true, 'default' => '']);
		$table->addColumn('progress', Types::TEXT, ['notnull' => true, 'default' => '']);
		$table->addColumn('error', Types::STRING, ['length' => 512, 'notnull' => true, 'default' => '']);
		$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('updated', Types::DATETIME, ['notnull' => false]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['did'], 'social_atpmv_did');
		$table->addIndex(['user_id'], 'social_atpmv_user');

		return $schema;
	}
}
