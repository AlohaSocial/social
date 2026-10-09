<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\AP;
use OCA\Social\Atproto\Reader\BlueskyGraphService;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Exceptions\BlockedByException;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Interfaces\Object\FollowInterface;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Service\AccountRelationService;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\BlockedBy\BlockedByService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\ModerationService;
use OCA\Social\Service\TimelineRevisionService;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A follow of an account that has blocked the follower is refused with the
 * reason, wherever the account is; a profile looked at asks whether it has.
 */
#[AllowMockObjectsWithoutExpectations]
class FollowServiceBlockedByTest extends TestCase {
	/** @var FollowsRequest&MockObject */
	private FollowsRequest $followsRequest;
	/** @var ActivityService&MockObject */
	private ActivityService $activityService;
	/** @var BlueskyGraphService&MockObject */
	private BlueskyGraphService $graph;
	/** @var BlockedByService&MockObject */
	private BlockedByService $blockedBy;
	/** @var CacheActorService&MockObject */
	private CacheActorService $cacheActorService;
	private FollowService $service;
	private Person $alice;

	protected function setUp(): void {
		$this->followsRequest = $this->createMock(FollowsRequest::class);
		$this->activityService = $this->createMock(ActivityService::class);
		$this->graph = $this->createMock(BlueskyGraphService::class);
		$this->blockedBy = $this->createMock(BlockedByService::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$ap = $this->createMock(AP::class);
		$ap->method('getItemFromType')->willReturnCallback(static function (): Follow {
			$follow = new Follow();
			$follow->setUrlCloud('https://social.test');

			return $follow;
		});
		AP::set($ap);
		$this->service = new FollowService(
			$this->createMock(IURLGenerator::class),
			$this->followsRequest,
			$this->createMock(ActorRelationRequest::class),
			$this->activityService,
			$this->cacheActorService,
			$this->createStub(ConfigService::class),
			$this->createMock(FollowInterface::class),
			$this->createMock(ModerationService::class),
			$this->createMock(AccountRelationService::class),
			$this->createMock(AccountService::class),
			$this->createMock(TimelineRevisionService::class),
			new NullLogger(),
			$this->graph,
			$this->blockedBy,
		);
		$this->alice = new Person();
		$this->alice->setId('https://social.test/@alice');
		$this->alice->setLocal(true);
		$this->alice->setNid(1);
	}

	protected function tearDown(): void {
		AP::set(null);
	}

	private static function account(string $id, int $nid): Person {
		$person = new Person();
		$person->setId($id);
		$person->setNid($nid);
		$person->setInbox($id . '/inbox');
		$person->setFollowers($id . '/followers');

		return $person;
	}

	public function testAFollowOfABlueskyAccountThatHasBlockedTheFollowerIsRefused(): void {
		$bob = self::account('https://bsky.app/profile/did:plc:bob', 2);
		$this->followsRequest->method('getByPersons')->willThrowException(new FollowNotFoundException());
		$this->blockedBy->expects($this->once())->method('assertNotBlocked')->with($this->alice, [$bob->getId()])
			->willThrowException(new BlockedByException(BlockedByService::REFUSAL));
		$this->followsRequest->expects($this->never())->method('save');
		$this->graph->expects($this->never())->method('follow');

		$this->expectException(BlockedByException::class);
		$this->expectExceptionMessage('This account has blocked you');
		$this->service->followActor($this->alice, $bob);
	}

	public function testAFollowOfAFediverseAccountThatHasBlockedTheFollowerIsRefusedBeforeAnythingIsSent(): void {
		$carol = self::account('https://remote.example/users/carol', 3);
		$this->followsRequest->method('getByPersons')->willThrowException(new FollowNotFoundException());
		$this->blockedBy->method('assertNotBlocked')->willThrowException(new BlockedByException(BlockedByService::REFUSAL));
		$this->followsRequest->expects($this->never())->method('save');
		$this->activityService->expects($this->never())->method('request');

		$this->expectException(BlockedByException::class);
		$this->service->followActor($this->alice, $carol);
	}

	public function testAFollowThatIsThereAlreadyIsNotAskedAbout(): void {
		$carol = self::account('https://remote.example/users/carol', 3);
		$this->followsRequest->method('getByPersons')->willReturn(new Follow());
		$this->blockedBy->expects($this->never())->method('assertNotBlocked');

		$this->assertFalse($this->service->followActor($this->alice, $carol));
	}

	public function testAFollowOfAnAccountThatHasNotBlockedTheFollowerGoesOut(): void {
		$carol = self::account('https://remote.example/users/carol', 3);
		$this->followsRequest->method('getByPersons')->willThrowException(new FollowNotFoundException());
		$this->blockedBy->expects($this->once())->method('assertNotBlocked');
		$this->followsRequest->expects($this->once())->method('save');
		$this->activityService->expects($this->once())->method('request');

		$this->assertTrue($this->service->followActor($this->alice, $carol));
	}

	public function testAProfileLookedAtAsksWhetherItsAccountHasBlockedTheViewer(): void {
		$bob = self::account('https://bsky.app/profile/did:plc:bob', 2);
		$this->cacheActorService->method('getFromNids')->willReturn([$bob]);
		$this->blockedBy->expects($this->once())->method('ask')->with($this->alice->getId(), [$bob->getId()]);
		$this->service->setViewer($this->alice);

		$this->service->getRelationships(['2']);
	}

	public function testAPageOfAccountsAsksNobody(): void {
		$this->cacheActorService->method('getFromNids')->willReturn([
			self::account('https://bsky.app/profile/did:plc:bob', 2),
			self::account('https://remote.example/users/carol', 3),
		]);
		$this->blockedBy->expects($this->never())->method('ask');
		$this->service->setViewer($this->alice);

		$this->service->getRelationships(['2', '3']);
	}
}
