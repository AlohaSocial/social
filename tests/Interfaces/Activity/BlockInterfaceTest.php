<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Activity;

use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Interfaces\Activity\BlockInterface;
use OCA\Social\Model\ActivityPub\Activity\Block;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Service\SignatureService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class BlockInterfaceTest extends TestCase {
	private const LOCAL_ALICE = 'https://cloud.example.org/users/alice';
	private const REMOTE_BOB = 'https://remote.example/users/bob';

	/** @var ActorRelationRequest&MockObject */
	private $actorRelationRequest;
	/** @var FollowsRequest&MockObject */
	private $followsRequest;
	/** @var CacheActorsRequest&MockObject */
	private $cacheActorsRequest;
	private BlockInterface $handler;

	protected function setUp(): void {
		$this->actorRelationRequest = $this->createMock(ActorRelationRequest::class);
		$this->followsRequest = $this->createMock(FollowsRequest::class);
		$this->cacheActorsRequest = $this->createMock(CacheActorsRequest::class);

		$this->handler = new BlockInterface(
			$this->actorRelationRequest,
			$this->followsRequest,
			$this->cacheActorsRequest,
			new NullLogger()
		);
	}

	private function incomingBlock(string $actorId = self::REMOTE_BOB, string $objectId = self::LOCAL_ALICE): Block {
		$block = new Block();
		$block->setId($actorId . '#block/1');
		$block->setActorId($actorId);
		$block->setObjectId($objectId);
		$block->setOrigin(
			parse_url($actorId, PHP_URL_HOST), SignatureService::ORIGIN_HEADER, time()
		);

		return $block;
	}

	private function localAlice(): Person {
		$alice = new Person();
		$alice->setId(self::LOCAL_ALICE);
		$alice->setLocal(true);

		return $alice;
	}

	public function testIncomingBlockIsStoredAndSeversTheFollowsBothWays(): void {
		$this->cacheActorsRequest->method('getFromId')->with(self::LOCAL_ALICE)
			->willReturn($this->localAlice());
		$this->actorRelationRequest->expects($this->once())->method('save')
			->with(self::LOCAL_ALICE, self::REMOTE_BOB, ActorRelation::TYPE_BLOCKED_BY);

		$follow = new Follow();
		$deleted = [];
		$this->followsRequest->method('getByPersons')->willReturn($follow);
		$this->followsRequest->expects($this->exactly(2))->method('delete')
			->willReturnCallback(function (Follow $f) use (&$deleted): void {
				$deleted[] = $f;
			});

		$this->handler->processIncomingRequest($this->incomingBlock());

		$this->assertCount(2, $deleted);
	}

	public function testIncomingBlockForAnUnknownTargetIsIgnored(): void {
		$this->cacheActorsRequest->method('getFromId')
			->willThrowException(new CacheActorDoesNotExistException());
		$this->actorRelationRequest->expects($this->never())->method('save');

		$this->handler->processIncomingRequest($this->incomingBlock());
	}

	public function testIncomingBlockForARemoteTargetIsIgnored(): void {
		$notOurs = new Person();
		$notOurs->setId('https://third.example/users/carol');
		$notOurs->setLocal(false);
		$this->cacheActorsRequest->method('getFromId')->willReturn($notOurs);
		$this->actorRelationRequest->expects($this->never())->method('save');

		$this->handler->processIncomingRequest(
			$this->incomingBlock(self::REMOTE_BOB, 'https://third.example/users/carol')
		);
	}

	public function testIncomingBlockWithAForgedActorIsRefused(): void {
		$block = $this->incomingBlock();
		// signed by remote.example but claiming an actor on another host
		$block->setActorId('https://third.example/users/mallory');
		$this->actorRelationRequest->expects($this->never())->method('save');

		$this->expectException(InvalidOriginException::class);
		$this->handler->processIncomingRequest($block);
	}

	public function testUndoBlockLiftsTheStoredBlock(): void {
		$this->cacheActorsRequest->method('getFromId')->with(self::LOCAL_ALICE)
			->willReturn($this->localAlice());
		$this->actorRelationRequest->expects($this->once())->method('delete')
			->with(self::LOCAL_ALICE, self::REMOTE_BOB, ActorRelation::TYPE_BLOCKED_BY);

		$block = $this->incomingBlock();
		$undo = new Undo();
		$undo->setId(self::REMOTE_BOB . '#undo/block/1');
		$undo->setActorId(self::REMOTE_BOB);
		$undo->setOrigin(
			parse_url(self::REMOTE_BOB, PHP_URL_HOST), SignatureService::ORIGIN_HEADER, time()
		);

		$this->handler->activity($undo, $block);
	}

	public function testANonUndoWrapperDoesNothing(): void {
		$this->actorRelationRequest->expects($this->never())->method('delete');

		$accept = new \OCA\Social\Model\ActivityPub\Activity\Accept();
		$this->handler->activity($accept, $this->incomingBlock());
	}

	public function testFollowNotFoundWhileSeveringIsTolerated(): void {
		$this->cacheActorsRequest->method('getFromId')->willReturn($this->localAlice());
		$this->followsRequest->method('getByPersons')
			->willThrowException(new FollowNotFoundException());
		$this->actorRelationRequest->expects($this->once())->method('save');

		$this->handler->processIncomingRequest($this->incomingBlock());
	}
}
