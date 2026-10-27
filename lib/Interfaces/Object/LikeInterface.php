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
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\Activity\AbstractActivityPubInterface;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Interfaces\Internal\SocialAppNotificationInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Details;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\NotificationService;

/**
 * Class LikeInterface
 *
 * @package OCA\Social\Interfaces\Object
 */
class LikeInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	/** Where an Undo that precedes its Like is kept, for the same six hours as an early Delete. */
	public const EARLY_UNDO_NAMESPACE = 'social.early_undo_like';
	public const EARLY_UNDO_TTL = 21600;

	public function __construct(
		private ActionsRequest $actionsRequest,
		private StreamRequest $streamRequest,
		private CacheActorService $cacheActorService,
		private NotificationService $notificationService,
		private ?DurableCache $durableCache = null,
	) {
	}

	/** Whether the same actor already undid this like before it arrived. */
	public static function undoneBeforeArrival(?DurableCache $durableCache, string $likeId, string $actorId, string $objectId): bool {
		if ($durableCache === null || $likeId === '' || $actorId === '') {
			return false;
		}

		$pending = $durableCache->getShared(self::EARLY_UNDO_NAMESPACE, hash('sha256', $likeId));

		return is_array($pending)
			&& ($pending['actor'] ?? '') === $actorId
			&& (($pending['object'] ?? '') === '' || ($pending['object'] ?? '') === $objectId);
	}

	/** Record an Undo for a Like not yet present locally. */
	public static function rememberUndoBeforeArrival(?DurableCache $durableCache, string $likeId, string $actorId, string $objectId = ''): void {
		if ($durableCache === null || $likeId === '' || $actorId === '') {
			return;
		}

		$durableCache->setShared(
			self::EARLY_UNDO_NAMESPACE,
			hash('sha256', $likeId),
			['actor' => $actorId, 'object' => $objectId],
			self::EARLY_UNDO_TTL,
		);
	}

	/**
	 * @throws InvalidOriginException
	 */
	#[\Override]
	public function processIncomingRequest(ACore $item): void {
		/** @var Like $like */
		$like = $item;
		$like->checkOrigin($like->getId());
		$like->checkOrigin($like->getActorId());
		if (self::undoneBeforeArrival($this->durableCache, $like->getId(), $like->getActorId(), $like->getObjectId())) {
			return;
		}

		try {
			$this->save($like);
		} catch (ItemAlreadyExistsException $e) {
		}
	}

	/**
	 * @throws InvalidOriginException
	 */
	#[\Override]
	public function activity(ACore $activity, ACore $item): void {
		/** @var Like $like */
		$like = $item;
		if ($activity->getType() === Undo::TYPE) {
			$activity->checkOrigin($like->getId());
			$activity->checkOrigin($like->getActorId());
			self::rememberUndoBeforeArrival(
				$this->durableCache, $like->getId(), $like->getActorId(), $like->getObjectId()
			);

			$this->delete($like);
		}
	}

	/**
	 * @throws ItemNotFoundException
	 */
	#[\Override]
	public function getItem(ACore $item): ACore {
		try {
			return $this->actionsRequest->getAction(
				$item->getActorId(),
				$item->getObjectId(),
				Like::TYPE
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
		if (self::undoneBeforeArrival(
			$this->durableCache, $item->getId(), $item->getActorId(), $item->getObjectId()
		)) {
			throw new ItemAlreadyExistsException();
		}

		try {
			$this->actionsRequest->getActionFromItem($item);
			throw new ItemAlreadyExistsException();
		} catch (ActionDoesNotExistException $e) {
		}

		// a redelivery overlapping the first copy passes the lookup above
		// and is refused by the database instead
		if (!$this->actionsRequest->save($item)) {
			throw new ItemAlreadyExistsException();
		}
		if (self::undoneBeforeArrival(
			$this->durableCache, $item->getId(), $item->getActorId(), $item->getObjectId()
		)) {
			$this->actionsRequest->deleteAction($item->getActorId(), $item->getObjectId(), Like::TYPE);
			throw new ItemAlreadyExistsException();
		}

		try {
			if ($item->hasActor()) {
				$actor = $item->getActor();
			} else {
				$actor = $this->cacheActorService->getFromId($item->getActorId());
			}

			$post = $this->streamRequest->getStreamById(
				$item->getObjectId(),
				false,
				ACore::FORMAT_LOCAL
			);
			$post->setCompleteDetails(true);
			$this->updateDetails($post);
			$this->generateNotification($post, $actor);
		} catch (Exception $e) {
		}
	}

	#[\Override]
	public function delete(ACore $item): void {
		$this->actionsRequest->delete($item);
		$this->undoLikeAction($item);
	}

	private function undoLikeAction(ACore $item): void {
		/** @var Like $like */
		$like = $item;
		try {
			if ($like->hasActor()) {
				$actor = $like->getActor();
			} else {
				$actor = $this->cacheActorService->getFromId($like->getActorId());
			}

			$post = $this->streamRequest->getStreamById($like->getObjectId());
			$this->updateDetails($post);
			$this->cancelNotification($post, $actor);
		} catch (Exception $e) {
		}
	}

	private function updateDetails(Stream $post): void {
		$this->streamRequest->recount($post, Details::LIKES);
	}

	/**
	 * @throws ItemAlreadyExistsException
	 * @throws ItemNotFoundException
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
				$post->getId(), SocialAppNotification::TYPE, Like::TYPE
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
				->setSubType(Like::TYPE)
				->setId($post->getId() . '/notification+like')
				->setSummary('{accounts} liked your post')
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
				$post->getId(), SocialAppNotification::TYPE, Like::TYPE
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
