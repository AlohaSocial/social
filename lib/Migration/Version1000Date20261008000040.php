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
 * Bluesky apps logging in here (§6.3): the app passwords a person makes for
 * them, `social_atproto_app_password`, stored as hashes, and the sessions
 * those passwords opened, `social_atproto_session`, by the id of their
 * refresh token so a session can be ended and a password's sessions with it.
 */
class Version1000Date20261008000040 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('social_atproto_app_password')) {
			$table = $schema->createTable('social_atproto_app_password');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('name', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('hash', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('last_used', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['user_id', 'name'], 'social_atpap_un');
			$changed = true;
		}

		if (!$schema->hasTable('social_atproto_session')) {
			$table = $schema->createTable('social_atproto_session');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('jti', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('did', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('app_password_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('expires', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['jti'], 'social_atpse_jti');
			$table->addIndex(['app_password_id'], 'social_atpse_ap');
			$table->addIndex(['user_id'], 'social_atpse_user');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
