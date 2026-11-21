<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Activity;

use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActorRelation;
use Psr\Log\LoggerInterface;

/**
 * A remote actor blocked (or un-blocked) one of the local users. The block
 * is kept as a `blocked_by` relation, which feeds the relationship flags and
 * hides the blocker from the user's timelines, and follows are severed both
 * ways, as Mastodon does.
 */
class BlockInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	public function __construct(
		private ActorRelationRequest $actorRelationRequest,
		private FollowsRequest $followsRequest,
		private CacheActorsRequest $cacheActorsRequest,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function processIncomingRequest(ACore $item): void {
		$item->checkOrigin($item->getActorId());

		$local = $this->getLocalTarget($item);
		if ($local === null) {
			return;
		}

		$this->actorRelationRequest->save(
			$local, $item->getActorId(), ActorRelation::TYPE_BLOCKED_BY, true, $item->getId()
		);

		foreach ([[$local, $item->getActorId()], [$item->getActorId(), $local]] as $pair) {
			try {
				$follow = $this->followsRequest->getByPersons($pair[0], $pair[1]);
				$this->followsRequest->delete($follow);
			} catch (FollowNotFoundException $e) {
			}
		}
	}

	/**
	 * Undo{Block}: the remote actor lifted the block.
	 */
	#[\Override]
	public function activity(ACore $activity, ACore $item): void {
		if ($activity->getType() !== Undo::TYPE) {
			return;
		}

		$activity->checkOrigin($item->getActorId());

		$local = $this->getLocalTarget($item);
		if ($local === null) {
			return;
		}

		$this->actorRelationRequest->delete(
			$local, $item->getActorId(), ActorRelation::TYPE_BLOCKED_BY
		);
	}

	/**
	 * Returns the id of the local actor the Block targets, null when the target is
	 * unknown or not local (nothing to enforce here then).
	 */
	private function getLocalTarget(ACore $block): ?string {
		$objectId = $block->getObjectId();
		if ($objectId === '' && $block->hasObject()) {
			$objectId = $block->getObject()->getId();
		}
		if ($objectId === '') {
			return null;
		}

		try {
			// DB-only lookup: a local target is always cached, and an unknown target
			// must not trigger a remote fetch on an attacker-supplied URI.
			$target = $this->cacheActorsRequest->getFromId($objectId);
		} catch (CacheActorDoesNotExistException $e) {
			return null;
		}

		if (!$target->isLocal()) {
			$this->logger->debug('BlockInterface - target is not local', ['objectId' => $objectId]);

			return null;
		}

		return $target->getId();
	}
}
