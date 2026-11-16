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
use OCA\Social\Tools\Nid;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Two flags that let the lists of posts drop an `OR` a planner estimates
 * badly.
 *
 * Every list admits `archived IS NULL` beside `archived = 0` for the rows
 * written before the column existed; here those rows are given a 0, batched
 * by nid, and `stream_archived_filled` says so. The media filters admit a
 * row with no `media_kind` by its attachments; `Version1000Date20260917000003`
 * filled the column, and once no row is found without one
 * `stream_media_kind_filled` says that too. On MySQL the `OR` on either
 * column is what turned a public or Photos page into a read of the whole
 * table (`Using temporary; Using filesort`), as the same SQL on the same rows
 * read twenty index entries the day before.
 *
 * Run again, it finds nothing to fill and sets both flags again.
 */
class Version1000Date20261013100500 extends SimpleMigrationStep {
	/** How many rows one page reads. */
	private const BATCH = 5000;

	/** How many of them one UPDATE names: past a thousand the IN list is logged as an error. */
	private const CHUNK = 1000;

	public function __construct(
		private IDBConnection $connection,
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->fill($output);
	}

	/**
	 * The backfill and the two flags, apart from the migration: a fresh
	 * install runs migrations schema-only, so `InstallBackfills`
	 * calls this on install, where the tables are empty and both flags go
	 * up at once.
	 */
	public function fill(IOutput $output): void {
		// paged on the primary key from where the last page ended: `archived`
		// has no index, and asking for the first NULL rows again each time
		// walked past every row already filled, so the whole backfill was
		// quadratic in the size of the table
		$filled = 0;
		$after = '0';
		while (true) {
			$qb = $this->connection->getQueryBuilder();
			$qb->select('nid')
				->from(CoreRequestBuilder::TABLE_STREAM)
				->where($qb->expr()->isNull('archived'))
				->andWhere($qb->expr()->gt('nid', $qb->createNamedParameter($after)))
				->orderBy('nid', 'asc')
				->setMaxResults(self::BATCH);
			$cursor = $qb->executeQuery();
			$nids = array_map(static fn (array $row): string => (string)$row['nid'], $cursor->fetchAll());
			$cursor->closeCursor();
			if ($nids === []) {
				break;
			}

			foreach ($nids as $nid) {
				$after = (Nid::compare($after, $nid) > 0) ? $after : $nid;
			}

			foreach (array_chunk($nids, self::CHUNK) as $chunk) {
				$update = $this->connection->getQueryBuilder();
				$update->update(CoreRequestBuilder::TABLE_STREAM)
					->set('archived', $update->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
					->where($update->expr()->in('nid', $update->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)))
					->andWhere($update->expr()->isNull('archived'));
				$filled += $update->executeStatement();
			}
		}

		$this->appConfig->setValueString('social', ConfigService::SOCIAL_STREAM_ARCHIVED_FILLED, '1');
		if ($filled > 0) {
			$output->info('gave ' . $filled . ' post(s) stored before the archive flag existed its value');
		}

		$qb = $this->connection->getQueryBuilder();
		$qb->select('nid')
			->from(CoreRequestBuilder::TABLE_STREAM)
			->where($qb->expr()->isNull('media_kind'))
			->setMaxResults(1);
		$cursor = $qb->executeQuery();
		$unfilled = $cursor->fetchOne();
		$cursor->closeCursor();
		if ($unfilled === false) {
			$this->appConfig->setValueString('social', ConfigService::SOCIAL_STREAM_MEDIA_KIND_FILLED, '1');
		} else {
			$output->info('a post still has no media kind; the media filters keep judging such rows by their attachments');
		}
	}
}
