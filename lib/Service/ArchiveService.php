<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;

/**
 * Putting one of your own posts away, and getting it back.
 *
 * Archiving only takes a post out of the lists this server builds; nothing
 * federates and the audience is unchanged, so anyone with the link can still
 * open it. Taking it back from other servers is what `Delete` is for.
 */
class ArchiveService {
	/** What one page of somebody's archive holds. */
	public const PAGE = 50;

	public function __construct(
		private StreamRequest $streamRequest,
	) {
	}

	/**
	 * @throws ItemNotFoundException no such post of this account, including
	 *                               somebody else's and one not written here
	 */
	public function archive(Person $actor, int|string $nid): void {
		if (!$this->streamRequest->setArchived($nid, $actor->getId(), true)) {
			throw new ItemNotFoundException('no post of yours with that id');
		}
	}

	/** @throws ItemNotFoundException */
	public function restore(Person $actor, int|string $nid): void {
		if (!$this->streamRequest->setArchived($nid, $actor->getId(), false)) {
			throw new ItemNotFoundException('no post of yours with that id');
		}
	}

	/**
	 * The account's own archive, newest first.
	 *
	 * @return Stream[]
	 */
	public function forActor(Person $actor, int $limit = self::PAGE, int|string $maxId = '0'): array {
		return $this->streamRequest->getArchivedByActor(
			$actor->getId(), max(1, min($limit, self::PAGE)), $maxId
		);
	}

	public function countFor(Person $actor): int {
		return $this->streamRequest->countArchivedByActor($actor->getId());
	}
}
