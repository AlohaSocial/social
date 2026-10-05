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
 * The AT-Proto side of a Bluesky link: which local account has one, which
 * records of it have been copied across, and which remote actors are read.
 *
 * Three small tables, and small on purpose — one row per linked account, one
 * per record copied, one per actor followed — because nothing here is a cache
 * of somebody else's repository: the records live on the PDS and are fetched
 * when they are needed, and this only remembers the mapping between the two
 * id schemes and how far a read got.
 *
 * The mapping is not optional: an ActivityPub id must pass an origin check
 * (`ACore::checkOrigin`), and `parse_url('at://…')` has no host to check
 * against, so an `at://` uri can never be stored as the id of a local row.
 * Every record keeps an `https://<cloud>/ap/bluesky/…` id instead, and
 * `social_atproto_link` is where its `at://` uri, collection, rkey and `cid`
 * live for the way back — an edit names the record it edits, a delete the one
 * it deletes, and both need the cid the repository addresses it with.
 *
 * Indexed on what the passes read: a linked account by its Nextcloud user,
 * a link by its `at://` uri and by the pair a record is addressed with, and
 * the watches by when they are next due (`Cron\AtprotoSync`).
 */
class Version1000Date20261006000001 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('social_atproto_account')) {
			return null;
		}

		$account = $schema->createTable('social_atproto_account');
		$account->addColumn('nid', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
		$account->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
		$account->addColumn('handle', Types::STRING, ['length' => 255, 'notnull' => true, 'default' => '']);
		$account->addColumn('did', Types::STRING, ['length' => 63, 'notnull' => true]);
		$account->addColumn('pds', Types::STRING, ['length' => 255, 'notnull' => true, 'default' => '']);
		$account->addColumn('app_password', Types::STRING, ['length' => 255, 'notnull' => true, 'default' => '']);
		$account->addColumn('state', Types::STRING, ['length' => 15, 'notnull' => true, 'default' => 'linked']);
		$account->addColumn('last_error', Types::STRING, ['length' => 255, 'notnull' => true, 'default' => '']);
		$account->addColumn('last_sync', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		$account->addColumn('creation', Types::DATETIME, ['notnull' => false]);
		$account->addColumn('updated', Types::DATETIME, ['notnull' => false]);
		$account->setPrimaryKey(['nid']);
		$account->addUniqueIndex(['user_id'], 'social_aa_user');
		$account->addUniqueIndex(['did'], 'social_aa_did');

		$link = $schema->createTable('social_atproto_link');
		$link->addColumn('nid', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
		$link->addColumn('local_id', Types::STRING, ['length' => 255, 'notnull' => true]);
		$link->addColumn('local_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
		$link->addColumn('at_uri', Types::STRING, ['length' => 255, 'notnull' => true]);
		$link->addColumn('cid', Types::STRING, ['length' => 63, 'notnull' => true, 'default' => '']);
		$link->addColumn('did', Types::STRING, ['length' => 63, 'notnull' => true]);
		$link->addColumn('collection', Types::STRING, ['length' => 63, 'notnull' => true]);
		$link->addColumn('rkey', Types::STRING, ['length' => 63, 'notnull' => true]);
		$link->addColumn('handle', Types::STRING, ['length' => 255, 'notnull' => true, 'default' => '']);
		$link->addColumn('creation', Types::DATETIME, ['notnull' => false]);
		$link->setPrimaryKey(['nid']);
		$link->addUniqueIndex(['local_id_prim'], 'social_al_prim');
		$link->addUniqueIndex(['at_uri'], 'social_al_uri');
		$link->addIndex(['did', 'rkey'], 'social_al_did_rkey');

		$watch = $schema->createTable('social_atproto_watch');
		$watch->addColumn('nid', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
		$watch->addColumn('did', Types::STRING, ['length' => 63, 'notnull' => true]);
		$watch->addColumn('handle', Types::STRING, ['length' => 255, 'notnull' => true, 'default' => '']);
		$watch->addColumn('cursor', Types::STRING, ['length' => 255, 'notnull' => true, 'default' => '']);
		$watch->addColumn('last_sync', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		$watch->addColumn('next_sync', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		$watch->addColumn('failures', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		$watch->addColumn('imported', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		$watch->addColumn('last_error', Types::STRING, ['length' => 255, 'notnull' => true, 'default' => '']);
		$watch->addColumn('creation', Types::DATETIME, ['notnull' => false]);
		$watch->setPrimaryKey(['nid']);
		$watch->addUniqueIndex(['did'], 'social_aw_did');
		$watch->addIndex(['next_sync'], 'social_aw_next');

		return $schema;
	}
}
