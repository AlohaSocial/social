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
 * The versions a status has been through.
 *
 * Append-only by design: a revision is never updated and never rewritten, so
 * the history of a status is a fact about what happened to it rather than a
 * summary somebody's later edit could change. The only write that removes rows
 * is the one that removes the status itself.
 */
class StatusRevisionsRequest extends StatusRevisionsRequestBuilder {
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
	 * Every version of one status, oldest first — which is the order Mastodon
	 * returns a history in, and the order the index is built for.
	 *
	 * Ordered by `id` and not by `published`: two edits made inside the same
	 * second carry the same stamp, and the order they happened in is the one
	 * thing a revision history may not get wrong.
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
			// the row holds the prim, which is not an id anything can be
			// looked up by; the caller asked about a status, so it is handed
			// back the status it asked about
			$revision->setStreamId($streamId);
			$revisions[] = $revision;
		}
		$cursor->closeCursor();

		return $revisions;
	}

	/**
	 * Whether anything at all has been recorded for a status.
	 *
	 * This is what decides, on an edit, whether the version being replaced is
	 * the original and has to be written too — see StatusRevisionService.
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
	 * The versions of a status that no longer exists.
	 *
	 * A revision outliving its status would be unreachable — every read here
	 * starts from a status that was resolved first — and would be handed to
	 * whatever next occupied the same id.
	 */
	public function deleteByStreamId(string $streamId): void {
		$qb = $this->getStatusRevisionsDeleteSql();
		$qb->where(
			$qb->expr()->eq('stream_id_prim', $qb->createNamedParameter($qb->prim($streamId)))
		);

		$qb->executeStatement();
	}
}
