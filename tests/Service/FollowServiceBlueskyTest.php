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
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Interfaces\Object\FollowInterface;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Service\AccountRelationService;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActivityService;
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
 * Following a Bluesky account: accepted at once, counted, handed to the
 * graph service, and nothing queued for delivery — there is no inbox.
 */
#[AllowMockObjectsWithoutExpectations]
class FollowServiceBlueskyTest extends TestCase {
	/** @var FollowsRequest&MockObject */
	private FollowsRequest $followsRequest;
	/** @var ActivityService&MockObject */
	private ActivityService $activityService;
	/** @var AccountService&MockObject */
	private AccountService $accountService;
	/** @var BlueskyGraphService&MockObject */
	private BlueskyGraphService $graph;
	/** @var CacheActorService&MockObject */
	private CacheActorService $cacheActorService;
	private FollowService $service;
	private Person $alice;
	private Person $bob;

	protected function setUp(): void {
		$this->followsRequest = $this->createMock(FollowsRequest::class);
		$this->activityService = $this->createMock(ActivityService::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->graph = $this->createMock(BlueskyGraphService::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$ap = $this->createMock(AP::class);
		$ap->method('getItemFromType')->willReturnCallback(static function (string $type) {
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
			$this->accountService,
			$this->createMock(TimelineRevisionService::class),
			new NullLogger(),
			$this->graph,
		);
		$this->alice = new Person();
		$this->alice->setId('https://social.test/@alice');
		$this->alice->setLocal(true);
		$this->bob = new Person();
		$this->bob->setId('https://bsky.app/profile/did:plc:ewvi7nxzyoun6zhxrhs64oiz');
		$this->bob->setAccount('bob.bsky.social');
		$this->bob->setFollowers('https://bsky.app/profile/did:plc:ewvi7nxzyoun6zhxrhs64oiz/followers');
	}

	protected function tearDown(): void {
		AP::set(null);
	}

	public function testAFollowIsAcceptedAtOnceAndNeverDelivered(): void {
		$this->followsRequest->method('getByPersons')->willThrowException(new FollowNotFoundException());
		$saved = null;
		$this->followsRequest->expects($this->once())->method('save')->willReturnCallback(static function (Follow $follow) use (&$saved): void {
			$saved = $follow;
		});
		$this->followsRequest->expects($this->once())->method('accepted');
		$this->accountService->expects($this->once())->method('bumpActorCount')->with($this->bob->getId(), 'count_followers', 1);
		$this->graph->expects($this->once())->method('follow')->with($this->alice, $this->bob, $this->isInstanceOf(Follow::class));
		$this->activityService->expects($this->never())->method('request');

		$this->assertTrue($this->service->followActor($this->alice, $this->bob));
		$this->assertTrue($saved->isAccepted());
		$this->assertSame($this->bob->getFollowers(), $saved->getFollowId(), 'the home timeline joins on the followers collection');
		$this->assertStringStartsWith('https://social.test/', $saved->getId());
	}

	public function testFollowingTwiceDoesNothing(): void {
		$this->followsRequest->method('getByPersons')->willReturn(new Follow());
		$this->followsRequest->expects($this->never())->method('save');
		$this->graph->expects($this->never())->method('follow');

		$this->assertFalse($this->service->followActor($this->alice, $this->bob));
	}

	public function testAnUnfollowRemovesTheRowAndTellsTheGraphWithoutAnUndo(): void {
		$follow = new Follow();
		$follow->setAccepted(true);
		$this->cacheActorService->method('getFromAccount')->with('bob.bsky.social')->willReturn($this->bob);
		$this->followsRequest->method('getByPersons')->willReturn($follow);
		$this->followsRequest->expects($this->once())->method('delete')->with($follow);
		$this->accountService->expects($this->once())->method('bumpActorCount')->with($this->bob->getId(), 'count_followers', -1);
		$this->graph->expects($this->once())->method('unfollow')->with($this->alice, $this->bob, $follow);
		$this->activityService->expects($this->never())->method('request');

		$this->assertTrue($this->service->unfollowAccount($this->alice, 'bob.bsky.social'));
	}
}
