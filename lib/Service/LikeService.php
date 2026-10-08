<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\AP;
use OCA\Social\Atproto\Publisher\InteractionQueue;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\StreamAction;
use OCA\Social\Tools\Traits\TStringTools;
use Psr\Log\LoggerInterface;

/**
 * Class LikeService
 *
 * @package OCA\Social\Service
 */
class LikeService {
	use TStringTools;

	public function __construct(
		private StreamService $streamService,
		private SignatureService $signatureService,
		private ActivityService $activityService,
		private StreamActionService $streamActionService,
		private CacheActorService $cacheActorService,
		private LoggerInterface $logger,
		private ModerationService $moderationService,
		private ?InteractionQueue $bluesky = null,
	) {
	}

	/**
	 * @param Person $actor
	 * @param string $postId
	 * @param string $token
	 *
	 * @return ACore
	 * @throws StreamNotFoundException
	 * @throws SocialAppConfigException
	 * @throws Exception
	 */
	public function create(Person $actor, string $postId, string &$token = ''): ACore {
		// a suspended account may not act: the check lives here rather than in
		// StreamService, which ModerationService itself now depends on
		$this->moderationService->assertNotSuspended($actor->getId());

		/** @var Like $like */
		$like = AP::instance()->getItemFromType(Like::TYPE);
		$like->setId($actor->getId() . '#like/' . $this->uuid(8));
		$like->setActor($actor);

		$this->logger->info('LikeService::create - start', [
			'actorId' => $actor->getId(),
			'actorPreferredUsername' => $actor->getPreferredUsername(),
			'postId' => $postId,
		]);

		$note = $this->streamService->getStreamById($postId, true);
		if ($note->getType() !== Note::TYPE) {
			$this->logger->warning('LikeService::create - stream is not a Note', [
				'postId' => $postId,
				'type' => $note->getType(),
			]);
			throw new StreamNotFoundException('Stream is not a Note');
		}

		$this->logger->info('LikeService::create - note found', [
			'noteId' => $note->getId(),
			'noteAttributedTo' => $note->getAttributedTo(),
			'noteType' => $note->getType(),
		]);

		$like->setObjectId($note->getId());
		$like->setTo($note->getAttributedTo());
		$this->assignInstance($like, $note);

		$this->logger->info('LikeService::create - instance paths', [
			'paths' => array_map(function ($p) {
				return $p->getAddress();
			}, $like->getInstancePaths()),
			'likeId' => $like->getId(),
		]);

		$like->setPublished(date('c'));
		$this->signatureService->signObject($actor, $like);

		$interface = AP::instance()->getInterfaceFromType(Like::TYPE);
		$interface->save($like);

		$this->streamActionService->setActionBool($actor->getId(), $postId, StreamAction::LIKED, true);
		$this->bluesky?->liked($actor->getId(), $note->getId(), $like->getId());
		$token = $this->activityService->request($like);

		$this->logger->info('LikeService::create - request done', [
			'token' => $token,
		]);

		return $like;
	}

	/**
	 * @param Person $actor
	 * @param string $postId
	 * @param string $token
	 *
	 * @return ACore
	 * @throws SocialAppConfigException
	 * @throws StreamNotFoundException
	 */
	public function delete(Person $actor, string $postId, string &$token = ''): ACore {
		$undo = new Undo();
		$undo->setActor($actor);

		$note = $this->streamService->getStreamById($postId, true);
		if ($note->getType() !== Note::TYPE) {
			throw new StreamNotFoundException('Stream is not a Note');
		}

		try {
			$this->assignInstance($undo, $note);
		} catch (Exception $e) {
			// the Undo has nowhere to go, but the like still has to come off
			// this instance — the author simply keeps theirs
			$this->logger->error('cannot federate the Undo of a Like', [
				'attributedTo' => $note->getAttributedTo(),
				'postId' => $postId,
				'exception' => $e,
			]);
		}

		try {
			$tmp = AP::instance()->getItemFromType(Like::TYPE);
			$tmp->setActor($actor);
			$tmp->setObjectId($postId);

			$interface = AP::instance()->getInterfaceFromType(Like::TYPE);
			$like = $interface->getItem($tmp);

			$undo->setId($like->getId() . '/undo');
			$undo->setObject($like);

			$interface->delete($like);
			$this->bluesky?->unliked($like->getId());

			$undo->setPublished(date('c'));
			$this->signatureService->signObject($actor, $undo);

			$token = $this->activityService->request($undo);
		} catch (ItemUnknownException $e) {
		} catch (ItemNotFoundException $e) {
		}

		$this->streamActionService->setActionBool($actor->getId(), $postId, StreamAction::LIKED, false);

		return $undo;
	}

	/**
	 * Addresses a Like, or its Undo, at the inbox of the post's author.
	 *
	 * An author whose actor cannot be resolved used to be addressed by their
	 * actor URL instead. That is not an inbox: the peer answers 4xx, the queue
	 * row is deleted as permanently rejected, and the like is lost without a
	 * word to anybody. There is nowhere to send it, so say so.
	 *
	 * @throws InvalidResourceException when the author publishes no inbox
	 * @throws Exception when the author's actor cannot be resolved at all
	 */
	private function assignInstance(ACore $item, Stream $note): void {
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
