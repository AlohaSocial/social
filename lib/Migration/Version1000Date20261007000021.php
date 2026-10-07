<?php

declare(strict_types=1);

namespace OCA\Social\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;

class Version1000Date20261007000021 extends \OCP\Migration\SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		// Add atproto columns to existing tables

		// social_stream - id can be at:// URI (no schema change needed, id_prim handles it)
		// social_cache_actor - id can be at:// URI (no schema change needed)

		// Add details.atproto column info - already JSON

		// Add indexes for performance
		if ($schema->hasTable('social_atproto_event')) {
			$table = $schema->getTable('social_atproto_event');
			if (!$table->hasIndex('social_at_event_time')) {
				$table->addIndex(['time'], 'social_at_event_time');
			}
		}

		if ($schema->hasTable('social_atproto_record')) {
			$table = $schema->getTable('social_atproto_record');
			if (!$table->hasIndex('social_at_record_created')) {
				$table->addIndex(['created'], 'social_at_record_created');
			}
		}

		if ($schema->hasTable('social_atproto_watch')) {
			$table = $schema->getTable('social_atproto_watch');
			if (!$table->hasIndex('social_at_watch_next')) {
				$table->addIndex(['next_sync'], 'social_at_watch_next');
			}
		}

		if ($schema->hasTable('social_atproto_notify_cursor')) {
			$table = $schema->getTable('social_atproto_notify_cursor');
			if (!$table->hasIndex('social_at_notify_next')) {
				$table->addIndex(['next_sync'], 'social_at_notify_next');
			}
		}
		return $schema;
	}
}
