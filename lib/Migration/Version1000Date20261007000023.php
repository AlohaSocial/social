<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20261007000023 extends SimpleMigrationStep {
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('social_atpds_event_clock')) {
			return null;
		}
		$table = $schema->createTable('social_atpds_event_clock');
		$table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('last_seq', 'bigint', ['notnull' => true, 'default' => 0]);
		$table->setPrimaryKey(['id']);
		return $schema;
	}
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		(new \OCA\Social\Atproto\Firehose\EventStore($this->db))->initialize();
	}
}
