<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Object;

use Exception;
use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Interfaces\Activity\AbstractActivityPubInterface;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Object\Dislike;
use OCA\Social\Model\Details;

/**
 * `Dislike`: the other half of PeerTube's two counters.
 *
 * Mastodon has never had one, so this app had no model for it and every
 * dislike that arrived was logged as an unknown type and dropped — a video
 * whose author cared about the number showed none of them.
 *
 * Stored the way a `Like` is and counted onto the post, and that is all it
 * does. **No notification**: a dislike arriving as one would be a way to
 * needle somebody from anywhere, one activity at a time.
 */
class DislikeInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	public function __construct(
		private ActionsRequest $actionsRequest,
		private StreamRequest $streamRequest,
	) {
	}

	#[\Override]
	public function processIncomingRequest(ACore $item): void {
		$item->checkOrigin($item->getId());
		$item->checkOrigin($item->getActorId());

		try {
			$this->save($item);
		} catch (ItemAlreadyExistsException $e) {
		}
	}

	#[\Override]
	public function activity(ACore $activity, ACore $item): void {
		if ($activity->getType() !== Undo::TYPE) {
			return;
		}

		$activity->checkOrigin($item->getId());
		$activity->checkOrigin($item->getActorId());

		$this->delete($item);
	}

	/**
	 * @throws ItemNotFoundException
	 */
	#[\Override]
	public function getItem(ACore $item): ACore {
		try {
			return $this->actionsRequest->getAction(
				$item->getActorId(), $item->getObjectId(), Dislike::TYPE
			);
		} catch (ActionDoesNotExistException $e) {
		}

		throw new ItemNotFoundException();
	}

	/**
	 * @throws ItemAlreadyExistsException
	 */
	#[\Override]
	public function save(ACore $item): void {
		try {
			$this->actionsRequest->getActionFromItem($item);
			throw new ItemAlreadyExistsException();
		} catch (ActionDoesNotExistException $e) {
		}

		$this->actionsRequest->save($item);
		$this->recount($item->getObjectId());
	}

	#[\Override]
	public function delete(ACore $item): void {
		$this->actionsRequest->delete($item);
		$this->recount($item->getObjectId());
	}

	/** What the post now says, counted rather than incremented. */
	private function recount(string $objectId): void {
		try {
			$post = $this->streamRequest->getStreamById($objectId, false, ACore::FORMAT_LOCAL);
			$this->streamRequest->recount($post, Details::DISLIKES);
		} catch (Exception $e) {
			// a dislike of a post this instance does not hold: the row is
			// stored and there is nothing here to count it onto
		}
	}
}
