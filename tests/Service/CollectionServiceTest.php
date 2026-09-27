<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\CollectionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\Collection;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CollectionService;
use OCA\Social\Service\FollowService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class CollectionServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example.org/users/alice';
	private const BOB = 'https://cloud.example.org/users/bob';

	private CollectionsRequest|MockObject $collectionsRequest;
	private StreamRequest|Stub $streamRequest;
	private FollowService|Stub $followService;
	private CollectionService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->collectionsRequest = $this->createMock(CollectionsRequest::class);
		$this->streamRequest = $this->createStub(StreamRequest::class);
		$this->followService = $this->createStub(FollowService::class);
		$this->service = new CollectionService(
			$this->collectionsRequest, $this->streamRequest, $this->followService,
			$this->createStub(CacheActorService::class)
		);
	}

	private function person(string $id): Person {
		$person = new Person();
		$person->setId($id);

		return $person;
	}

	private function collection(string $owner, string $visibility = 'public', int $id = 1): Collection {
		return (new Collection())->setId($id)->setOwnerId($owner)->setVisibility($visibility);
	}

	private function note(string $author, string $id = 'https://cloud.example.org/notes/1'): Note {
		$note = new Note();
		$note->setId($id);
		$note->setAttributedTo($author);

		return $note;
	}

	public function testACollectionNeedsATitle(): void {
		$this->expectException(InvalidResourceException::class);

		$this->service->create($this->person(self::ALICE), '   ', '', 'public');
	}

	public function testAnAccountCannotKeepUnlimitedCollections(): void {
		$this->collectionsRequest->method('getByActor')
			->willReturn(array_fill(0, CollectionsRequest::MAX_PER_ACTOR, new Collection()));

		$this->expectException(InvalidResourceException::class);
		$this->expectExceptionMessageMatches('/already has/');

		$this->service->create($this->person(self::ALICE), 'one more', '', 'public');
	}

	/**
	 * The rule the whole feature rests on. A collection of somebody else's
	 * pictures would re-publish them on a page with a visibility they never
	 * agreed to, and the peers that mirrored the page would keep them.
	 */
	public function testACollectionMayOnlyHoldItsOwnersPosts(): void {
		$this->collectionsRequest->method('getOwnedById')->willReturn($this->collection(self::ALICE));
		$this->streamRequest->method('getStreamByNid')->willReturn($this->note(self::BOB));

		$this->expectException(InvalidResourceException::class);
		$this->expectExceptionMessageMatches('/your own posts/');

		$this->service->addPost($this->person(self::ALICE), 1, 99);
	}

	public function testAddingAPostAlreadyInTheCollectionChangesNothing(): void {
		$this->collectionsRequest->method('getOwnedById')->willReturn($this->collection(self::ALICE));
		$this->streamRequest->method('getStreamByNid')->willReturn($this->note(self::ALICE));
		$this->collectionsRequest->method('hasItem')->willReturn(true);
		$this->collectionsRequest->expects($this->never())->method('addItem');

		$this->service->addPost($this->person(self::ALICE), 1, 99);
	}

	public function testACollectionHasACeilingOnWhatItHolds(): void {
		$this->collectionsRequest->method('getOwnedById')->willReturn($this->collection(self::ALICE));
		$this->streamRequest->method('getStreamByNid')->willReturn($this->note(self::ALICE));
		$this->collectionsRequest->method('hasItem')->willReturn(false);
		$this->collectionsRequest->method('countItems')->willReturn(CollectionsRequest::MAX_ITEMS);

		$this->expectException(InvalidResourceException::class);
		$this->expectExceptionMessageMatches('/at most/');

		$this->service->addPost($this->person(self::ALICE), 1, 99);
	}

	public function testAPublicCollectionIsReadableBySomebodySignedOut(): void {
		$this->collectionsRequest->method('getById')->willReturn($this->collection(self::ALICE, 'public'));

		$this->assertSame(1, $this->service->readable(null, 1)->getId());
	}

	/**
	 * The case that matters, because a signed-out reader is what a search
	 * engine is.
	 */
	public function testAFollowersOnlyCollectionIsNotReadableBySomebodySignedOut(): void {
		$this->collectionsRequest->method('getById')->willReturn($this->collection(self::ALICE, 'followers'));

		$this->expectException(ItemNotFoundException::class);

		$this->service->readable(null, 1);
	}

	public function testAFollowersOnlyCollectionIsReadableByItsOwner(): void {
		$this->collectionsRequest->method('getById')->willReturn($this->collection(self::ALICE, 'followers'));

		$this->assertSame(1, $this->service->readable($this->person(self::ALICE), 1)->getId());
	}

	public function testAFollowersOnlyCollectionIsReadableByAFollower(): void {
		$this->collectionsRequest->method('getById')->willReturn($this->collection(self::ALICE, 'followers'));
		$this->followService->method('getLinksBetweenPersons')
			->willReturn(['follower' => false, 'following' => true]);

		$this->assertSame(1, $this->service->readable($this->person(self::BOB), 1)->getId());
	}

	public function testAFollowersOnlyCollectionIsNotReadableByAStranger(): void {
		$this->collectionsRequest->method('getById')->willReturn($this->collection(self::ALICE, 'followers'));
		$this->followService->method('getLinksBetweenPersons')
			->willReturn(['follower' => false, 'following' => false]);

		$this->expectException(ItemNotFoundException::class);

		$this->service->readable($this->person(self::BOB), 1);
	}

	/** A stranger reading a profile sees only the public albums. */
	public function testAProfileShowsAStrangerOnlyThePublicCollections(): void {
		$this->followService->method('getLinksBetweenPersons')
			->willReturn(['follower' => false, 'following' => false]);
		$this->collectionsRequest->expects($this->once())
			->method('getByActor')
			->with(self::ALICE, true)
			->willReturn([]);

		$this->service->forProfile($this->person(self::BOB), $this->person(self::ALICE));
	}

	public function testAProfileShowsItsOwnerEverything(): void {
		$this->collectionsRequest->expects($this->once())
			->method('getByActor')
			->with(self::ALICE)
			->willReturn([]);

		$this->service->forProfile($this->person(self::ALICE), $this->person(self::ALICE));
	}

	public function testRemovingAPostThatIsAlreadyGoneIsNotAFailure(): void {
		$this->collectionsRequest->method('getOwnedById')->willReturn($this->collection(self::ALICE));
		$this->streamRequest->method('getStreamByNid')
			->willThrowException(new ItemNotFoundException('gone'));

		$this->service->removePost($this->person(self::ALICE), 1, 99);
		$this->addToAssertionCount(1);
	}
}
