<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Model\Client\StatusRevision;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The versions a status has been through. Append-only: revisions are never
 * updated, and only removing the status removes them.
 */
class StatusRevisionsRequest extends StatusRevisionsRequestBuilder {
	/** Highest revision id, used to start a streaming subscriber after existing edits. */
	public function newestId(): int {
		$qb = $this->getStatusRevisionsMaxIdSql();
		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($data['max_id'] ?? 0);
	}

	/**
	 * The edited statuses after a subscriber's cursor, oldest first.
	 *
	 * @return list<array{id: int, stream_id_prim: string}>
	 */
	public function getAfterId(int $afterId, int $limit): array {
		$qb = $this->getStatusRevisionsSelectCursorSql();
		$qb->andWhere($qb->expr()->gt('sr.id', $qb->createNamedParameter($afterId)));
		$qb->orderBy('sr.id', 'asc');
		$qb->setMaxResults($limit);

		$rows = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$rows[] = [
				'id' => (int)$data['id'],
				'stream_id_prim' => (string)$data['stream_id_prim'],
			];
		}
		$cursor->closeCursor();

		return $rows;
	}

	public function save(StatusRevision $revision): void {
		$qb = $this->getStatusRevisionsInsertSql();
		$qb->setValue('stream_id_prim', $qb->createNamedParameter($qb->prim($revision->getStreamId())))
			->setValue('content', $qb->createNamedParameter($revision->getContent()))
			->setValue('spoiler_text', $qb->createNamedParameter($revision->getSpoilerText()))
			->setValue('sensitive', $qb->createNamedParameter($revision->isSensitive() ? 1 : 0))
			->setValue('published', $qb->createNamedParameter($revision->getPublished()))
			->setValue('media', $qb->createNamedParameter($revision->getMediaAttachmentsAsJson()))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		$qb->executeStatement();
		$revision->setId($qb->getLastInsertId());
	}

	/**
	 * Every version of one status, oldest first, as Mastodon returns a history.
	 * Ordered by `id`, not `published`, as two edits in one second share a stamp.
	 *
	 * @return StatusRevision[]
	 */
	public function getByStreamId(string $streamId): array {
		$qb = $this->getStatusRevisionsSelectSql();
		$qb->andWhere(
			$qb->expr()->eq('sr.stream_id_prim', $qb->createNamedParameter($qb->prim($streamId)))
		);
		$qb->orderBy('sr.id', 'asc');

		$revisions = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$revision = $this->parseStatusRevisionsSelectSql($data);
			// The row holds the prim; hand back the id the caller asked about.
			$revision->setStreamId($streamId);
			$revisions[] = $revision;
		}
		$cursor->closeCursor();

		return $revisions;
	}

	/**
	 * Whether anything has been recorded for a status, which decides whether an
	 * edit must also write the original (see StatusRevisionService).
	 */
	public function hasRevisions(string $streamId): bool {
		$qb = $this->getStatusRevisionsSelectSql();
		$qb->andWhere(
			$qb->expr()->eq('sr.stream_id_prim', $qb->createNamedParameter($qb->prim($streamId)))
		);
		$qb->setMaxResults(1);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $data !== false;
	}

	/**
	 * The versions of a status that no longer exists, which would otherwise be
	 * unreachable.
	 */
	public function deleteByStreamId(string $streamId): void {
		$qb = $this->getStatusRevisionsDeleteSql();
		$qb->where(
			$qb->expr()->eq('stream_id_prim', $qb->createNamedParameter($qb->prim($streamId)))
		);

		$qb->executeStatement();
	}
}
