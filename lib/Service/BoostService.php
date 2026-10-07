<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\AP;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\StreamAction;
use OCA\Social\Tools\Traits\TStringTools;
use Psr\Log\LoggerInterface;

/**
 * Class BoostService
 *
 * @package OCA\Social\Service
 */
class BoostService {
	use TStringTools;

	public function __construct(
		private StreamRequest $streamRequest,
		private StreamService $streamService,
		private SignatureService $signatureService,
		private ActivityService $activityService,
		private StreamActionService $streamActionService,
		private StreamQueueService $streamQueueService,
		private CacheActorService $cacheActorService,
		private LoggerInterface $logger,
		private ModerationService $moderationService,
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

		/** @var Announce $announce */
		$announce = AP::instance()->getItemFromType(Announce::TYPE);
		$this->streamService->assignItem($announce, $actor, Stream::TYPE_ANNOUNCE);
		$announce->setActor($actor);

		$note = $this->streamService->getStreamById($postId, true);
		if ($note->getType() !== Note::TYPE) {
			throw new StreamNotFoundException('Stream is not a Note');
		}

		if (!$note->isPublic()) {
			throw new StreamNotFoundException('Stream is not Public');
		}

		if ($this->hasAnnounce($actor, $postId)) {
			throw new ItemAlreadyExistsException('this account has already boosted this post');
		}

		$announce->setTo(ACore::CONTEXT_PUBLIC);
		$announce->addCc($actor->getFollowers());
		//	$announce->addcc($note->getAttributedTo());

		try {
			$target = $this->cacheActorService->getFromId($note->getAttributedTo());
			$announce->addInstancePath(
				new InstancePath(
					$target->getInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_LOW
				)
			);
		} catch (Exception $e) {
			$this->logger->warning('Could not resolve actor inbox for Boost federation', [
				'attributedTo' => $note->getAttributedTo(),
				'exception' => $e,
			]);
		}

		$announce->setObjectId($note->getId());
		$announce->setRequestToken($this->uuid());

		$interface = AP::instance()->getInterfaceFromType(Announce::TYPE);
		$interface->save($announce);

		$this->streamActionService->setActionBool($actor->getId(), $postId, StreamAction::BOOSTED, true);
		$this->signatureService->signObject($actor, $announce);

		$token = $this->activityService->request($announce);

		$this->streamQueueService->cacheStreamByToken($announce->getRequestToken());

		return $announce;
	}

	/**
	 * @param string $postId
	 *
	 * @return Stream
	 * @throws ItemUnknownException
	 * @throws SocialAppConfigException
	 * @throws StreamNotFoundException
	 */
	public function get(string $postId): Stream {
		$stream = $this->streamRequest->getStreamByObjectId($postId, Announce::TYPE);

		return $stream;
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
		$this->streamService->assignItem($undo, $actor, Stream::TYPE_PUBLIC);
		$undo->setActor($actor);

		$note = $this->streamService->getStreamById($postId, true);
		if ($note->getType() !== Note::TYPE) {
			throw new StreamNotFoundException('Stream is not a Note');
		}

		try {
			$target = $this->cacheActorService->getFromId($note->getAttributedTo());
			$undo->addInstancePath(
				new InstancePath(
					$target->getInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_LOW
				)
			);
		} catch (Exception $e) {
			$this->logger->warning('Could not resolve actor inbox for Boost Undo federation', [
				'attributedTo' => $note->getAttributedTo(),
				'exception' => $e,
			]);
		}

		try {
			$announce = $this->findAnnounce($actor, $postId);
			$announce->setActor($actor);

			$undo->setObject($this->undoneAnnounce($actor, $announce, $postId));
			$undo->addCc($actor->getFollowers());

			$interface = AP::instance()->getInterfaceFromType(Announce::TYPE);
			$interface->delete($announce);
			$this->streamRequest->deleteById($announce->getId(), Announce::TYPE);
			$this->signatureService->signObject($actor, $undo);

			$token = $this->activityService->request($undo);
		} catch (ItemUnknownException $e) {
		} catch (StreamNotFoundException $e) {
		}

		$this->streamActionService->setActionBool($actor->getId(), $postId, StreamAction::BOOSTED, false);

		return $undo;
	}

	/**
	 * The Announce an Undo takes back, embedded the way every other Undo
	 * here embeds its object.
	 *
	 * PeerTube reads `object.type` to decide what is being undone and ignores
	 * an Undo whose object is a bare id, so the share was never removed there.
	 * Built from the stored row rather than exported from it: the row carries
	 * the boosted post and this app's bookkeeping, and a peer needs the id,
	 * the actor, the object and the audience.
	 */
	private function undoneAnnounce(Person $actor, Stream $announce, string $postId): Announce {
		$embedded = new Announce();
		$embedded->setId($announce->getId());
		$embedded->setActorId($actor->getId());
		$embedded->setObjectId(($announce->getObjectId() !== '') ? $announce->getObjectId() : $postId);
		$embedded->setTo($announce->getTo());
		$embedded->setToArray($announce->getToArray());
		$embedded->setCcArray($announce->getCcArray());
		$embedded->setPublished($announce->getPublished());

		return $embedded;
	}

	/** Whether this actor has already boosted the post. */
	private function hasAnnounce(Person $actor, string $postId): bool {
		try {
			$this->findAnnounce($actor, $postId);

			return true;
		} catch (StreamNotFoundException $e) {
			return false;
		}
	}

	/**
	 * This actor's own Announce of a post: one row per (post, booster), so
	 * somebody else's boost of the same post is never the one found, and the
	 * `Undo` names an Announce its signer made.
	 *
	 * @throws StreamNotFoundException when this actor has not boosted the post
	 */
	private function findAnnounce(Person $actor, string $postId): Stream {
		return $this->streamRequest->getAnnounceBy($postId, $actor->getId());
	}
}
