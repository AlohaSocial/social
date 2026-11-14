<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
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
 * OAuth refresh tokens for the client API (`ClientService::refresh()`).
 *
 * - `social_client_auth.refresh_token`: the digest of the authorization's
 *   current refresh token, '' for one that has none.
 * - `social_client_auth.token_creation`: when the current access token was
 *   issued, which a refresh moves; null for a row from before this step,
 *   whose lifetime is counted from `creation` as before.
 * - `social_client_auth.refresh_count`: how many times it was refreshed.
 * - `social_client_refresh_spent`: the digests of refresh tokens already
 *   exchanged, by authorization, so that presenting one again is recognised
 *   as a copy and revokes the authorization.
 *
 * Nothing is backfilled: an authorization from before has no refresh token
 * and keeps working until its lifetime ends, after which its client signs in
 * again and receives one.
 */
class Version1000Date20261113500001 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable(CoreRequestBuilder::TABLE_CLIENT_AUTH)) {
			$table = $schema->getTable(CoreRequestBuilder::TABLE_CLIENT_AUTH);
			if (!$table->hasColumn('refresh_token')) {
				$table->addColumn('refresh_token', Types::STRING, ['length' => 127, 'notnull' => false, 'default' => '']);
				$changed = true;
			}
			if (!$table->hasColumn('token_creation')) {
				$table->addColumn('token_creation', Types::DATETIME, ['notnull' => false]);
				$changed = true;
			}
			if (!$table->hasColumn('refresh_count')) {
				$table->addColumn('refresh_count', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
				$changed = true;
			}
			if (!$table->hasIndex('social_ca_rtok')) {
				$table->addIndex(['refresh_token'], 'social_ca_rtok');
				$changed = true;
			}
		}

		if (!$schema->hasTable(CoreRequestBuilder::TABLE_CLIENT_REFRESH_SPENT)) {
			$table = $schema->createTable(CoreRequestBuilder::TABLE_CLIENT_REFRESH_SPENT);
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('auth_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('token', Types::STRING, ['length' => 127, 'notnull' => true]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['token'], 'social_crs_tok');
			$table->addIndex(['auth_id'], 'social_crs_auth');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
