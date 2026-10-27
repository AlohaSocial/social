<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Object;

use Exception;
use OCA\Social\Db\ReactionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Interfaces\Activity\AbstractActivityPubInterface;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Interfaces\Object\LikeInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Object\EmojiReact;
use OCA\Social\Service\ReactionService;
use OCA\Social\Service\DurableCache;

/**
 * Reactions arriving from a peer, and going away again.
 *
 * The shape is the one `LikeInterface` uses, because a reaction is a like with
 * an emoji on it: an `EmojiReact` is saved, and an `Undo` of one deletes it.
 *
 * Two things are deliberately not done here that `LikeInterface` does:
 *
 * - **No notification.** A like already notifies, and a reaction is the same
 *   gesture with a picture chosen for it. An instance federating with a
 *   Misskey-family server would otherwise turn a reaction bar into a
 *   notification each, for something the reader can see on the post.
 * - **No `details` counter on the post.** Likes keep a count in the stream row
 *   because a count is all there is to show. A reaction bar needs the emoji
 *   and who used each one, which is a read of this table however it is
 *   counted, so a second copy of the number would only be something to keep
 *   in step.
 */
class EmojiReactInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	public function __construct(
		private ReactionsRequest $reactionsRequest,
		private StreamRequest $streamRequest,
		private ?DurableCache $durableCache = null,
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
		if (LikeInterface::undoneBeforeArrival(
			$this->durableCache, $reaction->getId(), $reaction->getActorId(), $reaction->getObjectId()
		)) {
			return;
		}

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

		// a reaction to a post this instance does not hold is nothing it can
		// draw, and storing it would be a row that is never read
		try {
			$this->streamRequest->getStreamById($reaction->getObjectId());
		} catch (Exception $e) {
			throw new ItemAlreadyExistsException('unknown post');
		}

		if (!ReactionService::isUsableEmoji($reaction->getContent())) {
			throw new ItemAlreadyExistsException('not an emoji this app can draw');
		}

		if (!$this->reactionsRequest->save($reaction)) {
			// already stored: the Fediverse redelivers, and the unique index
			// is what makes that harmless
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
