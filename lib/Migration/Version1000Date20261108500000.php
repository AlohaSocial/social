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
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `social_atp_chat_message.message_prim`: the md5 of the Bluesky message id,
 * indexed with the account, so the local message sent as a Bluesky message
 * is found without a cache that expires. The rows already there get theirs
 * computed; run again, it finds none left.
 */
class Version1000Date20261108500000 extends SimpleMigrationStep {
	private const TABLE = CoreRequestBuilder::TABLE_ATPROTO_CHAT_MESSAGE;
	private const BATCH = 500;

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable(self::TABLE)) {
			return null;
		}
		$table = $schema->getTable(self::TABLE);
		$changed = false;
		if (!$table->hasColumn('message_prim')) {
			$table->addColumn('message_prim', Types::STRING, ['length' => 32, 'notnull' => false]);
			$changed = true;
		}
		if (!$table->hasIndex('social_atp_chat_msg_mp')) {
			$table->addIndex(['did', 'message_prim'], 'social_atp_chat_msg_mp');
			$changed = true;
		}

		return $changed ? $schema : null;
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if (!$schemaClosure()->hasTable(self::TABLE)) {
			return;
		}
		do {
			$select = $this->connection->getQueryBuilder();
			$select->select('stream_id_prim', 'did', 'message_id')
				->from(self::TABLE)
				->where($select->expr()->isNull('message_prim'))
				->setMaxResults(self::BATCH);
			$cursor = $select->executeQuery();
			$rows = $cursor->fetchAll();
			$cursor->closeCursor();
			foreach ($rows as $row) {
				$update = $this->connection->getQueryBuilder();
				$update->update(self::TABLE)
					->set('message_prim', $update->createNamedParameter(md5((string)$row['message_id'])))
					->where($update->expr()->eq('stream_id_prim', $update->createNamedParameter((string)$row['stream_id_prim'])))
					->andWhere($update->expr()->eq('did', $update->createNamedParameter((string)$row['did'])));
				$update->executeStatement();
			}
		} while (count($rows) === self::BATCH);
	}
}
