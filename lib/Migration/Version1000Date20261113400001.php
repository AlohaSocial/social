<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCA\Social\Db\CoreRequestBuilder;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `social_server_block`: what is recorded about an instance-wide block or
 * silence beside the domain itself (`ServerBlockRequest`) — the reason for
 * the moderators (`private_comment`), the reason the published list shows
 * (`public_comment`), and when it was recorded. Unique on the domain; the
 * two lists in app config still decide what is blocked.
 */
class Version1000Date20261113400001 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable(CoreRequestBuilder::TABLE_SERVER_BLOCKS)) {
			return null;
		}

		$table = $schema->createTable(CoreRequestBuilder::TABLE_SERVER_BLOCKS);
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'length' => 11, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('domain', Types::STRING, ['length' => 253, 'notnull' => true]);
		$table->addColumn('private_comment', Types::TEXT, ['notnull' => false]);
		$table->addColumn('public_comment', Types::TEXT, ['notnull' => false]);
		$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['domain'], 'social_srvblk_domain');

		return $schema;
	}
}
