<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Object;

use Exception;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\ReactionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Interfaces\Activity\AbstractActivityPubInterface;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Object\EmojiReact;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\ReactionService;

/**
 * Reactions arriving from a peer, and going away again, shaped like
 * `LikeInterface`: an `EmojiReact` is saved, its `Undo` deletes it. Unlike a
 * like, it raises no notification (a Misskey reaction bar would otherwise
 * notify per emoji) and keeps no `details` counter, as the bar reads this
 * table anyway.
 */
class EmojiReactInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	public function __construct(
		private ReactionsRequest $reactionsRequest,
		private StreamRequest $streamRequest,
		private ?DurableCache $durableCache = null,
		private ?ActorRelationRequest $actorRelationRequest = null,
	) {
	}

	/**
	 * @throws InvalidOriginException
	 */
	#[\Override]
	public function processIncomingRequest(ACore $item): void {
		/** @var EmojiReact $reaction */
		$reaction = $item;
		$reaction->checkOrigin($reaction->getId());
		$reaction->checkOrigin($reaction->getActorId());

		// save() refuses a reaction undone before it arrived
		try {
			$this->save($reaction);
		} catch (ItemAlreadyExistsException $e) {
		}
	}

	/**
	 * @throws InvalidOriginException
	 */
	#[\Override]
	public function activity(ACore $activity, ACore $item): void {
		/** @var EmojiReact $reaction */
		$reaction = $item;
		if ($activity->getType() === Undo::TYPE) {
			$activity->checkOrigin($reaction->getId());
			$activity->checkOrigin($reaction->getActorId());
			LikeInterface::rememberUndoBeforeArrival(
				$this->durableCache, $reaction->getId(), $reaction->getActorId(), $reaction->getObjectId()
			);

			$this->delete($reaction);
		}
	}

	/**
	 * @throws ItemNotFoundException
	 */
	#[\Override]
	public function getItem(ACore $item): ACore {
		/** @var EmojiReact $reaction */
		$reaction = $item;

		try {
			return $this->reactionsRequest->getReaction(
				$reaction->getActorId(), $reaction->getObjectId(), $reaction->getContent()
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
		/** @var EmojiReact $reaction */
		$reaction = $item;
		if (LikeInterface::undoneBeforeArrival(
			$this->durableCache, $reaction->getId(), $reaction->getActorId(), $reaction->getObjectId()
		)) {
			throw new ItemAlreadyExistsException();
		}

		// A post not held here: nothing to draw it on.
		try {
			$post = $this->streamRequest->getStreamById($reaction->getObjectId());
		} catch (Exception $e) {
			throw new ItemAlreadyExistsException('unknown post');
		}

		// The author blocked the reacting account.
		if ($post->isLocal() && $this->actorRelationRequest?->exists(
			$post->getAttributedTo(), $reaction->getActorId(), ActorRelation::TYPE_BLOCK
		) === true) {
			throw new ItemAlreadyExistsException('blocked by the author');
		}

		if (!ReactionService::isUsableEmoji($reaction->getContent())) {
			throw new ItemAlreadyExistsException('not an emoji this app can draw');
		}

		if (!$this->reactionsRequest->save($reaction)) {
			// Already stored: redeliveries are settled by the unique index.
			throw new ItemAlreadyExistsException();
		}
		if (LikeInterface::undoneBeforeArrival(
			$this->durableCache, $reaction->getId(), $reaction->getActorId(), $reaction->getObjectId()
		)) {
			$this->delete($reaction);
			throw new ItemAlreadyExistsException();
		}
	}

	#[\Override]
	public function delete(ACore $item): void {
		/** @var EmojiReact $reaction */
		$reaction = $item;
		$this->reactionsRequest->delete($reaction);
	}
}
