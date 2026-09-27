<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Object;

use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Interfaces\Object\FollowInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Accept;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\Reject;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\InstancePath;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Service\TimelineRevisionService;
use OCA\Social\Tests\Interfaces\ActivityPubTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;

require_once __DIR__ . '/../ActivityPubTestCase.php';

#[AllowMockObjectsWithoutExpectations]
class FollowInterfaceTest extends ActivityPubTestCase {
	/** @var FollowsRequest&MockObject */
	private $followsRequest;
	/** @var ActorRelationRequest&MockObject */
	private $actorRelationRequest;
	/** @var ActorsRequest&MockObject */
	private $actorsRequest;
	/** @var CacheActorService&Stub */
	private $cacheActorService;
	/** @var AccountService&MockObject */
	private $accountService;
	/** @var ActivityService&MockObject */
	private $activityService;
	/** @var MiscService&MockObject */
	private $miscService;
	private TimelineRevisionService|Stub $timelineRevisionService;
	private FollowInterface $handler;

	private Person $alice;
	private Person $bob;
	private Person $carol;

	protected function setUp(): void {
		parent::setUp();

		$this->followsRequest = $this->createMock(FollowsRequest::class);
		$this->actorRelationRequest = $this->createMock(ActorRelationRequest::class);
		$this->actorsRequest = $this->createMock(ActorsRequest::class);
		$this->cacheActorService = $this->createStub(CacheActorService::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->activityService = $this->createMock(ActivityService::class);
		$this->miscService = $this->createMock(MiscService::class);
		$this->timelineRevisionService = $this->createStub(TimelineRevisionService::class);

		$this->handler = new FollowInterface(
			$this->followsRequest,
			$this->actorRelationRequest,
			$this->actorsRequest,
			$this->cacheActorService,
			$this->accountService,
			$this->activityService,
			$this->timelineRevisionService,
			$this->miscService,
		);

		$this->alice = $this->person(self::LOCAL_URL . '/users/alice', true);
		$this->alice->setPreferredUsername('alice');
		$this->bob = $this->person(self::REMOTE_URL . '/users/bob');
		$this->carol = $this->person('https://other.example/users/carol');

		$known = [$this->alice, $this->bob, $this->carol];
		$this->cacheActorService->method('getFromId')->willReturnCallback(function (string $id) use ($known): Person {
			foreach ($known as $actor) {
				if ($actor->getId() === $id) {
					return $actor;
				}
			}

			throw new CacheActorDoesNotExistException();
		});
	}

	/** bob (remote) asks to follow a target, alice (local) unless told otherwise. */
	private function incomingFollow(?string $target = null, string $origin = self::REMOTE_HOST): Follow {
		$follow = new Follow();
		$follow->setId(self::REMOTE_URL . '/follows/1');
		$follow->setActorId($this->bob->getId());
		$follow->setObjectId($target ?? $this->alice->getId());
		$follow->setOrigin($origin, SignatureService::ORIGIN_HEADER, time());

		return $follow;
	}

	/** alice's own follow request towards bob, as stored on our side. */
	private function ourFollowOfBob(): Follow {
		$follow = new Follow();
		$follow->setId(self::LOCAL_URL . '/follows/7');
		$follow->setActorId($this->alice->getId());
		$follow->setObjectId($this->bob->getId());

		return $follow;
	}

	private function noKnownFollow(): void {
		$this->followsRequest->method('getByPersons')->willThrowException(new FollowNotFoundException());
	}

	/** marks alice's actor row (the local source of truth) as locked. */
	private function lockAlice(): void {
		$row = new Person();
		$row->setLocked(true);
		$this->actorsRequest->method('getFromUsername')->with('alice')->willReturn($row);
	}

	public function testNewFollowOfALocalActorIsStoredAgainstTheirFollowersCollection(): void {
		$this->noKnownFollow();
		$follow = $this->incomingFollow();

		$this->followsRequest->expects($this->once())->method('save')->with($this->identicalTo($follow));

		$this->handler->processIncomingRequest($follow);

		$this->assertSame($this->alice->getFollowers(), $follow->getFollowId());
	}

	public function testNewFollowIsAnsweredWithAnAcceptDeliveredToTheFollowersInbox(): void {
		$this->noKnownFollow();
		$follow = $this->incomingFollow();

		$sent = null;
		$this->capture($this->activityService, 'request', $sent, '');

		$this->handler->processIncomingRequest($follow);

		$this->assertInstanceOf(Accept::class, $sent);
		$this->assertSame($this->alice->getId(), $sent->getActorId());
		$this->assertSame($follow, $sent->getObject());
		$this->assertStringStartsWith(self::LOCAL_URL . '/', $sent->getId());

		$paths = $sent->getInstancePaths();
		$this->assertCount(1, $paths);
		$this->assertSame($this->bob->getInbox(), $paths[0]->getUri());
		$this->assertSame(InstancePath::TYPE_INBOX, $paths[0]->getType());
	}

	public function testNewFollowIsMarkedAcceptedAndRefreshesTheLocalCounters(): void {
		$this->noKnownFollow();
		$follow = $this->incomingFollow();

		$this->followsRequest->expects($this->once())->method('accepted')->with($this->identicalTo($follow));
		$this->accountService->expects($this->once())
			->method('bumpActorCount')->with($this->alice->getId(), 'count_followers', 1);

		$this->handler->processIncomingRequest($follow);
	}

	public function testNewFollowNotifiesTheFollowedLocalActor(): void {
		$this->noKnownFollow();
		$follow = $this->incomingFollow();

		$notification = null;
		$this->capture($this->notificationInterface, 'save', $notification);

		$this->handler->processIncomingRequest($follow);

		$this->assertInstanceOf(SocialAppNotification::class, $notification);
		$this->assertSame(Follow::TYPE, $notification->getSubType());
		$this->assertSame($this->alice->getId(), $notification->getTo());
		$this->assertSame($this->bob->getId(), $notification->getActorId());
		$this->assertSame($follow->getId() . '/notification', $notification->getId());
		$this->assertSame('bob@remote.example', $notification->getDetailsAll()['account']);
		$this->assertTrue($notification->isLocal());
	}

	public function testFollowFromAnActorBlockedByTheTargetIsRejected(): void {
		$this->noKnownFollow();
		$follow = $this->incomingFollow();
		$this->actorRelationRequest->method('exists')
			->with($this->alice->getId(), $this->bob->getId(), ActorRelation::TYPE_BLOCK)
			->willReturn(true);

		$this->followsRequest->expects($this->never())->method('save');
		$this->followsRequest->expects($this->never())->method('accepted');
		$this->followsRequest->expects($this->once())
			->method('deleteByPersons')->with($this->identicalTo($follow));
		$this->notificationInterface->expects($this->never())->method('save');

		$sent = null;
		$this->capture($this->activityService, 'request', $sent, '');

		$this->handler->processIncomingRequest($follow);

		$this->assertInstanceOf(Reject::class, $sent);
		$this->assertSame($this->alice->getId(), $sent->getActorId());
		$this->assertSame($follow, $sent->getObject());
		$this->assertStringStartsWith(self::LOCAL_URL . '/', $sent->getId());

		$paths = $sent->getInstancePaths();
		$this->assertCount(1, $paths);
		$this->assertSame($this->bob->getInbox(), $paths[0]->getUri());
		$this->assertSame(InstancePath::TYPE_INBOX, $paths[0]->getType());
	}

	public function testFollowFromANonBlockedActorConsultsTheBlockListAndIsAccepted(): void {
		$this->noKnownFollow();
		$follow = $this->incomingFollow();
		$this->actorRelationRequest->expects($this->once())
			->method('exists')
			->with($this->alice->getId(), $this->bob->getId(), ActorRelation::TYPE_BLOCK)
			->willReturn(false);

		$this->followsRequest->expects($this->once())->method('save')->with($this->identicalTo($follow));
		$this->followsRequest->expects($this->never())->method('deleteByPersons');
		$this->activityService->expects($this->once())->method('request')->with($this->isInstanceOf(Accept::class));

		$this->handler->processIncomingRequest($follow);
	}

	public function testFollowOfARemoteActorIsRefused(): void {
		$this->noKnownFollow();

		$this->followsRequest->expects($this->never())->method('save');
		$this->followsRequest->expects($this->never())->method('accepted');
		$this->activityService->expects($this->never())->method('request');

		$this->handler->processIncomingRequest($this->incomingFollow($this->carol->getId()));
	}

	public function testFollowOfAnUnknownActorIsRefused(): void {
		$this->noKnownFollow();

		$this->followsRequest->expects($this->never())->method('save');
		$this->activityService->expects($this->never())->method('request');

		$this->expectException(CacheActorDoesNotExistException::class);

		$this->handler->processIncomingRequest($this->incomingFollow(self::LOCAL_URL . '/users/nobody'));
	}

	public function testFollowNotSignedByTheFollowersServerIsRefused(): void {
		$this->followsRequest->expects($this->never())->method('getByPersons');
		$this->followsRequest->expects($this->never())->method('save');

		$this->expectException(InvalidOriginException::class);

		$this->handler->processIncomingRequest($this->incomingFollow(null, 'evil.example'));
	}

	public function testFollowAlreadyAcceptedIsIgnored(): void {
		$known = new Follow();
		$known->setAccepted(true);
		$this->followsRequest->method('getByPersons')
			->with($this->bob->getId(), $this->alice->getId())
			->willReturn($known);

		$this->followsRequest->expects($this->never())->method('save');
		$this->followsRequest->expects($this->never())->method('accepted');
		$this->activityService->expects($this->never())->method('request');

		$this->handler->processIncomingRequest($this->incomingFollow());
	}

	public function testFollowStillPendingGetsTheAcceptAgainWithoutBeingStoredTwice(): void {
		$this->followsRequest->method('getByPersons')->willReturn(new Follow());
		$follow = $this->incomingFollow();

		$this->followsRequest->expects($this->never())->method('save');
		$this->activityService->expects($this->once())->method('request')->with($this->isInstanceOf(Accept::class));
		$this->followsRequest->expects($this->once())->method('accepted')->with($this->identicalTo($follow));

		$this->handler->processIncomingRequest($follow);
	}

	public function testFollowOfALockedAccountIsStoredButNotAccepted(): void {
		$this->noKnownFollow();
		$this->lockAlice();
		$follow = $this->incomingFollow();

		$this->followsRequest->expects($this->once())->method('save')->with($this->identicalTo($follow));
		$this->followsRequest->expects($this->never())->method('accepted');
		$this->activityService->expects($this->never())->method('request');

		$this->handler->processIncomingRequest($follow);

		$this->assertSame($this->alice->getFollowers(), $follow->getFollowId());
	}

	public function testFollowOfALockedAccountRaisesAFollowRequestNotification(): void {
		$this->noKnownFollow();
		$this->lockAlice();
		$follow = $this->incomingFollow();

		$notification = null;
		$this->capture($this->notificationInterface, 'save', $notification);

		$this->handler->processIncomingRequest($follow);

		$this->assertInstanceOf(SocialAppNotification::class, $notification);
		$this->assertSame(Follow::TYPE_REQUEST, $notification->getSubType());
		$this->assertSame($this->alice->getId(), $notification->getTo());
		$this->assertSame($this->bob->getId(), $notification->getActorId());
		$this->assertStringContainsString('wants to follow', $notification->getSummary());
	}

	public function testResentFollowOfALockedAccountStaysPending(): void {
		$this->lockAlice();
		$this->followsRequest->method('getByPersons')->willReturn(new Follow());

		$this->followsRequest->expects($this->never())->method('save');
		$this->followsRequest->expects($this->never())->method('accepted');
		$this->activityService->expects($this->never())->method('request');
		$this->notificationInterface->expects($this->never())->method('save');

		$this->handler->processIncomingRequest($this->incomingFollow());
	}

	public function testConfirmingAPendingFollowSendsTheAcceptAndNotifies(): void {
		$this->lockAlice();
		$follow = $this->incomingFollow();

		$sent = null;
		$this->capture($this->activityService, 'request', $sent, '');
		$notification = null;
		$this->capture($this->notificationInterface, 'save', $notification);
		$this->followsRequest->expects($this->once())->method('accepted')->with($this->identicalTo($follow));

		$this->handler->confirmFollowRequest($follow);

		$this->assertInstanceOf(Accept::class, $sent);
		$this->assertSame($follow, $sent->getObject());
		$this->assertInstanceOf(SocialAppNotification::class, $notification);
		$this->assertSame(Follow::TYPE, $notification->getSubType());
	}

	public function testRejectingAPendingFollowSendsARejectAndDeletesTheRow(): void {
		$this->lockAlice();
		$follow = $this->incomingFollow();

		$sent = null;
		$this->capture($this->activityService, 'request', $sent, '');
		$this->followsRequest->expects($this->once())
			->method('deleteByPersons')->with($this->identicalTo($follow));
		$this->followsRequest->expects($this->never())->method('accepted');

		$this->handler->rejectFollowRequest($follow);

		$this->assertInstanceOf(Reject::class, $sent);
		$this->assertSame($follow, $sent->getObject());
		$this->assertSame($this->alice->getId(), $sent->getActorId());

		$paths = $sent->getInstancePaths();
		$this->assertCount(1, $paths);
		$this->assertSame($this->bob->getInbox(), $paths[0]->getUri());
	}

	/**
	 * Whether the Accept reached the follower's server is a question for the
	 * delivery queue, not for whether this server considers the follow
	 * accepted. Delivery used to run first and take the accepted flag, the
	 * follower count and the notification down with it when it threw, so a
	 * peer that was briefly unreachable left the row pending for ever with
	 * nothing to retry it.
	 */
	public function testAFailedAcceptDeliveryIsLoggedButTheFollowIsStillAccepted(): void {
		$this->noKnownFollow();
		$this->activityService->method('request')->willThrowException(new \RuntimeException('inbox unreachable'));

		$this->followsRequest->expects($this->once())->method('save');
		$this->followsRequest->expects($this->once())->method('accepted');
		$this->notificationInterface->expects($this->once())->method('save');
		$this->miscService->expects($this->once())
			->method('log')->with($this->stringContains('sending an Accept'));

		$this->handler->processIncomingRequest($this->incomingFollow());
	}

	public function testUndoRemovesTheFollow(): void {
		$follow = $this->incomingFollow();
		$undo = $this->incoming(Undo::TYPE, self::REMOTE_URL . '/undo/1', $this->bob->getId(), $follow);

		$this->followsRequest->expects($this->once())->method('delete')->with($this->identicalTo($follow));
		$this->followsRequest->expects($this->never())->method('accepted');

		$this->handler->activity($undo, $follow);
	}

	/**
	 * The Accept counted the follower up; nothing counted it back down, so a
	 * local account's follower count only ever climbed between recounts.
	 */
	public function testUndoOfAnAcceptedFollowTakesTheFollowerOffTheCount(): void {
		$follow = $this->incomingFollow();
		$stored = clone $follow;
		$stored->setAccepted(true);
		$this->followsRequest->method('getByPersons')->willReturn($stored);
		$undo = $this->incoming(Undo::TYPE, self::REMOTE_URL . '/undo/1', $this->bob->getId(), $follow);

		$this->accountService->expects($this->once())
			->method('bumpActorCount')
			->with($follow->getObjectId(), 'count_followers', -1);

		$this->handler->activity($undo, $follow);
	}

	/** A follow request that was never accepted was never counted either. */
	public function testUndoOfAFollowRequestLeavesTheCountAlone(): void {
		$follow = $this->incomingFollow();
		$stored = clone $follow;
		$stored->setAccepted(false);
		$this->followsRequest->method('getByPersons')->willReturn($stored);
		$undo = $this->incoming(Undo::TYPE, self::REMOTE_URL . '/undo/1', $this->bob->getId(), $follow);

		$this->accountService->expects($this->never())->method('bumpActorCount');

		$this->handler->activity($undo, $follow);
	}

	public function testUndoNotComingFromTheFollowersServerIsRefused(): void {
		$follow = $this->incomingFollow();
		$undo = $this->incoming(Undo::TYPE, self::REMOTE_URL . '/undo/1', $this->bob->getId(), $follow, 'evil.example');

		$this->followsRequest->expects($this->never())->method('delete');

		$this->expectException(InvalidOriginException::class);

		$this->handler->activity($undo, $follow);
	}

	public function testAcceptMarksOurFollowRequestAccepted(): void {
		$follow = $this->ourFollowOfBob();
		$accept = $this->incoming(Accept::TYPE, self::REMOTE_URL . '/accepts/1', $this->bob->getId(), $follow);

		$this->followsRequest->expects($this->once())->method('accepted')->with($this->identicalTo($follow));
		$this->followsRequest->expects($this->never())->method('delete');

		$this->handler->activity($accept, $follow);
	}

	public function testAcceptNotComingFromTheFollowedActorsServerIsRefused(): void {
		$follow = $this->ourFollowOfBob();
		$accept = $this->incoming(Accept::TYPE, 'https://evil.example/accepts/1', $this->bob->getId(), $follow);

		$this->followsRequest->expects($this->never())->method('accepted');

		$this->expectException(InvalidOriginException::class);

		$this->handler->activity($accept, $follow);
	}

	public function testRejectRemovesOurPendingFollowRequest(): void {
		$follow = $this->ourFollowOfBob();
		$reject = $this->incoming(Reject::TYPE, self::REMOTE_URL . '/rejects/1', $this->bob->getId(), $follow);

		$this->followsRequest->expects($this->once())->method('delete')->with($this->identicalTo($follow));
		$this->followsRequest->expects($this->never())->method('accepted');

		$this->handler->activity($reject, $follow);
	}

	public function testOtherActivitiesLeaveTheFollowUntouched(): void {
		$follow = $this->ourFollowOfBob();
		/** @var ACore $create */
		$create = $this->incoming(Create::TYPE, self::REMOTE_URL . '/creates/1', $this->bob->getId(), $follow);

		$this->followsRequest->expects($this->never())->method('delete');
		$this->followsRequest->expects($this->never())->method('accepted');

		$this->handler->activity($create, $follow);
	}
}
