<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCA\Social\Db\HashtagsRequest;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Fills the hashtag trend counters of rows whose columns disagree with their
 * JSON. `getTrending()` orders and filters on the columns, and the trends cron
 * skips a hashtag whose count is unchanged, so such hashtags would stay out of
 * the trends. The JSON is the source of truth; rows in agreement are left
 * alone, so this is safe to re-run.
 */
class Version1000Date20260910000003 extends SimpleMigrationStep {
	/** Rows read per round trip — the table has one row per hashtag ever seen. */
	private const CHUNK = 1000;

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$schema = $schemaClosure();
		if (!$schema->hasTable('social_hashtag')) {
			return;
		}

		$table = $schema->getTable('social_hashtag');
		foreach (HashtagsRequest::TREND_COLUMNS as $column) {
			if (!$table->hasColumn($column)) {
				return;
			}
		}

		$backfilled = 0;
		// paged on the primary key, not by offset, so a large table is walked once
		$after = '';
		while (true) {
			$rows = $this->chunkAfter($after);
			if ($rows === []) {
				break;
			}

			foreach ($rows as $row) {
				$after = (string)$row['hashtag'];
				$trend = json_decode((string)$row['trend'], true);
				if (!is_array($trend)) {
					continue;
				}

				$stored = HashtagsRequest::countersFromRow($row);
				$wanted = [];
				foreach (array_keys(HashtagsRequest::TREND_COLUMNS) as $period) {
					$wanted[$period] = (int)($trend[$period] ?? 0);
				}

				if ($wanted === $stored) {
					continue;
				}

				$this->write($after, $wanted);
				$backfilled++;
			}

			if (count($rows) < self::CHUNK) {
				break;
			}
		}

		if ($backfilled > 0) {
			$output->info(sprintf('filled in the trend counters of %d hashtag(s)', $backfilled));
		}
	}

	/**
	 * @return array<array<string, mixed>>
	 */
	private function chunkAfter(string $after): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('hashtag', 'trend')
			->from('social_hashtag')
			->where($qb->expr()->gt('hashtag', $qb->createNamedParameter($after)))
			->orderBy('hashtag', 'asc')
			->setMaxResults(self::CHUNK);
		foreach (HashtagsRequest::TREND_COLUMNS as $column) {
			$qb->addSelect($column);
		}

		$cursor = $qb->executeQuery();
		$rows = $cursor->fetchAll();
		$cursor->closeCursor();

		return $rows;
	}

	/**
	 * @param array<string, int> $counters
	 */
	private function write(string $hashtag, array $counters): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->update('social_hashtag');
		foreach (HashtagsRequest::TREND_COLUMNS as $period => $column) {
			$qb->set($column, $qb->createNamedParameter($counters[$period]));
		}
		$qb->where($qb->expr()->eq('hashtag', $qb->createNamedParameter($hashtag)));

		$qb->executeStatement();
	}
}
