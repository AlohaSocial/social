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
 * `social_atproto_chat_cursor`: per local account, how far its Bluesky
 * direct messages have been read from the chat service (`ChatPoller`),
 * when they were last read and when they are due again — the same
 * bookkeeping as `social_atproto_notify_cursor`.
 */
class Version1000Date20261009000200 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('social_atproto_chat_cursor')) {
			return null;
		}
		$table = $schema->createTable('social_atproto_chat_cursor');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('did', Types::STRING, ['length' => 64, 'notnull' => true]);
		$table->addColumn('handle', Types::STRING, ['length' => 253, 'notnull' => true, 'default' => '']);
		$table->addColumn('cursor', Types::TEXT, ['notnull' => true, 'default' => '']);
		$table->addColumn('last_sync', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('next_sync', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('failures', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
		$table->addColumn('last_error', Types::TEXT, ['notnull' => true, 'default' => '']);
		$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['did'], 'social_atpc_did');
		$table->addIndex(['next_sync'], 'social_atpc_next');

		return $schema;
	}
}
