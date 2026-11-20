<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Service\ConfigService;
use OCP\DB\ISchemaWrapper;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The post's own sort key, on the row that says who received it.
 *
 * With `nid` here and an index on `(actor_id, type, nid)`, a home timeline
 * page is a descending index range per followed collection, bounded by the
 * page size; without it the database reads and sorts every post of every
 * followed account. No index can span the two tables, and a nid never
 * changes, so the copy is safe.
 *
 * Existing rows are backfilled in batches in `postSchemaChange`; a row not yet
 * filled carries `0`, which sorts last, and `StreamRequest` keeps the slow
 * predicate until the flag is raised.
 */
class Version1000Date20260917000001 extends SimpleMigrationStep {
	/**
	 * How many recipient rows one backfill statement carries: three placeholders
	 * a row, and SQLite allows 32,766 in a statement (PostgreSQL 65,535), so 5,000
	 * stays well inside both.
	 */
	private const BATCH = 5000;

	public function __construct(
		private IDBConnection $connection,
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * The columns are added by `Version1000Date20221118000002`, which describes
	 * the whole schema; this step fills the rows.
	 */

	/**
	 * Fills in the nids of the rows that already exist, through `stream_id` →
	 * `social_stream.id_prim`. Batched over ranges of posts, which are contiguous
	 * by `nid`, where recipient rows are scattered.
	 *
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->fill($output);
	}

	/**
	 * The backfill and its flag, apart from the migration: a fresh install
	 * runs migrations schema-only, so `InstallBackfills` calls this there.
	 */
	public function fill(IOutput $output): void {
		// `*PREFIX*` is what the connection rewrites; there is no public API
		// that hands the prefix over as a string
		$dest = '*PREFIX*' . CoreRequestBuilder::TABLE_STREAM_DEST;
		$stream = '*PREFIX*' . CoreRequestBuilder::TABLE_STREAM;

		$total = (int)$this->connection->executeQuery(
			'SELECT COUNT(*) FROM `' . $stream . '`'
		)->fetchOne();
		if ($total < 1) {
			// nothing to fill in, which is the same as having filled it in
			$this->markFilled();

			return;
		}

		$output->info('filling in the recipient rows\' sort key for ' . $total . ' post(s)');

		// PostgreSQL types a CASE whose branches are all parameters as text,
		// which it will not write into a bigint column
		$then = $this->connection->getDatabaseProvider() === IDBConnection::PLATFORM_POSTGRES
			? 'CAST(? AS BIGINT)'
			: '?';

		$after = '0';
		$done = 0;
		while (true) {
			$page = $this->connection->executeQuery(
				'SELECT `nid`, `id_prim` FROM `' . $stream . '` WHERE `nid` > ? ORDER BY `nid` ASC LIMIT '
				. self::BATCH,
				[$after]
			)->fetchAll();

			if ($page === []) {
				break;
			}

			// one statement per page, as a CASE over the page's prims: an
			// UPDATE per post would be twenty million round trips
			$prims = [];
			$cases = '';
			$values = [];
			foreach ($page as $row) {
				$after = \OCA\Social\Tools\Nid::compare($after, (string)$row['nid']) > 0 ? $after : (string)$row['nid'];
				$prims[] = (string)$row['id_prim'];
				$cases .= ' WHEN ? THEN ' . $then;
				$values[] = (string)$row['id_prim'];
				$values[] = (string)$row['nid'];
			}

			$in = implode(', ', array_fill(0, count($prims), '?'));
			$this->connection->executeStatement(
				'UPDATE `' . $dest . '` SET `nid` = CASE `stream_id`' . $cases . ' END'
				. ' WHERE `stream_id` IN (' . $in . ')',
				array_merge($values, $prims)
			);

			$done += count($page);
			if ($done % (self::BATCH * 10) === 0) {
				$output->info('  ' . $done . ' / ' . $total);
			}
		}

		$this->markFilled();
		$output->info('  done');
	}

	/**
	 * Records that every recipient row carries its post's nid, for the read
	 * path: no index can answer whether any nid is still zero.
	 */
	private function markFilled(): void {
		$this->appConfig->setValueString('social', ConfigService::SOCIAL_DEST_NID_FILLED, '1');
	}
}
