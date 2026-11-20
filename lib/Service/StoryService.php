<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\StoriesRequest;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\RefusalException;
use OCA\Social\Interfaces\Object\DocumentInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Add;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Story as ApStory;
use OCA\Social\Model\Client\Story;
use OCA\Social\Model\InstancePath;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stories (24-hour shorts in the UI): one photo that stops existing after a
 * day. Only the owner and the accounts that follow them may see one; there is
 * no public story, as on Pixelfed.
 */
class StoryService {
	public function __construct(
		private StoriesRequest $storiesRequest,
		private DocumentService $documentService,
		private FollowService $followService,
		private CacheActorService $cacheActorService,
		private IURLGenerator $urlGenerator,
		private ActivityService $activityService,
		private ConfigService $configService,
		private DocumentInterface $documentInterface,
		private StoryInteractionService $storyInteractionService,
		private MediaPurgeService $mediaPurgeService,
		private LoggerInterface $logger,
		private ?ITimeFactory $timeFactory = null,
	) {
	}

	/**
	 * The ActivityPub id a local story is published under, built from the
	 * account and the row's number, so it is set once the row exists. It
	 * resolves through `ActivityPubController::story()`, for the author or a
	 * follower.
	 */
	public function idOf(Person $owner, int $id): string {
		return $this->configService->getSocialUrl() . '@' . $owner->getPreferredUsername() . '/stories/' . $id;
	}

	/**
	 * The capability a story travels with: `bear:?t=<token>&u=<url>` (FEP-d8c2).
	 * Pixelfed's `StoryFetch` reads the story only through `object.object` as a
	 * bearcap and drops an `Add` without one. The field order is Pixelfed's
	 * `Bearcap::encode()`, and its decoder does not unescape, so neither half
	 * may be URL-encoded.
	 */
	public function bearcapOf(string $storyId): string {
		return 'bear:?t=' . $this->bearcapToken($storyId) . '&u=' . $storyId;
	}

	/**
	 * The token that fetches one story, and nothing else: derived, not stored,
	 * keyed on this instance's secret, so it cannot be worked out from the
	 * story's address.
	 */
	public function bearcapToken(string $storyId): string {
		return hash_hmac('sha256', $storyId, $this->configService->getStorySecret());
	}

	/**
	 * Whether a token presented for a story is the one this instance minted,
	 * compared in constant time.
	 */
	public function bearcapMatches(string $storyId, string $token): bool {
		return $token !== '' && hash_equals($this->bearcapToken($storyId), $token);
	}

	/**
	 * Posts one of the viewer's own uploads as a story.
	 *
	 * @throws InvalidResourceException when the upload is unknown or there are
	 *                                  already too many live
	 */
	public function add(Person $owner, int|string $mediaNid, string $caption, int $duration): Story {
		$documents = $this->documentService->getMediaFromArray(
			[(string)$mediaNid], $owner->getPreferredUsername()
		);
		if (count($documents) !== 1) {
			throw new InvalidResourceException('unknown media');
		}

		if ($this->storiesRequest->countLiveByActor($owner->getId()) >= Story::MAX_PER_ACTOR) {
			throw new RefusalException(
				'this account already has ' . Story::MAX_PER_ACTOR . ' live 24-hour shorts',
				'shorts-full',
				null,
				['limit' => Story::MAX_PER_ACTOR]
			);
		}

		$story = new Story();
		$story->setOwnerId($owner->getId())
			->setDocumentId($documents[0]->getId())
			->setCaption($caption)
			->setDuration($duration);

		$story = $this->storiesRequest->save($story);
		$story->setSourceId($this->idOf($owner, $story->getId()));
		$this->storiesRequest->setSourceId($story->getId(), $story->getSourceId());

		$hydrated = $this->hydrate($story, $owner);
		$this->publish($owner, $hydrated);

		return $hydrated;
	}

	/**
	 * Sends a story to the people who follow the account: an `Add` addressed
	 * to the followers collection, as Pixelfed publishes one, not a `Create`.
	 * A failure here loses the delivery, not the story.
	 */
	private function publish(Person $owner, Story $story): void {
		try {
			$this->activityService->request($this->addActivity($owner, $story));
		} catch (Throwable $e) {
			$this->logger->warning('could not send a story to the followers', [
				'story' => $story->getSourceId(), 'exception' => $e,
			]);
		}
	}

	/**
	 * The `Add` a story goes out as, and the `Delete` it is withdrawn with.
	 */
	private function addActivity(Person $owner, Story $story): Add {
		$activity = new Add();
		$activity->setId($story->getSourceId() . '/activity');
		$activity->setActor($owner);
		$activity->setObject($this->asActivityPub($owner, $story));
		$this->addressFollowers($owner, $activity);

		return $activity;
	}

	private function deleteActivity(Person $owner, Story $story): Delete {
		$activity = new Delete();
		$activity->setId($story->getSourceId() . '/activity#delete');
		$activity->setActor($owner);
		$activity->setObjectId($story->getSourceId());
		$this->addressFollowers($owner, $activity);

		return $activity;
	}

	private function addressFollowers(Person $owner, ACore $activity): void {
		$activity->setTo($owner->getFollowers());
		$activity->addInstancePath(
			new InstancePath($owner->getId(), InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW)
		);
	}

	/**
	 * One story as the wire carries it.
	 */
	public function asActivityPub(Person $owner, Story $story): ApStory {
		// A story read straight off its row (the fetch route) has no photo yet,
		// and Pixelfed discards a story without one, so it is put back here.
		if ($story->getMedia() === null) {
			$this->attachMedia($story);
		}

		$object = new ApStory();
		$object->setId($story->getSourceId());
		$object->setBearcap($this->bearcapOf($story->getSourceId()));
		$object->setAttributedTo($owner->getId());
		$object->setPublished(gmdate('Y-m-d\TH:i:s\Z', $story->getCreation()));
		$object->setTo($owner->getFollowers());
		$object->setCaption($story->getCaption());
		$object->setDuration($story->getDuration());
		$object->setExpiresAt($story->getExpiresAt());

		$media = $story->getMedia();
		if ($media !== null) {
			$document = new Document();
			$document->setUrl((string)$media->getUrl());
			$document->setMediaType($media->getMediaType());
			$document->setDescription($media->getDescription());
			$object->setAttachment($document);
		}

		return $object;
	}

	/**
	 * A story that arrived from another server, kept only when somebody here
	 * follows its author. Its expiry is the author's, capped at a day; one
	 * already past is refused.
	 *
	 * @throws InvalidResourceException when there is nobody here it is for
	 */
	public function receive(ApStory $object, Person $author): Story {
		if ($object->getId() === '' || $object->getAttachment() === null) {
			throw new InvalidResourceException('a story with no picture is nothing to show');
		}

		$now = $this->timeFactory?->getTime() ?? time();
		$expires = $object->getExpiresAt();
		$created = (int)strtotime($object->getPublished());
		$created = ($created > 0) ? $created : $now;
		if ($expires <= 0) {
			$expires = $created + ApStory::MAX_LIFETIME;
		}
		$expires = min($expires, $now + ApStory::MAX_LIFETIME);
		if ($expires <= $now) {
			throw new InvalidResourceException('this story has already expired');
		}

		if ($this->followService->getFollowers($author, 1) === []) {
			throw new InvalidResourceException('nobody here follows ' . $author->getId());
		}

		try {
			return $this->storiesRequest->getBySourceId($object->getId());
		} catch (ItemNotFoundException $e) {
			// not here yet, which is the ordinary case: a fan-out reaches this
			// instance once per follower on it, and the second one finds the
			// first
		}

		$document = $object->getAttachment();
		$document->setParentId($object->getId());
		if ($document->getId() === '') {
			$document->setId($document->getUrl());
		}
		// saved as a remote document, which is to say: the row says where the
		// file is, and the copy is taken the way every other remote
		// attachment's is
		$this->documentInterface->save($document);

		$story = new Story();
		$story->setOwnerId($author->getId())
			->setDocumentId($document->getId())
			->setCaption($object->getCaption())
			->setDuration(max(Story::MIN_DURATION, min(Story::MAX_DURATION, $object->getDuration())))
			->setSourceId($object->getId())
			->setLocal(false)
			->setCreation($created)
			->setExpiresAt($expires);

		return $this->storiesRequest->save($story);
	}

	/**
	 * One story by the ActivityPub id it travels under.
	 *
	 * @throws ItemNotFoundException
	 */
	public function bySourceId(string $sourceId): Story {
		return $this->storiesRequest->getBySourceId($sourceId);
	}

	/**
	 * Whether a reader may see one story: the author or a follower, as
	 * `forAccount()` decides for the client API. A refusal is a 404, as
	 * whether an account has a story up is told only to its followers.
	 */
	public function mayRead(Story $story, Person $reader): bool {
		if ($story->getOwnerId() === $reader->getId()) {
			return true;
		}

		$owner = new Person();
		$owner->setId($story->getOwnerId());

		return $this->followService->getLinksBetweenPersons($reader, $owner)['following'] === true;
	}

	/**
	 * Takes a remote story down on its author's word.
	 */
	public function withdrawn(string $sourceId, string $actorId): void {
		try {
			$story = $this->storiesRequest->getBySourceId($sourceId);
			if ($story->getOwnerId() === $actorId) {
				$this->forgetMedia($story);
			}
		} catch (ItemNotFoundException $e) {
			// nothing here under that address, which is what a `Delete` for a
			// story this instance never kept looks like
		}

		$this->storiesRequest->deleteBySourceId($sourceId, $actorId);
	}

	/** @throws ItemNotFoundException */
	public function delete(Person $owner, int $id): void {
		// read first, so somebody else's id is a 404 rather than a delete that
		// matched nothing
		$story = $this->storiesRequest->getLiveById($id);
		if ($story->getOwnerId() !== $owner->getId()) {
			throw new ItemNotFoundException('unknown story');
		}

		$this->storiesRequest->delete($owner->getId(), $id);
		$this->forgetMedia($story);

		if ($story->getSourceId() === '') {
			return;
		}

		try {
			$this->activityService->request($this->deleteActivity($owner, $story));
		} catch (Throwable $e) {
			$this->logger->warning('could not withdraw a story from the followers', [
				'story' => $story->getSourceId(), 'exception' => $e,
			]);
		}
	}

	/**
	 * The carousel: the viewer's own live stories first, then those of the
	 * accounts they follow.
	 *
	 * @return Story[]
	 */
	public function carousel(Person $viewer): array {
		$following = [];
		foreach ($this->followService->getFollowing($viewer) as $follow) {
			$following[] = $follow->getObjectId();
		}

		$stories = array_merge(
			$this->storiesRequest->getLiveByActor($viewer->getId()),
			$this->storiesRequest->getLiveByActors($following)
		);

		return $this->hydrateAll($stories, $viewer);
	}

	/**
	 * The live stories of one account, as the viewer may see them.
	 *
	 * @return Story[]
	 *
	 * @throws ItemNotFoundException when the viewer may not see them
	 */
	public function forAccount(Person $viewer, Person $owner): array {
		if ($viewer->getId() !== $owner->getId()) {
			$links = $this->followService->getLinksBetweenPersons($viewer, $owner);
			if ($links['following'] !== true) {
				// not "forbidden": whether an account has a story up is itself
				// something only its followers are told
				throw new ItemNotFoundException('no stories');
			}
		}

		return $this->hydrateAll($this->storiesRequest->getLiveByActor($owner->getId()), $viewer);
	}

	/**
	 * Marks a story seen by the viewer.
	 *
	 * @throws ItemNotFoundException when it is unknown, expired, or not the
	 *                               viewer's to see
	 */
	public function markSeen(Person $viewer, int $id): Story {
		$story = $this->storiesRequest->getLiveById($id);

		if ($story->getOwnerId() !== $viewer->getId()) {
			$owner = new Person();
			$owner->setId($story->getOwnerId());
			$links = $this->followService->getLinksBetweenPersons($viewer, $owner);
			if ($links['following'] !== true) {
				throw new ItemNotFoundException('unknown story');
			}
		}

		$this->storiesRequest->markSeen($id, $viewer->getId());
		// a story from another server is counted there, not here
		$this->storyInteractionService->sendView($viewer, $story);

		return $this->hydrate($story, $viewer);
	}

	/** Deletes what has expired. Called from cron. */
	/**
	 * Who watched one of the owner's own stories; anybody else gets the 404 of
	 * an unknown id.
	 *
	 * @return list<Person>
	 * @throws ItemNotFoundException
	 */
	public function viewers(Person $owner, int $id): array {
		$story = $this->storiesRequest->getLiveById($id);
		if ($story->getOwnerId() !== $owner->getId()) {
			throw new ItemNotFoundException('unknown story');
		}

		$viewers = [];
		foreach ($this->storiesRequest->viewersOf($id) as $actorId) {
			try {
				$viewers[] = $this->cacheActorService->getFromId($actorId);
			} catch (\Exception $e) {
				// a viewer this server cannot name any more is left out rather
				// than drawn as a blank
			}
		}

		return $viewers;
	}

	public function purgeExpired(int $limit = 500): int {
		$expired = $this->storiesRequest->getExpired($limit);
		foreach ($expired as $story) {
			$this->forgetMedia($story);
		}

		return $this->storiesRequest->deleteByIds(
			array_map(static fn (Story $story): int => $story->getId(), $expired)
		);
	}

	/** Everything an account owns, for a deletion or a suspension. */
	public function forgetActor(string $actorId): void {
		foreach ($this->storiesRequest->getLiveByActor($actorId) as $story) {
			$this->forgetMedia($story);
		}

		$this->storiesRequest->deleteRelatedId($actorId);
	}

	/**
	 * The photo a story was posted as, removed with the story: its document
	 * row and both copies in appdata, which `/media/{uuid}` would otherwise
	 * keep serving. Only a document that belongs to the story (none or the
	 * story as parent); a photo a post also shows stays.
	 */
	private function forgetMedia(Story $story): void {
		try {
			$document = $this->documentService->getDocumentById($story->getDocumentId());
		} catch (CacheDocumentDoesNotExistException $e) {
			// already gone, which is where this was heading anyway
			return;
		}

		try {
			$parent = $document->getParentId();
			if ($parent !== '' && $parent !== $story->getSourceId()) {
				return;
			}

			$this->mediaPurgeService->purge($document);
		} catch (Throwable $e) {
			// a story whose photo is already gone still has to go, and one
			// unreadable row must not end a sweep
			$this->logger->warning('could not remove the picture of a story', [
				'story' => $story->getId(), 'exception' => $e,
			]);
		}
	}

	/**
	 * @param Story[] $stories
	 *
	 * @return Story[]
	 */
	private function hydrateAll(array $stories, Person $viewer): array {
		if ($stories === []) {
			return [];
		}

		$ids = array_map(static fn (Story $story): int => $story->getId(), $stories);
		// one query for the whole carousel, not one per story
		$seen = $this->storiesRequest->seenAmong($ids, $viewer->getId());

		foreach ($stories as $story) {
			$story->setSeen(in_array($story->getId(), $seen, true));
			$this->attachMedia($story);
			$this->attachAuthor($story);
			// Only the poster is told how many people watched: set and cleared, so
			// the guarantee does not depend on how the object was read.
			$story->setViewCount(
				($story->getOwnerId() === $viewer->getId())
					? $this->storiesRequest->countViews($story->getId())
					: 0
			);
		}

		return $stories;
	}

	private function hydrate(Story $story, Person $viewer): Story {
		return $this->hydrateAll([$story], $viewer)[0];
	}

	private function attachMedia(Story $story): void {
		try {
			$document = $this->documentService->getDocumentById($story->getDocumentId());
			$story->setMedia($document->convertToMediaAttachment($this->urlGenerator));
		} catch (\Exception $e) {
			// a story whose photo is gone is shown without one; the row is about
			// to expire anyway and an empty frame beats a 500
			$story->setMedia(null);
		}
	}

	private function attachAuthor(Story $story): void {
		try {
			$author = $this->cacheActorService->getFromId($story->getOwnerId());
			// the client format, as every other account this API hands a client:
			// the carousel draws `acct`, `display_name` and `avatar`
			$author->setExportFormat(ACore::FORMAT_LOCAL);
			$story->setAuthor($author);
		} catch (\Exception $e) {
			$story->setAuthor(null);
		}
	}
}
