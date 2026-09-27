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
		// An actor speaks for itself and for nothing else. The origin check
		// above stops at the host, which on a shared server is every account
		// on it: without this, any of them could delete a neighbour's account
		// from here, or hand `updateActor()` a profile carrying its own public
		// key under the neighbour's id and sign as them from then on.
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
	 * Everything the account leaves behind, from the one list a suspension
	 * works from too — see `ActorCascadeService`, which exists because these
	 * were two lists and had drifted apart.
	 *
	 * A moderation decision is deliberately not in it: it is what keeps a
	 * suspended account suspended if it comes back.
	 */
	#[\Override]
	public function delete(ACore $item): void {
		if (!($item instanceof Person)) {
			return;
		}

		$this->actorCascadeService->purge($item->getId());
		$this->deleteStreamFromActor($item);
	}

	/**
	 * @param Person $actor
	 */
	private function deleteStreamFromActor(Person $actor): void {
		// first, we delete all post generate by actor
		$this->streamRequest->deleteByAuthor($actor->getId());

		if (!$this->detachRecipient($actor, self::DETACH_INLINE)) {
			// More posts address this account than one request may rewrite.
			// The rest is finished by a job, because the alternative is doing
			// it here: this runs inside the HTTP request a peer is waiting on
			// for its `Delete`, and a peer that times out re-sends it — so the
			// work would start again from the beginning, for ever, on exactly
			// the accounts that have too much of it.
			$this->jobList->add(ActorCleanup::class, ['actor' => $actor->getId()]);

			return;
		}

		$this->streamDestRequest->deleteRelatedToActor($actor->getId());
	}

	/**
	 * Rewrites the posts that address an account, in pages, and says whether it
	 * reached the end.
	 *
	 * Public so `Cron\ActorCleanup` can carry on where a request stopped: the
	 * two must do the same work, and two copies of this walk would be two
	 * chances to leave a post addressed to an account that no longer exists.
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
	 * Drops the rows that said a post was addressed to this account, once
	 * every post that carried it has been rewritten. Public for the same
	 * reason `detachRecipient()` is: the job has to be able to finish what a
	 * request started.
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
	 * The post and everything that hangs off it. StreamRequest::deleteById()
	 * takes either a uri or the prim a dest row holds, which is what this is
	 * given, and now removes the related rows itself — the name of this method
	 * was a promise its one-line body never kept.
	 */
	private function removeStreamAndRelated(string $idPrim): void {
		$this->streamRequest->deleteById($idPrim);
	}

	private function updateActor(Person $actor, ACore $activity) {
		$actor->setCreation($activity->getOriginCreationTime());

		try {
			$current = $this->cacheActorsRequest->getFromId($actor->getId());
			if ($current->getCreation() < $activity->getOriginCreationTime()) {
				$this->cacheActorsRequest->update($actor);
			}
		} catch (CacheActorDoesNotExistException $e) {
			$this->cacheActorsRequest->save($actor);
		}
	}
}
