<?php
declare(strict_types=1);

namespace OCA\Social\Migration;

use OCP\Migration\ISchemaWrapper;
use OCP\Migration\IOutput;

class Version000041 extends \OCP\Migration\SimpleMigrationStep {
	public function getSchemaName(): string {
		return 'social';
	}
	
	public function changeSchema(ISchemaWrapper $schema, IOutput $output): void {
		// Add atproto columns to existing tables
		
		// social_stream - id can be at:// URI (no schema change needed, id_prim handles it)
		// social_cache_actor - id can be at:// URI (no schema change needed)
		
		// Add details.atproto column info - already JSON
		
		// Add indexes for performance
		if ($schema->hasTable('social_atproto_event')) {
			$table = $schema->getTable('social_atproto_event');
			if (!$table->hasIndex('time_idx')) {
				$table->addIndex(['time'], 'time_idx');
			}
		}
		
		if ($schema->hasTable('social_atproto_record')) {
			$table = $schema->getTable('social_atproto_record');
			if (!$table->hasIndex('created_idx')) {
				$table->addIndex(['created'], 'created_idx');
			}
		}
		
		if ($schema->hasTable('social_atproto_watch')) {
			$table = $schema->getTable('social_atproto_watch');
			if (!$table->hasIndex('next_sync_idx')) {
				$table->addIndex(['next_sync'], 'next_sync_idx');
			}
		}
		
		if ($schema->hasTable('social_atproto_notify_cursor')) {
			$table = $schema->getTable('social_atproto_notify_cursor');
			if (!$table->hasIndex('next_sync_idx')) {
				$table->addIndex(['next_sync'], 'next_sync_idx');
			}
		}
	}
}