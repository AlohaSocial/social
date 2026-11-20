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
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Whether a post is news, in a column an index can be put on: an `Article` or
 * `Page`, or text linking somewhere else, which no database can work out on
 * read. Written when the post is stored, so `(news_kind, nid)` answers the
 * newest news posts as a descending range.
 *
 * `null` is "not looked at yet"; until the backfill below reaches a row it is
 * not in the News timeline, as there is no cheap SQL fallback.
 *
 * @see Stream::newsKindOf()
 */
class Version1000Date20260917000005 extends SimpleMigrationStep {
	/**
	 * How many posts one backfill statement looks at, as for `media_kind`: one
	 * placeholder a row plus the kind stays well inside SQLite's limit of
	 * 32,766 bound parameters.
	 */
	private const BATCH = 5000;

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	/**
	 * The columns are in `Version1000Date20221118000002`, which describes the
	 * whole schema and runs before this; this step fills the rows.
	 */

	/**
	 * Works out what every post already stored is, in PHP, as finding a link
	 * that is not a mention or a hashtag is no question for SQL; a page at a
	 * time, so each statement is bounded.
	 *
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$stream = '*PREFIX*' . CoreRequestBuilder::TABLE_STREAM;

		$total = (int)$this->connection->executeQuery(
			'SELECT COUNT(*) FROM `' . $stream . '` WHERE `news_kind` IS NULL'
		)->fetchOne();
		if ($total < 1) {
			return;
		}

		$output->info('working out which of ' . $total . ' post(s) are news');

		$after = '0';
		$done = 0;
		while (true) {
			$page = $this->connection->executeQuery(
				'SELECT `nid`, `content`, `subtype` FROM `' . $stream . '`'
				. ' WHERE `nid` > ? AND `news_kind` IS NULL ORDER BY `nid` ASC LIMIT ' . self::BATCH,
				[$after]
			)->fetchAll();

			if ($page === []) {
				break;
			}

			$byKind = [];
			foreach ($page as $row) {
				$after = \OCA\Social\Tools\Nid::compare($after, (string)$row['nid']) > 0 ? $after : (string)$row['nid'];
				$kind = Stream::newsKindOf(
					(string)($row['content'] ?? ''), (string)($row['subtype'] ?? '')
				);
				$byKind[$kind][] = (string)$row['nid'];
			}

			// one statement per kind per page rather than one per row
			foreach ($byKind as $kind => $nids) {
				$in = implode(', ', array_fill(0, count($nids), '?'));
				$this->connection->executeStatement(
					'UPDATE `' . $stream . '` SET `news_kind` = ? WHERE `nid` IN (' . $in . ')',
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
