<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `social_stream.poll_ends_at`: when a poll closes, as an indexed Unix time.
 *
 * The end of a poll lived only in the stored wire object, so the sweep that
 * announces closed polls read the newest few hundred polls of the last six
 * months and asked each one in PHP. A poll outside that page — one of many,
 * or one published long before it closed — was never announced. With the
 * end as a column the sweep asks for exactly the polls that closed in its
 * window.
 *
 * NULL on everything that is not a poll, and on a poll with no end. The
 * existing polls are filled in from their wire objects after the column is
 * added; they are a small part of the table.
 */
class Version1000Date20261008000501 extends SimpleMigrationStep {
	/** Rows read per page: each carries its wire object. */
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
		if (!$schema->hasTable('social_stream')) {
			return null;
		}

		$table = $schema->getTable('social_stream');
		$changed = false;

		if (!$table->hasColumn('poll_ends_at')) {
			$table->addColumn('poll_ends_at', Types::BIGINT, ['length' => 11, 'notnull' => false, 'unsigned' => true]);
			$changed = true;
		}

		if (!$table->hasIndex('social_s_pend')) {
			$table->addIndex(['poll_ends_at'], 'social_s_pend');
			$changed = true;
		}

		return $changed ? $schema : null;
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// `*PREFIX*` is what the connection rewrites; there is no public API
		// that hands the prefix over as a string
		$table = '*PREFIX*' . CoreRequestBuilder::TABLE_STREAM;

		// keyset on `nid`, so a poll with no end — left NULL, still
		// matching — cannot bring the same page back
		$after = '0';
		while (true) {
			$page = $this->connection->executeQuery(
				'SELECT `nid`, `source` FROM `' . $table . '`'
				. ' WHERE `type` = ? AND `poll_ends_at` IS NULL AND `nid` > ?'
				. ' ORDER BY `nid` ASC LIMIT ' . self::BATCH,
				[Question::TYPE, $after]
			)->fetchAll();

			if ($page === []) {
				break;
			}

			foreach ($page as $row) {
				$after = (string)$row['nid'];
				$source = json_decode((string)($row['source'] ?? ''), true);
				$ends = Question::timestampOf(is_array($source) ? (string)($source['endTime'] ?? '') : '');
				if ($ends === null) {
					continue;
				}

				$this->connection->executeStatement(
					'UPDATE `' . $table . '` SET `poll_ends_at` = ? WHERE `nid` = ?',
					[$ends, $after]
				);
			}

			if (count($page) < self::BATCH) {
				break;
			}
		}
	}
}
