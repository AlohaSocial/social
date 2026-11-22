<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Activity;

use Exception;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
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
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

class MoveInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	public function __construct(
		private CacheActorsRequest $cacheActorsRequest,
		private FollowsRequest $followsRequest,
		private StreamRequest $streamRequest,
		private StreamDestRequest $streamDestRequest,
		private CacheActorService $cacheActorService,
		private ActorsRequest $actorsRequest,
		private ActivityService $activityService,
		private IDBConnection $connection,
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
		// actor's followers at an account they control.
		if (!in_array($old->getId(), $new->getAlsoKnownAs(), true)) {
			throw new InvalidOriginException(
				'MoveInterface - target ' . $new->getId()
				. ' does not list ' . $old->getId() . ' in alsoKnownAs'
			);
		}

		$this->moveAccount($old, $new);
	}

	/**
	 * Carries an account's relationships over to the account it moved to: who
	 * follows it, whom it follows, and the posts addressed to it or to its
	 * collections. What the old account did (posts, likes, boosts, reactions,
	 * documents) stays its own, as on Mastodon. One transaction, so a failed
	 * Move leaves nothing half done for the sender's retry.
	 */
	public function moveAccount(Person $actor, Person $target): void {
		// Collected before the rows are rewritten: afterwards they name the new
		// account and there is nothing left to say who had followed the old one.
		$followers = $this->localFollowsTowards($actor->getId(), $target->getId());

		$this->connection->beginTransaction();
		try {
			$this->followsRequest->moveAccountFollowers($actor->getId(), $target);
			$this->followsRequest->moveAccountFollowing($actor->getId(), $target);

			$this->updateStreamFromActor($actor, $target);

			// The redirect a profile here shows is the cached actor's `movedTo`,
			// written from the Move as Mastodon does: the old server may serve the
			// pre-move document for minutes yet.
			$this->cacheActorsRequest->markMoved($actor->getId(), $target->getId());

			$this->connection->commit();
		} catch (Throwable $t) {
			$this->connection->rollBack();

			throw $t;
		}

		$this->refollow($followers, $target);
	}

	/**
	 * The accepted follows from a local actor towards the moving account, of
	 * actors that have not asked to follow the new one yet. A remote
	 * follower's own instance receives the same Move and re-follows itself.
	 *
	 * @return Follow[]
	 */
	private function localFollowsTowards(string $actorId, string $targetId): array {
		$local = [];
		foreach ($this->followsRequest->getFollowersByActorId($actorId) as $follow) {
			try {
				// only a local account has a row here
				$this->actorsRequest->getFromId($follow->getActorId());
			} catch (Exception $e) {
				continue;
			}

			$local[$follow->getActorId()] = $follow;
		}

		$asked = $this->followsRequest->getBetweenMany($targetId, array_keys($local))['followedBy'];

		return array_values(array_diff_key($local, $asked));
	}

	/**
	 * Follows the new account on behalf of everyone here who followed the old
	 * one, as Mastodon does on receiving a Move, so the new account's instance
	 * knows of the follow. The follow keeps the id of the local row, so the
	 * Accept that comes back matches it and a later Undo names a known follow.
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
			$follow->setFollowId($target->getOwnFollowers());
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
	 * Readdresses the posts that name the old account or its collections.
	 */
	private function updateStreamFromActor(Person $actor, Person $new): void {
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
