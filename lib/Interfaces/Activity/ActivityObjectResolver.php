<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Activity;

use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\ReactionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;

/**
 * The object of a wrapping activity, when it arrived as a bare URI (which
 * many implementations send, where Mastodon embeds).
 *
 * The URI is looked up only in what this instance stores, in the order the
 * wrapping activities need: a follow, a post, an action (Like, pin), an emoji
 * reaction, a block, then an actor. Nothing is fetched: something unknown
 * here has nothing to undo or accept.
 */
class ActivityObjectResolver {
	public function __construct(
		private StreamRequest $streamRequest,
		private FollowsRequest $followsRequest,
		private ActionsRequest $actionsRequest,
		private CacheActorsRequest $cacheActorsRequest,
		private ReactionsRequest $reactionsRequest,
		private ActorRelationRequest $actorRelationRequest,
	) {
	}

	/**
	 * What the activity is about: the stored row when there is one, the embedded
	 * copy only when not. The sender writes the embedded copy and could pair one
	 * account's object id with another `actor`; the stored row says whose it is.
	 *
	 * @throws ItemNotFoundException when there is neither
	 */
	public function resolve(ACore $activity): ACore {
		$embedded = $activity->hasObject() ? $activity->getObject() : null;
		$objectId = ($embedded !== null) ? $embedded->getId() : $activity->getObjectId();

		if ($objectId !== '') {
			try {
				$item = $this->fromStore($objectId);
				$item->setParent($activity);

				return $item;
			} catch (ItemNotFoundException $e) {
			}
		}

		if ($embedded !== null) {
			return $embedded;
		}

		throw new ItemNotFoundException(
			($objectId === '') ? 'activity carries no object' : 'unknown object: ' . $objectId
		);
	}

	/**
	 * @throws ItemNotFoundException
	 */
	private function fromStore(string $objectId): ACore {
		try {
			return $this->followsRequest->getById($objectId);
		} catch (FollowNotFoundException $e) {
		}

		try {
			return $this->streamRequest->getStreamById($objectId);
		} catch (StreamNotFoundException $e) {
		}

		try {
			return $this->actionsRequest->getById($objectId);
		} catch (ActionDoesNotExistException $e) {
		}

		try {
			return $this->reactionsRequest->getById($objectId);
		} catch (ActionDoesNotExistException $e) {
		}

		$block = $this->actorRelationRequest->getBlockByActivityId($objectId);
		if ($block !== null) {
			return $block;
		}

		try {
			return $this->cacheActorsRequest->getFromId($objectId);
		} catch (CacheActorDoesNotExistException $e) {
		}

		throw new ItemNotFoundException('unknown object: ' . $objectId);
	}
}
