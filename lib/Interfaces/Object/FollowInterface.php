<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Object;

use Exception;
use OCA\Social\AP;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Interfaces\Activity\AbstractActivityPubInterface;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Interfaces\Internal\SocialAppNotificationInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Accept;
use OCA\Social\Model\ActivityPub\Activity\Reject;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\Details;
use OCA\Social\Model\InstancePath;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\TimelineRevisionService;
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;

class FollowInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	public function __construct(
		private FollowsRequest $followsRequest,
		private ActorRelationRequest $actorRelationRequest,
		private ActorsRequest $actorsRequest,
		private CacheActorService $cacheActorService,
		private AccountService $accountService,
		private ActivityService $activityService,
		private TimelineRevisionService $timelineRevisionService,
		private MiscService $miscService,
	) {
	}

	/**
	 * Whether a follow towards this (local) actor needs manual approval. The
	 * flag lives on the actor row, the source of truth for local accounts.
	 */
	private function isLockedLocalActor(Person $actor): bool {
		if (!$actor->isLocal()) {
			return false;
		}

		try {
			return $this->actorsRequest->getFromUsername($actor->getPreferredUsername())->isLocked();
		} catch (Exception $e) {
			return false;
		}
	}

	/**
	 * Refuse a follow request: federate a Reject and make sure no follow row stays.
	 */
	public function rejectFollowRequest(Follow $follow): void {
		try {
			$remoteActor = $this->cacheActorService->getFromId($follow->getActorId());

			/** @var Reject $reject */
			$reject = AP::instance()->getItemFromType(Reject::TYPE);
			// hung off the local actor, not the cloud root: see
			// ACore::generateUniqueIdFromActor()
			$reject->generateUniqueIdFromActor($follow->getObjectId(), 'reject/follows');
			$reject->setActorId($follow->getObjectId());
			$reject->setObject($follow);

			$reject->addInstancePath(
				new InstancePath(
					$remoteActor->getInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_TOP
				)
			);

			$this->activityService->request($reject);
			$this->followsRequest->deleteByPersons($follow);
		} catch (Exception $e) {
			$this->miscService->log(
				'exception while rejectFollowRequest: ' . get_class($e) . ' - ' . $e->getMessage(),
				2
			);
		}
	}

	public function confirmFollowRequest(Follow $follow): void {
		// Record the acceptance before delivering the Accept: a failed delivery
		// must not leave the row pending for ever, and for a local follower the
		// delivery is dropped as our own (ActivityService::isOurs()). Delivery is
		// the queue's business.
		try {
			// Only the copy that marks the row counts and notifies, so an overlapping
			// redelivery does not count twice.
			if ($this->followsRequest->accepted($follow)) {
				// the followed account has one follower more; the three counters
				// are not recomputed for it — see `AccountService::bumpActorCount()`
				$this->accountService->bumpActorCount($follow->getObjectId(), 'count_followers', 1);

				$this->generateNotification($follow);
			}
		} catch (Exception $e) {
			$this->miscService->log(
				'exception while accepting a follow: ' . get_class($e) . ' - ' . $e->getMessage(),
				2
			);

			return;
		}

		$this->sendAccept($follow);
	}

	/**
	 * Delivers an `Accept` of `$follow` to the follower's inbox. A failure is
	 * logged and left to the delivery queue.
	 */
	private function sendAccept(Follow $follow): void {
		try {
			$remoteActor = $this->cacheActorService->getFromId($follow->getActorId());
			if ($remoteActor->isLocal()) {
				// both sides are on this instance: there is nobody to tell
				return;
			}

			$accept = AP::instance()->getItemFromType(Accept::TYPE);
			$accept->generateUniqueIdFromActor($follow->getObjectId(), 'accept/follows');
			$accept->setActorId($follow->getObjectId());
			$accept->setObject($follow);

			$accept->addInstancePath(
				new InstancePath(
					$remoteActor->getInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_TOP
				)
			);

			$this->activityService->request($accept);
		} catch (Exception $e) {
			$this->miscService->log(
				'exception while sending an Accept: ' . get_class($e) . ' - ' . $e->getMessage(),
				2
			);
		}
	}

	/**
	 * An incoming Follow of a local account: stored and accepted, or left
	 * pending for a locked account. A known follow is matched by actor and
	 * object, since the remote id differs from ours, and an unlocked account
	 * sends the Accept again.
	 *
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws SocialAppConfigException
	 * @throws ItemUnknownException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws RequestResultNotJsonException
	 * @throws Exception
	 */
	#[\Override]
	public function processIncomingRequest(ACore $item): void {
		/** @var Follow $follow */
		$follow = $item;
		$follow->checkOrigin($follow->getActorId());

		// A follow from an actor the target has blocked is refused outright, so the
		// block cannot be re-established as a follow relationship.
		try {
			$target = $this->cacheActorService->getFromId($follow->getObjectId());
			if ($target->isLocal()
				&& $this->actorRelationRequest->exists(
					$target->getId(), $follow->getActorId(), ActorRelation::TYPE_BLOCK
				)) {
				$this->rejectFollowRequest($follow);

				return;
			}
		} catch (Exception $e) {
		}

		try {
			$knownFollow = $this->followsRequest->getByPersons($follow->getActorId(), $follow->getObjectId());
		} catch (FollowNotFoundException $e) {
			$actor = $this->cacheActorService->getFromId($follow->getObjectId());
			if (!$actor->isLocal()) {
				return;
			}

			$follow->setFollowId($actor->getFollowers());
			if ($this->followsRequest->save($follow)) {
				if ($this->isLockedLocalActor($actor)) {
					// wait for the owner: no Accept, a follow_request notification instead
					$this->generateNotification($follow, true);
				} else {
					$this->confirmFollowRequest($follow);
				}

				return;
			}

			// a redelivery overlapping the first copy: the row went in
			// between the lookup and the write, so this is a known follow
			$knownFollow = $this->followsRequest->getByPersons($follow->getActorId(), $follow->getObjectId());
		}

		if ($knownFollow->isAccepted()) {
			// the follower asks again for a follow this side already counts: it hears
			// the Accept again, and nothing here changes
			$this->sendAccept($follow);
		} else {
			$actor = $this->cacheActorService->getFromId($follow->getObjectId());
			if (!$this->isLockedLocalActor($actor)) {
				// a re-sent Follow of an unlocked account: (re-)send the Accept.
				// For a locked account the pending row simply stays pending.
				$this->confirmFollowRequest($follow);
			}
		}
	}

	/**
	 * Activities wrapping a Follow: Accept and Reject of our follow, from the
	 * followed actor's server (its host must match the origin), mark it accepted
	 * or delete it; Undo of theirs deletes it. Counts move back as the stored
	 * row says.
	 *
	 * @param ACore $activity The wrapping activity (Accept/Reject/Undo)
	 * @param ACore $item The Follow object inside the activity
	 *
	 * @throws InvalidOriginException
	 */
	#[\Override]
	public function activity(Acore $activity, ACore $item): void {
		/** @var Follow $item */
		if ($activity->getType() === Undo::TYPE) {
			$activity->checkOrigin($item->getId());
			$activity->checkOrigin($item->getActorId());
			// The stored row, not what the sender wrote, decides what is removed and
			// whether the count moves: only an accepted row that is removed moves it
			// down.
			$stored = $this->storedFollow($item, true);
			if ($stored !== null) {
				$this->followsRequest->delete($stored);
				if ($stored->isAccepted()) {
					$this->accountService->bumpActorCount(
						$stored->getObjectId(), 'count_followers', -1
					);
				}
			}
		}

		if ($activity->getType() === Reject::TYPE) {
			$activity->checkOrigin($item->getObjectId());
			// As for an Undo, the stored row decides what moves back. The origin
			// check vouches for the followed account the Reject names, so the stored
			// follow has to be of that account.
			$stored = $this->storedFollow($item);
			if ($stored !== null) {
				$this->followsRequest->delete($stored);
				$this->accountService->bumpActorCount(
					$stored->getActorId(), 'count_following', -1
				);
				if ($stored->isAccepted()) {
					$this->accountService->bumpActorCount(
						$stored->getObjectId(), 'count_followers', -1
					);
				}
			}
			$this->timelineRevisionService->bumpForActor($item->getActorId());
		}

		if ($activity->getType() === Accept::TYPE) {
			$activity->checkOrigin($item->getObjectId());
			$this->followsRequest->accepted($item);
			// the follower's home timeline holds different posts from this
			// moment, and no id it is keyed on says so — see
			// TimelineRevisionService
			$this->timelineRevisionService->bumpForActor($item->getActorId());
		}
	}

	/**
	 * The stored follow an Undo or a Reject names, when it is between the
	 * accounts the activity names. Misskey's composite Follow IDs may resolve
	 * the stored row by its actor and object.
	 *
	 * @param bool $allowCompositeId whether a composite Follow ID may resolve
	 *                               the stored row by its actor and object
	 */
	private function storedFollow(Follow $follow, bool $allowCompositeId = false): ?Follow {
		try {
			$stored = $this->followsRequest->getById($follow->getId());
		} catch (Exception $e) {
			if (!$allowCompositeId || !$this->hasCompositeFollowId($follow->getId())) {
				return null;
			}

			try {
				$stored = $this->followsRequest->getByPersons($follow->getActorId(), $follow->getObjectId());
			} catch (Exception $e) {
				return null;
			}
		}

		if ($stored->getActorId() !== $follow->getActorId()
			|| $stored->getObjectId() !== $follow->getObjectId()) {
			return null;
		}

		return $stored;
	}

	private function hasCompositeFollowId(string $id): bool {
		$path = parse_url($id, PHP_URL_PATH);

		return is_string($path)
			&& preg_match('~/follows/[^/]+/[^/]+$~', $path) === 1;
	}

	/**
	 * @throws SocialAppConfigException|ItemAlreadyExistsException|ItemUnknownException
	 */
	private function generateNotification(Follow $follow, bool $pending = false): void {
		/** @var SocialAppNotificationInterface $notificationInterface */
		$notificationInterface = AP::instance()->getInterfaceFromType(SocialAppNotification::TYPE);

		try {
			$follower = $this->cacheActorService->getFromId($follow->getActorId());
		} catch (Exception $e) {
			return;
		}

		/** @var SocialAppNotification $notification */
		$notification = AP::instance()->getItemFromType(SocialAppNotification::TYPE);
		$notification->setDetail(Details::URL, $follower->getId());
		$notification->setDetail(Details::ACCOUNT, $follower->getAccount());
		$notification->setDetailItem(Details::ACTOR, $follower);
		$notification->setAttributedTo($follow->getActorId())
			->setId($follow->getId() . '/notification')
			->setSubType($pending ? Follow::TYPE_REQUEST : Follow::TYPE)
			->setActorId($follower->getId())
			->setSummary($pending ? '{account} wants to follow you' : '{account} is following you')
			->setTo($follow->getObjectId())
			->setLocal(true);

		$notificationInterface->save($notification);
	}
}
