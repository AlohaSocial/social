<?php

declare(strict_types=1);

namespace OCA\Social\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20261007000022 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('social_atproto_outbox')) {
			return null;
		}
		$t = $schema->createTable('social_atproto_outbox');
		$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
		$t->addColumn('post_nid', 'string', ['length' => 64, 'notnull' => true]);
		$t->addColumn('action', 'string', ['length' => 16, 'notnull' => true]);
		$t->addColumn('attempts', 'integer', ['default' => 0, 'notnull' => true]);
		$t->addColumn('next_try', 'integer', ['notnull' => true]);
		$t->addColumn('last_error', 'string', ['length' => 255, 'notnull' => false]);
		$t->setPrimaryKey(['id']);
		$t->addUniqueIndex(['post_nid'], 'social_at_outbox_post');
		$t->addIndex(['next_try'], 'social_at_outbox_due');
		return $schema;
	}
}
