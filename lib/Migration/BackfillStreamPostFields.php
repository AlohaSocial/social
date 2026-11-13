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
 * Fills in the five post columns `Version1000Date20260912000007` added, for the
 * rows that predate them.
 *
 * The columns are empty on every existing row, and `tags`, `language`,
 * `updated`, `quote` and `quote_authorization` are all still inside the `source`
 * column of each — which is where they used to be read from, and still are as a
 * fallback. Until this has run, a language filter would report those posts as
 * having no language and an index scan would agree with it; that is the whole
 * reason the columns were added.
 *
 * The extraction is `Stream::importFromDatabase()` itself rather than a second
 * copy of the parsing. Handed a row whose columns are empty it takes exactly the
 * fallback path a read takes today, so what is written here is what a read would
 * have derived — there is one parser, not two that can drift.
 *
 * Bounded and marked, like BackfillRemoteVisibility. Pages on the primary key
 * rather than by offset, because an offset scan re-reads everything it has
 * already walked and `social_stream` is the largest table in the app. A row that
 * already agrees with its wire object is not written. The marker is what makes
 * it affordable: "everything already agrees" is not free to establish, it is a
 * full scan of the table with the instance in maintenance mode, so once a run
 * completes it is not repeated on every later `occ upgrade`.
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
