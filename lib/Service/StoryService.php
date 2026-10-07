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
use OCA\Social\Interfaces\Object\DocumentInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Add;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Story as ApStory;
use OCA\Social\Model\Client\Story;
use OCA\Social\Model\InstancePath;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stories: one picture that stops existing after a day.
 *
 * Who may see one is the same question as who may see a followers-only post,
 * and it is answered the same way: the owner, and the accounts that follow
 * them. There is no public story. That is Pixelfed's rule and it is the one
 * people assume when they post something that disappears -- a story visible to
 * the open internet, cached by whatever crawled it, is exactly what the feature
 * promises not to be.
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
	) {
	}

	/**
	 * The ActivityPub id a local story is published under.
	 *
	 * Built from the account and the row's own number, which is why it can
	 * only be set once the row exists. It resolves: a peer that would rather
	 * fetch a story than trust the copy it was handed gets it from
	 * `ActivityPubController::story()`, under the same rule the API keeps —
	 * the author, or somebody who follows them.
	 */
	public function idOf(Person $owner, int $id): string {
		return $this->configService->getSocialUrl() . '@' . $owner->getPreferredUsername() . '/stories/' . $id;
	}

	/**
	 * The capability a story travels with: `bear:?t=<token>&u=<url>`.
	 *
	 * Pixelfed's inbox does not read a story out of the activity that carries
	 * it. `StoryFetch` requires `object.object`, decodes it as a bearcap
	 * (FEP-d8c2), and fetches what it names with the token — an `Add` without
	 * one is dropped without a word, which is what this app's stories used to
	 * be. The field order is Pixelfed's own `Bearcap::encode()`, and its
	 * decoder splits on `&` without unescaping, so neither half may be
	 * URL-encoded.
	 */
	public function bearcapOf(string $storyId): string {
		return 'bear:?t=' . $this->bearcapToken($storyId) . '&u=' . $storyId;
	}

	/**
	 * The token that fetches one story, and nothing else.
	 *
	 * Derived rather than stored: a story lives a day and there would be one
	 * row per story to write, expire and clean up for a value that can be
	 * recomputed. Keyed on a secret of this instance's own, so a token cannot
	 * be worked out from the story's address, and it stops working for every
	 * story at once if that secret is ever changed.
	 */
	public function bearcapToken(string $storyId): string {
		return hash_hmac('sha256', $storyId, $this->configService->getStorySecret());
	}

	/**
	 * Whether a token presented for a story is the one this instance minted.
	 *
	 * Compared in constant time: the comparison is against a secret-derived
	 * value, and a fetch is something anybody may attempt as often as they
	 * like.
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
			throw new InvalidResourceException(
				'this account already has ' . Story::MAX_PER_ACTOR . ' live 24-hour shorts'
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
	 * Sends a story to the people who follow the account.
	 *
	 * An `Add` addressed to the followers collection, which is the verb
	 * Pixelfed publishes a story with and the one its inbox handles — not a
	 * `Create`, which would put it where posts go. A server that does not know
	 * the type ignores it, which is the right outcome: a story is not a post
	 * and should not become one.
	 *
	 * A failure here is not a failure of the story. It is up on this instance
	 * either way, and a delivery that could not be queued is a delivery that
	 * did not happen, not a story that did not.
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
		// A story read straight off its row has no picture on it yet: the
		// timeline paths hydrate before they hand one over and the fetch route
		// does not, because it looks a story up by its address. A story
		// serialised without its picture is not a story — the receiver has
		// nothing to show and Pixelfed discards it — so the picture is put back
		// here rather than depended on.
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
	 * A story that arrived from another server.
	 *
	 * Kept only when somebody here follows its author. A story is published to
	 * followers, so an instance that holds one for an account nobody here
	 * follows is holding a picture it was never going to show — and an inbox
	 * that stores whatever is sent to it is a place to put things.
	 *
	 * The expiry is the author's, bounded: this app holds no story longer than
	 * a day whatever the sender says, and refuses one that is already past.
	 *
	 * @throws InvalidResourceException when there is nobody here it is for
	 */
	public function receive(ApStory $object, Person $author): Story {
		if ($object->getId() === '' || $object->getAttachment() === null) {
			throw new InvalidResourceException('a story with no picture is nothing to show');
		}

		$expires = $object->getExpiresAt();
		$created = (int)strtotime($object->getPublished());
		$created = ($created > 0) ? $created : time();
		if ($expires <= 0) {
			$expires = $created + ApStory::MAX_LIFETIME;
		}
		$expires = min($expires, time() + ApStory::MAX_LIFETIME);
		if ($expires <= time()) {
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
	 * Whether a reader may see one story.
	 *
	 * The author, or somebody who follows them — the same rule `forAccount()`
	 * keeps for the client API, applied to the reader a signed fetch resolved
	 * to. Whether an account even has a story up is told to its followers and
	 * to nobody else, which is why a refusal is a 404 rather than a 403.
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
		// reads it first so that somebody else's id is a 404 rather than a
		// delete that quietly matched nothing
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
		// a story from another server is counted there, not here: its author
		// reads their own instance's number, and without a receipt sent to
		// them watching it is invisible
		$this->storyInteractionService->sendView($viewer, $story);

		return $this->hydrate($story, $viewer);
	}

	/** Deletes what has expired. Called from cron. */
	/**
	 * Who watched one of the owner's own stories.
	 *
	 * Owner only, and the same 404 as an unknown id for anybody else: who
	 * watched is told to the poster and to nobody else, the way the view count
	 * is.
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
	 * The picture a story was posted as, taken away with the story.
	 *
	 * The promise of a story is that it stops existing after a day, and until
	 * this ran that was true of the row and false of everything else: the
	 * document row and both copies in appdata stayed, `/media/{uuid}` went on
	 * serving them with a cache lifetime of for ever, and anyone who had ever
	 * been shown the story — every viewer's client, every follower's instance
	 * — could still load it. The disk was never reclaimed either.
	 *
	 * Only a document that belongs to this story: one posted as a story has no
	 * parent, and one that arrived with a story names the story as its parent.
	 * Anything else is a picture some post is also showing, and a story is not
	 * a reason to take a post's picture away.
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
			// a story whose picture is already gone is a story that still has
			// to go, and one unreadable row must not end a sweep
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
			// Only the poster is told how many people watched. Set *and*
			// cleared, rather than only set: leaving whatever the object
			// happened to carry would make the guarantee depend on the story
			// having been freshly read, which is not something a caller can see.
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
			// a story whose picture is gone is a story with no picture; the row
			// is about to expire anyway and an empty frame beats a 500
			$story->setMedia(null);
		}
	}

	private function attachAuthor(Story $story): void {
		try {
			$author = $this->cacheActorService->getFromId($story->getOwnerId());
			// the client format, as every other account this API hands a
			// client is: an actor in ActivityPub shape has no `acct`, no
			// `display_name` and no `avatar`, which is everything a story
			// carousel draws
			$author->setExportFormat(ACore::FORMAT_LOCAL);
			$story->setAuthor($author);
		} catch (\Exception $e) {
			$story->setAuthor(null);
		}
	}
}
