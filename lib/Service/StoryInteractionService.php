<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\StoriesRequest;
use OCA\Social\Db\StoryInteractionsRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\StoryInteraction as ApInteraction;
use OCA\Social\Model\ActivityPub\Activity\StoryReaction as ApReaction;
use OCA\Social\Model\ActivityPub\Activity\StoryReply as ApReply;
use OCA\Social\Model\ActivityPub\Activity\View;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\Story;
use OCA\Social\Model\Client\StoryInteraction;
use OCA\Social\Model\InstancePath;
use OCA\Social\Tools\Traits\TStringTools;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Answering a story: watching it, reacting to it, replying to it.
 *
 * A story used to be a thing that could only be watched. Pixelfed has three
 * ways of answering one and a verb for each — `View`, `Story:Reaction`,
 * `Story:Reply` — and this app sent and understood none of them, so a story
 * posted to Pixelfed followers came back silent.
 *
 * Every rule here is the same rule twice, once outbound and once inbound,
 * because Pixelfed drops what fails it without a word and so a payload that
 * would be dropped must never be built:
 *
 *  - the answer goes only to the author, never to a collection;
 *  - only somebody who **follows** the author may answer at all, which is the
 *    same condition as being able to see the story;
 *  - the author is the one person told, as with the view count;
 *  - a story that has expired takes no answers, because there is nothing left
 *    to answer.
 *
 * A reply is deliberately not a post: no `social_stream` row, no timeline, no
 * outbox, and it is deleted with the story rather than outliving it.
 */
class StoryInteractionService {
	use TStringTools;

	public function __construct(
		private StoriesRequest $storiesRequest,
		private StoryInteractionsRequest $interactionsRequest,
		private FollowService $followService,
		private CacheActorService $cacheActorService,
		private ActivityService $activityService,
		private SignatureService $signatureService,
		private NotificationService $notificationService,
		private ConfigService $configService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Somebody here answers a story, whoever posted it.
	 *
	 * @throws ItemNotFoundException the story is unknown, expired, or not the
	 *                               viewer's to see — one answer for all three,
	 *                               because whether an account has a story up
	 *                               is told only to its followers
	 * @throws InvalidResourceException there is nothing in it, it is too long,
	 *                                  or this account has said enough
	 */
	public function answer(Person $viewer, int $storyId, string $type, string $content): StoryInteraction {
		$type = ($type === StoryInteraction::TYPE_REPLY)
			? StoryInteraction::TYPE_REPLY : StoryInteraction::TYPE_REACTION;

		$content = trim($content);
		if ($content === '') {
			throw new InvalidResourceException('there is nothing in this');
		}
		if (mb_strlen($content) > StoryInteraction::maxLengthOf($type)) {
			throw new InvalidResourceException(
				'at most ' . StoryInteraction::maxLengthOf($type) . ' characters'
			);
		}

		$story = $this->storiesRequest->getLiveById($storyId);
		$owner = $this->ownerOf($story, $viewer);

		if ($this->interactionsRequest->countByActor($storyId, $viewer->getId())
			>= StoryInteraction::MAX_PER_ACTOR) {
			throw new InvalidResourceException(
				'this account has already answered this short '
				. StoryInteraction::MAX_PER_ACTOR . ' times'
			);
		}

		$interaction = new StoryInteraction();
		$interaction->setStoryId($storyId)
			->setActorId($viewer->getId())
			->setType($type)
			->setContent($content)
			->setSourceId($this->idOf($viewer, $type));

		$this->interactionsRequest->save($interaction);
		$interaction->setAuthor($this->authorOf($viewer->getId()));

		if ($story->isLocal()) {
			// the author is here, so there is nobody to tell but them
			$this->notificationService->onStoryInteraction($story, $interaction);
		} else {
			$this->publish($viewer, $owner, $story, $interaction);
		}

		return $interaction;
	}

	/**
	 * What has been said about one of the owner's own stories.
	 *
	 * Owner only, and somebody else's story is the same 404 as one that does
	 * not exist — who answered is the poster's business, the way the view
	 * count and the viewer list are.
	 *
	 * @return StoryInteraction[]
	 * @throws ItemNotFoundException
	 */
	public function forStory(Person $owner, int $storyId): array {
		$story = $this->storiesRequest->getLiveById($storyId);
		if ($story->getOwnerId() !== $owner->getId()) {
			throw new ItemNotFoundException('unknown story');
		}

		$interactions = $this->interactionsRequest->forStory($storyId);
		foreach ($interactions as $interaction) {
			$interaction->setAuthor($this->authorOf($interaction->getActorId()));
		}

		return $interactions;
	}

	/**
	 * A receipt for a story of ours that somebody elsewhere has watched.
	 *
	 * Silently ignored unless every one of Pixelfed's own conditions holds:
	 * the story is this instance's, it is still live, and the watcher follows
	 * its author. A `View` for somebody else's story, or from an account that
	 * does not follow, is a claim rather than a receipt.
	 */
	public function receiveView(View $activity): bool {
		$story = $this->localStory($activity->getStoryId());
		if ($story === null) {
			// not a story of ours: PeerTube sends a `View` for every watch of a
			// video, and the caller offers those to the post counter instead
			return false;
		}

		$watcher = $activity->getActorId();
		if ($watcher === '' || !$this->follows($watcher, $story->getOwnerId())) {
			return true;
		}

		$this->storiesRequest->markSeen($story->getId(), $watcher);

		return true;
	}

	/**
	 * A reaction or a reply that arrived for a story of ours.
	 *
	 * The same conditions as a view, plus the two that make the text worth
	 * keeping: there is some, and it is not longer than this app would have
	 * let one of its own accounts send.
	 */
	public function receiveInteraction(ApInteraction $activity): void {
		$story = $this->localStory($activity->getStoryId());
		if ($story === null) {
			return;
		}

		$sender = $activity->getActorId();
		if ($sender === '' || !$this->follows($sender, $story->getOwnerId())) {
			return;
		}

		$type = ($activity instanceof ApReply)
			? StoryInteraction::TYPE_REPLY : StoryInteraction::TYPE_REACTION;

		// the text as text: a reaction is an emoji and a reply is a sentence,
		// and neither is somewhere to accept markup from another server
		$content = trim(html_entity_decode(strip_tags($activity->getContent()), ENT_QUOTES, 'UTF-8'));
		if ($content === '') {
			return;
		}
		$content = mb_substr($content, 0, StoryInteraction::maxLengthOf($type));

		if ($this->interactionsRequest->countByActor($story->getId(), $sender)
			>= StoryInteraction::MAX_PER_ACTOR) {
			return;
		}

		$interaction = new StoryInteraction();
		$interaction->setStoryId($story->getId())
			->setActorId($sender)
			->setType($type)
			->setContent($content)
			->setSourceId(($activity->getId() !== '') ? $activity->getId() : $this->uuid());

		// false means this exact activity is already here, which is what a
		// retried delivery looks like; telling the author twice about one
		// reaction is the thing the unique index exists to stop
		if (!$this->interactionsRequest->save($interaction)) {
			return;
		}

		$this->notificationService->onStoryInteraction($story, $interaction);
	}

	/**
	 * Tells the author of a remote story that somebody here has watched it.
	 *
	 * A failure is not a failure of the watching: the story is marked seen
	 * here either way, and a receipt that could not be sent is a receipt that
	 * did not arrive rather than a view that did not happen.
	 */
	public function sendView(Person $viewer, Story $story): void {
		if ($story->isLocal() || $story->getSourceId() === '' || $story->getOwnerId() === $viewer->getId()) {
			return;
		}

		try {
			$owner = $this->cacheActorService->getFromId($story->getOwnerId());

			$activity = new View();
			$activity->setId($viewer->getId() . '#stories/' . $story->getId() . '/view');
			$activity->setActor($viewer);
			$activity->setStoryId($story->getSourceId());
			$activity->setTo($owner->getId());
			$activity->addInstancePath(new InstancePath(
				$owner->getInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_LOW
			));

			$this->signatureService->signObject($viewer, $activity);
			$this->activityService->request($activity);
		} catch (Throwable $e) {
			$this->logger->debug('could not send a story view receipt', [
				'story' => $story->getSourceId(), 'exception' => $e,
			]);
		}
	}

	/** Everything said about a story goes with it. */
	public function forgetStory(int $storyId): void {
		$this->interactionsRequest->deleteByStory($storyId);
	}

	/** @param int[] $storyIds */
	public function forgetStories(array $storyIds): void {
		$this->interactionsRequest->deleteByStories($storyIds);
	}

	/** Everything an account has said, for a deletion or a suspension. */
	public function forgetActor(string $actorId): void {
		$this->interactionsRequest->deleteByActor($actorId);
	}

	/**
	 * Sends one answer to the author of a remote story.
	 *
	 * Pixelfed refuses an activity whose id is on a different host from its
	 * actor, so the id is minted under the answering account.
	 */
	private function publish(Person $viewer, Person $owner, Story $story, StoryInteraction $interaction): void {
		try {
			$activity = ($interaction->getType() === StoryInteraction::TYPE_REPLY)
				? new ApReply() : new ApReaction();
			$activity->setId($interaction->getSourceId());
			$activity->setActor($viewer);
			$activity->setStoryId($story->getSourceId());
			$activity->setContent($interaction->getContent());
			$activity->setPublished(date('c'));
			$activity->setTo($owner->getId());
			$activity->addInstancePath(new InstancePath(
				$owner->getInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_LOW
			));

			$this->signatureService->signObject($viewer, $activity);
			$this->activityService->request($activity);
		} catch (Throwable $e) {
			$this->logger->warning('could not send an answer to a story', [
				'story' => $story->getSourceId(), 'exception' => $e,
			]);
		}
	}

	/**
	 * A live local story by the address an activity named, or null.
	 *
	 * Null rather than an exception: every caller of this is an inbox handler,
	 * and an activity about a story this instance does not hold is nothing to
	 * do rather than a delivery to refuse.
	 */
	private function localStory(string $sourceId): ?Story {
		if ($sourceId === '' || !str_starts_with($sourceId, $this->configService->getSocialUrl())) {
			return null;
		}

		try {
			$story = $this->storiesRequest->getBySourceId($sourceId);
		} catch (ItemNotFoundException $e) {
			return null;
		}

		if (!$story->isLocal() || $story->getExpiresAt() <= time()) {
			return null;
		}

		return $story;
	}

	/**
	 * The author of a story the viewer is allowed to answer.
	 *
	 * @throws ItemNotFoundException
	 */
	private function ownerOf(Story $story, Person $viewer): Person {
		if ($story->getOwnerId() === $viewer->getId()) {
			// answering your own story is allowed and goes nowhere, which is
			// what Pixelfed does too
			return $viewer;
		}

		if (!$this->follows($viewer->getId(), $story->getOwnerId())) {
			throw new ItemNotFoundException('unknown story');
		}

		try {
			return $this->cacheActorService->getFromId($story->getOwnerId());
		} catch (Throwable $e) {
			throw new ItemNotFoundException('unknown story');
		}
	}

	/** Whether one account follows another, by id, without raising. */
	private function follows(string $actorId, string $targetId): bool {
		if ($actorId === $targetId) {
			return true;
		}

		$actor = new Person();
		$actor->setId($actorId);
		$target = new Person();
		$target->setId($targetId);

		try {
			return $this->followService->getLinksBetweenPersons($actor, $target)['following'] === true;
		} catch (Throwable $e) {
			return false;
		}
	}

	/** The address one of this instance's own answers travels under. */
	private function idOf(Person $viewer, string $type): string {
		return $viewer->getId() . '/story-' . $type . '/' . $this->uuid(12);
	}

	private function authorOf(string $actorId): ?Person {
		try {
			$author = $this->cacheActorService->getFromId($actorId);
			$author->setExportFormat(ACore::FORMAT_LOCAL);

			return $author;
		} catch (Throwable $e) {
			return null;
		}
	}
}
