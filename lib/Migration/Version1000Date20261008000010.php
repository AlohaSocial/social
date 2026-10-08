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
 * The AT Protocol side of every account: this instance is a Bluesky host.
 *
 * `social_atproto_identity` is one row per local actor — its `did:plc`, its
 * handle and its sealed signing key; `social_atproto_instance_key` holds the
 * instance's own rotation and service keys. `social_atproto_repo` is each
 * repository's signed head, `social_atproto_record` its live records with
 * their DAG-CBOR bytes and the Social object each came from, and
 * `social_atproto_block` the Merkle tree nodes and commits by CID, because
 * the sync endpoints and the firehose serve blocks. `social_atproto_blob`
 * names the stored documents a record refers to by CID.
 * `social_atproto_event` is the firehose: one frame per commit, numbered by
 * the database, kept for the replay window. `social_atproto_plc_log` records
 * every PLC operation before it is sent, so a crash between the two leaves a
 * row to reconcile from.
 *
 * Byte columns are BLOBs sized past 64 KB: a commit's frame carries the
 * changed tree nodes and the record, which a profile with a long bio or a
 * post with many facets pushes past MySQL's plain BLOB.
 */
class Version1000Date20261008000010 extends SimpleMigrationStep {
	/** MEDIUMBLOB on MySQL; the others do not size BLOBs */
	private const BYTES = 16777215;

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('social_atproto_identity')) {
			$table = $schema->createTable('social_atproto_identity');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('actor_id', Types::TEXT, ['notnull' => true]);
			$table->addColumn('actor_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('did', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('handle', Types::STRING, ['length' => 253, 'notnull' => true]);
			$table->addColumn('signing_key', Types::TEXT, ['notnull' => true]);
			$table->addColumn('signing_public', Types::STRING, ['length' => 127, 'notnull' => true]);
			$table->addColumn('recovery_public', Types::STRING, ['length' => 127, 'notnull' => true, 'default' => '']);
			$table->addColumn('state', Types::STRING, ['length' => 15, 'notnull' => true, 'default' => 'active']);
			$table->addColumn('moved_from_pds', Types::STRING, ['length' => 255, 'notnull' => true, 'default' => '']);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('updated', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['actor_id_prim'], 'social_atp_id_ap');
			$table->addUniqueIndex(['did'], 'social_atp_id_did');
			$table->addUniqueIndex(['handle'], 'social_atp_id_h');
			$changed = true;
		}

		if (!$schema->hasTable('social_atproto_instance_key')) {
			$table = $schema->createTable('social_atproto_instance_key');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('kind', Types::STRING, ['length' => 15, 'notnull' => true]);
			$table->addColumn('private_key', Types::TEXT, ['notnull' => true]);
			$table->addColumn('public_key', Types::STRING, ['length' => 127, 'notnull' => true]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('retired', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['kind'], 'social_atp_key_k');
			$changed = true;
		}

		if (!$schema->hasTable('social_atproto_repo')) {
			$table = $schema->createTable('social_atproto_repo');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('did', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('commit_cid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('rev', Types::STRING, ['length' => 13, 'notnull' => true]);
			$table->addColumn('record_count', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('blob_bytes', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('updated', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['did'], 'social_atp_repo_d');
			$changed = true;
		}

		if (!$schema->hasTable('social_atproto_record')) {
			$table = $schema->createTable('social_atproto_record');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('did', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('collection', Types::STRING, ['length' => 317, 'notnull' => true]);
			$table->addColumn('rkey', Types::STRING, ['length' => 512, 'notnull' => true]);
			$table->addColumn('path_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('cid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('bytes', Types::BLOB, ['notnull' => true, 'length' => self::BYTES]);
			$table->addColumn('local_id', Types::TEXT, ['notnull' => true]);
			$table->addColumn('local_id_prim', Types::STRING, ['length' => 32, 'notnull' => true, 'default' => '']);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['path_prim'], 'social_atp_rec_pp');
			$table->addIndex(['did', 'collection'], 'social_atp_rec_dc');
			$table->addIndex(['local_id_prim'], 'social_atp_rec_lp');
			$changed = true;
		}

		if (!$schema->hasTable('social_atproto_block')) {
			$table = $schema->createTable('social_atproto_block');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('did', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('cid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('kind', Types::STRING, ['length' => 7, 'notnull' => true]);
			$table->addColumn('bytes', Types::BLOB, ['notnull' => true, 'length' => self::BYTES]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['did', 'cid'], 'social_atp_blk_dc');
			$changed = true;
		}

		if (!$schema->hasTable('social_atproto_blob')) {
			$table = $schema->createTable('social_atproto_blob');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('did', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('cid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('document_id', Types::TEXT, ['notnull' => true]);
			$table->addColumn('document_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('mime', Types::STRING, ['length' => 127, 'notnull' => true]);
			$table->addColumn('size', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['did', 'cid'], 'social_atp_blob_dc');
			$table->addIndex(['document_id_prim'], 'social_atp_blob_dp');
			$changed = true;
		}

		if (!$schema->hasTable('social_atproto_event')) {
			$table = $schema->createTable('social_atproto_event');
			$table->addColumn('seq', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('did', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('kind', Types::STRING, ['length' => 15, 'notnull' => true]);
			$table->addColumn('bytes', Types::BLOB, ['notnull' => true, 'length' => self::BYTES]);
			$table->addColumn('time', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['seq']);
			$table->addIndex(['time'], 'social_atp_ev_t');
			$changed = true;
		}

		if (!$schema->hasTable('social_atproto_plc_log')) {
			$table = $schema->createTable('social_atproto_plc_log');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('did', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('cid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('operation', Types::TEXT, ['notnull' => true]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('sent', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('confirmed', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['did', 'id'], 'social_atp_plc_di');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
