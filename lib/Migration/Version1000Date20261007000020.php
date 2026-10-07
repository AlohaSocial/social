<?php

declare(strict_types=1);

namespace OCA\Social\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;

class Version1000Date20261007000020 extends \OCP\Migration\SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		// social_atpds_identity
		if (!$schema->hasTable('social_atpds_identity')) {
			$table = $schema->createTable('social_atpds_identity');
			$table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('actor_id', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('did', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('handle', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('signing_key', 'text', ['notnull' => true]);
			$table->addColumn('signing_public', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('recovery_public', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('state', 'string', ['length' => 32, 'notnull' => true, 'default' => 'active']);
			$table->addColumn('moved_from_pds', 'string', ['length' => 255, 'notnull' => false]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['actor_id'], 'social_pds_identity1');
			$table->addUniqueIndex(['did'], 'social_pds_identity2');
			$table->addUniqueIndex(['handle'], 'social_pds_identity3');
			$table->addIndex(['state'], 'social_pds_identity4');
		}

		// social_atpds_instance_key
		if (!$schema->hasTable('social_atpds_instance_key')) {
			$table = $schema->createTable('social_atpds_instance_key');
			$table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('kind', 'string', ['length' => 32, 'notnull' => true]);
			$table->addColumn('private_key', 'text', ['notnull' => true]);
			$table->addColumn('public_key', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('created', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['kind', 'created'], 'social_pds_instance_1');
		}

		// social_atpds_repo
		if (!$schema->hasTable('social_atpds_repo')) {
			$table = $schema->createTable('social_atpds_repo');
			$table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('did', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('commit_cid', 'string', ['length' => 255, 'notnull' => false]);
			$table->addColumn('rev', 'string', ['length' => 255, 'notnull' => false]);
			$table->addColumn('record_count', 'integer', ['notnull' => true, 'default' => 0]);
			$table->addColumn('blob_bytes', 'bigint', ['notnull' => true, 'default' => 0]);
			$table->addColumn('updated', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['did'], 'social_pds_repo1');
		}

		// social_atpds_record
		if (!$schema->hasTable('social_atpds_record')) {
			$table = $schema->createTable('social_atpds_record');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('did', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('collection', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('rkey', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('cid', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('bytes', 'blob', ['notnull' => true]);
			$table->addColumn('local_id', 'string', ['length' => 255, 'notnull' => false]);
			$table->addColumn('created', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['did', 'collection', 'rkey'], 'social_pds_record1');
			$table->addIndex(['local_id'], 'social_pds_record2');
		}

		// social_atpds_block
		if (!$schema->hasTable('social_atpds_block')) {
			$table = $schema->createTable('social_atpds_block');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('cid', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('did', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('bytes', 'blob', ['notnull' => true]);
			$table->addColumn('kind', 'string', ['length' => 32, 'notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['did', 'cid'], 'social_pds_block1');
			$table->addIndex(['kind'], 'social_pds_block2');
		}

		// social_atpds_blob
		if (!$schema->hasTable('social_atpds_blob')) {
			$table = $schema->createTable('social_atpds_blob');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('did', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('cid', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('document_id', 'string', ['length' => 255, 'notnull' => false]);
			$table->addColumn('mime', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('size', 'bigint', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['did', 'cid'], 'social_pds_blob1');
		}

		// social_atpds_event
		if (!$schema->hasTable('social_atpds_event')) {
			$table = $schema->createTable('social_atpds_event');
			$table->addColumn('seq', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('did', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('kind', 'string', ['length' => 32, 'notnull' => true]);
			$table->addColumn('bytes', 'blob', ['notnull' => true]);
			$table->addColumn('time', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['seq']);
			$table->addIndex(['did'], 'social_pds_event1');
			$table->addIndex(['kind'], 'social_pds_event2');
		}

		// social_atpds_plc_log
		if (!$schema->hasTable('social_atpds_plc_log')) {
			$table = $schema->createTable('social_atpds_plc_log');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('did', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('cid', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('operation', 'json', ['notnull' => true]);
			$table->addColumn('sent', 'datetime', ['notnull' => false]);
			$table->addColumn('confirmed', 'datetime', ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['did'], 'social_pds_plc_log1');
		}

		// social_atpds_watch
		if (!$schema->hasTable('social_atpds_watch')) {
			$table = $schema->createTable('social_atpds_watch');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('did', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('handle', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('cursor', 'string', ['length' => 255, 'notnull' => false]);
			$table->addColumn('last_sync', 'datetime', ['notnull' => false]);
			$table->addColumn('next_sync', 'datetime', ['notnull' => false]);
			$table->addColumn('failures', 'integer', ['notnull' => true, 'default' => 0]);
			$table->addColumn('last_error', 'string', ['length' => 255, 'notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['did'], 'social_pds_watch1');
		}

		// social_atpds_notify_cursor
		if (!$schema->hasTable('social_atpds_notify_cursor')) {
			$table = $schema->createTable('social_atpds_notify_cursor');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('did', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('cursor', 'string', ['length' => 255, 'notnull' => false]);
			$table->addColumn('last_sync', 'datetime', ['notnull' => false]);
			$table->addColumn('next_sync', 'datetime', ['notnull' => false]);
			$table->addColumn('failures', 'integer', ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['did'], 'social_pds_notify_cu1');
		}

		// social_atpds_labeler
		if (!$schema->hasTable('social_atpds_labeler')) {
			$table = $schema->createTable('social_atpds_labeler');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('actor_id', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('labeler_did', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('settings', 'json', ['notnull' => true]);
			$table->addColumn('added', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['actor_id', 'labeler_did'], 'social_pds_labeler1');
		}

		// social_atpds_blocklist
		if (!$schema->hasTable('social_atpds_blocklist')) {
			$table = $schema->createTable('social_atpds_blocklist');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('kind', 'string', ['length' => 32, 'notnull' => true]);
			$table->addColumn('value', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('reason', 'string', ['length' => 512, 'notnull' => false]);
			$table->addColumn('created', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['kind', 'value'], 'social_pds_blocklist1');
		}

		// social_atpds_session
		if (!$schema->hasTable('social_atpds_session')) {
			$table = $schema->createTable('social_atpds_session');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('did', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('jti', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('refresh_token_hash', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('expires', 'datetime', ['notnull' => true]);
			$table->addColumn('created', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['jti'], 'social_pds_session1');
			$table->addIndex(['did'], 'social_pds_session2');
		}

		// social_atpds_recovery_phrase
		if (!$schema->hasTable('social_atpds_recovery')) {
			$table = $schema->createTable('social_atpds_recovery');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('actor_id', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('recovery_phrase', 'string', ['length' => 512, 'notnull' => true]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['actor_id'], 'social_pds_recovery1');
		}
		return $schema;
	}
}
