<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use DateTime;
use DateTimeZone;
use Exception;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\ConfigService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Fills in the five post columns `Version1000Date20260912000007` added
 * (`tags`, `language`, `updated`, `quote`, `quote_authorization`) for older
 * rows, from their `source`, using `Stream::importFromDatabase()` so there is
 * one parser. Bounded and marked like BackfillRemoteVisibility: pages on the
 * primary key, skips rows that already agree, and is not repeated once a run
 * completes, as checking the whole table is a full scan in maintenance mode.
 */
class BackfillStreamPostFields implements IRepairStep {
	/** Rows read per round trip. */
	private const CHUNK = 500;

	public const MARKER = 'migration_stream_post_fields_backfilled';

	/** The columns this step fills, and the row keys they arrive under. */
	private const COLUMNS = ['tags', 'language', 'updated', 'quote', 'quote_authorization'];

	public function __construct(
		private IDBConnection $connection,
		private ConfigService $configService,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Backfill the post fields of statuses stored before they had columns';
	}

	#[\Override]
	public function run(IOutput $output): void {
		if ($this->configService->getAppValueInt(self::MARKER) === 1) {
			return;
		}

		$backfilled = 0;
		$after = '0';
		while (true) {
			$rows = $this->chunkAfter($after);
			if ($rows === []) {
				break;
			}

			foreach ($rows as $row) {
				$after = (string)$row['nid'];
				if ($this->backfill($row)) {
					$backfilled++;
				}
			}

			if (count($rows) < self::CHUNK) {
				break;
			}
		}

		$this->configService->setAppValue(self::MARKER, '1');

		if ($backfilled > 0) {
			$output->info(sprintf('filled in the post fields of %d status(es)', $backfilled));
		}
	}

	/**
	 * @param array<string, mixed> $row
	 *
	 * @return bool whether the row needed writing
	 */
	private function backfill(array $row): bool {
		if ((string)($row['source'] ?? '') === '') {
			// nothing to derive them from; a local-only item such as an
			// in-app notification, which has none of the five to begin with
			return false;
		}

		$stream = new Stream();
		$stream->importFromDatabase($row);

		$wanted = [
			'tags' => json_encode($stream->getTags(), JSON_UNESCAPED_SLASHES),
			'language' => $stream->getLanguage(),
			'quote' => $stream->getQuote(),
			'quote_authorization' => $stream->getQuoteAuthorization(),
		];

		$changed = false;
		foreach ($wanted as $column => $value) {
			if ($column === 'tags') {
				// compared as the array, not as the text: a post with no tags
				// has an empty column and encodes to `[]`, and writing every
				// such row to change nothing would be most of the table
				$stored = json_decode((string)($row[$column] ?? ''), true);
				$changed = $changed || (is_array($stored) ? $stored : []) !== $stream->getTags();

				continue;
			}

			$changed = $changed || (string)($row[$column] ?? '') !== (string)$value;
		}

		$updated = $this->asUtcDate($stream->getUpdated());
		if (($updated === null) !== ((string)($row['updated'] ?? '') === '')) {
			$changed = true;
		}

		if (!$changed) {
			return false;
		}

		$qb = $this->connection->getQueryBuilder();
		$qb->update(CoreRequestBuilder::TABLE_STREAM);
		foreach ($wanted as $column => $value) {
			$qb->set($column, $qb->createNamedParameter($value));
		}
		$qb->set('updated', $qb->createNamedParameter($updated, IQueryBuilder::PARAM_DATE));
		$qb->where($qb->expr()->eq('nid', $qb->createNamedParameter((string)$row['nid'])));
		$qb->executeStatement();

		return true;
	}

	/**
	 * The same conversion `StreamRequest` does before it binds: the column holds
	 * UTC, and a DateTime is rendered by Doctrine in whatever zone it carries.
	 */
	private function asUtcDate(string $updated): ?DateTime {
		if ($updated === '') {
			return null;
		}

		try {
			return (new DateTime($updated))->setTimezone(new DateTimeZone('UTC'));
		} catch (Exception) {
			return null;
		}
	}

	/**
	 * @return array<array<string, mixed>>
	 */
	private function chunkAfter(int|string $after): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('nid', 'source')
			->from(CoreRequestBuilder::TABLE_STREAM)
			->where($qb->expr()->gt('nid', $qb->createNamedParameter($after)))
			->orderBy('nid', 'asc')
			->setMaxResults(self::CHUNK);
		foreach (self::COLUMNS as $column) {
			$qb->addSelect($column);
		}

		$cursor = $qb->executeQuery();
		$rows = $cursor->fetchAll();
		$cursor->closeCursor();

		return $rows;
	}
}
