<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\CollectionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Collection;

/**
 * Albums an account curates out of its own posts. Only the owner's posts:
 * collecting other people's would republish them at the album's visibility,
 * which cannot be taken back once peers mirrored the page (Pixelfed has the
 * same rule).
 */
class CollectionService {
	/** How many posts a collection hands back as its cover. */
	public const PREVIEW_SIZE = 3;

	public function __construct(
		private CollectionsRequest $collectionsRequest,
		private StreamRequest $streamRequest,
		private FollowService $followService,
		private CacheActorService $cacheActorService,
	) {
	}

	/**
	 * @throws InvalidResourceException when the title is empty or there are
	 *                                  already too many
	 */
	public function create(Person $owner, string $title, string $description, string $visibility): Collection {
		$title = trim($title);
		if ($title === '') {
			throw new InvalidResourceException('a collection needs a title');
		}

		if (count($this->collectionsRequest->getByActor($owner->getId())) >= CollectionsRequest::MAX_PER_ACTOR) {
			throw new InvalidResourceException(
				'this account already has ' . CollectionsRequest::MAX_PER_ACTOR . ' collections'
			);
		}

		$collection = new Collection();
		$collection->setOwnerId($owner->getId())
			->setTitle($title)
			->setDescription($description)
			->setVisibility($visibility);

		return $this->collectionsRequest->save($collection);
	}

	/** @throws ItemNotFoundException */
	public function own(Person $owner, int $id): Collection {
		return $this->collectionsRequest->getOwnedById($owner->getId(), $id);
	}

	/**
	 * Sets the reader the collection's posts are read for, explicitly even for a
	 * visitor, since the request service keeps its viewer between reads.
	 */
	private function readAs(?Person $viewer): void {
		if ($viewer === null) {
			$this->collectionsRequest->resetViewer();
		} else {
			$this->collectionsRequest->setViewer($viewer);
		}
	}

	/**
	 * A collection as a reader may see it. A followers-only collection is shown
	 * only to its owner and their followers, never to a signed-out visitor.
	 *
	 * @throws ItemNotFoundException when it does not exist or may not be seen
	 */
	public function readable(?Person $viewer, int $id): Collection {
		$this->readAs($viewer);
		$collection = $this->collectionsRequest->getById($id);

		if ($collection->isPublic()) {
			return $collection;
		}

		if ($viewer === null) {
			throw new ItemNotFoundException('unknown collection');
		}

		if ($viewer->getId() === $collection->getOwnerId()) {
			return $collection;
		}

		$links = $this->followService->getLinksBetweenPersons($viewer, $this->ownerOf($collection));
		if ($links['following'] !== true) {
			throw new ItemNotFoundException('unknown collection');
		}

		return $collection;
	}

	/**
	 * Every collection of an account, as a reader may see them.
	 *
	 * @return Collection[]
	 */
	public function forProfile(?Person $viewer, Person $owner): array {
		$this->readAs($viewer);
		$isOwner = $viewer !== null && $viewer->getId() === $owner->getId();
		if ($isOwner) {
			return $this->collectionsRequest->getByActor($owner->getId());
		}

		$canSeeFollowersOnly = false;
		if ($viewer !== null) {
			$links = $this->followService->getLinksBetweenPersons($viewer, $owner);
			$canSeeFollowersOnly = ($links['following'] === true);
		}

		return $canSeeFollowersOnly
			? $this->collectionsRequest->getByActor($owner->getId())
			: $this->collectionsRequest->getByActor($owner->getId(), true);
	}

	/**
	 * @throws ItemNotFoundException
	 * @throws InvalidResourceException
	 */
	public function update(Person $owner, int $id, ?string $title, ?string $description, ?string $visibility): Collection {
		$collection = $this->own($owner, $id);

		if ($title !== null) {
			if (trim($title) === '') {
				throw new InvalidResourceException('a collection needs a title');
			}
			$collection->setTitle($title);
		}
		if ($description !== null) {
			$collection->setDescription($description);
		}
		if ($visibility !== null) {
			$collection->setVisibility($visibility);
		}

		$this->collectionsRequest->update($collection);

		return $collection;
	}

	/** @throws ItemNotFoundException */
	public function delete(Person $owner, int $id): void {
		$this->collectionsRequest->delete($this->own($owner, $id));
	}

	/**
	 * Adds one of the owner's own posts.
	 *
	 * @throws ItemNotFoundException when the collection or the post is unknown
	 * @throws InvalidResourceException when the post is not the owner's, or the
	 *                                  collection is full
	 */
	public function addPost(Person $owner, int $id, int|string $nid): Collection {
		$collection = $this->own($owner, $id);
		$post = $this->ownPost($owner, $nid);

		if ($this->collectionsRequest->hasItem($collection, $post->getId())) {
			return $collection;
		}

		if ($this->collectionsRequest->countItems($collection) >= CollectionsRequest::MAX_ITEMS) {
			throw new InvalidResourceException(
				'a collection holds at most ' . CollectionsRequest::MAX_ITEMS . ' posts'
			);
		}

		$this->collectionsRequest->addItem($collection, $post->getId());

		return $this->own($owner, $id);
	}

	/** @throws ItemNotFoundException */
	public function removePost(Person $owner, int $id, int|string $nid): Collection {
		$collection = $this->own($owner, $id);

		try {
			$post = $this->streamRequest->getStreamByNid($nid);
			$this->collectionsRequest->removeItem($collection, $post->getId());
		} catch (\Exception $e) {
			// A deleted post is already out of the collection.
		}

		return $this->own($owner, $id);
	}

	/**
	 * The posts of a collection, in the owner's order.
	 *
	 * @return Stream[]
	 */
	public function posts(Collection $collection, int $limit = CollectionsRequest::MAX_ITEMS, int $offset = 0): array {
		return $this->collectionsRequest->getItems($collection, $limit, $offset);
	}

	/** Fills in the first few posts, which is what a cover is drawn from. */
	public function withPreview(Collection $collection): Collection {
		$preview = $this->posts($collection, self::PREVIEW_SIZE);
		foreach ($preview as $post) {
			$post->setExportFormat(ACore::FORMAT_LOCAL);
		}

		try {
			$collection->setAccount($this->cacheActorService->getFromId($collection->getOwnerId()));
		} catch (\Exception $e) {
			// The owner cannot be named any more; the collection still stands.
			$collection->setAccount(null);
		}

		return $collection->setPreview($preview);
	}

	/** Takes a deleted post out of every collection holding it. */
	public function forgetPost(string $streamId): void {
		$this->collectionsRequest->removeStream($streamId);
	}

	/** Everything an account owns, for a deletion or a suspension. */
	public function forgetActor(string $actorId): void {
		$this->collectionsRequest->deleteRelatedId($actorId);
	}

	/**
	 * @throws ItemNotFoundException when there is no such post
	 * @throws InvalidResourceException when it belongs to somebody else
	 */
	private function ownPost(Person $owner, int|string $nid): Stream {
		try {
			$post = $this->streamRequest->getStreamByNid($nid);
		} catch (\Exception $e) {
			throw new ItemNotFoundException('unknown post');
		}

		if ($post->getAttributedTo() !== $owner->getId()) {
			// A collection of other people's posts would re-publish them under
			// somebody else's page, at whatever visibility that page has.
			throw new InvalidResourceException('a collection may only hold your own posts');
		}

		return $post;
	}

	/** @throws ItemNotFoundException */
	private function ownerOf(Collection $collection): Person {
		$owner = new Person();
		$owner->setId($collection->getOwnerId());

		return $owner;
	}
}
