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
 * The whole schema, in one step.
 *
 * This replaces the sixty-seven migrations that built it between November 2022
 * and September 2026. A fresh install ran all sixty-seven to reach a shape one
 * file can describe, and nobody could read that shape without replaying them
 * in their head.
 *
 * It was not written by hand. `tests/Migration/SquashedSchemaTest.php` replays
 * every migration this app has ever had against a recording schema, replays
 * this one against another, and fails if the two differ in any table, column,
 * type, option, index or key — so what is below is what the history produced,
 * and it goes on being that or CI says so.
 *
 * **Every step here is guarded**, and that is what makes it safe to run
 * anywhere. A table is created only if it is absent and a column added only if
 * it is missing, so the same file brings up an empty database, completes a
 * half-upgraded one, and does nothing at all on an instance that is already
 * current. No instance is left behind by this and there is no floor on the
 * version you may upgrade from.
 *
 * **The name is a sort key, not a date.** Nextcloud runs migrations in version
 * order, and this one has to run before the six that remain — each of those
 * moves data and needs its tables to exist. `20221118000002` puts it first,
 * immediately after the original initial step it subsumes. It was written on
 * 21 September 2026.
 *
 * The six that remain are the ones that do more than shape a table: they read
 * and rewrite rows, which no schema can express and this cannot replace.
 */
class Version1000Date20221118000002 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 *
	 * @return ISchemaWrapper
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('social_action')) {
			$table = $schema->createTable('social_action');
			$table->addColumn('id', Types::TEXT, ['notnull' => false]);
			$table->addColumn('id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			$table->addColumn('type', Types::STRING, ['default' => '', 'length' => 31, 'notnull' => false]);
			$table->addColumn('actor_id', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('actor_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			$table->addColumn('object_id', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('object_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id_prim']);
			$table->addUniqueIndex(['actor_id_prim', 'object_id_prim', 'type'], 'apopt');
			$table->addIndex(['object_id_prim', 'type'], 'social_a_oit');
		} else {
			$table = $schema->getTable('social_action');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('id_prim')) {
				$table->addColumn('id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('type')) {
				$table->addColumn('type', Types::STRING, ['default' => '', 'length' => 31, 'notnull' => false]);
			}
			if (!$table->hasColumn('actor_id')) {
				$table->addColumn('actor_id', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('actor_id_prim')) {
				$table->addColumn('actor_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('object_id')) {
				$table->addColumn('object_id', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('object_id_prim')) {
				$table->addColumn('object_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('apopt')) {
				$table->addUniqueIndex(['actor_id_prim', 'object_id_prim', 'type'], 'apopt');
			}
			if (!$table->hasIndex('social_a_oit')) {
				$table->addIndex(['object_id_prim', 'type'], 'social_a_oit');
			}
		}

		if (!$schema->hasTable('social_actor')) {
			$table = $schema->createTable('social_actor');
			$table->addColumn('id', Types::TEXT, ['notnull' => false]);
			$table->addColumn('id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			$table->addColumn('user_id', Types::STRING, ['length' => 63, 'notnull' => false]);
			$table->addColumn('preferred_username', Types::STRING, ['length' => 127, 'notnull' => false]);
			$table->addColumn('name', Types::STRING, ['default' => '', 'length' => 127, 'notnull' => false]);
			$table->addColumn('summary', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('public_key', Types::TEXT, ['notnull' => false]);
			$table->addColumn('private_key', Types::TEXT, ['notnull' => false]);
			$table->addColumn('avatar_version', Types::INTEGER, ['length' => 2, 'notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('deleted', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('locked', Types::SMALLINT, ['default' => 0, 'length' => 1, 'notnull' => true]);
			$table->addColumn('fields', Types::TEXT, ['notnull' => false]);
			$table->addColumn('discoverable', Types::SMALLINT, ['default' => 0, 'length' => 1, 'notnull' => true]);
			$table->addColumn('indexable', Types::SMALLINT, ['default' => 0, 'length' => 1, 'notnull' => true]);
			$table->addColumn('also_known_as', Types::TEXT, ['notnull' => false]);
			$table->addColumn('moved_to', Types::TEXT, ['notnull' => false]);
			$table->addColumn('bot', Types::BOOLEAN, ['default' => false, 'notnull' => false]);
			$table->addColumn('actor_type', Types::STRING, ['default' => '', 'length' => 31, 'notnull' => false]);
			$table->setPrimaryKey(['id_prim']);
			$table->addIndex(['user_id'], 'social_a_uid');
		} else {
			$table = $schema->getTable('social_actor');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('id_prim')) {
				$table->addColumn('id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('user_id')) {
				$table->addColumn('user_id', Types::STRING, ['length' => 63, 'notnull' => false]);
			}
			if (!$table->hasColumn('preferred_username')) {
				$table->addColumn('preferred_username', Types::STRING, ['length' => 127, 'notnull' => false]);
			}
			if (!$table->hasColumn('name')) {
				$table->addColumn('name', Types::STRING, ['default' => '', 'length' => 127, 'notnull' => false]);
			}
			if (!$table->hasColumn('summary')) {
				$table->addColumn('summary', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('public_key')) {
				$table->addColumn('public_key', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('private_key')) {
				$table->addColumn('private_key', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('avatar_version')) {
				$table->addColumn('avatar_version', Types::INTEGER, ['length' => 2, 'notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasColumn('deleted')) {
				$table->addColumn('deleted', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasColumn('locked')) {
				$table->addColumn('locked', Types::SMALLINT, ['default' => 0, 'length' => 1, 'notnull' => true]);
			}
			if (!$table->hasColumn('fields')) {
				$table->addColumn('fields', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('discoverable')) {
				$table->addColumn('discoverable', Types::SMALLINT, ['default' => 0, 'length' => 1, 'notnull' => true]);
			}
			if (!$table->hasColumn('indexable')) {
				$table->addColumn('indexable', Types::SMALLINT, ['default' => 0, 'length' => 1, 'notnull' => true]);
			}
			if (!$table->hasColumn('also_known_as')) {
				$table->addColumn('also_known_as', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('moved_to')) {
				$table->addColumn('moved_to', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('bot')) {
				$table->addColumn('bot', Types::BOOLEAN, ['default' => false, 'notnull' => false]);
			}
			if (!$table->hasColumn('actor_type')) {
				$table->addColumn('actor_type', Types::STRING, ['default' => '', 'length' => 31, 'notnull' => false]);
			}
			if (!$table->hasIndex('social_a_uid')) {
				$table->addIndex(['user_id'], 'social_a_uid');
			}
		}

		if (!$schema->hasTable('social_cache_actor')) {
			$table = $schema->createTable('social_cache_actor');
			$table->addColumn('nid', Types::BIGINT, ['autoincrement' => true, 'length' => 14, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('id', Types::TEXT, ['notnull' => false]);
			$table->addColumn('id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			$table->addColumn('type', Types::STRING, ['default' => '', 'length' => 31, 'notnull' => false]);
			$table->addColumn('account', Types::STRING, ['default' => '', 'length' => 127, 'notnull' => false]);
			$table->addColumn('local', Types::BOOLEAN, ['default' => false, 'notnull' => true]);
			$table->addColumn('following', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('followers', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('inbox', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('shared_inbox', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('outbox', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('featured', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('url', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('preferred_username', Types::STRING, ['default' => '', 'length' => 127, 'notnull' => false]);
			$table->addColumn('name', Types::STRING, ['default' => '', 'length' => 127, 'notnull' => false]);
			$table->addColumn('icon_id', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('summary', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('public_key', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('source', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('details', Types::TEXT, ['notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('details_update', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('sync_attempt', Types::BIGINT, ['default' => 0, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('sync_failures', Types::INTEGER, ['default' => 0, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('count_followers', Types::INTEGER, ['default' => -1, 'notnull' => false]);
			$table->addColumn('count_following', Types::INTEGER, ['default' => -1, 'notnull' => false]);
			$table->addColumn('count_posts', Types::INTEGER, ['default' => -1, 'notnull' => false]);
			$table->addColumn('host', Types::STRING, ['default' => '', 'length' => 255, 'notnull' => false]);
			$table->setPrimaryKey(['nid']);
			$table->addUniqueIndex(['id_prim']);
			$table->addIndex(['local', 'details_update'], 'social_ca_ldu');
			$table->addIndex(['local', 'sync_attempt'], 'social_ca_lsa');
			$table->addIndex(['host'], 'social_ca_host');
		} else {
			$table = $schema->getTable('social_cache_actor');
			if (!$table->hasColumn('nid')) {
				$table->addColumn('nid', Types::BIGINT, ['autoincrement' => true, 'length' => 14, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('id_prim')) {
				$table->addColumn('id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('type')) {
				$table->addColumn('type', Types::STRING, ['default' => '', 'length' => 31, 'notnull' => false]);
			}
			if (!$table->hasColumn('account')) {
				$table->addColumn('account', Types::STRING, ['default' => '', 'length' => 127, 'notnull' => false]);
			}
			if (!$table->hasColumn('local')) {
				$table->addColumn('local', Types::BOOLEAN, ['default' => false, 'notnull' => true]);
			}
			if (!$table->hasColumn('following')) {
				$table->addColumn('following', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('followers')) {
				$table->addColumn('followers', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('inbox')) {
				$table->addColumn('inbox', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('shared_inbox')) {
				$table->addColumn('shared_inbox', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('outbox')) {
				$table->addColumn('outbox', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('featured')) {
				$table->addColumn('featured', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('url')) {
				$table->addColumn('url', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('preferred_username')) {
				$table->addColumn('preferred_username', Types::STRING, ['default' => '', 'length' => 127, 'notnull' => false]);
			}
			if (!$table->hasColumn('name')) {
				$table->addColumn('name', Types::STRING, ['default' => '', 'length' => 127, 'notnull' => false]);
			}
			if (!$table->hasColumn('icon_id')) {
				$table->addColumn('icon_id', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('summary')) {
				$table->addColumn('summary', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('public_key')) {
				$table->addColumn('public_key', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('source')) {
				$table->addColumn('source', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('details')) {
				$table->addColumn('details', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasColumn('details_update')) {
				$table->addColumn('details_update', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasColumn('sync_attempt')) {
				$table->addColumn('sync_attempt', Types::BIGINT, ['default' => 0, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('sync_failures')) {
				$table->addColumn('sync_failures', Types::INTEGER, ['default' => 0, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('count_followers')) {
				$table->addColumn('count_followers', Types::INTEGER, ['default' => -1, 'notnull' => false]);
			}
			if (!$table->hasColumn('count_following')) {
				$table->addColumn('count_following', Types::INTEGER, ['default' => -1, 'notnull' => false]);
			}
			if (!$table->hasColumn('count_posts')) {
				$table->addColumn('count_posts', Types::INTEGER, ['default' => -1, 'notnull' => false]);
			}
			if (!$table->hasColumn('host')) {
				$table->addColumn('host', Types::STRING, ['default' => '', 'length' => 255, 'notnull' => false]);
			}
			if (!$table->hasIndex('social_ca_ldu')) {
				$table->addIndex(['local', 'details_update'], 'social_ca_ldu');
			}
			if (!$table->hasIndex('social_ca_lsa')) {
				$table->addIndex(['local', 'sync_attempt'], 'social_ca_lsa');
			}
			if (!$table->hasIndex('social_ca_host')) {
				$table->addIndex(['host'], 'social_ca_host');
			}
		}

		if (!$schema->hasTable('social_cache_doc')) {
			$table = $schema->createTable('social_cache_doc');
			$table->addColumn('nid', Types::BIGINT, ['autoincrement' => true, 'length' => 14, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('id', Types::TEXT, ['notnull' => false]);
			$table->addColumn('id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			$table->addColumn('type', Types::STRING, ['default' => '', 'length' => 31, 'notnull' => false]);
			$table->addColumn('parent_id', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('parent_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			$table->addColumn('media_type', Types::STRING, ['default' => '', 'length' => 63, 'notnull' => false]);
			$table->addColumn('mime_type', Types::STRING, ['default' => '', 'length' => 63, 'notnull' => false]);
			$table->addColumn('url', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('local_copy', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('resized_copy', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('account', Types::STRING, ['default' => '', 'length' => 127, 'notnull' => true]);
			$table->addColumn('meta', Types::TEXT, ['default' => '[]', 'notnull' => true]);
			$table->addColumn('blurhash', Types::STRING, ['default' => '', 'length' => 63, 'notnull' => true]);
			$table->addColumn('description', Types::TEXT, ['default' => '', 'notnull' => true]);
			$table->addColumn('public', Types::BOOLEAN, ['default' => false, 'notnull' => false]);
			$table->addColumn('error', Types::SMALLINT, ['length' => 1, 'notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('caching', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('transcoded', Types::SMALLINT, ['default' => 0, 'length' => 1, 'notnull' => false]);
			$table->addColumn('size', Types::BIGINT, ['default' => 0, 'length' => 15, 'notnull' => false, 'unsigned' => true]);
			$table->addColumn('laddered', Types::SMALLINT, ['default' => 0, 'notnull' => false]);
			$table->setPrimaryKey(['nid']);
			$table->addIndex(['id_prim'], 'social_cd_idp');
			$table->addIndex(['parent_id_prim'], 'social_cd_pidp');
		} else {
			$table = $schema->getTable('social_cache_doc');
			if (!$table->hasColumn('nid')) {
				$table->addColumn('nid', Types::BIGINT, ['autoincrement' => true, 'length' => 14, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('id_prim')) {
				$table->addColumn('id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('type')) {
				$table->addColumn('type', Types::STRING, ['default' => '', 'length' => 31, 'notnull' => false]);
			}
			if (!$table->hasColumn('parent_id')) {
				$table->addColumn('parent_id', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('parent_id_prim')) {
				$table->addColumn('parent_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('media_type')) {
				$table->addColumn('media_type', Types::STRING, ['default' => '', 'length' => 63, 'notnull' => false]);
			}
			if (!$table->hasColumn('mime_type')) {
				$table->addColumn('mime_type', Types::STRING, ['default' => '', 'length' => 63, 'notnull' => false]);
			}
			if (!$table->hasColumn('url')) {
				$table->addColumn('url', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('local_copy')) {
				$table->addColumn('local_copy', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('resized_copy')) {
				$table->addColumn('resized_copy', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('account')) {
				$table->addColumn('account', Types::STRING, ['default' => '', 'length' => 127, 'notnull' => true]);
			}
			if (!$table->hasColumn('meta')) {
				$table->addColumn('meta', Types::TEXT, ['default' => '[]', 'notnull' => true]);
			}
			if (!$table->hasColumn('blurhash')) {
				$table->addColumn('blurhash', Types::STRING, ['default' => '', 'length' => 63, 'notnull' => true]);
			}
			if (!$table->hasColumn('description')) {
				$table->addColumn('description', Types::TEXT, ['default' => '', 'notnull' => true]);
			}
			if (!$table->hasColumn('public')) {
				$table->addColumn('public', Types::BOOLEAN, ['default' => false, 'notnull' => false]);
			}
			if (!$table->hasColumn('error')) {
				$table->addColumn('error', Types::SMALLINT, ['length' => 1, 'notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasColumn('caching')) {
				$table->addColumn('caching', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasColumn('transcoded')) {
				$table->addColumn('transcoded', Types::SMALLINT, ['default' => 0, 'length' => 1, 'notnull' => false]);
			}
			if (!$table->hasColumn('size')) {
				$table->addColumn('size', Types::BIGINT, ['default' => 0, 'length' => 15, 'notnull' => false, 'unsigned' => true]);
			}
			if (!$table->hasColumn('laddered')) {
				$table->addColumn('laddered', Types::SMALLINT, ['default' => 0, 'notnull' => false]);
			}
			if (!$table->hasIndex('social_cd_idp')) {
				$table->addIndex(['id_prim'], 'social_cd_idp');
			}
			if (!$table->hasIndex('social_cd_pidp')) {
				$table->addIndex(['parent_id_prim'], 'social_cd_pidp');
			}
		}

		if (!$schema->hasTable('social_client')) {
			$table = $schema->createTable('social_client');
			$table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'length' => 7, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('app_name', Types::STRING, ['default' => '', 'length' => 127, 'notnull' => false]);
			$table->addColumn('app_website', Types::STRING, ['default' => '', 'length' => 255, 'notnull' => false]);
			$table->addColumn('app_redirect_uris', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('app_client_id', Types::STRING, ['default' => '', 'length' => 63, 'notnull' => false]);
			$table->addColumn('app_client_secret', Types::STRING, ['default' => '', 'length' => 127, 'notnull' => false]);
			$table->addColumn('app_scopes', Types::TEXT, ['notnull' => false]);
			$table->addColumn('auth_scopes', Types::TEXT, ['notnull' => false]);
			$table->addColumn('auth_account', Types::STRING, ['default' => '', 'length' => 127, 'notnull' => false]);
			$table->addColumn('auth_user_id', Types::STRING, ['default' => '', 'length' => 127, 'notnull' => false]);
			$table->addColumn('auth_code', Types::STRING, ['default' => '', 'length' => 127, 'notnull' => false]);
			$table->addColumn('token', Types::STRING, ['default' => '', 'length' => 127, 'notnull' => false]);
			$table->addColumn('last_update', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['auth_code', 'token', 'app_client_id', 'app_client_secret']);
			$table->addIndex(['token'], 'social_cl_tok');
		} else {
			$table = $schema->getTable('social_client');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'length' => 7, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('app_name')) {
				$table->addColumn('app_name', Types::STRING, ['default' => '', 'length' => 127, 'notnull' => false]);
			}
			if (!$table->hasColumn('app_website')) {
				$table->addColumn('app_website', Types::STRING, ['default' => '', 'length' => 255, 'notnull' => false]);
			}
			if (!$table->hasColumn('app_redirect_uris')) {
				$table->addColumn('app_redirect_uris', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('app_client_id')) {
				$table->addColumn('app_client_id', Types::STRING, ['default' => '', 'length' => 63, 'notnull' => false]);
			}
			if (!$table->hasColumn('app_client_secret')) {
				$table->addColumn('app_client_secret', Types::STRING, ['default' => '', 'length' => 127, 'notnull' => false]);
			}
			if (!$table->hasColumn('app_scopes')) {
				$table->addColumn('app_scopes', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('auth_scopes')) {
				$table->addColumn('auth_scopes', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('auth_account')) {
				$table->addColumn('auth_account', Types::STRING, ['default' => '', 'length' => 127, 'notnull' => false]);
			}
			if (!$table->hasColumn('auth_user_id')) {
				$table->addColumn('auth_user_id', Types::STRING, ['default' => '', 'length' => 127, 'notnull' => false]);
			}
			if (!$table->hasColumn('auth_code')) {
				$table->addColumn('auth_code', Types::STRING, ['default' => '', 'length' => 127, 'notnull' => false]);
			}
			if (!$table->hasColumn('token')) {
				$table->addColumn('token', Types::STRING, ['default' => '', 'length' => 127, 'notnull' => false]);
			}
			if (!$table->hasColumn('last_update')) {
				$table->addColumn('last_update', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_cl_tok')) {
				$table->addIndex(['token'], 'social_cl_tok');
			}
		}

		if (!$schema->hasTable('social_follow')) {
			$table = $schema->createTable('social_follow');
			$table->addColumn('id', Types::TEXT, ['notnull' => false]);
			$table->addColumn('id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			$table->addColumn('type', Types::STRING, ['default' => '', 'length' => 31, 'notnull' => false]);
			$table->addColumn('actor_id', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('actor_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			$table->addColumn('object_id', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('object_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			$table->addColumn('follow_id', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('follow_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			$table->addColumn('accepted', Types::BOOLEAN, ['default' => false, 'notnull' => true]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id_prim']);
			$table->addIndex(['actor_id_prim', 'accepted'], 'social_f_aa');
			$table->addIndex(['object_id_prim', 'creation'], 'social_f_ocr');
			$table->addUniqueIndex(['follow_id_prim', 'object_id_prim', 'actor_id_prim'], 'social_f_foa');
			$table->addUniqueIndex(['object_id_prim', 'actor_id_prim'], 'social_f_oa_u');
			$table->addIndex(['actor_id_prim', 'accepted', 'type', 'follow_id_prim'], 'social_f_aatf');
		} else {
			$table = $schema->getTable('social_follow');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('id_prim')) {
				$table->addColumn('id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('type')) {
				$table->addColumn('type', Types::STRING, ['default' => '', 'length' => 31, 'notnull' => false]);
			}
			if (!$table->hasColumn('actor_id')) {
				$table->addColumn('actor_id', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('actor_id_prim')) {
				$table->addColumn('actor_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('object_id')) {
				$table->addColumn('object_id', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('object_id_prim')) {
				$table->addColumn('object_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('follow_id')) {
				$table->addColumn('follow_id', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('follow_id_prim')) {
				$table->addColumn('follow_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('accepted')) {
				$table->addColumn('accepted', Types::BOOLEAN, ['default' => false, 'notnull' => true]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_f_aa')) {
				$table->addIndex(['actor_id_prim', 'accepted'], 'social_f_aa');
			}
			if (!$table->hasIndex('social_f_ocr')) {
				$table->addIndex(['object_id_prim', 'creation'], 'social_f_ocr');
			}
			if (!$table->hasIndex('social_f_foa')) {
				$table->addUniqueIndex(['follow_id_prim', 'object_id_prim', 'actor_id_prim'], 'social_f_foa');
			}
			if (!$table->hasIndex('social_f_oa_u')) {
				$table->addUniqueIndex(['object_id_prim', 'actor_id_prim'], 'social_f_oa_u');
			}
			if (!$table->hasIndex('social_f_aatf')) {
				$table->addIndex(['actor_id_prim', 'accepted', 'type', 'follow_id_prim'], 'social_f_aatf');
			}
		}

		if (!$schema->hasTable('social_hashtag')) {
			$table = $schema->createTable('social_hashtag');
			$table->addColumn('hashtag', Types::STRING, ['length' => 127, 'notnull' => false]);
			$table->addColumn('trend', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('trend_1h', Types::INTEGER, ['default' => 0, 'notnull' => false]);
			$table->addColumn('trend_12h', Types::INTEGER, ['default' => 0, 'notnull' => false]);
			$table->addColumn('trend_1d', Types::INTEGER, ['default' => 0, 'notnull' => false]);
			$table->addColumn('trend_3d', Types::INTEGER, ['default' => 0, 'notnull' => false]);
			$table->addColumn('trend_10d', Types::INTEGER, ['default' => 0, 'notnull' => false]);
			$table->setPrimaryKey(['hashtag']);
			$table->addIndex(['trend_1d'], 'social_h_t1d');
			$table->addIndex(['trend_1h'], 'social_h_t1h');
			$table->addIndex(['trend_12h'], 'social_h_t12h');
			$table->addIndex(['trend_3d'], 'social_h_t3d');
			$table->addIndex(['trend_10d'], 'social_h_t10d');
		} else {
			$table = $schema->getTable('social_hashtag');
			if (!$table->hasColumn('hashtag')) {
				$table->addColumn('hashtag', Types::STRING, ['length' => 127, 'notnull' => false]);
			}
			if (!$table->hasColumn('trend')) {
				$table->addColumn('trend', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('trend_1h')) {
				$table->addColumn('trend_1h', Types::INTEGER, ['default' => 0, 'notnull' => false]);
			}
			if (!$table->hasColumn('trend_12h')) {
				$table->addColumn('trend_12h', Types::INTEGER, ['default' => 0, 'notnull' => false]);
			}
			if (!$table->hasColumn('trend_1d')) {
				$table->addColumn('trend_1d', Types::INTEGER, ['default' => 0, 'notnull' => false]);
			}
			if (!$table->hasColumn('trend_3d')) {
				$table->addColumn('trend_3d', Types::INTEGER, ['default' => 0, 'notnull' => false]);
			}
			if (!$table->hasColumn('trend_10d')) {
				$table->addColumn('trend_10d', Types::INTEGER, ['default' => 0, 'notnull' => false]);
			}
			if (!$table->hasIndex('social_h_t1d')) {
				$table->addIndex(['trend_1d'], 'social_h_t1d');
			}
			if (!$table->hasIndex('social_h_t1h')) {
				$table->addIndex(['trend_1h'], 'social_h_t1h');
			}
			if (!$table->hasIndex('social_h_t12h')) {
				$table->addIndex(['trend_12h'], 'social_h_t12h');
			}
			if (!$table->hasIndex('social_h_t3d')) {
				$table->addIndex(['trend_3d'], 'social_h_t3d');
			}
			if (!$table->hasIndex('social_h_t10d')) {
				$table->addIndex(['trend_10d'], 'social_h_t10d');
			}
		}

		if (!$schema->hasTable('social_instance')) {
			$table = $schema->createTable('social_instance');
			$table->addColumn('local', Types::SMALLINT, ['default' => 0, 'length' => 1, 'notnull' => false, 'unsigned' => true]);
			$table->addColumn('uri', Types::STRING, ['length' => 255, 'notnull' => false]);
			$table->addColumn('title', Types::STRING, ['default' => '', 'length' => 255, 'notnull' => false]);
			$table->addColumn('version', Types::STRING, ['default' => '', 'length' => 31, 'notnull' => false]);
			$table->addColumn('short_description', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('description', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('email', Types::STRING, ['default' => '', 'length' => 255, 'notnull' => false]);
			$table->addColumn('urls', Types::TEXT, ['default' => '[]', 'notnull' => false]);
			$table->addColumn('stats', Types::TEXT, ['default' => '[]', 'notnull' => false]);
			$table->addColumn('usage', Types::TEXT, ['default' => '[]', 'notnull' => false]);
			$table->addColumn('image', Types::STRING, ['default' => '', 'length' => 255, 'notnull' => false]);
			$table->addColumn('languages', Types::TEXT, ['default' => '[]', 'notnull' => false]);
			$table->addColumn('contact', Types::STRING, ['default' => '', 'length' => 127, 'notnull' => false]);
			$table->addColumn('account_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['uri']);
			$table->addIndex(['local', 'uri', 'account_prim']);
		} else {
			$table = $schema->getTable('social_instance');
			if (!$table->hasColumn('local')) {
				$table->addColumn('local', Types::SMALLINT, ['default' => 0, 'length' => 1, 'notnull' => false, 'unsigned' => true]);
			}
			if (!$table->hasColumn('uri')) {
				$table->addColumn('uri', Types::STRING, ['length' => 255, 'notnull' => false]);
			}
			if (!$table->hasColumn('title')) {
				$table->addColumn('title', Types::STRING, ['default' => '', 'length' => 255, 'notnull' => false]);
			}
			if (!$table->hasColumn('version')) {
				$table->addColumn('version', Types::STRING, ['default' => '', 'length' => 31, 'notnull' => false]);
			}
			if (!$table->hasColumn('short_description')) {
				$table->addColumn('short_description', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('description')) {
				$table->addColumn('description', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('email')) {
				$table->addColumn('email', Types::STRING, ['default' => '', 'length' => 255, 'notnull' => false]);
			}
			if (!$table->hasColumn('urls')) {
				$table->addColumn('urls', Types::TEXT, ['default' => '[]', 'notnull' => false]);
			}
			if (!$table->hasColumn('stats')) {
				$table->addColumn('stats', Types::TEXT, ['default' => '[]', 'notnull' => false]);
			}
			if (!$table->hasColumn('usage')) {
				$table->addColumn('usage', Types::TEXT, ['default' => '[]', 'notnull' => false]);
			}
			if (!$table->hasColumn('image')) {
				$table->addColumn('image', Types::STRING, ['default' => '', 'length' => 255, 'notnull' => false]);
			}
			if (!$table->hasColumn('languages')) {
				$table->addColumn('languages', Types::TEXT, ['default' => '[]', 'notnull' => false]);
			}
			if (!$table->hasColumn('contact')) {
				$table->addColumn('contact', Types::STRING, ['default' => '', 'length' => 127, 'notnull' => false]);
			}
			if (!$table->hasColumn('account_prim')) {
				$table->addColumn('account_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
		}

		if (!$schema->hasTable('social_req_queue')) {
			$table = $schema->createTable('social_req_queue');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('token', Types::STRING, ['length' => 63, 'notnull' => false]);
			$table->addColumn('author', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('author_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			$table->addColumn('activity', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('instance', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('priority', Types::SMALLINT, ['default' => 0, 'length' => 1, 'notnull' => false]);
			$table->addColumn('status', Types::SMALLINT, ['default' => 0, 'length' => 1, 'notnull' => false]);
			$table->addColumn('tries', Types::SMALLINT, ['default' => 0, 'length' => 2, 'notnull' => false]);
			$table->addColumn('last', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('object_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['token']);
			$table->addIndex(['status', 'id'], 'social_rq_si');
			$table->addIndex(['tries'], 'social_rq_tries');
			$table->addIndex(['object_id_prim'], 'social_rq_object');
			$table->addIndex(['status', 'priority', 'tries', 'last'], 'social_rq_sptl');
		} else {
			$table = $schema->getTable('social_req_queue');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('token')) {
				$table->addColumn('token', Types::STRING, ['length' => 63, 'notnull' => false]);
			}
			if (!$table->hasColumn('author')) {
				$table->addColumn('author', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('author_prim')) {
				$table->addColumn('author_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('activity')) {
				$table->addColumn('activity', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('instance')) {
				$table->addColumn('instance', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('priority')) {
				$table->addColumn('priority', Types::SMALLINT, ['default' => 0, 'length' => 1, 'notnull' => false]);
			}
			if (!$table->hasColumn('status')) {
				$table->addColumn('status', Types::SMALLINT, ['default' => 0, 'length' => 1, 'notnull' => false]);
			}
			if (!$table->hasColumn('tries')) {
				$table->addColumn('tries', Types::SMALLINT, ['default' => 0, 'length' => 2, 'notnull' => false]);
			}
			if (!$table->hasColumn('last')) {
				$table->addColumn('last', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasColumn('object_id_prim')) {
				$table->addColumn('object_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => true]);
			}
			if (!$table->hasIndex('social_rq_si')) {
				$table->addIndex(['status', 'id'], 'social_rq_si');
			}
			if (!$table->hasIndex('social_rq_tries')) {
				$table->addIndex(['tries'], 'social_rq_tries');
			}
			if (!$table->hasIndex('social_rq_object')) {
				$table->addIndex(['object_id_prim'], 'social_rq_object');
			}
			if (!$table->hasIndex('social_rq_sptl')) {
				$table->addIndex(['status', 'priority', 'tries', 'last'], 'social_rq_sptl');
			}
		}

		if (!$schema->hasTable('social_stream')) {
			$table = $schema->createTable('social_stream');
			$table->addColumn('nid', Types::BIGINT, ['length' => 20, 'unsigned' => true]);
			$table->addColumn('id', Types::TEXT, ['notnull' => false]);
			$table->addColumn('id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			$table->addColumn('type', Types::STRING, ['default' => '', 'length' => 31, 'notnull' => false]);
			$table->addColumn('subtype', Types::STRING, ['default' => '', 'length' => 31, 'notnull' => false]);
			$table->addColumn('visibility', Types::STRING, ['default' => '', 'length' => 31, 'notnull' => false]);
			$table->addColumn('to', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('to_array', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('cc', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('bcc', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('content', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('summary', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('published', Types::STRING, ['default' => '', 'length' => 31, 'notnull' => false]);
			$table->addColumn('published_time', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('attributed_to', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('attributed_to_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			$table->addColumn('in_reply_to', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('in_reply_to_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			$table->addColumn('activity_id', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('object_id', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('object_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			$table->addColumn('hashtags', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('details', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('source', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('instances', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('attachments', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('cache', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('local', Types::BOOLEAN, ['default' => false, 'notnull' => false]);
			$table->addColumn('filter_duplicate', Types::BOOLEAN, ['default' => false, 'notnull' => false]);
			$table->addColumn('sensitive', Types::SMALLINT, ['default' => 0, 'length' => 1, 'notnull' => true]);
			$table->addColumn('tags', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('language', Types::STRING, ['default' => '', 'length' => 15, 'notnull' => false]);
			$table->addColumn('updated', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('quote', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('quote_authorization', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('place_id', Types::BIGINT, ['default' => 0, 'length' => 11, 'notnull' => false, 'unsigned' => true]);
			$table->addColumn('archived', Types::BOOLEAN, ['default' => false, 'notnull' => false]);
			$table->addColumn('quote_policy', Types::STRING, ['default' => '', 'length' => 15, 'notnull' => false]);
			$table->addColumn('media_kind', Types::STRING, ['default' => null, 'length' => 7, 'notnull' => false]);
			$table->addColumn('news_kind', Types::STRING, ['default' => null, 'length' => 7, 'notnull' => false]);
			$table->setPrimaryKey(['nid']);
			$table->addUniqueIndex(['id_prim']);
			$table->addIndex(['object_id_prim'], 'object_id_prim');
			$table->addIndex(['in_reply_to_prim'], 'in_reply_to_prim');
			$table->addIndex(['attributed_to_prim'], 'attributed_to_prim');
			$table->addIndex(['published_time'], 'social_s_pub');
			$table->addIndex(['creation'], 'social_s_crea');
			$table->addIndex(['language'], 'social_s_lang');
			$table->addIndex(['place_id'], 'social_s_place');
			$table->addIndex(['media_kind', 'nid'], 'social_s_mk');
			$table->addIndex(['news_kind', 'nid'], 'social_s_nk');
		} else {
			$table = $schema->getTable('social_stream');
			if (!$table->hasColumn('nid')) {
				$table->addColumn('nid', Types::BIGINT, ['length' => 20, 'unsigned' => true]);
			}
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('id_prim')) {
				$table->addColumn('id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('type')) {
				$table->addColumn('type', Types::STRING, ['default' => '', 'length' => 31, 'notnull' => false]);
			}
			if (!$table->hasColumn('subtype')) {
				$table->addColumn('subtype', Types::STRING, ['default' => '', 'length' => 31, 'notnull' => false]);
			}
			if (!$table->hasColumn('visibility')) {
				$table->addColumn('visibility', Types::STRING, ['default' => '', 'length' => 31, 'notnull' => false]);
			}
			if (!$table->hasColumn('to')) {
				$table->addColumn('to', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('to_array')) {
				$table->addColumn('to_array', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('cc')) {
				$table->addColumn('cc', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('bcc')) {
				$table->addColumn('bcc', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('content')) {
				$table->addColumn('content', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('summary')) {
				$table->addColumn('summary', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('published')) {
				$table->addColumn('published', Types::STRING, ['default' => '', 'length' => 31, 'notnull' => false]);
			}
			if (!$table->hasColumn('published_time')) {
				$table->addColumn('published_time', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasColumn('attributed_to')) {
				$table->addColumn('attributed_to', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('attributed_to_prim')) {
				$table->addColumn('attributed_to_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('in_reply_to')) {
				$table->addColumn('in_reply_to', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('in_reply_to_prim')) {
				$table->addColumn('in_reply_to_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('activity_id')) {
				$table->addColumn('activity_id', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('object_id')) {
				$table->addColumn('object_id', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('object_id_prim')) {
				$table->addColumn('object_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('hashtags')) {
				$table->addColumn('hashtags', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('details')) {
				$table->addColumn('details', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('source')) {
				$table->addColumn('source', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('instances')) {
				$table->addColumn('instances', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('attachments')) {
				$table->addColumn('attachments', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('cache')) {
				$table->addColumn('cache', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasColumn('local')) {
				$table->addColumn('local', Types::BOOLEAN, ['default' => false, 'notnull' => false]);
			}
			if (!$table->hasColumn('filter_duplicate')) {
				$table->addColumn('filter_duplicate', Types::BOOLEAN, ['default' => false, 'notnull' => false]);
			}
			if (!$table->hasColumn('sensitive')) {
				$table->addColumn('sensitive', Types::SMALLINT, ['default' => 0, 'length' => 1, 'notnull' => true]);
			}
			if (!$table->hasColumn('tags')) {
				$table->addColumn('tags', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('language')) {
				$table->addColumn('language', Types::STRING, ['default' => '', 'length' => 15, 'notnull' => false]);
			}
			if (!$table->hasColumn('updated')) {
				$table->addColumn('updated', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasColumn('quote')) {
				$table->addColumn('quote', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('quote_authorization')) {
				$table->addColumn('quote_authorization', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('place_id')) {
				$table->addColumn('place_id', Types::BIGINT, ['default' => 0, 'length' => 11, 'notnull' => false, 'unsigned' => true]);
			}
			if (!$table->hasColumn('archived')) {
				$table->addColumn('archived', Types::BOOLEAN, ['default' => false, 'notnull' => false]);
			}
			if (!$table->hasColumn('quote_policy')) {
				$table->addColumn('quote_policy', Types::STRING, ['default' => '', 'length' => 15, 'notnull' => false]);
			}
			if (!$table->hasColumn('media_kind')) {
				$table->addColumn('media_kind', Types::STRING, ['default' => null, 'length' => 7, 'notnull' => false]);
			}
			if (!$table->hasColumn('news_kind')) {
				$table->addColumn('news_kind', Types::STRING, ['default' => null, 'length' => 7, 'notnull' => false]);
			}
			if (!$table->hasIndex('object_id_prim')) {
				$table->addIndex(['object_id_prim'], 'object_id_prim');
			}
			if (!$table->hasIndex('in_reply_to_prim')) {
				$table->addIndex(['in_reply_to_prim'], 'in_reply_to_prim');
			}
			if (!$table->hasIndex('attributed_to_prim')) {
				$table->addIndex(['attributed_to_prim'], 'attributed_to_prim');
			}
			if (!$table->hasIndex('social_s_pub')) {
				$table->addIndex(['published_time'], 'social_s_pub');
			}
			if (!$table->hasIndex('social_s_crea')) {
				$table->addIndex(['creation'], 'social_s_crea');
			}
			if (!$table->hasIndex('social_s_lang')) {
				$table->addIndex(['language'], 'social_s_lang');
			}
			if (!$table->hasIndex('social_s_place')) {
				$table->addIndex(['place_id'], 'social_s_place');
			}
			if (!$table->hasIndex('social_s_mk')) {
				$table->addIndex(['media_kind', 'nid'], 'social_s_mk');
			}
			if (!$table->hasIndex('social_s_nk')) {
				$table->addIndex(['news_kind', 'nid'], 'social_s_nk');
			}
		}

		if (!$schema->hasTable('social_stream_act')) {
			$table = $schema->createTable('social_stream_act');
			$table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('actor_id', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('actor_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			$table->addColumn('stream_id', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('stream_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			$table->addColumn('liked', Types::BOOLEAN, ['default' => false]);
			$table->addColumn('boosted', Types::BOOLEAN, ['default' => false]);
			$table->addColumn('replied', Types::BOOLEAN, ['default' => false]);
			$table->addColumn('values', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('bookmarked', Types::SMALLINT, ['default' => 0, 'length' => 1, 'notnull' => true]);
			$table->addColumn('disliked', Types::BOOLEAN, ['default' => false, 'notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['stream_id_prim', 'actor_id_prim'], 'sa');
			$table->addIndex(['actor_id_prim', 'liked'], 'social_sa_al');
			$table->addIndex(['actor_id_prim', 'bookmarked'], 'social_sa_ab');
		} else {
			$table = $schema->getTable('social_stream_act');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('actor_id')) {
				$table->addColumn('actor_id', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('actor_id_prim')) {
				$table->addColumn('actor_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('stream_id')) {
				$table->addColumn('stream_id', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('stream_id_prim')) {
				$table->addColumn('stream_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('liked')) {
				$table->addColumn('liked', Types::BOOLEAN, ['default' => false]);
			}
			if (!$table->hasColumn('boosted')) {
				$table->addColumn('boosted', Types::BOOLEAN, ['default' => false]);
			}
			if (!$table->hasColumn('replied')) {
				$table->addColumn('replied', Types::BOOLEAN, ['default' => false]);
			}
			if (!$table->hasColumn('values')) {
				$table->addColumn('values', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('bookmarked')) {
				$table->addColumn('bookmarked', Types::SMALLINT, ['default' => 0, 'length' => 1, 'notnull' => true]);
			}
			if (!$table->hasColumn('disliked')) {
				$table->addColumn('disliked', Types::BOOLEAN, ['default' => false, 'notnull' => false]);
			}
			if (!$table->hasIndex('sa')) {
				$table->addUniqueIndex(['stream_id_prim', 'actor_id_prim'], 'sa');
			}
			if (!$table->hasIndex('social_sa_al')) {
				$table->addIndex(['actor_id_prim', 'liked'], 'social_sa_al');
			}
			if (!$table->hasIndex('social_sa_ab')) {
				$table->addIndex(['actor_id_prim', 'bookmarked'], 'social_sa_ab');
			}
		}

		if (!$schema->hasTable('social_stream_dest')) {
			$table = $schema->createTable('social_stream_dest');
			$table->addColumn('stream_id', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			$table->addColumn('actor_id', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			$table->addColumn('type', Types::STRING, ['default' => '', 'length' => 15, 'notnull' => false]);
			$table->addColumn('subtype', Types::STRING, ['default' => '', 'length' => 7, 'notnull' => false]);
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('nid', Types::BIGINT, ['default' => 0, 'length' => 11, 'notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['stream_id', 'actor_id', 'type'], 'sat');
			$table->addIndex(['actor_id', 'type'], 'social_sd_at');
			$table->addIndex(['actor_id', 'type', 'nid'], 'social_sd_atn');
		} else {
			$table = $schema->getTable('social_stream_dest');
			if (!$table->hasColumn('stream_id')) {
				$table->addColumn('stream_id', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('actor_id')) {
				$table->addColumn('actor_id', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('type')) {
				$table->addColumn('type', Types::STRING, ['default' => '', 'length' => 15, 'notnull' => false]);
			}
			if (!$table->hasColumn('subtype')) {
				$table->addColumn('subtype', Types::STRING, ['default' => '', 'length' => 7, 'notnull' => false]);
			}
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('nid')) {
				$table->addColumn('nid', Types::BIGINT, ['default' => 0, 'length' => 11, 'notnull' => false]);
			}
			if (!$table->hasIndex('sat')) {
				$table->addUniqueIndex(['stream_id', 'actor_id', 'type'], 'sat');
			}
			if (!$table->hasIndex('social_sd_at')) {
				$table->addIndex(['actor_id', 'type'], 'social_sd_at');
			}
			if (!$table->hasIndex('social_sd_atn')) {
				$table->addIndex(['actor_id', 'type', 'nid'], 'social_sd_atn');
			}
		}

		if (!$schema->hasTable('social_stream_queue')) {
			$table = $schema->createTable('social_stream_queue');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('token', Types::STRING, ['length' => 63, 'notnull' => false]);
			$table->addColumn('stream_id', Types::STRING, ['default' => '', 'length' => 255, 'notnull' => false]);
			$table->addColumn('type', Types::STRING, ['default' => '', 'length' => 31, 'notnull' => false]);
			$table->addColumn('status', Types::SMALLINT, ['default' => 0, 'length' => 1, 'notnull' => false]);
			$table->addColumn('tries', Types::SMALLINT, ['default' => 0, 'length' => 2, 'notnull' => false]);
			$table->addColumn('last', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['token']);
			$table->addIndex(['status', 'id'], 'social_sq_si');
		} else {
			$table = $schema->getTable('social_stream_queue');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('token')) {
				$table->addColumn('token', Types::STRING, ['length' => 63, 'notnull' => false]);
			}
			if (!$table->hasColumn('stream_id')) {
				$table->addColumn('stream_id', Types::STRING, ['default' => '', 'length' => 255, 'notnull' => false]);
			}
			if (!$table->hasColumn('type')) {
				$table->addColumn('type', Types::STRING, ['default' => '', 'length' => 31, 'notnull' => false]);
			}
			if (!$table->hasColumn('status')) {
				$table->addColumn('status', Types::SMALLINT, ['default' => 0, 'length' => 1, 'notnull' => false]);
			}
			if (!$table->hasColumn('tries')) {
				$table->addColumn('tries', Types::SMALLINT, ['default' => 0, 'length' => 2, 'notnull' => false]);
			}
			if (!$table->hasColumn('last')) {
				$table->addColumn('last', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_sq_si')) {
				$table->addIndex(['status', 'id'], 'social_sq_si');
			}
		}

		if (!$schema->hasTable('social_stream_tag')) {
			$table = $schema->createTable('social_stream_tag');
			$table->addColumn('stream_id', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			$table->addColumn('hashtag', Types::STRING, ['default' => '', 'length' => 127, 'notnull' => false]);
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['stream_id', 'hashtag'], 'sh');
			$table->addIndex(['hashtag'], 'social_st_ht');
		} else {
			$table = $schema->getTable('social_stream_tag');
			if (!$table->hasColumn('stream_id')) {
				$table->addColumn('stream_id', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('hashtag')) {
				$table->addColumn('hashtag', Types::STRING, ['default' => '', 'length' => 127, 'notnull' => false]);
			}
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasIndex('sh')) {
				$table->addUniqueIndex(['stream_id', 'hashtag'], 'sh');
			}
			if (!$table->hasIndex('social_st_ht')) {
				$table->addIndex(['hashtag'], 'social_st_ht');
			}
		}

		if (!$schema->hasTable('social_actor_relation')) {
			$table = $schema->createTable('social_actor_relation');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('object_id', Types::TEXT, ['notnull' => true]);
			$table->addColumn('object_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('type', Types::STRING, ['length' => 15, 'notnull' => true]);
			$table->addColumn('notifications', Types::BOOLEAN, ['default' => true, 'notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['actor_id_prim', 'object_id_prim', 'type'], 'social_ar_aot');
			$table->addIndex(['actor_id_prim', 'type'], 'social_ar_at');
		} else {
			$table = $schema->getTable('social_actor_relation');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('actor_id_prim')) {
				$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			}
			if (!$table->hasColumn('object_id')) {
				$table->addColumn('object_id', Types::TEXT, ['notnull' => true]);
			}
			if (!$table->hasColumn('object_id_prim')) {
				$table->addColumn('object_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			}
			if (!$table->hasColumn('type')) {
				$table->addColumn('type', Types::STRING, ['length' => 15, 'notnull' => true]);
			}
			if (!$table->hasColumn('notifications')) {
				$table->addColumn('notifications', Types::BOOLEAN, ['default' => true, 'notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_ar_aot')) {
				$table->addUniqueIndex(['actor_id_prim', 'object_id_prim', 'type'], 'social_ar_aot');
			}
			if (!$table->hasIndex('social_ar_at')) {
				$table->addIndex(['actor_id_prim', 'type'], 'social_ar_at');
			}
		}

		if (!$schema->hasTable('social_report')) {
			$table = $schema->createTable('social_report');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('actor_id', Types::TEXT, ['notnull' => true]);
			$table->addColumn('account_id', Types::TEXT, ['notnull' => true]);
			$table->addColumn('status_ids', Types::TEXT, ['notnull' => false]);
			$table->addColumn('comment', Types::TEXT, ['notnull' => false]);
			$table->addColumn('category', Types::STRING, ['default' => 'other', 'length' => 31, 'notnull' => true]);
			$table->addColumn('local', Types::SMALLINT, ['default' => 1, 'length' => 1, 'notnull' => true]);
			$table->addColumn('resolved', Types::SMALLINT, ['default' => 0, 'length' => 1, 'notnull' => true]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('assigned_to', Types::STRING, ['length' => 64, 'notnull' => false]);
			$table->addColumn('action_taken_by', Types::STRING, ['length' => 64, 'notnull' => false]);
			$table->addColumn('action_taken_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('forwarded', Types::SMALLINT, ['default' => 0, 'length' => 1, 'notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['resolved'], 'social_rep_res');
		} else {
			$table = $schema->getTable('social_report');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('actor_id')) {
				$table->addColumn('actor_id', Types::TEXT, ['notnull' => true]);
			}
			if (!$table->hasColumn('account_id')) {
				$table->addColumn('account_id', Types::TEXT, ['notnull' => true]);
			}
			if (!$table->hasColumn('status_ids')) {
				$table->addColumn('status_ids', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('comment')) {
				$table->addColumn('comment', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('category')) {
				$table->addColumn('category', Types::STRING, ['default' => 'other', 'length' => 31, 'notnull' => true]);
			}
			if (!$table->hasColumn('local')) {
				$table->addColumn('local', Types::SMALLINT, ['default' => 1, 'length' => 1, 'notnull' => true]);
			}
			if (!$table->hasColumn('resolved')) {
				$table->addColumn('resolved', Types::SMALLINT, ['default' => 0, 'length' => 1, 'notnull' => true]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasColumn('assigned_to')) {
				$table->addColumn('assigned_to', Types::STRING, ['length' => 64, 'notnull' => false]);
			}
			if (!$table->hasColumn('action_taken_by')) {
				$table->addColumn('action_taken_by', Types::STRING, ['length' => 64, 'notnull' => false]);
			}
			if (!$table->hasColumn('action_taken_at')) {
				$table->addColumn('action_taken_at', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasColumn('forwarded')) {
				$table->addColumn('forwarded', Types::SMALLINT, ['default' => 0, 'length' => 1, 'notnull' => true]);
			}
			if (!$table->hasIndex('social_rep_res')) {
				$table->addIndex(['resolved'], 'social_rep_res');
			}
		}

		if (!$schema->hasTable('social_stream_card')) {
			$table = $schema->createTable('social_stream_card');
			$table->addColumn('stream_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('url', Types::TEXT, ['notnull' => true]);
			$table->addColumn('title', Types::TEXT, ['notnull' => false]);
			$table->addColumn('description', Types::TEXT, ['notnull' => false]);
			$table->addColumn('image', Types::TEXT, ['notnull' => false]);
			$table->addColumn('provider_name', Types::STRING, ['length' => 255, 'notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['stream_id_prim']);
		} else {
			$table = $schema->getTable('social_stream_card');
			if (!$table->hasColumn('stream_id_prim')) {
				$table->addColumn('stream_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			}
			if (!$table->hasColumn('url')) {
				$table->addColumn('url', Types::TEXT, ['notnull' => true]);
			}
			if (!$table->hasColumn('title')) {
				$table->addColumn('title', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('description')) {
				$table->addColumn('description', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('image')) {
				$table->addColumn('image', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('provider_name')) {
				$table->addColumn('provider_name', Types::STRING, ['length' => 255, 'notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
		}

		if (!$schema->hasTable('social_moderation')) {
			$table = $schema->createTable('social_moderation');
			$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('actor_id', Types::STRING, ['length' => 1000, 'notnull' => true]);
			$table->addColumn('level', Types::STRING, ['length' => 15, 'notnull' => true]);
			$table->addColumn('comment', Types::TEXT, ['notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('force_sensitive', Types::BOOLEAN, ['default' => false, 'notnull' => false]);
			$table->setPrimaryKey(['actor_id_prim']);
			$table->addIndex(['level'], 'smlv');
		} else {
			$table = $schema->getTable('social_moderation');
			if (!$table->hasColumn('actor_id_prim')) {
				$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			}
			if (!$table->hasColumn('actor_id')) {
				$table->addColumn('actor_id', Types::STRING, ['length' => 1000, 'notnull' => true]);
			}
			if (!$table->hasColumn('level')) {
				$table->addColumn('level', Types::STRING, ['length' => 15, 'notnull' => true]);
			}
			if (!$table->hasColumn('comment')) {
				$table->addColumn('comment', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasColumn('force_sensitive')) {
				$table->addColumn('force_sensitive', Types::BOOLEAN, ['default' => false, 'notnull' => false]);
			}
			if (!$table->hasIndex('smlv')) {
				$table->addIndex(['level'], 'smlv');
			}
		}

		if (!$schema->hasTable('social_followed_tag')) {
			$table = $schema->createTable('social_followed_tag');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('hashtag', Types::STRING, ['length' => 127, 'notnull' => true]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['actor_id_prim', 'hashtag'], 'social_ft_ah');
		} else {
			$table = $schema->getTable('social_followed_tag');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('actor_id_prim')) {
				$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			}
			if (!$table->hasColumn('hashtag')) {
				$table->addColumn('hashtag', Types::STRING, ['length' => 127, 'notnull' => true]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_ft_ah')) {
				$table->addUniqueIndex(['actor_id_prim', 'hashtag'], 'social_ft_ah');
			}
		}

		if (!$schema->hasTable('social_list')) {
			$table = $schema->createTable('social_list');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('actor_id', Types::TEXT, ['notnull' => true]);
			$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('title', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('replies_policy', Types::STRING, ['default' => 'list', 'length' => 15, 'notnull' => true]);
			$table->addColumn('exclusive', Types::BOOLEAN, ['default' => false, 'notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('group_id', Types::STRING, ['default' => '', 'length' => 64, 'notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['actor_id_prim'], 'social_list_a');
			$table->addIndex(['group_id'], 'social_list_g');
		} else {
			$table = $schema->getTable('social_list');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('actor_id')) {
				$table->addColumn('actor_id', Types::TEXT, ['notnull' => true]);
			}
			if (!$table->hasColumn('actor_id_prim')) {
				$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			}
			if (!$table->hasColumn('title')) {
				$table->addColumn('title', Types::STRING, ['length' => 255, 'notnull' => true]);
			}
			if (!$table->hasColumn('replies_policy')) {
				$table->addColumn('replies_policy', Types::STRING, ['default' => 'list', 'length' => 15, 'notnull' => true]);
			}
			if (!$table->hasColumn('exclusive')) {
				$table->addColumn('exclusive', Types::BOOLEAN, ['default' => false, 'notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasColumn('group_id')) {
				$table->addColumn('group_id', Types::STRING, ['default' => '', 'length' => 64, 'notnull' => true]);
			}
			if (!$table->hasIndex('social_list_a')) {
				$table->addIndex(['actor_id_prim'], 'social_list_a');
			}
			if (!$table->hasIndex('social_list_g')) {
				$table->addIndex(['group_id'], 'social_list_g');
			}
		}

		if (!$schema->hasTable('social_list_member')) {
			$table = $schema->createTable('social_list_member');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('list_id', Types::BIGINT, ['length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('actor_id', Types::TEXT, ['notnull' => true]);
			$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['list_id', 'actor_id_prim'], 'social_lm_la');
			$table->addIndex(['actor_id_prim'], 'social_lm_a');
		} else {
			$table = $schema->getTable('social_list_member');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('list_id')) {
				$table->addColumn('list_id', Types::BIGINT, ['length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('actor_id')) {
				$table->addColumn('actor_id', Types::TEXT, ['notnull' => true]);
			}
			if (!$table->hasColumn('actor_id_prim')) {
				$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_lm_la')) {
				$table->addUniqueIndex(['list_id', 'actor_id_prim'], 'social_lm_la');
			}
			if (!$table->hasIndex('social_lm_a')) {
				$table->addIndex(['actor_id_prim'], 'social_lm_a');
			}
		}

		if (!$schema->hasTable('social_filter')) {
			$table = $schema->createTable('social_filter');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('title', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('contexts', Types::STRING, ['default' => '', 'length' => 255, 'notnull' => true]);
			$table->addColumn('action', Types::STRING, ['default' => 'warn', 'length' => 15, 'notnull' => true]);
			$table->addColumn('expires_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['actor_id_prim'], 'social_flt_actor');
		} else {
			$table = $schema->getTable('social_filter');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('actor_id_prim')) {
				$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			}
			if (!$table->hasColumn('title')) {
				$table->addColumn('title', Types::STRING, ['length' => 255, 'notnull' => true]);
			}
			if (!$table->hasColumn('contexts')) {
				$table->addColumn('contexts', Types::STRING, ['default' => '', 'length' => 255, 'notnull' => true]);
			}
			if (!$table->hasColumn('action')) {
				$table->addColumn('action', Types::STRING, ['default' => 'warn', 'length' => 15, 'notnull' => true]);
			}
			if (!$table->hasColumn('expires_at')) {
				$table->addColumn('expires_at', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_flt_actor')) {
				$table->addIndex(['actor_id_prim'], 'social_flt_actor');
			}
		}

		if (!$schema->hasTable('social_filter_kw')) {
			$table = $schema->createTable('social_filter_kw');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('filter_id', Types::BIGINT, ['length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('keyword', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('whole_word', Types::SMALLINT, ['default' => 0, 'length' => 1, 'notnull' => true]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['filter_id'], 'social_fltkw_filter');
		} else {
			$table = $schema->getTable('social_filter_kw');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('filter_id')) {
				$table->addColumn('filter_id', Types::BIGINT, ['length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('keyword')) {
				$table->addColumn('keyword', Types::STRING, ['length' => 255, 'notnull' => true]);
			}
			if (!$table->hasColumn('whole_word')) {
				$table->addColumn('whole_word', Types::SMALLINT, ['default' => 0, 'length' => 1, 'notnull' => true]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_fltkw_filter')) {
				$table->addIndex(['filter_id'], 'social_fltkw_filter');
			}
		}

		if (!$schema->hasTable('social_convo_state')) {
			$table = $schema->createTable('social_convo_state');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('actor_id', Types::TEXT, ['notnull' => true]);
			$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('root_id', Types::TEXT, ['notnull' => true]);
			$table->addColumn('root_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('read_nid', Types::BIGINT, ['default' => 0, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('hidden_nid', Types::BIGINT, ['default' => 0, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('muted', Types::BOOLEAN, ['default' => false, 'notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['actor_id_prim', 'root_id_prim'], 'social_convo_ar');
		} else {
			$table = $schema->getTable('social_convo_state');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('actor_id')) {
				$table->addColumn('actor_id', Types::TEXT, ['notnull' => true]);
			}
			if (!$table->hasColumn('actor_id_prim')) {
				$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			}
			if (!$table->hasColumn('root_id')) {
				$table->addColumn('root_id', Types::TEXT, ['notnull' => true]);
			}
			if (!$table->hasColumn('root_id_prim')) {
				$table->addColumn('root_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			}
			if (!$table->hasColumn('read_nid')) {
				$table->addColumn('read_nid', Types::BIGINT, ['default' => 0, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('hidden_nid')) {
				$table->addColumn('hidden_nid', Types::BIGINT, ['default' => 0, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasColumn('muted')) {
				$table->addColumn('muted', Types::BOOLEAN, ['default' => false, 'notnull' => false]);
			}
			if (!$table->hasIndex('social_convo_ar')) {
				$table->addUniqueIndex(['actor_id_prim', 'root_id_prim'], 'social_convo_ar');
			}
		}

		if (!$schema->hasTable('social_domain_block')) {
			$table = $schema->createTable('social_domain_block');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('domain', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['actor_id_prim', 'domain'], 'social_dblk_ad');
		} else {
			$table = $schema->getTable('social_domain_block');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('actor_id_prim')) {
				$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			}
			if (!$table->hasColumn('domain')) {
				$table->addColumn('domain', Types::STRING, ['length' => 255, 'notnull' => true]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_dblk_ad')) {
				$table->addUniqueIndex(['actor_id_prim', 'domain'], 'social_dblk_ad');
			}
		}

		if (!$schema->hasTable('social_account_note')) {
			$table = $schema->createTable('social_account_note');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('object_id', Types::TEXT, ['notnull' => false]);
			$table->addColumn('object_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('note', Types::TEXT, ['notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['actor_id_prim', 'object_id_prim'], 'social_anote_ao');
		} else {
			$table = $schema->getTable('social_account_note');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('actor_id_prim')) {
				$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			}
			if (!$table->hasColumn('object_id')) {
				$table->addColumn('object_id', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('object_id_prim')) {
				$table->addColumn('object_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			}
			if (!$table->hasColumn('note')) {
				$table->addColumn('note', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_anote_ao')) {
				$table->addUniqueIndex(['actor_id_prim', 'object_id_prim'], 'social_anote_ao');
			}
		}

		if (!$schema->hasTable('social_mute_expiry')) {
			$table = $schema->createTable('social_mute_expiry');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('object_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('expires_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['actor_id_prim', 'object_id_prim'], 'social_mexp_ao');
		} else {
			$table = $schema->getTable('social_mute_expiry');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('actor_id_prim')) {
				$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			}
			if (!$table->hasColumn('object_id_prim')) {
				$table->addColumn('object_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			}
			if (!$table->hasColumn('expires_at')) {
				$table->addColumn('expires_at', Types::DATETIME, ['notnull' => true]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_mexp_ao')) {
				$table->addUniqueIndex(['actor_id_prim', 'object_id_prim'], 'social_mexp_ao');
			}
		}

		if (!$schema->hasTable('social_stream_rev')) {
			$table = $schema->createTable('social_stream_rev');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('stream_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('content', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('spoiler_text', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('sensitive', Types::SMALLINT, ['default' => 0, 'length' => 1, 'notnull' => true]);
			$table->addColumn('published', Types::STRING, ['default' => '', 'length' => 31, 'notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['stream_id_prim', 'id'], 'social_sr_si');
		} else {
			$table = $schema->getTable('social_stream_rev');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('stream_id_prim')) {
				$table->addColumn('stream_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			}
			if (!$table->hasColumn('content')) {
				$table->addColumn('content', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('spoiler_text')) {
				$table->addColumn('spoiler_text', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('sensitive')) {
				$table->addColumn('sensitive', Types::SMALLINT, ['default' => 0, 'length' => 1, 'notnull' => true]);
			}
			if (!$table->hasColumn('published')) {
				$table->addColumn('published', Types::STRING, ['default' => '', 'length' => 31, 'notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_sr_si')) {
				$table->addIndex(['stream_id_prim', 'id'], 'social_sr_si');
			}
		}

		if (!$schema->hasTable('social_featured_tag')) {
			$table = $schema->createTable('social_featured_tag');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('actor_id', Types::TEXT, ['notnull' => false]);
			$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('hashtag', Types::STRING, ['length' => 127, 'notnull' => true]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['actor_id_prim', 'hashtag'], 'social_feat_ah');
		} else {
			$table = $schema->getTable('social_featured_tag');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('actor_id')) {
				$table->addColumn('actor_id', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('actor_id_prim')) {
				$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			}
			if (!$table->hasColumn('hashtag')) {
				$table->addColumn('hashtag', Types::STRING, ['length' => 127, 'notnull' => true]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_feat_ah')) {
				$table->addUniqueIndex(['actor_id_prim', 'hashtag'], 'social_feat_ah');
			}
		}

		if (!$schema->hasTable('social_announcement')) {
			$table = $schema->createTable('social_announcement');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('content', Types::TEXT, ['notnull' => true]);
			$table->addColumn('starts_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('ends_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('all_day', Types::SMALLINT, ['default' => 0, 'length' => 1, 'notnull' => true]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('last_update', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
		} else {
			$table = $schema->getTable('social_announcement');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('content')) {
				$table->addColumn('content', Types::TEXT, ['notnull' => true]);
			}
			if (!$table->hasColumn('starts_at')) {
				$table->addColumn('starts_at', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasColumn('ends_at')) {
				$table->addColumn('ends_at', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasColumn('all_day')) {
				$table->addColumn('all_day', Types::SMALLINT, ['default' => 0, 'length' => 1, 'notnull' => true]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasColumn('last_update')) {
				$table->addColumn('last_update', Types::DATETIME, ['notnull' => false]);
			}
		}

		if (!$schema->hasTable('social_announce_read')) {
			$table = $schema->createTable('social_announce_read');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('announcement_id', Types::BIGINT, ['length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['actor_id_prim', 'announcement_id'], 'social_annread_aa');
		} else {
			$table = $schema->getTable('social_announce_read');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('announcement_id')) {
				$table->addColumn('announcement_id', Types::BIGINT, ['length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('actor_id_prim')) {
				$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_annread_aa')) {
				$table->addUniqueIndex(['actor_id_prim', 'announcement_id'], 'social_annread_aa');
			}
		}

		if (!$schema->hasTable('social_scheduled')) {
			$table = $schema->createTable('social_scheduled');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('actor_id', Types::TEXT, ['notnull' => true]);
			$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('scheduled_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('params', Types::TEXT, ['notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['actor_id_prim', 'scheduled_at'], 'social_sched_as');
			$table->addIndex(['scheduled_at'], 'social_sched_due');
		} else {
			$table = $schema->getTable('social_scheduled');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('actor_id')) {
				$table->addColumn('actor_id', Types::TEXT, ['notnull' => true]);
			}
			if (!$table->hasColumn('actor_id_prim')) {
				$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			}
			if (!$table->hasColumn('scheduled_at')) {
				$table->addColumn('scheduled_at', Types::DATETIME, ['notnull' => true]);
			}
			if (!$table->hasColumn('params')) {
				$table->addColumn('params', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_sched_as')) {
				$table->addIndex(['actor_id_prim', 'scheduled_at'], 'social_sched_as');
			}
			if (!$table->hasIndex('social_sched_due')) {
				$table->addIndex(['scheduled_at'], 'social_sched_due');
			}
		}

		if (!$schema->hasTable('social_strike')) {
			$table = $schema->createTable('social_strike');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('actor_id', Types::STRING, ['length' => 1000, 'notnull' => true]);
			$table->addColumn('action', Types::STRING, ['length' => 15, 'notnull' => true]);
			$table->addColumn('text', Types::TEXT, ['notnull' => false]);
			$table->addColumn('moderator', Types::STRING, ['length' => 64, 'notnull' => false]);
			$table->addColumn('report_id', Types::INTEGER, ['default' => 0, 'notnull' => true]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['actor_id_prim'], 'social_str_aid');
		} else {
			$table = $schema->getTable('social_strike');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('actor_id_prim')) {
				$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			}
			if (!$table->hasColumn('actor_id')) {
				$table->addColumn('actor_id', Types::STRING, ['length' => 1000, 'notnull' => true]);
			}
			if (!$table->hasColumn('action')) {
				$table->addColumn('action', Types::STRING, ['length' => 15, 'notnull' => true]);
			}
			if (!$table->hasColumn('text')) {
				$table->addColumn('text', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('moderator')) {
				$table->addColumn('moderator', Types::STRING, ['length' => 64, 'notnull' => false]);
			}
			if (!$table->hasColumn('report_id')) {
				$table->addColumn('report_id', Types::INTEGER, ['default' => 0, 'notnull' => true]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_str_aid')) {
				$table->addIndex(['actor_id_prim'], 'social_str_aid');
			}
		}

		if (!$schema->hasTable('social_emoji')) {
			$table = $schema->createTable('social_emoji');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('shortcode', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('category', Types::STRING, ['length' => 64, 'notnull' => false]);
			$table->addColumn('filename', Types::STRING, ['length' => 128, 'notnull' => true]);
			$table->addColumn('media_type', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('visible', Types::BOOLEAN, ['default' => true, 'notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['shortcode'], 'social_emo_sc');
		} else {
			$table = $schema->getTable('social_emoji');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('shortcode')) {
				$table->addColumn('shortcode', Types::STRING, ['length' => 64, 'notnull' => true]);
			}
			if (!$table->hasColumn('category')) {
				$table->addColumn('category', Types::STRING, ['length' => 64, 'notnull' => false]);
			}
			if (!$table->hasColumn('filename')) {
				$table->addColumn('filename', Types::STRING, ['length' => 128, 'notnull' => true]);
			}
			if (!$table->hasColumn('media_type')) {
				$table->addColumn('media_type', Types::STRING, ['length' => 64, 'notnull' => true]);
			}
			if (!$table->hasColumn('visible')) {
				$table->addColumn('visible', Types::BOOLEAN, ['default' => true, 'notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_emo_sc')) {
				$table->addUniqueIndex(['shortcode'], 'social_emo_sc');
			}
		}

		if (!$schema->hasTable('social_announce_react')) {
			$table = $schema->createTable('social_announce_react');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('announcement_id', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('name', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['announcement_id', 'actor_id_prim', 'name'], 'social_arct_aan');
		} else {
			$table = $schema->getTable('social_announce_react');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('announcement_id')) {
				$table->addColumn('announcement_id', Types::INTEGER, ['notnull' => true]);
			}
			if (!$table->hasColumn('actor_id_prim')) {
				$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			}
			if (!$table->hasColumn('name')) {
				$table->addColumn('name', Types::STRING, ['length' => 64, 'notnull' => true]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_arct_aan')) {
				$table->addUniqueIndex(['announcement_id', 'actor_id_prim', 'name'], 'social_arct_aan');
			}
		}

		if (!$schema->hasTable('social_access_block')) {
			$table = $schema->createTable('social_access_block');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('type', Types::STRING, ['length' => 15, 'notnull' => true]);
			$table->addColumn('value', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('severity', Types::STRING, ['length' => 31, 'notnull' => false]);
			$table->addColumn('comment', Types::TEXT, ['notnull' => false]);
			$table->addColumn('expires', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['type', 'value'], 'social_acb_tv');
		} else {
			$table = $schema->getTable('social_access_block');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('type')) {
				$table->addColumn('type', Types::STRING, ['length' => 15, 'notnull' => true]);
			}
			if (!$table->hasColumn('value')) {
				$table->addColumn('value', Types::STRING, ['length' => 255, 'notnull' => true]);
			}
			if (!$table->hasColumn('severity')) {
				$table->addColumn('severity', Types::STRING, ['length' => 31, 'notnull' => false]);
			}
			if (!$table->hasColumn('comment')) {
				$table->addColumn('comment', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('expires')) {
				$table->addColumn('expires', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_acb_tv')) {
				$table->addUniqueIndex(['type', 'value'], 'social_acb_tv');
			}
		}

		if (!$schema->hasTable('social_collection')) {
			$table = $schema->createTable('social_collection');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('actor_id', Types::TEXT, ['notnull' => false]);
			$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			$table->addColumn('title', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('description', Types::TEXT, ['notnull' => false]);
			$table->addColumn('visibility', Types::STRING, ['default' => 'public', 'length' => 15, 'notnull' => true]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('updated', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['actor_id_prim'], 'social_coll_a');
		} else {
			$table = $schema->getTable('social_collection');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('actor_id')) {
				$table->addColumn('actor_id', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('actor_id_prim')) {
				$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('title')) {
				$table->addColumn('title', Types::STRING, ['length' => 255, 'notnull' => true]);
			}
			if (!$table->hasColumn('description')) {
				$table->addColumn('description', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('visibility')) {
				$table->addColumn('visibility', Types::STRING, ['default' => 'public', 'length' => 15, 'notnull' => true]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasColumn('updated')) {
				$table->addColumn('updated', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_coll_a')) {
				$table->addIndex(['actor_id_prim'], 'social_coll_a');
			}
		}

		if (!$schema->hasTable('social_collection_item')) {
			$table = $schema->createTable('social_collection_item');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('collection_id', Types::BIGINT, ['length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('stream_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			$table->addColumn('position', Types::INTEGER, ['default' => 0, 'notnull' => true]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['collection_id', 'stream_id_prim'], 'social_ci_cp');
			$table->addIndex(['stream_id_prim'], 'social_ci_s');
		} else {
			$table = $schema->getTable('social_collection_item');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('collection_id')) {
				$table->addColumn('collection_id', Types::BIGINT, ['length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('stream_id_prim')) {
				$table->addColumn('stream_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('position')) {
				$table->addColumn('position', Types::INTEGER, ['default' => 0, 'notnull' => true]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_ci_cp')) {
				$table->addUniqueIndex(['collection_id', 'stream_id_prim'], 'social_ci_cp');
			}
			if (!$table->hasIndex('social_ci_s')) {
				$table->addIndex(['stream_id_prim'], 'social_ci_s');
			}
		}

		if (!$schema->hasTable('social_story')) {
			$table = $schema->createTable('social_story');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('actor_id', Types::TEXT, ['notnull' => false]);
			$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			$table->addColumn('document_id', Types::TEXT, ['notnull' => false]);
			$table->addColumn('document_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			$table->addColumn('caption', Types::TEXT, ['notnull' => false]);
			$table->addColumn('duration', Types::INTEGER, ['default' => 5, 'notnull' => true]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('expires_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('source_id', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('source_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			$table->addColumn('local', Types::BOOLEAN, ['default' => true, 'notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['actor_id_prim', 'expires_at'], 'social_story_ae');
			$table->addIndex(['expires_at'], 'social_story_e');
			$table->addUniqueIndex(['source_id_prim'], 'social_story_src');
		} else {
			$table = $schema->getTable('social_story');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('actor_id')) {
				$table->addColumn('actor_id', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('actor_id_prim')) {
				$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('document_id')) {
				$table->addColumn('document_id', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('document_id_prim')) {
				$table->addColumn('document_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('caption')) {
				$table->addColumn('caption', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('duration')) {
				$table->addColumn('duration', Types::INTEGER, ['default' => 5, 'notnull' => true]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasColumn('expires_at')) {
				$table->addColumn('expires_at', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasColumn('source_id')) {
				$table->addColumn('source_id', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('source_id_prim')) {
				$table->addColumn('source_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('local')) {
				$table->addColumn('local', Types::BOOLEAN, ['default' => true, 'notnull' => false]);
			}
			if (!$table->hasIndex('social_story_ae')) {
				$table->addIndex(['actor_id_prim', 'expires_at'], 'social_story_ae');
			}
			if (!$table->hasIndex('social_story_e')) {
				$table->addIndex(['expires_at'], 'social_story_e');
			}
			if (!$table->hasIndex('social_story_src')) {
				$table->addUniqueIndex(['source_id_prim'], 'social_story_src');
			}
		}

		if (!$schema->hasTable('social_story_view')) {
			$table = $schema->createTable('social_story_view');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('story_id', Types::BIGINT, ['length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['story_id', 'actor_id_prim'], 'social_sv_sa');
		} else {
			$table = $schema->getTable('social_story_view');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('story_id')) {
				$table->addColumn('story_id', Types::BIGINT, ['length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('actor_id_prim')) {
				$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_sv_sa')) {
				$table->addUniqueIndex(['story_id', 'actor_id_prim'], 'social_sv_sa');
			}
		}

		if (!$schema->hasTable('social_client_auth')) {
			$table = $schema->createTable('social_client_auth');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('client_id', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('account', Types::STRING, ['length' => 127, 'notnull' => false]);
			$table->addColumn('scopes', Types::TEXT, ['notnull' => false]);
			$table->addColumn('code', Types::STRING, ['length' => 127, 'notnull' => false]);
			$table->addColumn('token', Types::STRING, ['length' => 127, 'notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('last_update', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('code_challenge', Types::STRING, ['default' => '', 'length' => 128, 'notnull' => false]);
			$table->addColumn('code_challenge_method', Types::STRING, ['default' => '', 'length' => 16, 'notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['client_id', 'user_id'], 'social_ca_cu');
			$table->addIndex(['token'], 'social_ca_tok');
			$table->addIndex(['code'], 'social_ca_code');
		} else {
			$table = $schema->getTable('social_client_auth');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('client_id')) {
				$table->addColumn('client_id', Types::INTEGER, ['notnull' => true]);
			}
			if (!$table->hasColumn('user_id')) {
				$table->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			}
			if (!$table->hasColumn('account')) {
				$table->addColumn('account', Types::STRING, ['length' => 127, 'notnull' => false]);
			}
			if (!$table->hasColumn('scopes')) {
				$table->addColumn('scopes', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('code')) {
				$table->addColumn('code', Types::STRING, ['length' => 127, 'notnull' => false]);
			}
			if (!$table->hasColumn('token')) {
				$table->addColumn('token', Types::STRING, ['length' => 127, 'notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasColumn('last_update')) {
				$table->addColumn('last_update', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasColumn('code_challenge')) {
				$table->addColumn('code_challenge', Types::STRING, ['default' => '', 'length' => 128, 'notnull' => false]);
			}
			if (!$table->hasColumn('code_challenge_method')) {
				$table->addColumn('code_challenge_method', Types::STRING, ['default' => '', 'length' => 16, 'notnull' => false]);
			}
			if (!$table->hasIndex('social_ca_cu')) {
				$table->addUniqueIndex(['client_id', 'user_id'], 'social_ca_cu');
			}
			if (!$table->hasIndex('social_ca_tok')) {
				$table->addIndex(['token'], 'social_ca_tok');
			}
			if (!$table->hasIndex('social_ca_code')) {
				$table->addIndex(['code'], 'social_ca_code');
			}
		}

		if (!$schema->hasTable('social_place')) {
			$table = $schema->createTable('social_place');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('name', Types::TEXT, ['notnull' => false]);
			$table->addColumn('name_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			$table->addColumn('country', Types::STRING, ['default' => '', 'length' => 2, 'notnull' => false]);
			$table->addColumn('lat', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			$table->addColumn('lon', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['name_prim', 'country'], 'social_place_nc');
			$table->addIndex(['country'], 'social_place_c');
		} else {
			$table = $schema->getTable('social_place');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('name')) {
				$table->addColumn('name', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('name_prim')) {
				$table->addColumn('name_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('country')) {
				$table->addColumn('country', Types::STRING, ['default' => '', 'length' => 2, 'notnull' => false]);
			}
			if (!$table->hasColumn('lat')) {
				$table->addColumn('lat', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('lon')) {
				$table->addColumn('lon', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_place_nc')) {
				$table->addUniqueIndex(['name_prim', 'country'], 'social_place_nc');
			}
			if (!$table->hasIndex('social_place_c')) {
				$table->addIndex(['country'], 'social_place_c');
			}
		}

		if (!$schema->hasTable('social_reaction')) {
			$table = $schema->createTable('social_reaction');
			$table->addColumn('id', Types::STRING, ['length' => 1000, 'notnull' => false]);
			$table->addColumn('id_prim', Types::STRING, ['length' => 128, 'notnull' => false]);
			$table->addColumn('actor_id', Types::STRING, ['length' => 1000, 'notnull' => false]);
			$table->addColumn('actor_id_prim', Types::STRING, ['length' => 128, 'notnull' => false]);
			$table->addColumn('object_id', Types::STRING, ['length' => 1000, 'notnull' => false]);
			$table->addColumn('object_id_prim', Types::STRING, ['length' => 128, 'notnull' => false]);
			$table->addColumn('emoji', Types::STRING, ['default' => '', 'length' => 63, 'notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id_prim']);
			$table->addUniqueIndex(['actor_id_prim', 'object_id_prim', 'emoji'], 'social_react_aoe');
			$table->addIndex(['object_id_prim'], 'social_react_o');
		} else {
			$table = $schema->getTable('social_reaction');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::STRING, ['length' => 1000, 'notnull' => false]);
			}
			if (!$table->hasColumn('id_prim')) {
				$table->addColumn('id_prim', Types::STRING, ['length' => 128, 'notnull' => false]);
			}
			if (!$table->hasColumn('actor_id')) {
				$table->addColumn('actor_id', Types::STRING, ['length' => 1000, 'notnull' => false]);
			}
			if (!$table->hasColumn('actor_id_prim')) {
				$table->addColumn('actor_id_prim', Types::STRING, ['length' => 128, 'notnull' => false]);
			}
			if (!$table->hasColumn('object_id')) {
				$table->addColumn('object_id', Types::STRING, ['length' => 1000, 'notnull' => false]);
			}
			if (!$table->hasColumn('object_id_prim')) {
				$table->addColumn('object_id_prim', Types::STRING, ['length' => 128, 'notnull' => false]);
			}
			if (!$table->hasColumn('emoji')) {
				$table->addColumn('emoji', Types::STRING, ['default' => '', 'length' => 63, 'notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_react_aoe')) {
				$table->addUniqueIndex(['actor_id_prim', 'object_id_prim', 'emoji'], 'social_react_aoe');
			}
			if (!$table->hasIndex('social_react_o')) {
				$table->addIndex(['object_id_prim'], 'social_react_o');
			}
		}

		if (!$schema->hasTable('social_gif')) {
			$table = $schema->createTable('social_gif');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('slug', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('title', Types::STRING, ['default' => '', 'length' => 255, 'notnull' => false]);
			$table->addColumn('filename', Types::STRING, ['length' => 128, 'notnull' => true]);
			$table->addColumn('media_type', Types::STRING, ['length' => 63, 'notnull' => true]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['slug'], 'social_gif_slug');
		} else {
			$table = $schema->getTable('social_gif');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('slug')) {
				$table->addColumn('slug', Types::STRING, ['length' => 64, 'notnull' => true]);
			}
			if (!$table->hasColumn('title')) {
				$table->addColumn('title', Types::STRING, ['default' => '', 'length' => 255, 'notnull' => false]);
			}
			if (!$table->hasColumn('filename')) {
				$table->addColumn('filename', Types::STRING, ['length' => 128, 'notnull' => true]);
			}
			if (!$table->hasColumn('media_type')) {
				$table->addColumn('media_type', Types::STRING, ['length' => 63, 'notnull' => true]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_gif_slug')) {
				$table->addUniqueIndex(['slug'], 'social_gif_slug');
			}
		}

		if (!$schema->hasTable('social_filter_st')) {
			$table = $schema->createTable('social_filter_st');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('filter_id', Types::BIGINT, ['length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('status_id', Types::BIGINT, ['length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['filter_id'], 'social_fltst_filter');
			$table->addIndex(['status_id'], 'social_fltst_status');
		} else {
			$table = $schema->getTable('social_filter_st');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('filter_id')) {
				$table->addColumn('filter_id', Types::BIGINT, ['length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('status_id')) {
				$table->addColumn('status_id', Types::BIGINT, ['length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_fltst_filter')) {
				$table->addIndex(['filter_id'], 'social_fltst_filter');
			}
			if (!$table->hasIndex('social_fltst_status')) {
				$table->addIndex(['status_id'], 'social_fltst_status');
			}
		}

		if (!$schema->hasTable('social_import_post')) {
			$table = $schema->createTable('social_import_post');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('actor_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			$table->addColumn('source_id', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('source_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			$table->addColumn('stream_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['actor_id_prim', 'source_id_prim'], 'social_imppost_src');
		} else {
			$table = $schema->getTable('social_import_post');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('actor_id_prim')) {
				$table->addColumn('actor_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('source_id')) {
				$table->addColumn('source_id', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('source_id_prim')) {
				$table->addColumn('source_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('stream_id_prim')) {
				$table->addColumn('stream_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_imppost_src')) {
				$table->addUniqueIndex(['actor_id_prim', 'source_id_prim'], 'social_imppost_src');
			}
		}

		if (!$schema->hasTable('social_post_hold')) {
			$table = $schema->createTable('social_post_hold');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('actor_id', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('actor_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			$table->addColumn('params', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('reason', Types::STRING, ['default' => '', 'length' => 31, 'notnull' => false]);
			$table->addColumn('digest', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['digest'], 'social_hold_digest');
			$table->addIndex(['actor_id_prim', 'id'], 'social_hold_actor');
		} else {
			$table = $schema->getTable('social_post_hold');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('actor_id')) {
				$table->addColumn('actor_id', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('actor_id_prim')) {
				$table->addColumn('actor_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('params')) {
				$table->addColumn('params', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('reason')) {
				$table->addColumn('reason', Types::STRING, ['default' => '', 'length' => 31, 'notnull' => false]);
			}
			if (!$table->hasColumn('digest')) {
				$table->addColumn('digest', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_hold_digest')) {
				$table->addUniqueIndex(['digest'], 'social_hold_digest');
			}
			if (!$table->hasIndex('social_hold_actor')) {
				$table->addIndex(['actor_id_prim', 'id'], 'social_hold_actor');
			}
		}

		if (!$schema->hasTable('social_media_block')) {
			$table = $schema->createTable('social_media_block');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('hash', Types::STRING, ['default' => '', 'length' => 64, 'notnull' => false]);
			$table->addColumn('reason', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('moderator', Types::STRING, ['default' => '', 'length' => 64, 'notnull' => false]);
			$table->addColumn('blocked', Types::INTEGER, ['default' => 0, 'notnull' => false, 'unsigned' => true]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['hash'], 'social_mediablock_h');
		} else {
			$table = $schema->getTable('social_media_block');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('hash')) {
				$table->addColumn('hash', Types::STRING, ['default' => '', 'length' => 64, 'notnull' => false]);
			}
			if (!$table->hasColumn('reason')) {
				$table->addColumn('reason', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('moderator')) {
				$table->addColumn('moderator', Types::STRING, ['default' => '', 'length' => 64, 'notnull' => false]);
			}
			if (!$table->hasColumn('blocked')) {
				$table->addColumn('blocked', Types::INTEGER, ['default' => 0, 'notnull' => false, 'unsigned' => true]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_mediablock_h')) {
				$table->addUniqueIndex(['hash'], 'social_mediablock_h');
			}
		}

		if (!$schema->hasTable('social_stream_view')) {
			$table = $schema->createTable('social_stream_view');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('stream_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			$table->addColumn('actor_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['stream_id_prim', 'actor_id_prim'], 'social_sview_pair');
		} else {
			$table = $schema->getTable('social_stream_view');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('stream_id_prim')) {
				$table->addColumn('stream_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('actor_id_prim')) {
				$table->addColumn('actor_id_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_sview_pair')) {
				$table->addUniqueIndex(['stream_id_prim', 'actor_id_prim'], 'social_sview_pair');
			}
		}

		if (!$schema->hasTable('social_discover_cat')) {
			$table = $schema->createTable('social_discover_cat');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('name', Types::STRING, ['default' => '', 'length' => 64, 'notnull' => false]);
			$table->addColumn('hashtags', Types::TEXT, ['default' => '', 'notnull' => false]);
			$table->addColumn('position', Types::INTEGER, ['default' => 0, 'notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
		} else {
			$table = $schema->getTable('social_discover_cat');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('name')) {
				$table->addColumn('name', Types::STRING, ['default' => '', 'length' => 64, 'notnull' => false]);
			}
			if (!$table->hasColumn('hashtags')) {
				$table->addColumn('hashtags', Types::TEXT, ['default' => '', 'notnull' => false]);
			}
			if (!$table->hasColumn('position')) {
				$table->addColumn('position', Types::INTEGER, ['default' => 0, 'notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
		}

		if (!$schema->hasTable('social_story_react')) {
			$table = $schema->createTable('social_story_react');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('story_id', Types::BIGINT, ['length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('actor_id', Types::TEXT, ['notnull' => false]);
			$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			$table->addColumn('type', Types::STRING, ['default' => '', 'length' => 15, 'notnull' => false]);
			$table->addColumn('content', Types::TEXT, ['notnull' => false]);
			$table->addColumn('source_id', Types::TEXT, ['notnull' => false]);
			$table->addColumn('source_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['story_id', 'id'], 'social_stra_si');
			$table->addIndex(['story_id', 'actor_id_prim'], 'social_stra_sa');
			$table->addUniqueIndex(['source_id_prim'], 'social_stra_src');
		} else {
			$table = $schema->getTable('social_story_react');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('story_id')) {
				$table->addColumn('story_id', Types::BIGINT, ['length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('actor_id')) {
				$table->addColumn('actor_id', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('actor_id_prim')) {
				$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('type')) {
				$table->addColumn('type', Types::STRING, ['default' => '', 'length' => 15, 'notnull' => false]);
			}
			if (!$table->hasColumn('content')) {
				$table->addColumn('content', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('source_id')) {
				$table->addColumn('source_id', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('source_id_prim')) {
				$table->addColumn('source_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_stra_si')) {
				$table->addIndex(['story_id', 'id'], 'social_stra_si');
			}
			if (!$table->hasIndex('social_stra_sa')) {
				$table->addIndex(['story_id', 'actor_id_prim'], 'social_stra_sa');
			}
			if (!$table->hasIndex('social_stra_src')) {
				$table->addUniqueIndex(['source_id_prim'], 'social_stra_src');
			}
		}

		if (!$schema->hasTable('social_media_tag')) {
			$table = $schema->createTable('social_media_tag');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('stream_id', Types::BIGINT, ['length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('stream_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			$table->addColumn('actor_id', Types::TEXT, ['notnull' => false]);
			$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			$table->addColumn('tagger_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['stream_id', 'actor_id_prim'], 'social_mtag_sa');
			$table->addIndex(['actor_id_prim', 'stream_id'], 'social_mtag_as');
		} else {
			$table = $schema->getTable('social_media_tag');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('stream_id')) {
				$table->addColumn('stream_id', Types::BIGINT, ['length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('stream_id_prim')) {
				$table->addColumn('stream_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('actor_id')) {
				$table->addColumn('actor_id', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('actor_id_prim')) {
				$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('tagger_id_prim')) {
				$table->addColumn('tagger_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_mtag_sa')) {
				$table->addUniqueIndex(['stream_id', 'actor_id_prim'], 'social_mtag_sa');
			}
			if (!$table->hasIndex('social_mtag_as')) {
				$table->addIndex(['actor_id_prim', 'stream_id'], 'social_mtag_as');
			}
		}

		if (!$schema->hasTable('social_portfolio')) {
			$table = $schema->createTable('social_portfolio');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('actor_id', Types::TEXT, ['notnull' => false]);
			$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			$table->addColumn('active', Types::BOOLEAN, ['default' => false, 'notnull' => false]);
			$table->addColumn('title', Types::STRING, ['default' => '', 'length' => 128, 'notnull' => false]);
			$table->addColumn('intro', Types::TEXT, ['notnull' => false]);
			$table->addColumn('layout', Types::STRING, ['default' => 'grid', 'length' => 15, 'notnull' => false]);
			$table->addColumn('source', Types::STRING, ['default' => 'recent', 'length' => 15, 'notnull' => false]);
			$table->addColumn('collection_id', Types::BIGINT, ['default' => 0, 'length' => 11, 'notnull' => false, 'unsigned' => true]);
			$table->addColumn('show_captions', Types::BOOLEAN, ['default' => true, 'notnull' => false]);
			$table->addColumn('show_places', Types::BOOLEAN, ['default' => true, 'notnull' => false]);
			$table->addColumn('show_dates', Types::BOOLEAN, ['default' => false, 'notnull' => false]);
			$table->addColumn('show_avatar', Types::BOOLEAN, ['default' => true, 'notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['actor_id_prim'], 'social_pfol_a');
		} else {
			$table = $schema->getTable('social_portfolio');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('actor_id')) {
				$table->addColumn('actor_id', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('actor_id_prim')) {
				$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('active')) {
				$table->addColumn('active', Types::BOOLEAN, ['default' => false, 'notnull' => false]);
			}
			if (!$table->hasColumn('title')) {
				$table->addColumn('title', Types::STRING, ['default' => '', 'length' => 128, 'notnull' => false]);
			}
			if (!$table->hasColumn('intro')) {
				$table->addColumn('intro', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('layout')) {
				$table->addColumn('layout', Types::STRING, ['default' => 'grid', 'length' => 15, 'notnull' => false]);
			}
			if (!$table->hasColumn('source')) {
				$table->addColumn('source', Types::STRING, ['default' => 'recent', 'length' => 15, 'notnull' => false]);
			}
			if (!$table->hasColumn('collection_id')) {
				$table->addColumn('collection_id', Types::BIGINT, ['default' => 0, 'length' => 11, 'notnull' => false, 'unsigned' => true]);
			}
			if (!$table->hasColumn('show_captions')) {
				$table->addColumn('show_captions', Types::BOOLEAN, ['default' => true, 'notnull' => false]);
			}
			if (!$table->hasColumn('show_places')) {
				$table->addColumn('show_places', Types::BOOLEAN, ['default' => true, 'notnull' => false]);
			}
			if (!$table->hasColumn('show_dates')) {
				$table->addColumn('show_dates', Types::BOOLEAN, ['default' => false, 'notnull' => false]);
			}
			if (!$table->hasColumn('show_avatar')) {
				$table->addColumn('show_avatar', Types::BOOLEAN, ['default' => true, 'notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_pfol_a')) {
				$table->addUniqueIndex(['actor_id_prim'], 'social_pfol_a');
			}
		}

		if (!$schema->hasTable('social_team')) {
			$table = $schema->createTable('social_team');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('actor_id', Types::TEXT, ['notnull' => false]);
			$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			$table->addColumn('group_id', Types::STRING, ['default' => '', 'length' => 64, 'notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['actor_id_prim'], 'social_team_a');
			$table->addIndex(['group_id'], 'social_team_g');
		} else {
			$table = $schema->getTable('social_team');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('actor_id')) {
				$table->addColumn('actor_id', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('actor_id_prim')) {
				$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('group_id')) {
				$table->addColumn('group_id', Types::STRING, ['default' => '', 'length' => 64, 'notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_team_a')) {
				$table->addUniqueIndex(['actor_id_prim'], 'social_team_a');
			}
			if (!$table->hasIndex('social_team_g')) {
				$table->addIndex(['group_id'], 'social_team_g');
			}
		}

		if (!$schema->hasTable('social_team_post')) {
			$table = $schema->createTable('social_team_post');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('stream_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			$table->addColumn('author_id', Types::TEXT, ['notnull' => false]);
			$table->addColumn('author_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['stream_id_prim'], 'social_teamp_s');
		} else {
			$table = $schema->getTable('social_team_post');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('stream_id_prim')) {
				$table->addColumn('stream_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('author_id')) {
				$table->addColumn('author_id', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('author_id_prim')) {
				$table->addColumn('author_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_teamp_s')) {
				$table->addUniqueIndex(['stream_id_prim'], 'social_teamp_s');
			}
		}

		if (!$schema->hasTable('social_trend_review')) {
			$table = $schema->createTable('social_trend_review');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('kind', Types::STRING, ['default' => '', 'length' => 15, 'notnull' => false]);
			$table->addColumn('ref', Types::TEXT, ['notnull' => false]);
			$table->addColumn('ref_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			$table->addColumn('approved', Types::BOOLEAN, ['default' => false, 'notnull' => false]);
			$table->addColumn('moderator', Types::STRING, ['default' => '', 'length' => 64, 'notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['kind', 'ref_prim'], 'social_trrev_kr');
		} else {
			$table = $schema->getTable('social_trend_review');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('kind')) {
				$table->addColumn('kind', Types::STRING, ['default' => '', 'length' => 15, 'notnull' => false]);
			}
			if (!$table->hasColumn('ref')) {
				$table->addColumn('ref', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('ref_prim')) {
				$table->addColumn('ref_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('approved')) {
				$table->addColumn('approved', Types::BOOLEAN, ['default' => false, 'notnull' => false]);
			}
			if (!$table->hasColumn('moderator')) {
				$table->addColumn('moderator', Types::STRING, ['default' => '', 'length' => 64, 'notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_trrev_kr')) {
				$table->addUniqueIndex(['kind', 'ref_prim'], 'social_trrev_kr');
			}
		}

		if (!$schema->hasTable('social_relay')) {
			$table = $schema->createTable('social_relay');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('actor_id', Types::TEXT, ['notnull' => false]);
			$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			$table->addColumn('inbox', Types::TEXT, ['notnull' => false]);
			$table->addColumn('status', Types::STRING, ['default' => '', 'length' => 15, 'notnull' => false]);
			$table->addColumn('follow_id', Types::TEXT, ['notnull' => false]);
			$table->addColumn('error', Types::TEXT, ['notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('last_update', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['actor_id_prim'], 'social_relay_aid');
		} else {
			$table = $schema->getTable('social_relay');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('actor_id')) {
				$table->addColumn('actor_id', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('actor_id_prim')) {
				$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('inbox')) {
				$table->addColumn('inbox', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('status')) {
				$table->addColumn('status', Types::STRING, ['default' => '', 'length' => 15, 'notnull' => false]);
			}
			if (!$table->hasColumn('follow_id')) {
				$table->addColumn('follow_id', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('error')) {
				$table->addColumn('error', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasColumn('last_update')) {
				$table->addColumn('last_update', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_relay_aid')) {
				$table->addUniqueIndex(['actor_id_prim'], 'social_relay_aid');
			}
		}

		if (!$schema->hasTable('social_quote_grant')) {
			$table = $schema->createTable('social_quote_grant');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('target_id', Types::TEXT, ['notnull' => false]);
			$table->addColumn('target_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			$table->addColumn('quoting_id', Types::TEXT, ['notnull' => false]);
			$table->addColumn('quoting_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			$table->addColumn('actor_id', Types::TEXT, ['notnull' => false]);
			$table->addColumn('request_id', Types::TEXT, ['notnull' => false]);
			$table->addColumn('authorization', Types::TEXT, ['notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['target_id_prim', 'quoting_id_prim'], 'social_qgrant_tq');
		} else {
			$table = $schema->getTable('social_quote_grant');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('target_id')) {
				$table->addColumn('target_id', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('target_id_prim')) {
				$table->addColumn('target_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('quoting_id')) {
				$table->addColumn('quoting_id', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('quoting_id_prim')) {
				$table->addColumn('quoting_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('actor_id')) {
				$table->addColumn('actor_id', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('request_id')) {
				$table->addColumn('request_id', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('authorization')) {
				$table->addColumn('authorization', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_qgrant_tq')) {
				$table->addUniqueIndex(['target_id_prim', 'quoting_id_prim'], 'social_qgrant_tq');
			}
		}

		if (!$schema->hasTable('social_channel')) {
			$table = $schema->createTable('social_channel');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('actor_id', Types::TEXT, ['notnull' => false]);
			$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			$table->addColumn('owner_id', Types::TEXT, ['notnull' => false]);
			$table->addColumn('owner_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			$table->addColumn('name', Types::STRING, ['default' => '', 'length' => 255, 'notnull' => false]);
			$table->addColumn('description', Types::TEXT, ['notnull' => false]);
			$table->addColumn('is_default', Types::BOOLEAN, ['default' => false, 'notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['actor_id_prim'], 'social_chan_aid');
			$table->addIndex(['owner_id_prim'], 'social_chan_own');
		} else {
			$table = $schema->getTable('social_channel');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('actor_id')) {
				$table->addColumn('actor_id', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('actor_id_prim')) {
				$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('owner_id')) {
				$table->addColumn('owner_id', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('owner_id_prim')) {
				$table->addColumn('owner_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('name')) {
				$table->addColumn('name', Types::STRING, ['default' => '', 'length' => 255, 'notnull' => false]);
			}
			if (!$table->hasColumn('description')) {
				$table->addColumn('description', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('is_default')) {
				$table->addColumn('is_default', Types::BOOLEAN, ['default' => false, 'notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_chan_aid')) {
				$table->addUniqueIndex(['actor_id_prim'], 'social_chan_aid');
			}
			if (!$table->hasIndex('social_chan_own')) {
				$table->addIndex(['owner_id_prim'], 'social_chan_own');
			}
		}

		if (!$schema->hasTable('social_watch')) {
			$table = $schema->createTable('social_watch');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('stream_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			$table->addColumn('position', Types::INTEGER, ['default' => 0, 'notnull' => false]);
			$table->addColumn('duration', Types::INTEGER, ['default' => 0, 'notnull' => false]);
			$table->addColumn('last_update', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['stream_id_prim', 'actor_id_prim'], 'social_watch_sa');
			$table->addIndex(['actor_id_prim', 'last_update'], 'social_watch_al');
		} else {
			$table = $schema->getTable('social_watch');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('stream_id_prim')) {
				$table->addColumn('stream_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('actor_id_prim')) {
				$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			}
			if (!$table->hasColumn('position')) {
				$table->addColumn('position', Types::INTEGER, ['default' => 0, 'notnull' => false]);
			}
			if (!$table->hasColumn('duration')) {
				$table->addColumn('duration', Types::INTEGER, ['default' => 0, 'notnull' => false]);
			}
			if (!$table->hasColumn('last_update')) {
				$table->addColumn('last_update', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_watch_sa')) {
				$table->addUniqueIndex(['stream_id_prim', 'actor_id_prim'], 'social_watch_sa');
			}
			if (!$table->hasIndex('social_watch_al')) {
				$table->addIndex(['actor_id_prim', 'last_update'], 'social_watch_al');
			}
		}

		if (!$schema->hasTable('social_video_rendition')) {
			$table = $schema->createTable('social_video_rendition');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('doc_nid', Types::BIGINT, ['default' => 0, 'length' => 11, 'notnull' => false, 'unsigned' => true]);
			$table->addColumn('height', Types::INTEGER, ['default' => 0, 'notnull' => false]);
			$table->addColumn('bandwidth', Types::INTEGER, ['default' => 0, 'notnull' => false]);
			$table->addColumn('size', Types::BIGINT, ['default' => 0, 'length' => 20, 'notnull' => false, 'unsigned' => true]);
			$table->addColumn('local_copy', Types::STRING, ['default' => '', 'length' => 255, 'notnull' => false]);
			$table->addColumn('playlist', Types::TEXT, ['notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['doc_nid', 'height'], 'social_rend_dh');
		} else {
			$table = $schema->getTable('social_video_rendition');
			if (!$table->hasColumn('id')) {
				$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			}
			if (!$table->hasColumn('doc_nid')) {
				$table->addColumn('doc_nid', Types::BIGINT, ['default' => 0, 'length' => 11, 'notnull' => false, 'unsigned' => true]);
			}
			if (!$table->hasColumn('height')) {
				$table->addColumn('height', Types::INTEGER, ['default' => 0, 'notnull' => false]);
			}
			if (!$table->hasColumn('bandwidth')) {
				$table->addColumn('bandwidth', Types::INTEGER, ['default' => 0, 'notnull' => false]);
			}
			if (!$table->hasColumn('size')) {
				$table->addColumn('size', Types::BIGINT, ['default' => 0, 'length' => 20, 'notnull' => false, 'unsigned' => true]);
			}
			if (!$table->hasColumn('local_copy')) {
				$table->addColumn('local_copy', Types::STRING, ['default' => '', 'length' => 255, 'notnull' => false]);
			}
			if (!$table->hasColumn('playlist')) {
				$table->addColumn('playlist', Types::TEXT, ['notnull' => false]);
			}
			if (!$table->hasColumn('creation')) {
				$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			}
			if (!$table->hasIndex('social_rend_dh')) {
				$table->addUniqueIndex(['doc_nid', 'height'], 'social_rend_dh');
			}
		}

		return $schema;
	}
}
