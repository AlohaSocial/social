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
 * A post's four interaction counters as columns of `social_stream`.
 *
 * They lived in the `details` JSON, and the only way to change one key of a
 * JSON blob is to read all of it and write all of it back. Five writers did
 * that — a like, a boost, a reply, a dislike and the cron that asks other
 * servers for their totals — and also every writer of the blob's other keys,
 * so two of them landing on one post together kept whichever wrote last and
 * lost the other. As columns each one is recounted in a single statement
 * that touches nothing else (`StreamRequest::recount()`).
 *
 * Existing rows are filled from the JSON here, in pages of `nid`. Only the
 * totals move: the origin's halves (`remote_likes` and the like) are absolute
 * numbers written by one writer and stay in `details`.
 */
class Version1000Date20261008000600 extends SimpleMigrationStep {
	/**
	 * `details` key => column. Every value written is an int cast here and
	 * clamped at zero, so it is inlined into the statement rather than bound.
	 */
	public const COLUMNS = [
		'replies' => 'count_replies',
		'likes' => 'count_likes',
		'boosts' => 'count_boosts',
		'dislikes' => 'count_dislikes',
	];

	/** The largest value a signed 32-bit INTEGER holds. */
	public const MAX = 2147483647;

	/** Rows read per page; `details` can run to a few kilobytes a row. */
	public const BATCH = 1000;

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
		foreach (self::COLUMNS as $column) {
			if (!$table->hasColumn($column)) {
				$table->addColumn($column, Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// `*PREFIX*` is what the connection rewrites; there is no public API
		// that hands the prefix over as a string
		$table = '*PREFIX*' . CoreRequestBuilder::TABLE_STREAM;

		// only rows whose counters are all still at their default: a row this
		// step has already filled is left alone if it runs again, because by
		// then the column is what moves and the JSON is what has gone stale
		$untouched = '';
		foreach (self::COLUMNS as $column) {
			$untouched .= ' AND `' . $column . '` = 0';
		}

		$after = 0;
		$filled = 0;
		while (true) {
			$page = $this->connection->executeQuery(
				'SELECT `nid`, `details` FROM `' . $table . '` WHERE `nid` > ' . $after . $untouched
				. ' ORDER BY `nid` ASC LIMIT ' . self::BATCH
			)->fetchAll();

			if ($page === []) {
				break;
			}

			$cases = array_fill_keys(self::COLUMNS, '');
			$nids = [];
			foreach ($page as $row) {
				$nid = (int)$row['nid'];
				$after = max($after, $nid);

				$counts = self::countsOf((string)($row['details'] ?? ''));
				if ($counts === []) {
					continue;
				}

				$nids[] = $nid;
				foreach (self::COLUMNS as $key => $column) {
					$cases[$column] .= ' WHEN ' . $nid . ' THEN ' . ($counts[$key] ?? 0);
				}
			}

			if ($nids !== []) {
				$set = [];
				foreach ($cases as $column => $when) {
					$set[] = '`' . $column . '` = CASE `nid`' . $when . ' ELSE `' . $column . '` END';
				}
				$this->connection->executeStatement(
					'UPDATE `' . $table . '` SET ' . implode(', ', $set)
					. ' WHERE `nid` IN (' . implode(', ', $nids) . ')'
				);
				$filled += count($nids);
			}

			if (count($page) < self::BATCH) {
				break;
			}
		}

		if ($filled > 0) {
			$output->info('moved the counters of ' . $filled . ' post(s) out of their details');
		}
	}

	/**
	 * The counters a `details` blob holds, as ints from 1 to the largest an
	 * INTEGER column takes on every platform; empty when it holds none worth
	 * writing. A blob written before remote counts had a ceiling may hold a
	 * figure no column can, and MySQL's strict mode refuses it rather than
	 * storing it.
	 *
	 * @return array<string, int>
	 */
	public static function countsOf(string $details): array {
		$decoded = json_decode($details, true);
		if (!is_array($decoded)) {
			return [];
		}

		$counts = [];
		foreach (array_keys(self::COLUMNS) as $key) {
			$value = $decoded[$key] ?? null;
			if (is_numeric($value) && (int)$value > 0) {
				$counts[$key] = min((int)$value, self::MAX);
			}
		}

		return $counts;
	}
}
