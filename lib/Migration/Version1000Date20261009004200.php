<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
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
 * How many posts quote a post: `count_quotes`, a fifth counter beside the
 * four of `Version1000Date20261008000600`, recounted the same way
 * (`StreamRequest::recountQuotes()`); and `quote_prim`, the hash of the id a
 * post quotes, indexed, which the quotes are counted by — `quote` is TEXT,
 * which no index covers.
 *
 * Both are filled here from what is stored, in pages of `nid`; what a
 * post's own network counts arrives with its next count.
 */
class Version1000Date20261009004200 extends SimpleMigrationStep {
	public const COUNT = 'count_quotes';
	public const PRIM = 'quote_prim';
	/** rows read, and quoted posts written, per statement */
	public const BATCH = 500;

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
		if (!$schema->hasTable(CoreRequestBuilder::TABLE_STREAM)) {
			return null;
		}
		$table = $schema->getTable(CoreRequestBuilder::TABLE_STREAM);
		$changed = false;
		if (!$table->hasColumn(self::COUNT)) {
			$table->addColumn(self::COUNT, Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$changed = true;
		}
		if (!$table->hasColumn(self::PRIM)) {
			$table->addColumn(self::PRIM, Types::STRING, ['notnull' => false, 'default' => '', 'length' => 32]);
			$table->addIndex([self::PRIM], 'social_s_qp');
			$changed = true;
		}

		return $changed ? $schema : null;
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$table = '*PREFIX*' . CoreRequestBuilder::TABLE_STREAM;

		// the hash of every quote stored, as `SocialCoreQueryBuilder::prim()`
		// makes it; in PHP, as SQLite has no md5()
		$counts = [];
		$after = 0;
		while (true) {
			$page = $this->connection->executeQuery(
				'SELECT `nid`, `quote` FROM `' . $table . '` WHERE `nid` > ' . $after
				. ' AND `quote` IS NOT NULL AND `quote` <> \'\' ORDER BY `nid` ASC LIMIT ' . self::BATCH
			)->fetchAll();
			if ($page === []) {
				break;
			}
			$cases = '';
			$nids = [];
			foreach ($page as $row) {
				$after = max($after, (int)$row['nid']);
				$quoted = (string)$row['quote'];
				if (!str_starts_with($quoted, 'http')) {
					continue;
				}
				$prim = md5($quoted);
				$counts[$prim] = ($counts[$prim] ?? 0) + 1;
				$cases .= ' WHEN ' . (int)$row['nid'] . ' THEN ' . $this->connection->quote($prim);
				$nids[] = (int)$row['nid'];
			}
			if ($nids !== []) {
				$this->connection->executeStatement(
					'UPDATE `' . $table . '` SET `' . self::PRIM . '` = CASE `nid`' . $cases . ' ELSE `' . self::PRIM . '` END'
					. ' WHERE `nid` IN (' . implode(', ', $nids) . ')'
				);
			}
			if (count($page) < self::BATCH) {
				break;
			}
		}

		$filled = 0;
		foreach (array_chunk($counts, self::BATCH, true) as $chunk) {
			$cases = '';
			$prims = [];
			foreach ($chunk as $prim => $count) {
				$quoted = $this->connection->quote($prim);
				$cases .= ' WHEN ' . $quoted . ' THEN ' . min($count, Version1000Date20261008000600::MAX);
				$prims[] = $quoted;
			}
			$filled += $this->connection->executeStatement(
				'UPDATE `' . $table . '` SET `' . self::COUNT . '` = CASE `id_prim`' . $cases . ' ELSE `' . self::COUNT . '` END'
				. ' WHERE `id_prim` IN (' . implode(', ', $prims) . ')'
			);
		}

		if ($filled > 0) {
			$output->info('counted the quotes of ' . $filled . ' post(s)');
		}
	}
}
