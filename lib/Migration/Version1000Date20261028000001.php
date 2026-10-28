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

class Version1000Date20261028000001 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('social_atp_chat_message')) {
			return null;
		}
		$table = $schema->createTable('social_atp_chat_message');
		$table->addColumn('stream_id_prim', Types::STRING, ['length' => 32, 'notnull' => true]);
		$table->addColumn('did', Types::STRING, ['length' => 255, 'notnull' => true]);
		$table->addColumn('convo_id', Types::TEXT, ['notnull' => true]);
		$table->addColumn('message_id', Types::TEXT, ['notnull' => true]);
		$table->setPrimaryKey(['stream_id_prim', 'did'], 'social_atp_chat_msg_pk');

		return $schema;
	}
}
