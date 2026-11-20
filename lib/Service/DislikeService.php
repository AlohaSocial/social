<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\AP;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Db\ActionsRequest;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Dislike;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\StreamAction;
use OCA\Social\Tools\Traits\TStringTools;
use Psr\Log\LoggerInterface;

/**
 * Sending PeerTube's dislike; `DislikeInterface` stores and counts arriving
 * ones. Videos only: Mastodon has no dislike and would drop the activity,
 * while PeerTube publishes the counter.
 */
class DislikeService {
	use TStringTools;

	public function __construct(
		private StreamService $streamService,
		private SignatureService $signatureService,
		private ActivityService $activityService,
		private ActionsRequest $actionsRequest,
		private CacheActorService $cacheActorService,
		private StreamActionService $streamActionService,
		private ModerationService $moderationService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @throws InvalidActionException the post is not something that can be disliked
	 * @throws StreamNotFoundException
	 */
	public function create(Person $actor, string $postId, string &$token = ''): ACore {
		$this->moderationService->assertNotSuspended($actor->getId());

		$note = $this->dislikeable($postId);

		/** @var Dislike $dislike */
		$dislike = AP::instance()->getItemFromType(Dislike::TYPE);
		$dislike->setId($actor->getId() . '#dislike/' . $this->uuid(8));
		$dislike->setActor($actor);
		$dislike->setObjectId($note->getId());
		$dislike->setTo($note->getAttributedTo());
		$this->assignInstance($dislike, $note);

		$dislike->setPublished(date('c'));
		$this->signatureService->signObject($actor, $dislike);

		$interface = AP::instance()->getInterfaceFromType(Dislike::TYPE);
		$interface->save($dislike);

		$this->streamActionService->setActionBool(
			$actor->getId(), $note->getId(), StreamAction::DISLIKED, true
		);
		$token = $this->activityService->request($dislike);

		return $dislike;
	}

	/**
	 * @throws InvalidActionException
	 * @throws StreamNotFoundException
	 */
	public function delete(Person $actor, string $postId, string &$token = ''): ACore {
		$note = $this->dislikeable($postId);

		$undo = new Undo();
		$undo->setActor($actor);

		try {
			$this->assignInstance($undo, $note);
		} catch (Exception $e) {
			// the Undo has nowhere to go, but the dislike still has to come
			// off this instance — the author simply keeps theirs
			$this->logger->error('cannot federate the Undo of a Dislike', [
				'attributedTo' => $note->getAttributedTo(),
				'postId' => $postId,
				'exception' => $e,
			]);
		}

		try {
			$tmp = AP::instance()->getItemFromType(Dislike::TYPE);
			$tmp->setActor($actor);
			$tmp->setObjectId($note->getId());

			$interface = AP::instance()->getInterfaceFromType(Dislike::TYPE);
			$dislike = $interface->getItem($tmp);

			$undo->setId($dislike->getId() . '/undo');
			$undo->setObject($dislike);

			$interface->delete($dislike);

			$undo->setPublished(date('c'));
			$this->signatureService->signObject($actor, $undo);

			$token = $this->activityService->request($undo);
		} catch (ItemUnknownException $e) {
		} catch (ItemNotFoundException $e) {
		}

		$this->streamActionService->setActionBool(
			$actor->getId(), $note->getId(), StreamAction::DISLIKED, false
		);

		return $undo;
	}

	/** Whether this viewer has already disliked this post. */
	public function disliked(string $actorId, string $postId): bool {
		if ($actorId === '' || $postId === '') {
			return false;
		}

		try {
			$this->actionsRequest->getAction($actorId, $postId, Dislike::TYPE);

			return true;
		} catch (ItemNotFoundException $e) {
			return false;
		}
	}

	/**
	 * The post, where it is one a dislike means anything about.
	 *
	 * @throws InvalidActionException
	 * @throws StreamNotFoundException
	 */
	private function dislikeable(string $postId): Stream {
		$note = $this->streamService->getStreamById($postId, true);
		if (!$note->isVideo()) {
			throw new InvalidActionException('only a video can be disliked');
		}

		return $note;
	}

	/**
	 * Addresses the activity at the inbox of the post's author, as a Like is;
	 * see `LikeService::assignInstance()`.
	 *
	 * @throws InvalidResourceException when the author publishes no inbox
	 */
	private function assignInstance(ACore $item, Stream $note): void {
		if (BlueskyIds::isActorId($note->getAttributedTo())) {
			// Bluesky has no dislike; it stays here
			return;
		}
		$target = $this->cacheActorService->getFromId($note->getAttributedTo());
		$inbox = $target->getInbox();
		if ($inbox === '') {
			throw new InvalidResourceException(
				'the author of ' . $note->getId() . ' publishes no inbox'
			);
		}

		$item->addInstancePath(
			new InstancePath($inbox, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_LOW)
		);
	}
}
