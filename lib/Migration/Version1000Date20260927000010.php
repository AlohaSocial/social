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
 * The three tables of self-registered external users.
 *
 * `social_ext_user` is the login of an external user: the Nextcloud user id,
 * which is also their handle, and the password hash. Everything else about
 * the person (email, display name, preferences) lives where it does for any
 * Nextcloud user. `social_ext_signup` is a registration that is waiting for
 * its email to be confirmed or for an administrator to approve it, and
 * `social_ext_invite` an invitation link.
 *
 * Guarded like the squash, so running it twice asks for nothing the second
 * time.
 */
class Version1000Date20260927000010 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('social_ext_user')) {
			$table = $schema->createTable('social_ext_user');
			$table->addColumn('uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('uid_lower', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('password', Types::STRING, ['length' => 255, 'notnull' => true, 'default' => '']);
			$table->addColumn('displayname', Types::STRING, ['length' => 255, 'notnull' => false]);
			$table->addColumn('origin', Types::STRING, ['length' => 64, 'notnull' => true, 'default' => '']);
			$table->addColumn('creation', Types::BIGINT, ['length' => 11, 'notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['uid']);
			$table->addUniqueIndex(['uid_lower'], 'social_extu_lower');
			$changed = true;
		}

		if (!$schema->hasTable('social_ext_signup')) {
			$table = $schema->createTable('social_ext_signup');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('handle', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('email', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('password', Types::STRING, ['length' => 255, 'notnull' => true, 'default' => '']);
			$table->addColumn('token', Types::STRING, ['length' => 64, 'notnull' => true, 'default' => '']);
			$table->addColumn('verified', Types::SMALLINT, ['length' => 1, 'notnull' => true, 'default' => 0]);
			$table->addColumn('approval', Types::SMALLINT, ['length' => 1, 'notnull' => true, 'default' => 0]);
			$table->addColumn('invite_id', Types::BIGINT, ['length' => 11, 'notnull' => true, 'default' => 0]);
			$table->addColumn('ip_hash', Types::STRING, ['length' => 64, 'notnull' => true, 'default' => '']);
			$table->addColumn('creation', Types::BIGINT, ['length' => 11, 'notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['handle'], 'social_exts_handle');
			$table->addIndex(['email'], 'social_exts_email');
			$table->addIndex(['token'], 'social_exts_token');
			$table->addIndex(['creation'], 'social_exts_c');
			$changed = true;
		}

		if (!$schema->hasTable('social_ext_invite')) {
			$table = $schema->createTable('social_ext_invite');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('token', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('creator', Types::STRING, ['length' => 64, 'notnull' => true, 'default' => '']);
			$table->addColumn('note', Types::STRING, ['length' => 255, 'notnull' => true, 'default' => '']);
			$table->addColumn('max_uses', Types::INTEGER, ['notnull' => true, 'default' => 1]);
			$table->addColumn('uses', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('expires', Types::BIGINT, ['length' => 11, 'notnull' => true, 'default' => 0]);
			$table->addColumn('creation', Types::BIGINT, ['length' => 11, 'notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['token'], 'social_exti_token');
			$table->addIndex(['creator'], 'social_exti_creator');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
