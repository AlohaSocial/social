<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CollectionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\Collection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Who may read the posts inside a collection.
 *
 * A collection says which posts its owner grouped together; it says nothing
 * about who may read them. The routes that serve one are unauthenticated, so
 * the per-post predicate is the only thing standing between a followers-only
 * post dropped into a public album and the whole internet.
 *
 * This needs a real database: what is being asserted is which rows a WHERE
 * clause returns for a given viewer, which no mock can answer.
 *
 * All rows carry unique '-colvis-' ids and are removed in tearDown.
 */
class CollectionItemVisibilityTest extends TestCase {
	private const BASE = 'https://cloud.example.org/colvis';
	private const OWNER = self::BASE . '/users/owner';
	private const OWNER_FOLLOWERS = self::OWNER . '/followers';
	private const STRANGER = 'https://remote.example/colvis/users/stranger';

	private const NOTES = ['open', 'followers-only'];

	private StreamRequest $streamRequest;
	private CollectionsRequest $collectionsRequest;
	private CacheActorsRequest $cacheActorsRequest;
	private int $collectionId = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->collectionsRequest = Server::get(CollectionsRequest::class);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach (self::NOTES as $suffix) {
			$this->streamRequest->deleteById(self::BASE . '/notes/' . $suffix, Note::TYPE);
		}
		foreach ([self::OWNER, self::STRANGER] as $id) {
			$this->cacheActorsRequest->deleteCacheById($id);
		}
		$this->collectionsRequest->deleteRelatedId(self::OWNER);
		$this->collectionId = 0;
	}

	private function person(string $id, string $username, bool $local = false): Person {
		$person = new Person();
		$person->setId($id)->setPreferredUsername($username);
		$person->setAccount($username . '@' . parse_url($id, PHP_URL_HOST))
			->setFollowers($id . '/followers')
			->setFollowing($id . '/following')
			->setInbox($id . '/inbox')
			->setOutbox($id . '/outbox')
			->setLocal($local);
		$this->cacheActorsRequest->save($person);

		return $person;
	}

	private function note(string $suffix, string $to): Note {
		$note = new Note();
		$note->setId(self::BASE . '/notes/' . $suffix);
		$note->setAttributedTo(self::OWNER);
		$note->setTo($to);
		$note->setContent('<p>' . $suffix . '</p>');
		$note->setPublishedTime(time());
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$this->streamRequest->save($note);

		return $note;
	}

	/** An album holding both posts, the way its owner would build one. */
	private function album(): Collection {
		$collection = new Collection();
		$collection->setOwnerId(self::OWNER)
			->setTitle('colvis album')
			->setVisibility(Collection::VISIBILITY_PUBLIC);
		$collection = $this->collectionsRequest->save($collection);
		$this->collectionId = $collection->getId();

		foreach (self::NOTES as $suffix) {
			$this->collectionsRequest->addItem($collection, self::BASE . '/notes/' . $suffix);
		}

		return $collection;
	}

	/** @return string[] the ids the collection hands back, for whoever is asking */
	private function itemsFor(?Person $viewer): array {
		if ($viewer === null) {
			$this->streamRequest->resetViewer();
		} else {
			$this->streamRequest->setViewer($viewer);
		}

		$collection = $this->collectionsRequest->getById($this->collectionId);

		return array_map(
			static fn ($stream): string => $stream->getId(),
			$this->collectionsRequest->getItems($collection)
		);
	}

	/**
	 * The one that matters: a visitor with no session reads a public album and
	 * gets only the posts that were published to everybody.
	 */
	public function testAVisitorSeesOnlyThePublicPostsOfAPublicAlbum(): void {
		$this->person(self::OWNER, 'owner', true);
		$this->note('open', ACore::CONTEXT_PUBLIC);
		$this->note('followers-only', self::OWNER_FOLLOWERS);
		$this->album();

		$ids = $this->itemsFor(null);

		$this->assertContains(self::BASE . '/notes/open', $ids);
		$this->assertNotContains(
			self::BASE . '/notes/followers-only',
			$ids,
			'a followers-only post in a public album was served to a visitor'
		);
	}

	/** And a signed-in stranger who follows nobody is in the same position. */
	public function testAStrangerSeesOnlyThePublicPosts(): void {
		$this->person(self::OWNER, 'owner', true);
		$stranger = $this->person(self::STRANGER, 'stranger');
		$this->note('open', ACore::CONTEXT_PUBLIC);
		$this->note('followers-only', self::OWNER_FOLLOWERS);
		$this->album();

		$ids = $this->itemsFor($stranger);

		$this->assertSame([self::BASE . '/notes/open'], $ids);
	}

	/**
	 * And the predicate does not empty the album of what was always readable:
	 * a public post stays, in the owner's own order.
	 */
	public function testThePublicPostsAreStillThereInTheOwnersOrder(): void {
		$this->person(self::OWNER, 'owner', true);
		$this->note('open', ACore::CONTEXT_PUBLIC);
		$this->note('followers-only', ACore::CONTEXT_PUBLIC);
		$this->album();

		$ids = $this->itemsFor(null);

		$this->assertSame(
			[self::BASE . '/notes/open', self::BASE . '/notes/followers-only'],
			$ids
		);
	}
}
