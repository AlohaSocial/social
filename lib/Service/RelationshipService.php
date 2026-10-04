<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\AP;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Block;
use OCA\Social\Model\ActivityPub\Activity\Reject;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\InstancePath;
use Psr\Log\LoggerInterface;

/**
 * Blocking and muting.
 *
 * A mute is purely local: nothing is federated and the muted account cannot tell.
 * A block severs the follow relationship in both directions and — unless the
 * instance disables it — federates a Block activity the way Mastodon does, so the
 * remote server can enforce it too. The timelines enforce both through
 * SocialLimitsQueryBuilder::filterHiddenActors().
 */
class RelationshipService {
	public function __construct(
		private ActorRelationRequest $actorRelationRequest,
		private FollowsRequest $followsRequest,
		private ActivityService $activityService,
		private CacheActorService $cacheActorService,
		private ConfigService $configService,
		private TimelineRevisionService $timelineRevisionService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The accounts the viewer holds a relation of $type with — feeds the
	 * /api/v1/blocks and /api/v1/mutes listings.
	 *
	 * @return Person[]
	 */
	public function getRelated(Person $viewer, string $type, int $limit = 40): array {
		$relations = $this->actorRelationRequest->getByActor($viewer->getId(), $type, $limit);

		// one query, and no federated fetch on a miss: an account you have
		// blocked or muted is an account you have seen, so it is cached — and a
		// listing must not be able to hang on someone else's instance
		$cached = $this->cacheActorService->getCachedFromIds(
			array_map(static fn (ActorRelation $relation): string => $relation->getObjectId(), $relations)
		);

		$accounts = [];
		foreach ($relations as $relation) {
			$account = $cached[$relation->getObjectId()] ?? null;
			if ($account === null) {
				$this->logger->debug('getRelated - cannot resolve related account', [
					'objectId' => $relation->getObjectId(),
				]);

				continue;
			}

			$accounts[] = $account;
		}

		return $accounts;
	}

	public function mute(Person $viewer, Person $target, bool $notifications = true): void {
		$this->requireOtherAccount($viewer, $target, 'mute');
		$this->actorRelationRequest->save(
			$viewer->getId(), $target->getId(), ActorRelation::TYPE_MUTE, $notifications
		);
		// their timelines hide different posts from now on, which no id the
		// ETag is built from reflects — see TimelineRevisionService
		$this->timelineRevisionService->bumpForActor($viewer->getId());
	}

	public function unmute(Person $viewer, Person $target): void {
		$this->actorRelationRequest->delete($viewer->getId(), $target->getId(), ActorRelation::TYPE_MUTE);
		$this->timelineRevisionService->bumpForActor($viewer->getId());
	}

	public function block(Person $viewer, Person $target): void {
		$this->requireOtherAccount($viewer, $target, 'block');
		$this->actorRelationRequest->save($viewer->getId(), $target->getId(), ActorRelation::TYPE_BLOCK);
		$this->severFollows($viewer, $target);
		$this->timelineRevisionService->bumpForActor($viewer->getId());

		if ($target->isLocal()) {
			// what BlockInterface records when the block arrives from another
			// instance: the blocked account's own reads -- notifications,
			// suggestions, follow requests -- are filtered on this row, and a
			// block between two accounts of this instance never arrives
			$this->actorRelationRequest->save($target->getId(), $viewer->getId(), ActorRelation::TYPE_BLOCKED_BY);
			$this->timelineRevisionService->bumpForActor($target->getId());

			return;
		}

		if ($this->configService->isBlockFederationEnabled()) {
			/** @var Block $block */
			$block = AP::instance()->getItemFromType(Block::TYPE);
			$block->generateUniqueIdFromActor($viewer->getId(), 'block');
			$block->setActorId($viewer->getId());
			$block->setObjectId($target->getId());
			$this->send($block, $target);
		}
	}

	public function unblock(Person $viewer, Person $target): void {
		$this->actorRelationRequest->delete($viewer->getId(), $target->getId(), ActorRelation::TYPE_BLOCK);
		$this->timelineRevisionService->bumpForActor($viewer->getId());

		if ($target->isLocal()) {
			$this->actorRelationRequest->delete($target->getId(), $viewer->getId(), ActorRelation::TYPE_BLOCKED_BY);
			$this->timelineRevisionService->bumpForActor($target->getId());

			return;
		}

		if ($this->configService->isBlockFederationEnabled()) {
			/** @var Block $block */
			$block = AP::instance()->getItemFromType(Block::TYPE);
			$block->generateUniqueIdFromActor($viewer->getId(), 'block');
			$block->setActorId($viewer->getId());
			$block->setObjectId($target->getId());

			/** @var Undo $undo */
			$undo = AP::instance()->getItemFromType(Undo::TYPE);
			$undo->generateUniqueIdFromActor($viewer->getId(), 'undo/block');
			$undo->setActorId($viewer->getId());
			$undo->setObject($block);
			$this->send($undo, $target);
		}
	}

	/**
	 * A block ends the relationship in both directions: the viewer's follow of the
	 * target (with a federated Undo) and the target's follow of the viewer (with a
	 * federated Reject, so a Mastodon server drops the follow on its side).
	 */
	private function severFollows(Person $viewer, Person $target): void {
		try {
			$follow = $this->followsRequest->getByPersons($viewer->getId(), $target->getId());
			$this->followsRequest->delete($follow);

			if (!$target->isLocal()) {
				/** @var Undo $undo */
				$undo = AP::instance()->getItemFromType(Undo::TYPE);
				$undo->generateUniqueIdFromActor($viewer->getId(), 'undo/follows');
				$undo->setActorId($viewer->getId());
				$undo->setObject($follow);
				$this->send($undo, $target);
			}
		} catch (FollowNotFoundException $e) {
		}

		try {
			$follow = $this->followsRequest->getByPersons($target->getId(), $viewer->getId());
			$this->followsRequest->delete($follow);

			if (!$target->isLocal()) {
				/** @var Reject $reject */
				$reject = AP::instance()->getItemFromType(Reject::TYPE);
				$reject->generateUniqueIdFromActor($viewer->getId(), 'reject/follows');
				$reject->setActorId($viewer->getId());
				$reject->setObject($follow);
				$this->send($reject, $target);
			}
		} catch (FollowNotFoundException $e) {
		}
	}

	private function send(ACore $activity, Person $remote): void {
		$activity->addInstancePath(
			new InstancePath($remote->getInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_TOP)
		);

		try {
			$this->activityService->request($activity);
		} catch (Exception $e) {
			// The relation row is already stored, so enforcement holds locally even
			// when the remote instance is unreachable right now.
			$this->logger->warning('RelationshipService - could not federate activity', [
				'type' => $activity->getType(),
				'exception' => $e,
			]);
		}
	}

	/**
	 * @throws InvalidResourceException
	 */
	private function requireOtherAccount(Person $viewer, Person $target, string $action): void {
		if ($viewer->getId() === $target->getId()) {
			throw new InvalidResourceException('cannot ' . $action . ' your own account');
		}
	}
}
