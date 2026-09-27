<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Activity;

use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\Activity\AddInterface;
use OCA\Social\Interfaces\Activity\FeaturedCollection;
use OCA\Social\Interfaces\Activity\RemoveInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Add;
use OCA\Social\Model\ActivityPub\Activity\Remove;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\PinService;
use OCA\Social\Tests\Interfaces\ActivityPubTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\NullLogger;

require_once __DIR__ . '/../ActivityPubTestCase.php';

/**
 * A remote account pinning and unpinning its own posts: `Add`/`Remove` with
 * the actor's `featured` collection as `target`. Mastodon sends `object` as a
 * bare URI, which is why both used to do nothing at all.
 */
#[AllowMockObjectsWithoutExpectations]
class FeaturedCollectionTest extends ActivityPubTestCase {
	private const ACTOR = self::REMOTE_URL . '/users/bob';
	private const FEATURED = self::REMOTE_URL . '/users/bob/collections/featured';
	private const POST = self::REMOTE_URL . '/users/bob/statuses/1';

	/** @var CacheActorsRequest&MockObject */
	private $cacheActorsRequest;
	/** @var StreamRequest&Stub */
	private $streamRequest;
	/** @var ActionsRequest&MockObject */
	private $actionsRequest;
	/** @var CurlService&MockObject */
	private $curlService;

	private FeaturedCollection $featured;

	protected function setUp(): void {
		parent::setUp();

		$this->cacheActorsRequest = $this->createMock(CacheActorsRequest::class);
		$this->streamRequest = $this->createStub(StreamRequest::class);
		$this->actionsRequest = $this->createMock(ActionsRequest::class);

		$this->curlService = $this->createMock(CurlService::class);
		$this->featured = new FeaturedCollection(
			$this->cacheActorsRequest,
			$this->streamRequest,
			$this->actionsRequest,
			new NullLogger(),
			$this->curlService
		);
	}

	private function bob(string $featured = self::FEATURED): Person {
		$actor = new Person();
		$actor->setId(self::ACTOR);
		$actor->setFeatured($featured);

		return $actor;
	}

	/** @param string[] $objectIds what is pinned here right now */
	private function pinned(array $objectIds): void {
		$pins = [];
		foreach ($objectIds as $objectId) {
			$pin = new Like();
			$pin->setType(PinService::TYPE);
			$pin->setActorId(self::ACTOR);
			$pin->setObjectId($objectId);
			$pins[] = $pin;
		}
		$this->actionsRequest->method('getActionsByActor')->with(self::ACTOR, PinService::TYPE)->willReturn($pins);
	}

	// refresh(): reading the collection itself

	public function testRefreshPinsWhatTheCollectionNamesAndWeHold(): void {
		$this->postKnown();
		$this->actionsRequest->method('getAction')->willThrowException(new ActionDoesNotExistException());
		$this->pinned([]);
		$this->curlService->method('retrieveObject')->with(self::FEATURED)
			->willReturn(['type' => 'OrderedCollection', 'orderedItems' => [self::POST]]);
		/** @var ACore|null $saved */
		$saved = null;
		$this->capture($this->actionsRequest, 'save', $saved);

		$this->assertSame(1, $this->featured->refresh($this->bob()));

		$this->assertNotNull($saved);
		$this->assertSame(self::POST, $saved->getObjectId());
	}

	public function testRefreshTakesDownAPinTheCollectionNoLongerHas(): void {
		$this->postKnown();
		$this->pinned([self::POST, self::REMOTE_URL . '/users/bob/statuses/old']);
		$this->actionsRequest->method('getAction')->willReturn(new Like());
		$this->curlService->method('retrieveObject')
			->willReturn(['type' => 'OrderedCollection', 'orderedItems' => [self::POST]]);
		$this->actionsRequest->expects($this->once())->method('deleteAction')
			->with(self::ACTOR, self::REMOTE_URL . '/users/bob/statuses/old', PinService::TYPE);

		$this->assertSame(1, $this->featured->refresh($this->bob()));
	}

	public function testRefreshFollowsThePagedCollectionToItsFirstPage(): void {
		$this->postKnown();
		$this->actionsRequest->method('getAction')->willThrowException(new ActionDoesNotExistException());
		$this->pinned([]);
		$this->curlService->method('retrieveObject')->willReturnMap([
			[self::FEATURED, true, ['type' => 'OrderedCollection', 'first' => self::FEATURED . '?page=1']],
			[self::FEATURED . '?page=1', true, ['type' => 'OrderedCollectionPage', 'orderedItems' => [['id' => self::POST, 'type' => 'Note']]]],
		]);

		$this->assertSame(1, $this->featured->refresh($this->bob()));
	}

	public function testRefreshLeavesTheStoredPinsAloneWhenTheCollectionCannotBeRead(): void {
		$this->curlService->method('retrieveObject')->willThrowException(new \RuntimeException('down'));
		$this->actionsRequest->expects($this->never())->method('deleteAction');
		$this->actionsRequest->expects($this->never())->method('save');

		$this->assertSame(-1, $this->featured->refresh($this->bob()));
	}

	public function testRefreshDoesNothingForAnActorWithoutACollection(): void {
		$this->curlService->expects($this->never())->method('retrieveObject');

		$this->assertSame(0, $this->featured->refresh($this->bob('')));
	}

	public function testRefreshDoesNotPinAPostWeDoNotHoldAndWasNotEmbedded(): void {
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->pinned([]);
		$this->curlService->method('retrieveObject')
			->willReturn(['type' => 'OrderedCollection', 'orderedItems' => [self::POST]]);
		$this->actionsRequest->expects($this->never())->method('save');

		$this->assertSame(0, $this->featured->refresh($this->bob()));
	}

	public function testRefreshRefusesAnEmbeddedPostBySomebodyElse(): void {
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->pinned([]);
		$this->curlService->method('retrieveObject')->willReturn(['type' => 'OrderedCollection', 'orderedItems' => [
			['id' => self::POST, 'type' => 'Note', 'attributedTo' => self::REMOTE_URL . '/users/carol'],
		]]);
		$this->actionsRequest->expects($this->never())->method('save');

		$this->assertSame(0, $this->featured->refresh($this->bob()));
	}

	private function actorKnown(string $featured = self::FEATURED): void {
		$actor = new Person();
		$actor->setId(self::ACTOR);
		$actor->setFeatured($featured);
		$this->cacheActorsRequest->method('getFromId')->with(self::ACTOR)->willReturn($actor);
	}

	private function postKnown(string $author = self::ACTOR): void {
		$note = new Note();
		$note->setId(self::POST);
		$note->setAttributedTo($author);
		$this->streamRequest->method('getStreamById')->willReturn($note);
	}

	private function notPinnedYet(): void {
		$this->actionsRequest->method('getAction')
			->willThrowException(new ActionDoesNotExistException());
		$this->actionsRequest->method('getActionsByActor')->willReturn([]);
	}

	/** @return Add|Remove */
	private function collectionActivity(string $type, string $target, string $objectId): ACore {
		$activity = $this->incoming($type, self::REMOTE_URL . '/activities/1', self::ACTOR);
		$activity->setObjectId($objectId);
		$activity->setTarget($target);

		return $activity;
	}

	public function testAddWithABareObjectUriPinsThePost(): void {
		$this->actorKnown();
		$this->postKnown();
		$this->notPinnedYet();

		/** @var ACore|null $saved */
		$saved = null;
		$this->capture($this->actionsRequest, 'save', $saved);

		(new AddInterface($this->featured))->processIncomingRequest(
			$this->collectionActivity(Add::TYPE, self::FEATURED, self::POST)
		);

		$this->assertNotNull($saved);
		$this->assertSame(PinService::TYPE, $saved->getType());
		$this->assertSame(self::ACTOR, $saved->getActorId());
		$this->assertSame(self::POST, $saved->getObjectId());
		// the id has to name the actor too, or two accounts pinning the same
		// post would collide on the row id
		$this->assertSame(self::POST . '#pin/' . md5(self::ACTOR), $saved->getId());
	}

	public function testRemoveUnpinsThePost(): void {
		$this->actorKnown();
		$this->postKnown();

		$this->actionsRequest->expects($this->once())
			->method('deleteAction')
			->with(self::ACTOR, self::POST, PinService::TYPE);
		$this->actionsRequest->expects($this->never())->method('save');

		(new RemoveInterface($this->featured))->processIncomingRequest(
			$this->collectionActivity(Remove::TYPE, self::FEATURED, self::POST)
		);
	}

	public function testAddWithAnEmbeddedObjectPinsThePost(): void {
		$this->actorKnown();
		$this->postKnown();
		$this->notPinnedYet();

		$note = new Note();
		$note->setId(self::POST);
		$activity = $this->incoming(Add::TYPE, self::REMOTE_URL . '/activities/1', self::ACTOR, $note);
		$activity->setTarget(self::FEATURED);

		$this->actionsRequest->expects($this->once())->method('save');

		(new AddInterface($this->featured))->processIncomingRequest($activity);
	}

	public function testAddTargetingAnythingElseThanTheFeaturedCollectionIsIgnored(): void {
		$this->actorKnown();
		$this->actionsRequest->expects($this->never())->method('save');
		$this->actionsRequest->expects($this->never())->method('deleteAction');

		(new AddInterface($this->featured))->processIncomingRequest(
			$this->collectionActivity(Add::TYPE, self::REMOTE_URL . '/users/bob/collections/tags', self::POST)
		);
	}

	public function testAddWithoutATargetIsIgnored(): void {
		$this->actionsRequest->expects($this->never())->method('save');

		(new AddInterface($this->featured))->processIncomingRequest(
			$this->collectionActivity(Add::TYPE, '', self::POST)
		);
	}

	public function testAddFromAnActorWeDoNotKnowIsIgnored(): void {
		$this->cacheActorsRequest->method('getFromId')
			->willThrowException(new CacheActorDoesNotExistException());
		$this->actionsRequest->expects($this->never())->method('save');

		(new AddInterface($this->featured))->processIncomingRequest(
			$this->collectionActivity(Add::TYPE, self::FEATURED, self::POST)
		);
	}

	public function testAddOfAPostWeDoNotHoldIsIgnored(): void {
		$this->actorKnown();
		$this->streamRequest->method('getStreamById')
			->willThrowException(new StreamNotFoundException());
		$this->actionsRequest->expects($this->never())->method('save');

		(new AddInterface($this->featured))->processIncomingRequest(
			$this->collectionActivity(Add::TYPE, self::FEATURED, self::POST)
		);
	}

	/**
	 * The featured collection is the actor's own, so it can only hold the
	 * actor's own posts — otherwise an account could pin a stranger's post to
	 * its profile.
	 */
	public function testAddOfSomebodyElsesPostIsRefused(): void {
		$this->actorKnown();
		$this->postKnown(self::REMOTE_URL . '/users/carol');
		$this->actionsRequest->expects($this->never())->method('save');

		(new AddInterface($this->featured))->processIncomingRequest(
			$this->collectionActivity(Add::TYPE, self::FEATURED, self::POST)
		);
	}

	public function testAddFromAnotherHostThanTheActorIsRefused(): void {
		$this->expectException(InvalidOriginException::class);

		$activity = $this->incoming(
			Add::TYPE, self::REMOTE_URL . '/activities/1', 'https://elsewhere.example/users/bob'
		);
		$activity->setObjectId(self::POST);
		$activity->setTarget(self::FEATURED);

		(new AddInterface($this->featured))->processIncomingRequest($activity);
	}

	public function testAlreadyPinnedPostIsNotPinnedTwice(): void {
		$this->actorKnown();
		$this->postKnown();
		$this->actionsRequest->method('getAction')->willReturn(new Note());
		$this->actionsRequest->expects($this->never())->method('save');

		(new AddInterface($this->featured))->processIncomingRequest(
			$this->collectionActivity(Add::TYPE, self::FEATURED, self::POST)
		);
	}

	public function testAPeerCannotPinWithoutLimit(): void {
		$this->actorKnown();
		$this->postKnown();
		$this->actionsRequest->method('getAction')
			->willThrowException(new ActionDoesNotExistException());
		$this->actionsRequest->method('getActionsByActor')
			->willReturn(array_fill(0, FeaturedCollection::MAX_REMOTE_PINS, new Note()));
		$this->actionsRequest->expects($this->never())->method('save');

		(new AddInterface($this->featured))->processIncomingRequest(
			$this->collectionActivity(Add::TYPE, self::FEATURED, self::POST)
		);
	}
}
