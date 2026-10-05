<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Activity;

use Exception;
use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\ReactionsRequest;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\InstancePath;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use Psr\Log\LoggerInterface;

class MoveInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	public function __construct(
		private ActionsRequest $actionsRequest,
		private ReactionsRequest $reactionsRequest,
		private CacheActorsRequest $cacheActorsRequest,
		private CacheDocumentsRequest $cacheDocumentsRequest,
		private FollowsRequest $followsRequest,
		private StreamRequest $streamRequest,
		private StreamDestRequest $streamDestRequest,
		private CacheActorService $cacheActorService,
		private ActorsRequest $actorsRequest,
		private ActivityService $activityService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @throws InvalidOriginException
	 */
	#[\Override]
	public function processIncomingRequest(ACore $item): void {
		$item->checkOrigin($item->getId());
		$item->checkOrigin($item->getObjectId());
		$item->checkOrigin($item->getActorId());

		try {
			// cache only: an actor this instance has never seen has nothing to move
			$old = $this->cacheActorsRequest->getFromId($item->getActorId());
		} catch (CacheActorDoesNotExistException $e) {
			return;
		}

		// Refresh the target so the alsoKnownAs list is the one its server
		// publishes right now, not a stale cached copy.
		$new = $this->cacheActorService->getFromId($item->getTarget(), true);

		// The signature only proves the Move comes from the old account's
		// server. Without the back-reference, anyone could re-point another
		// actor's followers and posts at an account they control.
		if (!in_array($old->getId(), $new->getAlsoKnownAs(), true)) {
			throw new InvalidOriginException(
				'MoveInterface - target ' . $new->getId()
				. ' does not list ' . $old->getId() . ' in alsoKnownAs'
			);
		}

		$this->moveAccount($old, $new);

		// The redirect a profile here shows is the cached actor's `movedTo`.
		// Written from the Move, as Mastodon does, rather than fetched: the
		// old server may serve the pre-move document for minutes yet.
		$this->cacheActorsRequest->markMoved($old->getId(), $new->getId());
	}

	public function moveAccount(Person $actor, Person $target): void {
		// Collected before the rows are rewritten: afterwards they name the new
		// account and there is nothing left to say who had followed the old one.
		$followers = $this->localFollowsTowards($actor->getId());

		$this->actionsRequest->moveAccount($actor->getId(), $target->getId());
		$this->reactionsRequest->moveAccount($actor->getId(), $target->getId());
		$this->cacheDocumentsRequest->moveAccount($actor->getId(), $target->getId());

		$this->followsRequest->moveAccountFollowers($actor->getId(), $target);
		$this->followsRequest->moveAccountFollowing($actor->getId(), $target);

		$this->updateStreamFromActor($actor, $target);

		$this->refollow($followers, $target);
	}

	/**
	 * The accepted follows from a *local* actor towards the account that is
	 * moving.
	 *
	 * Only local ones: a remote follower's own instance receives the same Move
	 * and re-follows for itself.
	 *
	 * @return Follow[]
	 */
	private function localFollowsTowards(string $actorId): array {
		$local = [];
		foreach ($this->followsRequest->getFollowersByActorId($actorId) as $follow) {
			try {
				// only a local account has a row here
				$this->actorsRequest->getFromId($follow->getActorId());
			} catch (Exception $e) {
				continue;
			}

			$local[] = $follow;
		}

		return $local;
	}

	/**
	 * Follows the new account on behalf of everyone here who followed the old
	 * one.
	 *
	 * Rewriting the rows was only half of a migration: they then said the local
	 * user follows the new account while the new account's instance had never
	 * heard of them, so no posts arrived and a later unfollow sent
	 * `Undo{Follow}` for a follow that was never established. Mastodon
	 * re-issues the Follow on receiving a Move; so does this.
	 *
	 * The follow keeps the id the local row already has, so the Accept that
	 * comes back matches it and a later Undo names something the target knows.
	 *
	 * @param Follow[] $follows
	 */
	private function refollow(array $follows, Person $target): void {
		if ($target->getInbox() === '') {
			$this->logger->notice('cannot re-follow a moved account with no inbox', [
				'target' => $target->getId(),
			]);

			return;
		}

		foreach ($follows as $known) {
			$follow = new Follow();
			$follow->setId($known->getId());
			$follow->setActorId($known->getActorId());
			$follow->setObjectId($target->getId());
			$follow->setFollowId($target->getFollowers());
			$follow->addInstancePath(
				new InstancePath(
					$target->getInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_TOP
				)
			);

			try {
				$this->activityService->request($follow);
			} catch (Exception $e) {
				// one unreachable target must not stop the others
				$this->logger->warning('cannot re-follow a moved account', [
					'follower' => $known->getActorId(),
					'target' => $target->getId(),
					'exception' => $e,
				]);
			}
		}
	}

	/**
	 * @param Person $actor
	 */
	private function updateStreamFromActor(Person $actor, Person $new): void {
		// first, we delete all post generate by actor
		$this->streamRequest->updateAuthor($actor->getId(), $new->getId());

		// then we look for link to the actor as dest
		foreach ($this->streamDestRequest->getRelatedToActor($actor) as $streamDest) {
			if ($streamDest->getType() !== 'recipient') {
				continue;
			}

			try {
				$stream = $this->streamRequest->getStream($streamDest->getStreamId());
			} catch (StreamNotFoundException $e) {
				continue;
			}

			// upgrading to[] and cc[] based on old actorId and followId with new uri
			$changed = false;
			switch ($streamDest->getSubtype()) {
				case 'to':
					if ($stream->getTo() === $actor->getId()) {
						$stream->setTo($new->getId());
						$changed = true;
					}

					foreach (
						[
							$actor->getId() => $new->getId(),
							$actor->getFollowers() => $new->getFollowers(),
							$actor->getFollowing() => $new->getFollowing()
						] as $itemId => $newId
					) {
						$arr = $stream->getToArray();
						if (in_array($itemId, $arr, true)) {
							$stream->setToArray(array_unique(array_merge(array_diff($arr, [$itemId]), [$newId])));
							$changed = true;
						}
					}
					break;

				case 'cc':
					$changed = false;
					foreach (
						[
							$actor->getId() => $new->getId(),
							$actor->getFollowers() => $new->getFollowers(),
							$actor->getFollowing() => $new->getFollowing()
						] as $itemId => $newId
					) {
						$arr = $stream->getCcArray();
						if (in_array($itemId, $arr, true)) {
							$stream->setCcArray(array_unique(array_merge(array_diff($arr, [$itemId]), [$newId])));
							$changed = true;
						}
					}
					break;
			}

			if ($changed) {
				$this->streamRequest->update($stream);
			}
		}

		$this->streamDestRequest->moveActor($actor->getId(), $new->getId());
		$this->streamDestRequest->moveActor($actor->getFollowing(), $new->getFollowing());
		$this->streamDestRequest->moveActor($actor->getFollowers(), $new->getFollowers());
	}
}
