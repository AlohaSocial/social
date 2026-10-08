<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Object;

use Exception;
use OCA\Social\AP;
use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Interfaces\Activity\AbstractActivityPubInterface;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Interfaces\Internal\SocialAppNotificationInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Details;
use OCA\Social\Model\StreamQueue;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\NotificationService;
use OCA\Social\Service\StreamQueueService;
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Class AnnounceInterface
 *
 * @package OCA\Social\Interfaces\Object
 */
class AnnounceInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	use TArrayTools;

	public function __construct(
		private StreamRequest $streamRequest,
		private ActionsRequest $actionsRequest,
		private StreamQueueService $streamQueueService,
		private CacheActorService $cacheActorService,
		private MiscService $miscService,
		private NotificationService $notificationService,
	) {
	}

	/**
	 * @throws InvalidOriginException
	 * @throws Exception
	 */
	#[\Override]
	public function processIncomingRequest(ACore $item): void {
		$item->checkOrigin($item->getId());
		$item->checkOrigin($item->getActorId());

		$this->save($item);
	}

	/**
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws UnauthorizedFediverseException
	 */
	#[\Override]
	public function activity(Acore $activity, ACore $item): void {
		/** @var Announce $announce */
		$announce = $item;
		if ($activity->getType() === Undo::TYPE) {
			$activity->checkOrigin($announce->getId());
			$activity->checkOrigin($announce->getActorId());

			$this->delete($announce);
		}
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
		throw new ItemNotFoundException();
	}

	/**
	 * @throws Exception
	 */
	#[\Override]
	public function save(ACore $item): void {
		/** @var Announce $item */
		if ($item->hasActor()) {
			$actor = $item->getActor();
		} else {
			$actor = $this->cacheActorService->getFromId($item->getActorId());
		}

		try {
			$post = $this->streamRequest->getStreamById($item->getObjectId(), false, ACore::FORMAT_LOCAL);
		} catch (StreamNotFoundException $e) {
			$post = null;
		}

		// A boost cannot widen the audience of the post it repeats: the local
		// boost path refuses to create one at all (BoostService::create()), and
		// storing a remote one would republish a followers-only or direct post
		// into the timeline of everyone the booster reaches. An object we do
		// not hold yet is caught on the way out instead, by the visibility
		// condition on leftJoinObjectStatus().
		if ($post !== null && !$post->isPublic()) {
			return;
		}

		// A PeerTube channel announces its own video to its followers the
		// moment it publishes it, so a followed channel arrived twice: once as
		// the video, once as "the channel boosted the video". Narrow on
		// purpose — only a video, and only where the announcer is the account
		// the video is already attributed to — because somebody boosting their
		// own *post* to resurface it is a thing people do on Mastodon and this
		// must not swallow that.
		if ($post !== null
			&& ($post->getType() === 'Video' || $post->getSubType() === 'Video')
			&& strcasecmp($post->getAttributedTo(), $item->getActorId()) === 0) {
			$this->miscService->log(
				'skipping a channel announcing its own video: ' . $item->getObjectId(), 1
			);

			return;
		}

		// one Announce row per booster: a second boost of the same post is a
		// row of its own, attributed to whoever made it, so that an Undo can
		// name it and the timelines can say who boosted. The same booster
		// announcing again changes nothing.
		try {
			$this->streamRequest->getAnnounceBy($item->getObjectId(), $actor->getId());
		} catch (StreamNotFoundException $e) {
			$objectId = $item->getObjectId();
			$item->addCacheItem($objectId);
			$item->setAttributedTo($item->getActorId());
			$this->streamRequest->save($item);

			$this->streamQueueService->generateStreamQueue(
				$item->getRequestToken(), StreamQueue::TYPE_CACHE, $item->getId()
			);
		}

		if ($post === null) {
			return; // the object is not here (yet); nothing to count or notify
		}

		try {
			$this->actionsRequest->getActionFromItem($item);
		} catch (ActionDoesNotExistException $e) {
			$this->actionsRequest->save($item);
		}

		$this->updateDetails($post);
		$this->generateNotification($post, $actor);
	}

	/**
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws RedundancyLimitException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws UnauthorizedFediverseException
	 * @throws MalformedArrayException
	 */
	#[\Override]
	public function delete(ACore $item): void {
		try {
			if ($item->hasActor()) {
				$actor = $item->getActor();
			} else {
				$actor = $this->cacheActorService->getFromId($item->getActorId());
			}

			// this booster's row and nobody else's; a row stored before boosts
			// had one row each, attributed to another booster, is left as it is
			$knownItem = $this->streamRequest->getAnnounceBy($item->getObjectId(), $actor->getId());
			$this->streamRequest->deleteById($knownItem->getId(), Announce::TYPE);
		} catch (StreamNotFoundException|ItemUnknownException|SocialAppConfigException $e) {
		}

		$this->undoAnnounceAction($item);
	}

	#[\Override]
	public function event(ACore $item, string $source): void {
	}

	private function undoAnnounceAction(ACore $announce): void {
		try {
			$this->actionsRequest->getActionFromItem($announce);
			$this->actionsRequest->delete($announce);
		} catch (ActionDoesNotExistException $e) {
		}

		try {
			if ($announce->hasActor()) {
				$actor = $announce->getActor();
			} else {
				$actor = $this->cacheActorService->getFromId($announce->getActorId());
			}

			$post = $this->streamRequest->getStreamById($announce->getObjectId());
			$this->updateDetails($post);
			$this->cancelNotification($post, $actor);
		} catch (Exception $e) {
		}
	}

	private function updateDetails(Stream $post): void {
		$this->streamRequest->recount($post, Details::BOOSTS);
	}

	/**
	 * @throws ItemUnknownException
	 * @throws SocialAppConfigException
	 */
	private function generateNotification(Stream $post, Person $author): void {
		if (!$post->isLocal()) {
			return;
		}

		/** @var SocialAppNotificationInterface $notificationInterface */
		$notificationInterface
			= AP::instance()->getInterfaceFromType(SocialAppNotification::TYPE);

		try {
			$notification = $this->streamRequest->getStreamByObjectId(
				$post->getId(), SocialAppNotification::TYPE, Announce::TYPE
			);

			$notification->addDetail(Details::ACCOUNTS, $author->getAccount());
			$notificationInterface->update($notification);
			$this->notificationService->onNotification($notification, $author->getId());
		} catch (StreamNotFoundException $e) {
			/** @var SocialAppNotification $notification */
			$notification = AP::instance()->getItemFromType(SocialAppNotification::TYPE);
			//			$notification->setDetail(Details::URL, '');

			$notification->setDetailItem(Details::POST, $post);
			$notification->addDetail(Details::ACCOUNTS, $author->getAccount());
			$notification->setAttributedTo($author->getId())
				->setSubType(Announce::TYPE)
				->setId($post->getId() . '/notification+boost')
				->setSummary('{accounts} boosted your post')
				->setObjectId($post->getId())
				->setTo($post->getAttributedTo())
				->setLocal(true);

			$notificationInterface->save($notification);
		}
	}

	/**
	 * @throws ItemUnknownException
	 * @throws SocialAppConfigException
	 */
	private function cancelNotification(Stream $post, Person $author): void {
		if (!$post->isLocal()) {
			return;
		}

		/** @var SocialAppNotificationInterface $notificationInterface */
		$notificationInterface
			= AP::instance()->getInterfaceFromType(SocialAppNotification::TYPE);

		try {
			$notification = $this->streamRequest->getStreamByObjectId(
				$post->getId(), SocialAppNotification::TYPE, Announce::TYPE
			);

			$notification->removeDetail(Details::ACCOUNTS, $author->getAccount());
			if (empty($notification->getDetails(Details::ACCOUNTS))) {
				$notificationInterface->delete($notification);
			} else {
				$notificationInterface->update($notification);
			}
		} catch (StreamNotFoundException $e) {
		}
	}
}
