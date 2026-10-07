<?php
declare(strict_types=1);

namespace OCA\Social\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;

class Version1000Date20261007000020 extends \OCP\Migration\SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		// social_atproto_identity
		if (!$schema->hasTable('social_atproto_identity')) {
			$table = $schema->createTable('social_atproto_identity');
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
			$table->addUniqueIndex(['actor_id'], 'social_at_identity1');
			$table->addUniqueIndex(['did'], 'social_at_identity2');
			$table->addUniqueIndex(['handle'], 'social_at_identity3');
			$table->addIndex(['state'], 'social_at_identity4');
		}
		
		// social_atproto_instance_key
		if (!$schema->hasTable('social_atproto_instance_key')) {
			$table = $schema->createTable('social_atproto_instance_key');
			$table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('kind', 'string', ['length' => 32, 'notnull' => true]);
			$table->addColumn('private_key', 'text', ['notnull' => true]);
			$table->addColumn('public_key', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('created', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['kind', 'created'], 'social_at_instance_1');
		}
		
		// social_atproto_repo
		if (!$schema->hasTable('social_atproto_repo')) {
			$table = $schema->createTable('social_atproto_repo');
			$table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('did', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('commit_cid', 'string', ['length' => 255, 'notnull' => false]);
			$table->addColumn('rev', 'string', ['length' => 255, 'notnull' => false]);
			$table->addColumn('record_count', 'integer', ['notnull' => true, 'default' => 0]);
			$table->addColumn('blob_bytes', 'bigint', ['notnull' => true, 'default' => 0]);
			$table->addColumn('updated', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['did'], 'social_at_repo1');
		}
		
		// social_atproto_record
		if (!$schema->hasTable('social_atproto_record')) {
			$table = $schema->createTable('social_atproto_record');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('did', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('collection', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('rkey', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('cid', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('bytes', 'blob', ['notnull' => true]);
			$table->addColumn('local_id', 'string', ['length' => 255, 'notnull' => false]);
			$table->addColumn('created', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['did', 'collection', 'rkey'], 'social_at_record1');
			$table->addIndex(['local_id'], 'social_at_record2');
		}
		
		// social_atproto_block
		if (!$schema->hasTable('social_atproto_block')) {
			$table = $schema->createTable('social_atproto_block');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('cid', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('did', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('bytes', 'blob', ['notnull' => true]);
			$table->addColumn('kind', 'string', ['length' => 32, 'notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['did', 'cid'], 'social_at_block1');
			$table->addIndex(['kind'], 'social_at_block2');
		}
		
		// social_atproto_blob
		if (!$schema->hasTable('social_atproto_blob')) {
			$table = $schema->createTable('social_atproto_blob');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('did', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('cid', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('document_id', 'string', ['length' => 255, 'notnull' => false]);
			$table->addColumn('mime', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('size', 'bigint', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['did', 'cid'], 'social_at_blob1');
		}
		
		// social_atproto_event
		if (!$schema->hasTable('social_atproto_event')) {
			$table = $schema->createTable('social_atproto_event');
			$table->addColumn('seq', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('did', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('kind', 'string', ['length' => 32, 'notnull' => true]);
			$table->addColumn('bytes', 'blob', ['notnull' => true]);
			$table->addColumn('time', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['seq']);
			$table->addIndex(['did'], 'social_at_event1');
			$table->addIndex(['kind'], 'social_at_event2');
		}
		
		// social_atproto_plc_log
		if (!$schema->hasTable('social_atproto_plc_log')) {
			$table = $schema->createTable('social_atproto_plc_log');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('did', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('cid', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('operation', 'json', ['notnull' => true]);
			$table->addColumn('sent', 'datetime', ['notnull' => false]);
			$table->addColumn('confirmed', 'datetime', ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['did'], 'social_at_plc_log1');
		}
		
		// social_atproto_watch
		if (!$schema->hasTable('social_atproto_watch')) {
			$table = $schema->createTable('social_atproto_watch');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('did', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('handle', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('cursor', 'string', ['length' => 255, 'notnull' => false]);
			$table->addColumn('last_sync', 'datetime', ['notnull' => false]);
			$table->addColumn('next_sync', 'datetime', ['notnull' => false]);
			$table->addColumn('failures', 'integer', ['notnull' => true, 'default' => 0]);
			$table->addColumn('last_error', 'string', ['length' => 255, 'notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['did'], 'social_at_watch1');
		}
		
		// social_atproto_notify_cursor
		if (!$schema->hasTable('social_atproto_notify_cursor')) {
			$table = $schema->createTable('social_atproto_notify_cursor');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('did', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('cursor', 'string', ['length' => 255, 'notnull' => false]);
			$table->addColumn('last_sync', 'datetime', ['notnull' => false]);
			$table->addColumn('next_sync', 'datetime', ['notnull' => false]);
			$table->addColumn('failures', 'integer', ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['did'], 'social_at_notify_cu1');
		}
		
		// social_atproto_labeler
		if (!$schema->hasTable('social_atproto_labeler')) {
			$table = $schema->createTable('social_atproto_labeler');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('actor_id', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('labeler_did', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('settings', 'json', ['notnull' => true]);
			$table->addColumn('added', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['actor_id', 'labeler_did'], 'social_at_labeler1');
		}
		
		// social_atproto_blocklist
		if (!$schema->hasTable('social_atproto_blocklist')) {
			$table = $schema->createTable('social_atproto_blocklist');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('kind', 'string', ['length' => 32, 'notnull' => true]);
			$table->addColumn('value', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('reason', 'string', ['length' => 512, 'notnull' => false]);
			$table->addColumn('created', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['kind', 'value'], 'social_at_blocklist1');
		}
		
		// social_atproto_session
		if (!$schema->hasTable('social_atproto_session')) {
			$table = $schema->createTable('social_atproto_session');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('did', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('jti', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('refresh_token_hash', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('expires', 'datetime', ['notnull' => true]);
			$table->addColumn('created', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['jti'], 'social_at_session1');
			$table->addIndex(['did'], 'social_at_session2');
		}
		
		// social_atproto_recovery_phrase
		if (!$schema->hasTable('social_atproto_recovery')) {
			$table = $schema->createTable('social_atproto_recovery');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('actor_id', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('recovery_phrase', 'string', ['length' => 512, 'notnull' => true]);
			$table->addColumn('created_at', 'datetime', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['actor_id'], 'social_at_recovery1');
		}
		return $schema;
	}
}
