<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Model\ActivityPub\Stream;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Backfills `media_kind`, the kind of media a post carries, so the Photos and
 * Videos timelines and `only_media` can use an index on `(kind, nid)` instead
 * of a `LIKE` on the stored JSON.
 *
 * Values are the `MediaAttachment` types plus `''` for no media and `mixed`
 * for several kinds; `mixed` matches every "has this kind" predicate. `null`
 * means not filled yet, which the `LIKE` predicate still answers until the
 * backfill finishes, so this is safe on a running instance.
 */
class Version1000Date20260917000003 extends SimpleMigrationStep {
	/**
	 * How many posts one backfill statement looks at: at most 5,001 bound
	 * parameters, well under SQLite's limit of 32,766.
	 */
	private const BATCH = 5000;

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	/**
	 * The columns are in `Version1000Date20221118000002`, which describes the
	 * whole schema; this step only fills the rows.
	 */

	/**
	 * Works out the kind of every stored post, a page at a time, in PHP
	 * because the supported databases spell JSON queries differently.
	 *
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$stream = '*PREFIX*' . CoreRequestBuilder::TABLE_STREAM;

		$total = (int)$this->connection->executeQuery(
			'SELECT COUNT(*) FROM `' . $stream . '` WHERE `media_kind` IS NULL'
		)->fetchOne();
		if ($total < 1) {
			return;
		}

		$output->info('working out what ' . $total . ' post(s) carry');

		$after = '0';
		$done = 0;
		while (true) {
			$page = $this->connection->executeQuery(
				'SELECT `nid`, `attachments`, `subtype` FROM `' . $stream . '`'
				. ' WHERE `nid` > ? AND `media_kind` IS NULL ORDER BY `nid` ASC LIMIT ' . self::BATCH,
				[$after]
			)->fetchAll();

			if ($page === []) {
				break;
			}

			$byKind = [];
			foreach ($page as $row) {
				$after = \OCA\Social\Tools\Nid::compare($after, (string)$row['nid']) > 0 ? $after : (string)$row['nid'];
				$kind = Stream::mediaKindOf(
					(string)($row['attachments'] ?? ''), (string)($row['subtype'] ?? '')
				);
				$byKind[$kind][] = (string)$row['nid'];
			}

			// one statement per kind per page rather than one per row
			foreach ($byKind as $kind => $nids) {
				$in = implode(', ', array_fill(0, count($nids), '?'));
				$this->connection->executeStatement(
					'UPDATE `' . $stream . '` SET `media_kind` = ? WHERE `nid` IN (' . $in . ')',
					array_merge([$kind], array_map('strval', $nids))
				);
			}

			$done += count($page);
			if ($done % (self::BATCH * 10) === 0) {
				$output->info('  ' . $done . ' / ' . $total);
			}
		}

		$output->info('  done');
	}
}
