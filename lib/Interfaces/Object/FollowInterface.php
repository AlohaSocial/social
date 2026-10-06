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

/**
 * Class FollowInterface
 *
 * @package OCA\Social\Interfaces\Object
 */
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
		// Record the acceptance here first. Delivering the Accept used to come
		// first, and everything below it — the accepted flag, the follower
		// count, the notification — was skipped when that delivery threw. A
		// peer that was briefly unreachable therefore left the row pending for
		// ever, with nothing to retry it, and for a follower on this very
		// instance the delivery is dropped as our own (see
		// ActivityService::isOurs()), so a local follow could never be
		// accepted at all. Whether the Accept reaches the other server is a
		// question for the delivery queue, not for whether this server
		// considers the follow accepted.
		try {
			$this->followsRequest->accepted($follow);

			// the followed account has one follower more; the three counters
			// are not recomputed for it — see `AccountService::bumpActorCount()`
			$this->accountService->bumpActorCount($follow->getObjectId(), 'count_followers', 1);

			$this->generateNotification($follow);
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
	 * Process an incoming Follow activity (remote user wants to follow a local user).
	 *
	 * Flow:
	 *  1. Verify the Follow actor's origin matches the request origin.
	 *  2. Check if we already have this follow in DB.
	 *  3a. If new: save it, accept it, send Accept activity back.
	 *  3b. If existing but not yet accepted: (re-)send Accept.
	 *      IMPORTANT: The embedded Follow's id differs from our local db id
	 *      (remote uses their own id), so match by actor+object pair.
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
			if ($knownFollow->isAccepted()) {
				// the follower asks again for a follow this side already
				// counts: its own record is pending, or gone. It hears the
				// Accept again, and nothing here changes.
				$this->sendAccept($follow);
			} else {
				$actor = $this->cacheActorService->getFromId($follow->getObjectId());
				if (!$this->isLockedLocalActor($actor)) {
					// a re-sent Follow of an unlocked account: (re-)send the Accept.
					// For a locked account the pending row simply stays pending.
					$this->confirmFollowRequest($follow);
				}
			}
		} catch (FollowNotFoundException $e) {
			$actor = $this->cacheActorService->getFromId($follow->getObjectId());

			if ($actor->isLocal()) {
				$follow->setFollowId($actor->getFollowers());
				$this->followsRequest->save($follow);
				if ($this->isLockedLocalActor($actor)) {
					// wait for the owner: no Accept, a follow_request notification instead
					$this->generateNotification($follow, true);
				} else {
					$this->confirmFollowRequest($follow);
				}
			}
		}
	}

	/**
	 * Handle activities wrapping a Follow (Accept, Reject, Undo).
	 *
	 * This is called when an Accept/Reject/Undo activity targeting a Follow arrives.
	 *
	 * For Accept(ourFollow): remote accepted our follow → mark accepted in DB.
	 *   origin check: the Accept comes from the followed actor's server, and
	 *   $item->getObjectId() is the followed actor → host must match origin.
	 *
	 * For Reject(ourFollow): remote rejected our follow → delete from DB.
	 *
	 * For Undo(theirFollow): remote unfollowed us → delete from DB.
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
			// read before the row goes: the count was moved up by the Accept,
			// so a follow request that was never accepted must not move it
			// down. The activity carries what the sender wrote, which is not
			// where this is recorded.
			$accepted = $this->wasAccepted($item);
			$this->followsRequest->delete($item);
			if ($accepted) {
				$this->accountService->bumpActorCount(
					$item->getObjectId(), 'count_followers', -1
				);
			}
		}

		if ($activity->getType() === Reject::TYPE) {
			$activity->checkOrigin($item->getObjectId());
			$this->followsRequest->delete($item);
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

	/** Whether the stored follow this activity is about had been accepted. */
	private function wasAccepted(Follow $follow): bool {
		try {
			return $this->followsRequest
				->getByPersons($follow->getActorId(), $follow->getObjectId())
				->isAccepted();
		} catch (Exception $e) {
			return false;
		}
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
