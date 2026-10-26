<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Store the instance-wide learning invalidation epoch in one row. */
class Version1000Date20261025000001 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('social_rec_epoch')) {
			return null;
		}

		$table = $schema->createTable('social_rec_epoch');
		$table->addColumn('id', Types::SMALLINT, ['notnull' => true]);
		$table->addColumn('epoch', Types::BIGINT, ['default' => 0, 'notnull' => true]);
		$table->setPrimaryKey(['id']);

		return $schema;
	}
}
