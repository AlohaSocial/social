<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\AP;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Block;
use OCA\Social\Model\ActivityPub\Activity\Reject;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\InstancePath;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\RelationshipService;
use OCA\Social\Service\TimelineRevisionService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

#[AllowMockObjectsWithoutExpectations]
class RelationshipServiceTest extends TestCase {
	private const CLOUD_URL = 'https://cloud.example';
	private const ALICE_ID = 'https://social.example/@alice';
	private const BOB_ID = 'https://remote.example/users/bob';
	private const CAROL_ID = 'https://social.example/@carol';

	/** @var ActorRelationRequest&MockObject */
	private $actorRelationRequest;
	/** @var FollowsRequest&MockObject */
	private $followsRequest;
	/** @var ActivityService&MockObject */
	private $activityService;
	/** @var CacheActorService&MockObject */
	private $cacheActorService;
	/** @var ConfigService&Stub */
	private $configService;
	private TimelineRevisionService|MockObject $timelineRevisionService;
	/** @var LoggerInterface&MockObject */
	private $logger;
	private RelationshipService $service;

	protected function setUp(): void {
		$this->bootActivityPub();

		$this->actorRelationRequest = $this->createMock(ActorRelationRequest::class);
		$this->followsRequest = $this->createMock(FollowsRequest::class);
		$this->activityService = $this->createMock(ActivityService::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->configService = $this->createStub(ConfigService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->timelineRevisionService = $this->createMock(TimelineRevisionService::class);

		$this->service = new RelationshipService(
			$this->actorRelationRequest,
			$this->followsRequest,
			$this->activityService,
			$this->cacheActorService,
			$this->configService,
			$this->timelineRevisionService,
			$this->logger
		);
	}

	protected function tearDown(): void {
		AP::set(null);
	}

	/**
	 * A real AP with every ActivityPub interface mocked, so getItemFromType()
	 * behaves as in production (including the cloud url needed for ids).
	 */
	private function bootActivityPub(): void {
		$args = [];
		foreach ((new ReflectionClass(AP::class))->getConstructor()->getParameters() as $parameter) {
			$class = $parameter->getType()->getName();
			$mock = $this->createStub($class);
			if ($class === ConfigService::class) {
				$mock->method('getCloudUrl')->willReturn(self::CLOUD_URL);
			}
			$args[] = $mock;
		}
		AP::set(new AP(...$args));
	}

	private function person(string $id, bool $local = false): Person {
		$person = new Person();
		$person->setId($id);
		$person->setInbox($id . '/inbox');
		$person->setLocal($local);

		return $person;
	}

	private function alice(): Person {
		return $this->person(self::ALICE_ID, true);
	}

	private function bob(): Person {
		return $this->person(self::BOB_ID);
	}

	private function follow(string $actorId, string $objectId): Follow {
		$follow = new Follow();
		$follow->setId(self::CLOUD_URL . '/follow/' . md5($actorId . $objectId));
		$follow->setActorId($actorId);
		$follow->setObjectId($objectId);

		return $follow;
	}

	/** Both follow directions between alice and bob exist and can be looked up. */
	private function mutualFollows(Follow $outgoing, Follow $incoming): void {
		$this->followsRequest->method('getByPersons')
			->willReturnCallback(function (string $actorId, string $remoteId) use ($outgoing, $incoming): Follow {
				if ($actorId === self::ALICE_ID && $remoteId === self::BOB_ID) {
					return $outgoing;
				}
				if ($actorId === self::BOB_ID && $remoteId === self::ALICE_ID) {
					return $incoming;
				}
				throw new FollowNotFoundException();
			});
	}

	private function noFollows(): void {
		$this->followsRequest->method('getByPersons')->willThrowException(new FollowNotFoundException());
	}

	/**
	 * Collects every activity handed to ActivityService::request() into $sent.
	 *
	 * @param ACore[] $sent filled as the service runs
	 */
	private function captureRequests(array &$sent): void {
		$this->activityService->method('request')
			->willReturnCallback(function (ACore $activity) use (&$sent): string {
				$sent[] = $activity;

				return 'token';
			});
	}

	/**
	 * @param class-string $class
	 */
	private function assertOneOfType(array $activities, string $class): ACore {
		$matching = array_values(array_filter(
			$activities,
			fn (ACore $activity): bool => get_class($activity) === $class
		));
		$this->assertCount(1, $matching, 'expected exactly one ' . $class . ' to be federated');

		return $matching[0];
	}

	private function assertDeliveredToInbox(ACore $activity, string $inbox): void {
		$paths = $activity->getInstancePaths();
		$this->assertCount(1, $paths);
		$this->assertSame($inbox, $paths[0]->getUri());
		$this->assertSame(InstancePath::TYPE_INBOX, $paths[0]->getType());
		$this->assertSame(InstancePath::PRIORITY_TOP, $paths[0]->getPriority());
	}

	// mute() / unmute()

	/**
	 * @return array<string, array{bool}>
	 */
	public static function notificationsFlagProvider(): array {
		return [
			'hiding notifications' => [true],
			'keeping notifications' => [false],
		];
	}

	#[DataProvider('notificationsFlagProvider')]
	public function testMuteSavesAMuteRowWithTheNotificationsFlag(bool $notifications): void {
		$this->actorRelationRequest->expects($this->once())
			->method('save')
			->with(self::ALICE_ID, self::BOB_ID, ActorRelation::TYPE_MUTE, $notifications);
		$this->activityService->expects($this->never())->method('request');
		$this->followsRequest->expects($this->never())->method('getByPersons');
		$this->followsRequest->expects($this->never())->method('delete');

		$this->service->mute($this->alice(), $this->bob(), $notifications);
	}

	public function testMuteHidesNotificationsByDefault(): void {
		$this->actorRelationRequest->expects($this->once())
			->method('save')
			->with(self::ALICE_ID, self::BOB_ID, ActorRelation::TYPE_MUTE, true);

		$this->service->mute($this->alice(), $this->bob());
	}

	public function testUnmuteDeletesExactlyTheMuteRow(): void {
		$this->actorRelationRequest->expects($this->once())
			->method('delete')
			->with(self::ALICE_ID, self::BOB_ID, ActorRelation::TYPE_MUTE);
		$this->actorRelationRequest->expects($this->never())->method('save');
		$this->activityService->expects($this->never())->method('request');

		$this->service->unmute($this->alice(), $this->bob());
	}

	/**
	 * Every one of the four changes what the reader's timelines may show, and
	 * none of it moves an id the polling tag is built from — see
	 * TimelineRevisionService.
	 */
	public function testEachRelationshipChangeMovesTheReadersTimelineRevision(): void {
		$this->timelineRevisionService->expects($this->exactly(4))
			->method('bumpForActor')
			->with(self::ALICE_ID);

		$this->service->mute($this->alice(), $this->bob());
		$this->service->unmute($this->alice(), $this->bob());
		$this->service->block($this->alice(), $this->bob());
		$this->service->unblock($this->alice(), $this->bob());
	}

	public function testMutingYourselfIsRefused(): void {
		$this->actorRelationRequest->expects($this->never())->method('save');

		$this->expectException(InvalidResourceException::class);

		$this->service->mute($this->alice(), $this->alice());
	}

	public function testBlockingYourselfIsRefused(): void {
		$this->actorRelationRequest->expects($this->never())->method('save');
		$this->activityService->expects($this->never())->method('request');

		$this->expectException(InvalidResourceException::class);

		$this->service->block($this->alice(), $this->alice());
	}

	// block()

	public function testBlockOfARemoteAccountSaversRowSeversFollowsAndFederates(): void {
		$this->configService->method('isBlockFederationEnabled')->willReturn(true);
		$outgoing = $this->follow(self::ALICE_ID, self::BOB_ID);
		$incoming = $this->follow(self::BOB_ID, self::ALICE_ID);
		$this->mutualFollows($outgoing, $incoming);

		$this->actorRelationRequest->expects($this->once())
			->method('save')
			->with(self::ALICE_ID, self::BOB_ID, ActorRelation::TYPE_BLOCK);

		$deleted = [];
		$this->followsRequest->expects($this->exactly(2))
			->method('delete')
			->willReturnCallback(function (Follow $follow) use (&$deleted): void {
				$deleted[] = $follow;
			});

		$sent = [];
		$this->captureRequests($sent);

		$this->service->block($this->alice(), $this->bob());

		$this->assertSame([$outgoing, $incoming], $deleted);
		$this->assertCount(3, $sent);

		$undo = $this->assertOneOfType($sent, Undo::class);
		$this->assertSame(self::ALICE_ID, $undo->getActorId());
		$this->assertSame($outgoing, $undo->getObject());
		$this->assertStringContainsString('#undo/follows/', $undo->getId());
		$this->assertDeliveredToInbox($undo, self::BOB_ID . '/inbox');

		$reject = $this->assertOneOfType($sent, Reject::class);
		$this->assertSame(self::ALICE_ID, $reject->getActorId());
		$this->assertSame($incoming, $reject->getObject());
		$this->assertStringContainsString('#reject/follows/', $reject->getId());
		$this->assertDeliveredToInbox($reject, self::BOB_ID . '/inbox');

		$block = $this->assertOneOfType($sent, Block::class);
		$this->assertSame(self::ALICE_ID, $block->getActorId());
		$this->assertSame(self::BOB_ID, $block->getObjectId());
		// the id hangs off the actor, not the cloud root: everything after `#`
		// is a fragment, so an id on the root dereferences to the Nextcloud
		// landing page rather than to the activity
		$this->assertStringStartsWith(self::ALICE_ID . '#block/', $block->getId());
		$this->assertDeliveredToInbox($block, self::BOB_ID . '/inbox');
	}

	public function testBlockWithFederationDisabledStillSeversFollowsButSendsNoBlock(): void {
		$this->configService->method('isBlockFederationEnabled')->willReturn(false);
		$outgoing = $this->follow(self::ALICE_ID, self::BOB_ID);
		$incoming = $this->follow(self::BOB_ID, self::ALICE_ID);
		$this->mutualFollows($outgoing, $incoming);

		$this->actorRelationRequest->expects($this->once())
			->method('save')
			->with(self::ALICE_ID, self::BOB_ID, ActorRelation::TYPE_BLOCK);
		$this->followsRequest->expects($this->exactly(2))->method('delete');

		$sent = [];
		$this->captureRequests($sent);

		$this->service->block($this->alice(), $this->bob());

		// The toggle gates only the Block activity: the Undo/Reject severing the
		// follow relationship is still delivered so the remote drops the follows.
		$this->assertCount(2, $sent);
		$this->assertOneOfType($sent, Undo::class);
		$this->assertOneOfType($sent, Reject::class);
		foreach ($sent as $activity) {
			$this->assertNotInstanceOf(Block::class, $activity);
		}
	}

	public function testBlockOfALocalAccountFederatesNothing(): void {
		$this->configService->method('isBlockFederationEnabled')->willReturn(true);
		$carol = $this->person(self::CAROL_ID, true);
		$outgoing = $this->follow(self::ALICE_ID, self::CAROL_ID);
		$incoming = $this->follow(self::CAROL_ID, self::ALICE_ID);
		$this->followsRequest->method('getByPersons')
			->willReturnCallback(function (string $actorId, string $remoteId) use ($outgoing, $incoming): Follow {
				return $actorId === self::ALICE_ID ? $outgoing : $incoming;
			});

		$this->actorRelationRequest->expects($this->once())
			->method('save')
			->with(self::ALICE_ID, self::CAROL_ID, ActorRelation::TYPE_BLOCK);
		$this->followsRequest->expects($this->exactly(2))->method('delete');
		$this->activityService->expects($this->never())->method('request');

		$this->service->block($this->alice(), $carol);
	}

	public function testBlockWithoutAnyFollowRelationStillSavesAndFederatesTheBlock(): void {
		$this->configService->method('isBlockFederationEnabled')->willReturn(true);
		$this->noFollows();

		$this->actorRelationRequest->expects($this->once())
			->method('save')
			->with(self::ALICE_ID, self::BOB_ID, ActorRelation::TYPE_BLOCK);
		$this->followsRequest->expects($this->never())->method('delete');

		$sent = [];
		$this->captureRequests($sent);

		$this->service->block($this->alice(), $this->bob());

		$this->assertCount(1, $sent);
		$block = $this->assertOneOfType($sent, Block::class);
		$this->assertSame(self::ALICE_ID, $block->getActorId());
		$this->assertSame(self::BOB_ID, $block->getObjectId());
		$this->assertDeliveredToInbox($block, self::BOB_ID . '/inbox');
	}

	public function testBlockHoldsLocallyWhenFederationDeliveryFails(): void {
		$this->configService->method('isBlockFederationEnabled')->willReturn(true);
		$this->noFollows();
		$this->activityService->method('request')
			->willThrowException(new \RuntimeException('remote unreachable'));

		$this->actorRelationRequest->expects($this->once())
			->method('save')
			->with(self::ALICE_ID, self::BOB_ID, ActorRelation::TYPE_BLOCK);
		$this->logger->expects($this->once())->method('warning');

		$this->service->block($this->alice(), $this->bob());
	}

	// unblock()

	public function testUnblockOfARemoteAccountDeletesTheRowAndSendsAnUndoBlock(): void {
		$this->configService->method('isBlockFederationEnabled')->willReturn(true);

		$this->actorRelationRequest->expects($this->once())
			->method('delete')
			->with(self::ALICE_ID, self::BOB_ID, ActorRelation::TYPE_BLOCK);
		$this->followsRequest->expects($this->never())->method('getByPersons');
		$this->followsRequest->expects($this->never())->method('delete');

		$sent = [];
		$this->captureRequests($sent);

		$this->service->unblock($this->alice(), $this->bob());

		$this->assertCount(1, $sent);
		$undo = $this->assertOneOfType($sent, Undo::class);
		$this->assertSame(self::ALICE_ID, $undo->getActorId());
		$this->assertStringContainsString('#undo/block/', $undo->getId());
		$this->assertDeliveredToInbox($undo, self::BOB_ID . '/inbox');

		$block = $undo->getObject();
		$this->assertInstanceOf(Block::class, $block);
		$this->assertSame(self::ALICE_ID, $block->getActorId());
		$this->assertSame(self::BOB_ID, $block->getObjectId());
	}

	public function testUnblockWithFederationDisabledOnlyDeletesTheRow(): void {
		$this->configService->method('isBlockFederationEnabled')->willReturn(false);

		$this->actorRelationRequest->expects($this->once())
			->method('delete')
			->with(self::ALICE_ID, self::BOB_ID, ActorRelation::TYPE_BLOCK);
		$this->activityService->expects($this->never())->method('request');

		$this->service->unblock($this->alice(), $this->bob());
	}

	public function testUnblockOfALocalAccountFederatesNothing(): void {
		$this->configService->method('isBlockFederationEnabled')->willReturn(true);
		$this->activityService->expects($this->never())->method('request');

		$this->service->unblock($this->alice(), $this->person(self::CAROL_ID, true));
	}

	// getRelated()

	public function testGetRelatedResolvesTheRelatedAccountsAndSkipsUnresolvableOnes(): void {
		$bob = $this->bob();
		$relationToBob = (new ActorRelation())->setObjectId(self::BOB_ID);
		$relationToGhost = (new ActorRelation())->setObjectId('https://gone.example/users/ghost');

		$this->actorRelationRequest->expects($this->once())
			->method('getByActor')
			->with(self::ALICE_ID, ActorRelation::TYPE_BLOCK, 5)
			->willReturn([$relationToBob, $relationToGhost]);
		// resolved from the cache in one query: an account you have blocked is
		// an account you have seen, and a listing must not make a federated
		// request per row
		$this->cacheActorService->expects($this->once())
			->method('getCachedFromIds')
			->with([self::BOB_ID, 'https://gone.example/users/ghost'])
			->willReturn([self::BOB_ID => $bob]);
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$this->assertSame([$bob], $this->service->getRelated($this->alice(), ActorRelation::TYPE_BLOCK, 5));
	}
}
