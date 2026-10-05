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
 * The imports an account asked for, worked through in the background.
 *
 * Every import used to run inside the request that uploaded the file: each
 * follow is a WebFinger lookup, an actor fetch and a delivery, so a few
 * hundred of them ran into the web server's timeout, and the rate limit that
 * protected the server from that meant a timed-out upload could not simply be
 * tried again. A row here is one upload, or one server to pull from, with
 * where the run got to and what it came to; `Cron\RunImport` works it off and
 * the Migration page watches the row.
 *
 * Indexed on (user, id): the page reads one account's rows newest first, and
 * "is one of this kind already under way" is the same index with a filter.
 */
class Version1000Date20261004000002 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('social_import')) {
			return null;
		}

		$table = $schema->createTable('social_import');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
		$table->addColumn('kind', Types::STRING, ['length' => 31, 'notnull' => true]);
		$table->addColumn('status', Types::STRING, ['length' => 15, 'notnull' => true, 'default' => 'queued']);
		$table->addColumn('total', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		$table->addColumn('done', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		$table->addColumn('skipped', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		$table->addColumn('failed', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		$table->addColumn('report', Types::TEXT, ['notnull' => false]);
		$table->addColumn('options', Types::TEXT, ['notnull' => false]);
		$table->addColumn('file', Types::STRING, ['length' => 127, 'notnull' => true, 'default' => '']);
		$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('updated', Types::DATETIME, ['notnull' => false]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['user_id', 'id'], 'social_imp_ui');

		return $schema;
	}
}
