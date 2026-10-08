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
 * OAuth for Bluesky apps (§6.3): the pushed authorization requests waiting
 * for a person's consent and then for their code to be exchanged
 * (`social_atproto_oauth_request`), the sessions that exchange started,
 * bound to the app's DPoP key and holding the hash of the one refresh token
 * that is good (`social_atproto_oauth_session`), and the one-time values a
 * proof or a client assertion may not repeat (`social_atproto_oauth_replay`).
 */
class Version1000Date20261008000060 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('social_atproto_oauth_request')) {
			$table = $schema->createTable('social_atproto_oauth_request');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('request_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('client_id', Types::TEXT, ['notnull' => true]);
			$table->addColumn('client_auth', Types::TEXT, ['notnull' => true]);
			$table->addColumn('params', Types::TEXT, ['notnull' => true]);
			$table->addColumn('dpop_jkt', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('code_challenge', Types::STRING, ['length' => 128, 'notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true, 'default' => '']);
			$table->addColumn('did', Types::STRING, ['length' => 64, 'notnull' => true, 'default' => '']);
			$table->addColumn('code_hash', Types::STRING, ['length' => 64, 'notnull' => true, 'default' => '']);
			$table->addColumn('session_id', Types::STRING, ['length' => 64, 'notnull' => true, 'default' => '']);
			$table->addColumn('expires', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['request_id'], 'social_atpoar_req');
			$table->addIndex(['code_hash'], 'social_atpoar_code');
			$table->addIndex(['code_challenge'], 'social_atpoar_pkce');
			$table->addIndex(['expires'], 'social_atpoar_exp');
			$changed = true;
		}

		if (!$schema->hasTable('social_atproto_oauth_session')) {
			$table = $schema->createTable('social_atproto_oauth_session');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('session_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('did', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('client_id', Types::TEXT, ['notnull' => true]);
			$table->addColumn('client_auth', Types::TEXT, ['notnull' => true]);
			$table->addColumn('scope', Types::STRING, ['length' => 1024, 'notnull' => true]);
			$table->addColumn('dpop_jkt', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('refresh_hash', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('previous_hash', Types::STRING, ['length' => 64, 'notnull' => true, 'default' => '']);
			$table->addColumn('refresh_expires', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('expires', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('last_used', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['session_id'], 'social_atpoas_sid');
			$table->addUniqueIndex(['refresh_hash'], 'social_atpoas_ref');
			$table->addIndex(['previous_hash'], 'social_atpoas_prev');
			$table->addIndex(['user_id'], 'social_atpoas_user');
			$table->addIndex(['expires'], 'social_atpoas_exp');
			$changed = true;
		}

		if (!$schema->hasTable('social_atproto_oauth_replay')) {
			$table = $schema->createTable('social_atproto_oauth_replay');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('hash', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('expires', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['hash'], 'social_atpoarp_hash');
			$table->addIndex(['expires'], 'social_atpoarp_exp');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
