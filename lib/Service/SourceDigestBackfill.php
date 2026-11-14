<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\AppInfo\Application;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Migration\BackfillStreamPostFields;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tools\Nid;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Gives the posts stored before `social_stream.source_digest` existed theirs
 * (`Stream::sourceDigestOf()`), so their reads stop decoding `source`.
 *
 * A row without one still reads correctly — out of `source`, as before — so
 * this is only ever catching up. The table is walked once, in pages by
 * `nid`, from a cursor kept in the app config (the last `nid` done, or `done`
 * once the walk passed the end); every write of `source` writes the digest,
 * so a walk that reached the end is finished for good.
 *
 * It waits for `BackfillStreamPostFields`: a row with a digest is read
 * without the fallback that fills the five post columns out of `source`, so
 * those columns have to be right before a row gets one. A fresh install never
 * runs that step and needs nothing from this either, since every row it
 * writes carries its digest.
 */
class SourceDigestBackfill {
	public const BATCH = 500;
	public const CURSOR = 'source_digest_walk';
	private const DONE = 'done';

	public function __construct(
		private IDBConnection $connection,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Up to `$pages` pages of the walk.
	 *
	 * @return int how many rows were given a digest
	 */
	public function run(int $pages = 10): int {
		if ($this->appConfig->getValueString(Application::APP_ID, BackfillStreamPostFields::MARKER, '') !== '1') {
			return 0;
		}

		$digested = 0;
		for ($page = 0; $page < $pages; $page++) {
			$done = $this->page();
			if ($done === null) {
				break;
			}
			$digested += $done;
		}

		return $digested;
	}

	/**
	 * One page of the walk.
	 *
	 * @return ?int the rows given a digest, null when the walk is finished
	 */
	public function page(): ?int {
		$after = $this->appConfig->getValueString(Application::APP_ID, self::CURSOR, '0');
		if ($after === self::DONE) {
			return null;
		}

		$select = $this->connection->getQueryBuilder();
		$select->select('nid', 'source')
			->from(CoreRequestBuilder::TABLE_STREAM)
			->where($select->expr()->gt('nid', $select->createNamedParameter(Nid::fromStorage($after === '' ? '0' : $after))))
			->andWhere($select->expr()->isNull('source_digest'))
			->orderBy('nid', 'ASC')
			->setMaxResults(self::BATCH);
		$cursor = $select->executeQuery();
		$rows = $cursor->fetchAll();
		$cursor->closeCursor();

		$digested = 0;
		foreach ($rows as $row) {
			$after = (string)$row['nid'];
			try {
				$update = $this->connection->getQueryBuilder();
				$update->update(CoreRequestBuilder::TABLE_STREAM)
					->set('source_digest', $update->createNamedParameter(Stream::sourceDigestOf((string)($row['source'] ?? ''))))
					->where($update->expr()->eq('nid', $update->createNamedParameter(Nid::fromStorage($after))))
					// a write since the read brought its own digest, of a newer source
					->andWhere($update->expr()->isNull('source_digest'));
				$digested += $update->executeStatement();
			} catch (Throwable $e) {
				$this->logger->warning('cannot digest the source of a stored post', ['nid' => $after, 'exception' => $e]);
			}
		}

		$this->appConfig->setValueString(
			Application::APP_ID, self::CURSOR, (count($rows) < self::BATCH) ? self::DONE : $after
		);

		return ($rows === []) ? null : $digested;
	}
}
