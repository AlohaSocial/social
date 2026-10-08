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
 * Reading Bluesky: what this instance polls the AppView for.
 *
 * `social_atproto_watch` is one row per Bluesky author somebody here
 * follows — the author-feed cursor, when the feed was last read and when
 * it is due again, with the backoff in `next_sync`. `social_atproto_notify_cursor`
 * is the same bookkeeping per local account for the notifications the
 * AppView keeps for it: who followed, liked, reposted, replied, mentioned.
 */
class Version1000Date20261008000020 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		foreach (['social_atproto_watch' => 'social_atpw', 'social_atproto_notify_cursor' => 'social_atpn'] as $name => $prefix) {
			if ($schema->hasTable($name)) {
				continue;
			}
			$table = $schema->createTable($name);
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
			$table->addUniqueIndex(['did'], $prefix . '_did');
			$table->addIndex(['next_sync'], $prefix . '_next');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
