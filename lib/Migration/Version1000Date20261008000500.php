<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\FileCommentsRequest;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `social_action.poll_prim`: the poll a vote was cast in, as an indexed key.
 *
 * A vote's object is the poll's id with an option suffix, so the only way to
 * find the votes of one poll was a `LIKE '<poll>#option-%'` on an unindexed
 * text column of a table that also holds every like and boost — once per
 * poll, on every closed-poll sweep. With the poll as a column the sweep asks
 * for all its polls at once and an index answers.
 *
 * Every other kind of action carries `''`. The existing votes are filled in
 * after the column is added; they are a small part of the table, and the
 * keyset below walks it once.
 */
class Version1000Date20261008000500 extends SimpleMigrationStep {
	/** Rows per page and per update: three placeholders a row, inside SQLite's ceiling. */
	private const BATCH = 2000;

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
		if (!$schema->hasTable('social_action')) {
			return null;
		}

		$table = $schema->getTable('social_action');
		$changed = false;

		if (!$table->hasColumn('poll_prim')) {
			$table->addColumn('poll_prim', Types::STRING, ['default' => '', 'length' => 32, 'notnull' => false]);
			$changed = true;
		}

		if (!$table->hasIndex('social_a_poll')) {
			$table->addIndex(['poll_prim'], 'social_a_poll');
			$changed = true;
		}

		return $changed ? $schema : null;
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// `*PREFIX*` is what the connection rewrites; there is no public API
		// that hands the prefix over as a string
		$table = '*PREFIX*' . CoreRequestBuilder::TABLE_ACTIONS;

		// keyset on `id_prim`, so a vote whose object names no poll — left at
		// `''`, still matching — cannot bring the same page back
		$after = '';
		while (true) {
			$page = $this->connection->executeQuery(
				'SELECT `id_prim`, `object_id` FROM `' . $table . '`'
				. ' WHERE `type` = ? AND (`poll_prim` = \'\' OR `poll_prim` IS NULL) AND `id_prim` > ?'
				. ' ORDER BY `id_prim` ASC LIMIT ' . self::BATCH,
				['Vote', $after]
			)->fetchAll();

			if ($page === []) {
				break;
			}

			$prims = [];
			$cases = '';
			$values = [];
			foreach ($page as $row) {
				$after = (string)$row['id_prim'];
				$poll = FileCommentsRequest::primOf(
					ActionsRequest::pollOfVote('Vote', (string)$row['object_id'])
				);
				if ($poll === '') {
					continue;
				}

				$prims[] = $after;
				$cases .= ' WHEN ? THEN ?';
				$values[] = $after;
				$values[] = $poll;
			}

			if ($prims !== []) {
				$in = implode(', ', array_fill(0, count($prims), '?'));
				$this->connection->executeStatement(
					'UPDATE `' . $table . '` SET `poll_prim` = CASE `id_prim`' . $cases . ' END'
					. ' WHERE `id_prim` IN (' . $in . ')',
					array_merge($values, $prims)
				);
			}

			if (count($page) < self::BATCH) {
				break;
			}
		}
	}
}
