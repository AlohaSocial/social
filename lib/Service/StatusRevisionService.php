<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\StatusRevisionsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\StatusRevision;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What a status used to say. An edit records the version it replaces before
 * the new one, so the first row of any status is the text that was posted and
 * the history is a plain ordered select, with no diffs.
 */
class StatusRevisionService {
	public function __construct(
		private StatusRevisionsRequest $revisionsRequest,
		private CacheActorService $cacheActorService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Records an edit, called with the status as it was and as it now is. The
	 * replaced version is written only when nothing has been written for this
	 * status yet: then it is the original; later it is already the last row.
	 *
	 * A failure is logged and swallowed: the edit is already stored and
	 * federated, and a missing revision is the smaller loss.
	 */
	public function recordEdit(Stream $before, Stream $after): void {
		try {
			if (!$this->revisionsRequest->hasRevisions($after->getId())) {
				$this->revisionsRequest->save(StatusRevision::fromStream($before));
			}

			$this->revisionsRequest->save(StatusRevision::fromStream($after));
		} catch (Throwable $e) {
			$this->logger->warning('could not record the revision of an edited post', [
				'status' => $after->getId(),
				'exception' => $e,
			]);
		}
	}

	/**
	 * Every version of a status, oldest first, as Mastodon StatusEdit
	 * entities. A status with no rows answers with its one current version,
	 * as Mastodon never returns an empty list and clients read `history[0]`.
	 *
	 * @return StatusRevision[]
	 */
	public function history(Stream $stream): array {
		$revisions = $this->revisionsRequest->getByStreamId($stream->getId());
		if ($revisions === []) {
			$revisions = [StatusRevision::fromStream($stream)];
		}

		$account = $this->author($stream);
		foreach ($revisions as $revision) {
			$revision->setAccount($account);
		}

		return $revisions;
	}

	/**
	 * The status author, as they are now; null rather than a failure when the
	 * actor is not cached.
	 */
	private function author(Stream $stream): ?Person {
		try {
			return $this->cacheActorService->getFromId($stream->getAttributedTo());
		} catch (Throwable $e) {
			$this->logger->debug('no cached author for the history of a status', [
				'status' => $stream->getId(),
				'exception' => $e->getMessage(),
			]);

			return null;
		}
	}
}
