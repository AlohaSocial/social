<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Actor;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Interfaces\Actor\PersonInterface;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\ActorCascadeService;
use OCA\Social\Service\ActorService;
use OCA\Social\Tests\Interfaces\ActivityPubTestCase;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\MockObject\MockObject;

require_once __DIR__ . '/../ActivityPubTestCase.php';

/**
 * Service, Group, Organization and Application actors are handled by
 * PersonInterface subclasses that add nothing of their own; these are the
 * behaviours every actor kind must share.
 */
abstract class ActorInterfaceTestCase extends ActivityPubTestCase {
	protected const BOB = self::REMOTE_URL . '/users/bob';

	/** @var CacheActorsRequest&MockObject */
	protected $cacheActorsRequest;
	/** @var StreamRequest&MockObject */
	protected $streamRequest;
	/** @var StreamDestRequest&MockObject */
	protected $streamDestRequest;
	/** @var ActorService&MockObject */
	protected $actorService;
	protected ActorCascadeService|MockObject $actorCascadeService;
	protected PersonInterface $handler;

	/** The handler under test, wired with the mocks above. */
	abstract protected function createHandler(): PersonInterface;

	/** An empty instance of the actor model this handler is registered for. */
	abstract protected function createActor(): Person;

	protected IJobList|MockObject $jobList;

	protected function setUp(): void {
		parent::setUp();

		$this->cacheActorsRequest = $this->createMock(CacheActorsRequest::class);
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->streamDestRequest = $this->createMock(StreamDestRequest::class);
		$this->actorCascadeService = $this->createMock(ActorCascadeService::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->actorService = $this->createMock(ActorService::class);

		$this->handler = $this->createHandler();
	}

	/** bob, a remote actor of the kind under test. */
	protected function bob(): Person {
		$bob = $this->createActor();
		$bob->setAccount('bob@remote.example')
			->setInbox(self::BOB . '/inbox')
			->setFollowers(self::BOB . '/followers')
			->setFollowing(self::BOB . '/following');
		$bob->setId(self::BOB);

		return $bob;
	}

	protected function nothingCached(): void {
		$this->cacheActorsRequest->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());
	}

	public function testGetItemByIdReturnsTheCachedActor(): void {
		$bob = $this->bob();
		$this->cacheActorsRequest->method('getFromId')->with(self::BOB)->willReturn($bob);

		$this->assertSame($bob, $this->handler->getItemById(self::BOB));
	}

	public function testGetItemByIdThrowsWhenTheActorIsNotCached(): void {
		$this->nothingCached();

		$this->expectException(ItemNotFoundException::class);

		$this->handler->getItemById(self::BOB);
	}

	public function testSaveOfAnUnknownActorCreatesIt(): void {
		$this->nothingCached();
		$bob = $this->bob();

		$this->actorService->expects($this->once())->method('save')->with($this->identicalTo($bob));
		$this->actorService->expects($this->never())->method('update');

		$this->handler->save($bob);
	}

	public function testSaveOfACachedActorUpdatesIt(): void {
		$bob = $this->bob();
		$this->cacheActorsRequest->method('getFromId')->willReturn($bob);

		$this->actorService->expects($this->once())->method('update')->with($this->identicalTo($bob));
		$this->actorService->expects($this->never())->method('save');

		$this->handler->save($bob);
	}

	/**
	 * What an account leaves behind is one list, shared with the suspension
	 * path; `ActorCascadeServiceTest` pins what is on it. Here: that the
	 * deletion asks for all of it, and for the version that spares nothing —
	 * a deleted account is not coming back.
	 */
	public function testDeleteActivityWipesEverythingTheActorLeftBehind(): void {
		$bob = $this->bob();
		$delete = $this->incoming(Delete::TYPE, self::BOB . '#delete', self::BOB, $bob);
		$this->streamDestRequest->method('getRelatedToActor')->willReturn([]);

		$this->actorCascadeService->expects($this->once())->method('purge')->with(self::BOB);
		$this->streamRequest->expects($this->once())->method('deleteByAuthor')->with(self::BOB);
		$this->streamDestRequest->expects($this->once())->method('deleteRelatedToActor')->with(self::BOB);

		$this->handler->activity($delete, $bob);
	}

	/**
	 * The origin check is host-wide, and a server holds more than one account:
	 * without an actor-level check any of them could delete any other, or hand
	 * `updateActor()` a profile carrying its own public key under a
	 * neighbour's id and sign as that neighbour from then on.
	 */
	public function testDeleteOfANeighbouringAccountIsRefused(): void {
		$bob = $this->bob();
		$mallory = self::REMOTE_URL . '/users/mallory';
		$delete = $this->incoming(Delete::TYPE, $mallory . '#delete', $mallory, $bob);

		$this->cacheActorsRequest->expects($this->never())->method('deleteCacheById');
		$this->streamRequest->expects($this->never())->method('deleteByAuthor');

		$this->expectException(InvalidOriginException::class);

		$this->handler->activity($delete, $bob);
	}

	public function testUpdateOfANeighboursProfileIsRefused(): void {
		$bob = $this->bob();
		$bob->setPublicKey('-----BEGIN PUBLIC KEY-----mallory');
		$mallory = self::REMOTE_URL . '/users/mallory';
		$update = $this->incoming(Update::TYPE, $mallory . '#update', $mallory, $bob);

		$this->cacheActorsRequest->expects($this->never())->method('update');
		$this->cacheActorsRequest->expects($this->never())->method('save');

		$this->expectException(InvalidOriginException::class);

		$this->handler->activity($update, $bob);
	}

	public function testAnAccountUpdatingItsOwnProfileIsApplied(): void {
		$bob = $this->bob();
		$update = $this->incoming(Update::TYPE, self::BOB . '#update', self::BOB, $bob);
		$stored = $this->bob();
		$stored->setCreation(1);
		$this->cacheActorsRequest->method('getFromId')->with(self::BOB)->willReturn($stored);

		$this->actorService->expects($this->once())->method('update')->with($this->identicalTo($bob));

		$this->handler->activity($update, $bob);
	}
}
