<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Add;
use OCA\Social\Model\ActivityPub\Activity\Remove;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\InstancePath;
use Psr\Log\LoggerInterface;

/**
 * Pinned posts: the handful of own posts an account keeps at the top of its
 * profile. A pin is a row in the actions table (`Pin`), like a Like or a poll
 * vote, so no schema change is needed; pins are never federated as activities
 * of their own — remote servers read them from the actor's `featured`
 * collection, the same way Mastodon publishes them.
 *
 * Only the author can pin, so `pinned` is a property of the post rather than
 * of the viewer. On Bluesky the newest pin is the profile's `pinnedPost`.
 */
class PinService {
	public const TYPE = 'Pin';

	/** Mastodon's own limit; keeps the featured collection small enough to embed. */
	public const MAX_PINS = 5;

	public function __construct(
		private StreamRequest $streamRequest,
		private ActionsRequest $actionsRequest,
		private ActivityService $activityService,
		private SignatureService $signatureService,
		private ActorsRequest $actorsRequest,
		private LoggerInterface $logger,
		private AccountService $accountService,
	) {
	}

	/**
	 * @throws StreamNotFoundException when the post is unknown
	 * @throws InvalidActionException when the post cannot be pinned
	 */
	public function pin(Person $actor, int|string $nid): Stream {
		$post = $this->ownPost($actor, $nid);

		// the featured collection is read by anyone, local or remote, so what
		// goes into it must be addressed to everyone in the first place
		if (!$post->isPublic()) {
			throw new InvalidActionException('you can only pin a public post');
		}

		if ($this->isPinned($actor->getId(), $post->getId())) {
			return $post->setPinned(true);
		}

		if (count($this->getPinnedIds($actor->getId())) >= self::MAX_PINS) {
			throw new InvalidActionException(
				'you cannot pin more than ' . self::MAX_PINS . ' posts'
			);
		}

		$pin = new Like();
		$pin->setType(self::TYPE);
		// the actions table is keyed by the row id, so the id has to name the
		// actor as well: two actors pinning the same post must not collide
		$pin->setId($post->getId() . '#pin/' . md5($actor->getId()));
		$pin->setActorId($actor->getId());
		$pin->setObjectId($post->getId());
		$this->actionsRequest->save($pin);
		$this->federate($actor, $post, new Add());
		$this->accountService->queueBlueskyProfile($actor);

		return $post->setPinned(true);
	}

	/**
	 * @throws StreamNotFoundException when the post is unknown
	 * @throws InvalidActionException when the post is not the actor's own
	 */
	public function unpin(Person $actor, int|string $nid): Stream {
		$post = $this->ownPost($actor, $nid);
		$this->actionsRequest->deleteAction($actor->getId(), $post->getId(), self::TYPE);
		$this->federate($actor, $post, new Remove());
		$this->accountService->queueBlueskyProfile($actor);

		return $post->setPinned(false);
	}

	/**
	 * Tells the followers that a post entered or left the featured collection.
	 *
	 * A pin is not an activity of its own on the wire: what travels is an
	 * `Add` or a `Remove` whose `target` is the actor's `featured` collection
	 * and whose `object` is the post. Without it a pin was visible only to a
	 * peer that happened to re-read the collection — which nothing prompts it
	 * to do — so a pin made here appeared on other instances late or never,
	 * and an unpin never at all.
	 *
	 * The pin itself is already stored. A failure to federate is logged and
	 * nothing else: the profile here is correct either way, and a pin is not
	 * worth failing a request over.
	 */
	private function federate(Person $actor, Stream $post, ACore $activity): void {
		$activity->setId($post->getId() . '#' . strtolower($activity->getType()) . '/featured');
		$activity->setActorId($actor->getId());
		$activity->setObjectId($post->getId());
		$activity->setTarget($actor->getFeatured());
		$activity->setToArray([ACore::CONTEXT_PUBLIC]);
		$activity->addInstancePath(
			new InstancePath(
				$actor->getId(), InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW
			)
		);

		try {
			$this->signatureService->signObject(
				$this->actorsRequest->getFromId($actor->getId()), $activity
			);
			$this->activityService->request($activity);
		} catch (\Exception $e) {
			$this->logger->warning('could not federate a change to the featured collection', [
				'actor' => $actor->getId(), 'post' => $post->getId(),
				'activity' => $activity->getType(), 'exception' => $e,
			]);
		}
	}

	public function isPinned(string $actorId, string $objectId): bool {
		try {
			$this->actionsRequest->getAction($actorId, $objectId, self::TYPE);

			return true;
		} catch (ActionDoesNotExistException $e) {
			return false;
		}
	}

	/**
	 * The pinned post ids of an account, newest pin first.
	 *
	 * @return string[]
	 */
	public function getPinnedIds(string $actorId): array {
		return array_map(
			static fn (ACore $pin): string => $pin->getObjectId(),
			$this->actionsRequest->getActionsByActor($actorId, self::TYPE)
		);
	}

	/**
	 * The pinned posts of an account, newest pin first. Posts the viewer
	 * cannot see, or that are gone, are skipped.
	 *
	 * @return Stream[]
	 */
	public function getPinnedPosts(string $actorId, ?Person $viewer = null): array {
		if ($viewer !== null) {
			$this->streamRequest->setViewer($viewer);
		}

		$posts = [];
		foreach ($this->getPinnedIds($actorId) as $id) {
			try {
				// always through the visibility filter: with no viewer that is
				// the public-only one an anonymous reader gets. Reading these
				// unfiltered served a pinned followers-only post to whoever
				// asked for the featured collection.
				$post = $this->streamRequest->getStreamById($id, true, ACore::FORMAT_LOCAL);
			} catch (StreamNotFoundException $e) {
				continue;
			}
			$posts[] = $post->setPinned(true);
		}

		return $posts;
	}

	/**
	 * Flags the pinned ones among posts that all belong to $actorId — one
	 * query for the whole page instead of one per post.
	 *
	 * @param Stream[] $posts
	 */
	public function markPinned(array $posts, string $actorId): void {
		if ($posts === []) {
			return;
		}

		$pinned = $this->getPinnedIds($actorId);
		if ($pinned === []) {
			return;
		}

		foreach ($posts as $post) {
			if (in_array($post->getId(), $pinned, true)) {
				$post->setPinned(true);
			}
		}
	}

	/**
	 * @throws StreamNotFoundException
	 * @throws InvalidActionException
	 */
	private function ownPost(Person $actor, int|string $nid): Stream {
		$post = $this->streamRequest->getStreamByNid($nid);

		if (!$post->isLocal() || $post->getAttributedTo() !== $actor->getId()) {
			throw new InvalidActionException('you can only pin your own posts');
		}

		return $post;
	}
}
