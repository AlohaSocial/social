<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Actor;

use OCA\Social\Cron\ActorCleanup;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\Activity\AbstractActivityPubInterface;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\StreamDest;
use OCA\Social\Service\ActorCascadeService;
use OCA\Social\Service\ActorService;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\BackgroundJob\IJobList;

/**
 * Class PersonService
 *
 * @package OCA\Social\Service\ActivityPub
 */
class PersonInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	use TArrayTools;

	/**
	 * How many posts one inbox request may rewrite before the rest becomes a
	 * job. Enough that an ordinary account is finished inline; small enough
	 * that the peer waiting on the `Delete` gets its answer.
	 */
	private const DETACH_INLINE = 500;

	/** rows per query while walking them */
	private const DETACH_PAGE = 100;

	public function __construct(
		private CacheActorsRequest $cacheActorsRequest,
		private StreamRequest $streamRequest,
		private StreamDestRequest $streamDestRequest,
		private ActorService $actorService,
		private ActorCascadeService $actorCascadeService,
		private IJobList $jobList,
	) {
	}

	/**
	 * @throws ItemNotFoundException
	 */
	#[\Override]
	public function getItem(ACore $item): ACore {
		throw new ItemNotFoundException();
	}

	/**
	 * @throws ItemNotFoundException
	 */
	#[\Override]
	public function getItemById(string $id): ACore {
		try {
			$actor = $this->cacheActorsRequest->getFromId($id);

			return $actor;
		} catch (CacheActorDoesNotExistException $e) {
			throw new ItemNotFoundException();
		}
	}

	/**
	 * @throws InvalidOriginException
	 */
	#[\Override]
	public function activity(Acore $activity, ACore $item): void {
		/** @var Person $item */
		$activity->checkOrigin($item->getId());
		// An actor speaks only for itself. The origin check stops at the host,
		// so without this any account on a shared server could delete a
		// neighbour's account or replace its public key.
		$activity->checkActor($item->getId(), $activity->getActorId());

		switch ($activity->getType()) {
			case Update::TYPE:
				$this->updateActor($item, $activity);
				break;

			case Delete::TYPE:
				$this->delete($item);
				break;
		}
	}

	#[\Override]
	public function save(ACore $item): void {
		/** @var Person $person */
		$person = $item;
		try {
			$this->getItemById($person->getId());
			$this->actorService->update($person);
		} catch (ItemNotFoundException $e) {
			$this->actorService->save($person);
		}
	}

	/**
	 * Everything the account leaves behind, from the list a suspension also
	 * uses (`ActorCascadeService`). The moderation decision is kept, so a
	 * suspended account stays suspended if it comes back.
	 */
	#[\Override]
	public function delete(ACore $item): void {
		if (!($item instanceof Person)) {
			return;
		}

		$this->actorCascadeService->purge($item->getId(), false, $item->getUserId());
		$this->deleteStreamFromActor($item);
	}

	/**
	 * @param Person $actor
	 */
	private function deleteStreamFromActor(Person $actor): void {
		// first, we delete all post generate by actor
		$this->streamRequest->deleteByAuthor($actor->getId());

		if (!$this->detachRecipient($actor, self::DETACH_INLINE)) {
			// The rest is left to a job: this runs inside the request a peer
			// waits on for its `Delete`, and a timed-out peer re-sends it, which
			// would restart the work for ever.
			$this->jobList->add(ActorCleanup::class, ['actor' => $actor->getId()]);

			return;
		}

		$this->streamDestRequest->deleteRelatedToActor($actor->getId());
	}

	/**
	 * Rewrites the posts that address an account, in pages, and says whether
	 * it reached the end. Public so `Cron\ActorCleanup` can finish what a
	 * request started.
	 *
	 * @return bool true when nothing is left to detach
	 */
	public function detachRecipient(Person $actor, int $maxRows): bool {
		$afterId = 0;
		$seen = 0;

		while ($seen < $maxRows) {
			$page = $this->streamDestRequest->getRelatedToActor(
				$actor, min(self::DETACH_PAGE, $maxRows - $seen), $afterId
			);
			if ($page === []) {
				return true;
			}

			foreach ($page as $streamDest) {
				$afterId = $streamDest->getId();
				$seen++;
				$this->detachOne($actor, $streamDest);
			}

			if (count($page) < self::DETACH_PAGE) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Drops the rows that addressed a post to this account, once every such
	 * post has been rewritten. Public for `Cron\ActorCleanup`, as
	 * `detachRecipient()` is.
	 */
	public function forgetRecipient(string $actorId): void {
		$this->streamDestRequest->deleteRelatedToActor($actorId);
	}

	private function detachOne(Person $actor, StreamDest $streamDest): void {
		if ($streamDest->getType() !== 'recipient') {
			return;
		}

		try {
			$stream = $this->streamRequest->getStream($streamDest->getStreamId());
		} catch (StreamNotFoundException $e) {
			return;
		}

		// upgrading to[] and cc[] without the deleted actor and follow uri
		switch ($streamDest->getSubtype()) {
			case 'to':
				if ($stream->getTo() === $actor->getId()) {
					// the post was addressed to this account alone: it is
					// gone, and there is nothing left to rewrite on it
					$this->removeStreamAndRelated($streamDest->getStreamId());

					return;
				}

				$arr = array_diff(
					$stream->getToArray(),
					[$actor->getId(), $actor->getFollowers(), $actor->getFollowing()]
				);
				if (!empty(array_diff($stream->getToArray(), $arr))) {
					$stream->setToArray($arr);
					$this->streamRequest->update($stream);
				}
				break;
			case 'cc':
				$arr = array_diff(
					$stream->getCcArray(),
					[$actor->getId(), $actor->getFollowers(), $actor->getFollowing()]
				);
				if (!empty(array_diff($stream->getCcArray(), $arr))) {
					$stream->setCcArray($arr);
					$this->streamRequest->update($stream);
				}
				break;
		}
	}

	/**
	 * The post and everything that hangs off it; StreamRequest::deleteById()
	 * takes the prim a dest row holds and removes the related rows itself.
	 */
	private function removeStreamAndRelated(string $idPrim): void {
		$this->streamRequest->deleteById($idPrim);
	}

	private function updateActor(Person $actor, ACore $activity) {
		$actor->setCreation($activity->getOriginCreationTime());

		// through ActorService, which stores the avatar the profile names
		// before the profile points at it
		try {
			$current = $this->cacheActorsRequest->getFromId($actor->getId());
			if ($current->getCreation() < $activity->getOriginCreationTime()) {
				$this->actorService->update($actor);
			}
		} catch (CacheActorDoesNotExistException $e) {
			$this->actorService->save($actor);
		}
	}
}
