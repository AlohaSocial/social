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
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\FollowLimitException;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\FollowSameAccountException;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Interfaces\Object\FollowInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\OrderedCollection;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\InstancePath;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

#[AllowMockObjectsWithoutExpectations]
class FollowServiceTest extends TestCase {
	private const CLOUD_URL = 'https://cloud.example';
	private const ALICE_ID = 'https://social.example/@alice';
	private const BOB_ID = 'https://remote.example/users/bob';
	private const CAROL_ID = 'https://other.example/users/carol';

	private IURLGenerator|MockObject $urlGenerator;
	private FollowsRequest|MockObject $followsRequest;
	/** @var ActorRelationRequest&MockObject */
	private $actorRelationRequest;
	private AccountRelationService|MockObject $accountRelationService;
	private ActivityService|MockObject $activityService;
	private CacheActorService|MockObject $cacheActorService;
	/** @var FollowInterface&Stub */
	/** @var ConfigService&Stub */
	private $configService;
	private TimelineRevisionService|MockObject $timelineRevisionService;
	private AccountService|MockObject $accountService;
	private $followInterface;
	private ModerationService|MockObject $moderationService;
	private FollowService $service;

	protected function setUp(): void {
		$this->accountRelationService = $this->createMock(AccountRelationService::class);
		$this->bootActivityPub();

		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->followsRequest = $this->createMock(FollowsRequest::class);
		$this->actorRelationRequest = $this->createMock(ActorRelationRequest::class);
		$this->activityService = $this->createMock(ActivityService::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->followInterface = $this->createMock(FollowInterface::class);
		$this->moderationService = $this->createMock(ModerationService::class);
		$this->configService = $this->createStub(ConfigService::class);
		$this->timelineRevisionService = $this->createMock(TimelineRevisionService::class);
		$this->accountService = $this->createMock(AccountService::class);

		$this->service = new FollowService(
			$this->urlGenerator,
			$this->followsRequest,
			$this->actorRelationRequest,
			$this->activityService,
			$this->cacheActorService,
			$this->configService,
			$this->followInterface,
			$this->moderationService,
			$this->accountRelationService,
			$this->accountService,
			$this->timelineRevisionService,
			new NullLogger()
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

	private function person(string $id, string $username, int $nid = 0): Person {
		$person = new Person();
		$person->setId($id);
		$person->setNid($nid);
		$person->setPreferredUsername($username);
		$person->setInbox($id . '/inbox');
		$person->setFollowers($id . '/followers');
		$person->setFollowing($id . '/following');

		return $person;
	}

	private function alice(): Person {
		$alice = $this->person(self::ALICE_ID, 'alice', 1);
		$alice->setLocal(true);

		return $alice;
	}

	private function follow(string $actorId, string $objectId, bool $accepted): Follow {
		$follow = new Follow();
		$follow->setId(self::CLOUD_URL . '/follow/' . md5($actorId . $objectId));
		$follow->setActorId($actorId);
		$follow->setObjectId($objectId);
		$follow->setAccepted($accepted);

		return $follow;
	}

	// followAccount()

	public function testFollowAccountSavesFollowAndSendsItToTargetInbox(): void {
		$bob = $this->person(self::BOB_ID, 'bob');
		$this->cacheActorService->expects($this->once())
			->method('getFromAccount')
			->with('bob@remote.example')
			->willReturn($bob);
		$this->followsRequest->expects($this->once())
			->method('getByPersons')
			->with(self::ALICE_ID, self::BOB_ID)
			->willThrowException(new FollowNotFoundException());

		$saved = null;
		$this->followsRequest->expects($this->once())
			->method('save')
			->with($this->callback(function (Follow $follow) use (&$saved): bool {
				$saved = $follow;

				return true;
			}));
		$sent = null;
		$this->activityService->expects($this->once())
			->method('request')
			->with($this->callback(function (ACore $item) use (&$sent): bool {
				$sent = $item;

				return true;
			}))
			->willReturn('token');

		$this->assertTrue($this->service->followAccount($this->alice(), 'bob@remote.example'));

		$this->assertInstanceOf(Follow::class, $saved);
		$this->assertSame($saved, $sent);
		$this->assertSame(Follow::TYPE, $saved->getType());
		$this->assertStringStartsWith(self::CLOUD_URL . '/', $saved->getId());
		$this->assertSame(self::ALICE_ID, $saved->getActorId());
		$this->assertSame(self::BOB_ID, $saved->getObjectId());
		$this->assertSame(self::BOB_ID . '/followers', $saved->getFollowId());
		$this->assertFalse($saved->isAccepted());

		$paths = $sent->getInstancePaths();
		$this->assertCount(1, $paths);
		$this->assertSame(self::BOB_ID . '/inbox', $paths[0]->getUri());
		$this->assertSame(InstancePath::TYPE_INBOX, $paths[0]->getType());
		$this->assertSame(InstancePath::PRIORITY_TOP, $paths[0]->getPriority());
	}

	/**
	 * A compromised account, or one running a script, can fan out follows to
	 * thousands of servers from this instance's address in a few minutes —
	 * every one of them a signed request this instance is answerable for, and
	 * the shape of it is indistinguishable from a botnet warming up.
	 */
	public function testAnAccountThatHasFollowedItsHoursWorthIsRefused(): void {
		$this->configService->method('getAppValue')->willReturnCallback(
			fn (string $key): string => ($key === ConfigService::SOCIAL_FOLLOW_LIMIT) ? '5' : ''
		);
		$this->followsRequest->method('countFollowsSince')->willReturn(5);
		$this->cacheActorService->method('getFromAccount')->willReturn($this->person(self::BOB_ID, 'bob'));

		$this->followsRequest->expects($this->never())->method('save');
		$this->activityService->expects($this->never())->method('request');

		$this->expectException(FollowLimitException::class);
		$this->service->followAccount($this->alice(), 'bob@remote.example');
	}

	/** The hour is the window, and only what is in it counts. */
	public function testOnlyTheLastHourOfFollowsIsCounted(): void {
		$since = 0;
		$this->configService->method('getAppValue')->willReturnCallback(
			fn (string $key): string => ($key === ConfigService::SOCIAL_FOLLOW_LIMIT) ? '5' : ''
		);
		$this->followsRequest->method('countFollowsSince')
			->willReturnCallback(function (string $actorId, int $from) use (&$since): int {
				$since = $from;

				return 0;
			});
		$this->cacheActorService->method('getFromAccount')->willReturn($this->person(self::BOB_ID, 'bob'));
		$this->activityService->method('request')->willReturn('token');

		$this->service->followAccount($this->alice(), 'bob@remote.example');

		$this->assertEqualsWithDelta(time() - 3600, $since, 5);
	}

	public function testAnAccountUnderTheLimitFollowsAsBefore(): void {
		$this->configService->method('getAppValue')->willReturnCallback(
			fn (string $key): string => ($key === ConfigService::SOCIAL_FOLLOW_LIMIT) ? '5' : ''
		);
		$this->followsRequest->method('countFollowsSince')->willReturn(4);
		$this->cacheActorService->method('getFromAccount')->willReturn($this->person(self::BOB_ID, 'bob'));
		// nobody follows bob yet, which is what makes this a new follow
		$this->followsRequest->method('getByPersons')
			->willThrowException(new FollowNotFoundException());
		$this->activityService->method('request')->willReturn('token');

		$this->followsRequest->expects($this->once())->method('save');

		$this->service->followAccount($this->alice(), 'bob@remote.example');
	}

	/** An instance that wants no limit says so, and nothing is counted. */
	public function testAnInstanceCanSwitchTheFollowLimitOff(): void {
		$this->configService->method('getAppValue')->willReturn('0');
		$this->followsRequest->expects($this->never())->method('countFollowsSince');
		$this->cacheActorService->method('getFromAccount')->willReturn($this->person(self::BOB_ID, 'bob'));
		$this->activityService->method('request')->willReturn('token');

		$this->service->followAccount($this->alice(), 'bob@remote.example');
	}

	/**
	 * A delivery addressed to this instance is dropped before it is sent (see
	 * ActivityService::isOurs()), because the server would otherwise have to
	 * reach its own public address. A post loses nothing by that, since its
	 * recipients are written into social_stream_dest when it is saved, but a
	 * Follow has no such path: following somebody on your own instance left a
	 * row at `accepted = 0` for ever and changed nobody's timeline. The inbox
	 * side runs here instead.
	 */
	public function testFollowingALocalAccountIsHandledInProcessRatherThanOverHttp(): void {
		$carol = $this->person(self::CLOUD_URL . '/@carol', 'carol', 2);
		$carol->setLocal(true);
		$this->cacheActorService->method('getFromAccount')->with('carol')->willReturn($carol);
		$this->followsRequest->method('getByPersons')
			->willThrowException(new FollowNotFoundException());
		$this->followsRequest->expects($this->once())->method('save');
		$this->configService->method('getCloudHost')->willReturn('social.example');

		// nothing goes to the network, and the inbox handler runs on the Follow
		// that was just saved
		$this->activityService->expects($this->never())->method('request');
		$handled = null;
		$this->followInterface->expects($this->once())
			->method('processIncomingRequest')
			->with($this->callback(function (ACore $item) use (&$handled): bool {
				$handled = $item;

				return true;
			}));

		$this->assertTrue($this->service->followAccount($this->alice(), 'carol'));

		$this->assertInstanceOf(Follow::class, $handled);
		$this->assertSame(self::ALICE_ID, $handled->getActorId());
		$this->assertSame($carol->getId(), $handled->getObjectId());
		// the handler is the inbox's, and it checks the origin against the
		// actor's host before touching anything
		$this->assertSame('social.example', $handled->getOrigin());
	}

	public function testFollowAccountRefusesFollowingYourself(): void {
		$this->cacheActorService->method('getFromAccount')->willReturn($this->alice());
		$this->followsRequest->expects($this->never())->method('save');
		$this->activityService->expects($this->never())->method('request');

		$this->expectException(FollowSameAccountException::class);
		$this->service->followAccount($this->alice(), 'alice@social.example');
	}

	public function testFollowAccountIgnoresAnExistingFollow(): void {
		$this->cacheActorService->method('getFromAccount')->willReturn($this->person(self::BOB_ID, 'bob'));
		$this->followsRequest->method('getByPersons')->willReturn($this->follow(self::ALICE_ID, self::BOB_ID, true));
		$this->followsRequest->expects($this->never())->method('save');
		$this->activityService->expects($this->never())->method('request');

		$this->assertFalse($this->service->followAccount($this->alice(), 'bob@remote.example'));
	}

	public function testFollowAccountKeepsTheFollowWhenFederationFails(): void {
		$this->cacheActorService->method('getFromAccount')->willReturn($this->person(self::BOB_ID, 'bob'));
		$this->followsRequest->method('getByPersons')->willThrowException(new FollowNotFoundException());
		$this->followsRequest->expects($this->once())->method('save');
		$this->activityService->method('request')->willThrowException(new \RuntimeException('queue down'));

		$this->service->followAccount($this->alice(), 'bob@remote.example');
	}

	/**
	 * `addInstancePath()` drops a path with an empty URI, so `request()` had
	 * nothing to send, answered "<request token not needed>" and logged that
	 * the activity had been queued — while the follow row sat pending for ever
	 * and nobody was ever told.
	 */
	public function testFollowAccountFailsWhenTheTargetPublishesNoInbox(): void {
		$bob = $this->person(self::BOB_ID, 'bob');
		$bob->setInbox('');
		$this->cacheActorService->method('getFromAccount')->willReturn($bob);
		$this->followsRequest->expects($this->never())->method('save');
		$this->activityService->expects($this->never())->method('request');

		$this->expectException(InvalidResourceException::class);
		$this->service->followAccount($this->alice(), 'bob@remote.example');
	}

	public function testFollowAccountFailsForUnknownAccount(): void {
		$this->cacheActorService->method('getFromAccount')->willThrowException(new CacheActorDoesNotExistException());
		$this->followsRequest->expects($this->never())->method('save');

		$this->expectException(CacheActorDoesNotExistException::class);
		$this->service->followAccount($this->alice(), 'nobody@remote.example');
	}

	public function testASuspendedAccountCannotFollow(): void {
		$this->moderationService->expects($this->once())
			->method('assertNotSuspended')
			->with(self::ALICE_ID)
			->willThrowException(new InvalidActionException('this account is suspended'));
		$this->followsRequest->expects($this->never())->method('save');
		$this->activityService->expects($this->never())->method('request');

		$this->expectException(InvalidActionException::class);

		$this->service->followAccount($this->alice(), 'bob@remote.example');
	}

	// unfollowAccount()

	public function testUnfollowAccountDeletesFollowAndSendsUndo(): void {
		$bob = $this->person(self::BOB_ID, 'bob');
		$follow = $this->follow(self::ALICE_ID, self::BOB_ID, true);
		$this->cacheActorService->method('getFromAccount')->with('bob@remote.example')->willReturn($bob);
		$this->followsRequest->method('getByPersons')->with(self::ALICE_ID, self::BOB_ID)->willReturn($follow);
		$this->followsRequest->expects($this->once())->method('delete')->with($this->identicalTo($follow));

		$sent = null;
		$this->activityService->expects($this->once())
			->method('request')
			->with($this->callback(function (ACore $item) use (&$sent): bool {
				$sent = $item;

				return true;
			}))
			->willReturn('token');

		$this->assertTrue($this->service->unfollowAccount($this->alice(), 'bob@remote.example'));

		$this->assertInstanceOf(Undo::class, $sent);
		$this->assertStringContainsString('#undo/follows/', $sent->getId());
		$this->assertSame(self::ALICE_ID, $sent->getActorId());
		$this->assertSame($follow, $sent->getObject());
		$this->assertSame($sent, $follow->getParent());
		$this->assertSame($follow->getId(), $sent->getObjectId());

		$paths = $sent->getInstancePaths();
		$this->assertCount(1, $paths);
		$this->assertSame(self::BOB_ID . '/inbox', $paths[0]->getUri());
		$this->assertSame(InstancePath::TYPE_INBOX, $paths[0]->getType());
		$this->assertSame(InstancePath::PRIORITY_TOP, $paths[0]->getPriority());
	}

	/** The row goes here either way; only the Undo has nowhere to go. */
	public function testUnfollowAccountStillDeletesTheFollowWhenThereIsNoInbox(): void {
		$bob = $this->person(self::BOB_ID, 'bob');
		$bob->setInbox('');
		$follow = $this->follow(self::ALICE_ID, self::BOB_ID, true);
		$this->cacheActorService->method('getFromAccount')->willReturn($bob);
		$this->followsRequest->method('getByPersons')->willReturn($follow);
		$this->followsRequest->expects($this->once())->method('delete')->with($this->identicalTo($follow));
		$this->activityService->expects($this->never())->method('request');

		$this->assertTrue($this->service->unfollowAccount($this->alice(), 'bob@remote.example'));
	}

	public function testUnfollowAccountIsANoopWhenNotFollowing(): void {
		$this->cacheActorService->method('getFromAccount')->willReturn($this->person(self::BOB_ID, 'bob'));
		$this->followsRequest->method('getByPersons')->willThrowException(new FollowNotFoundException());
		$this->followsRequest->expects($this->never())->method('delete');
		$this->activityService->expects($this->never())->method('request');
		$this->timelineRevisionService->expects($this->never())->method('bumpForActor');

		$this->assertFalse($this->service->unfollowAccount($this->alice(), 'bob@remote.example'));
	}

	/**
	 * The Accept is what counted the follower up — see
	 * `FollowInterface::confirmFollowRequest()` — and nothing counted it back
	 * down, so an account's follower count only ever climbed until the cron's
	 * recount reached it.
	 */
	public function testUnfollowingTakesTheFollowerOffTheTargetsCount(): void {
		$bob = $this->person(self::BOB_ID, 'bob');
		$this->cacheActorService->method('getFromAccount')->willReturn($bob);
		$this->activityService->method('request')->willReturn('token');
		$this->followsRequest->method('getByPersons')
			->willReturn($this->follow(self::ALICE_ID, self::BOB_ID, true));
		$this->accountService->expects($this->once())
			->method('bumpActorCount')
			->with(self::BOB_ID, 'count_followers', -1);

		$this->service->unfollowAccount($this->alice(), 'bob@remote.example');
	}

	/** A follow request that was never accepted was never counted either. */
	public function testWithdrawingAFollowRequestLeavesTheCountAlone(): void {
		$bob = $this->person(self::BOB_ID, 'bob');
		$this->cacheActorService->method('getFromAccount')->willReturn($bob);
		$this->activityService->method('request')->willReturn('token');
		$this->followsRequest->method('getByPersons')
			->willReturn($this->follow(self::ALICE_ID, self::BOB_ID, false));
		$this->accountService->expects($this->never())->method('bumpActorCount');

		$this->service->unfollowAccount($this->alice(), 'bob@remote.example');
	}

	/**
	 * Their home timeline holds different posts from this moment, and the tag
	 * it is polled with is built from ids that none of this moves — so without
	 * this the reader is answered `304` and goes on being shown the account
	 * they just stopped following. See TimelineRevisionService.
	 */
	public function testFollowingAndUnfollowingMoveTheReadersTimelineRevision(): void {
		$bob = $this->person(self::BOB_ID, 'bob');
		$this->cacheActorService->method('getFromAccount')->willReturn($bob);
		$this->activityService->method('request')->willReturn('token');
		$this->timelineRevisionService->expects($this->exactly(2))
			->method('bumpForActor')
			->with(self::ALICE_ID);

		$this->followsRequest->method('getByPersons')
			->willReturnOnConsecutiveCalls(
				$this->throwException(new FollowNotFoundException()),
				$this->follow(self::ALICE_ID, self::BOB_ID, true)
			);

		$this->service->followAccount($this->alice(), 'bob@remote.example');
		$this->service->unfollowAccount($this->alice(), 'bob@remote.example');
	}

	// getLinksBetweenPersons()

	/**
	 * @return array<string, array{bool, bool}>
	 */
	public static function linksProvider(): array {
		return [
			'no relation' => [false, false],
			'local follows actor' => [true, false],
			'actor follows local' => [false, true],
			'mutual' => [true, true],
		];
	}

	#[DataProvider('linksProvider')]
	public function testGetLinksBetweenPersonsReportsBothDirections(bool $following, bool $follower): void {
		$this->followsRequest->method('getByPersons')
			->willReturnCallback(function (string $actorId, string $remoteId) use ($following, $follower): Follow {
				if ($actorId === self::ALICE_ID && $remoteId === self::BOB_ID && $following) {
					return $this->follow($actorId, $remoteId, true);
				}
				if ($actorId === self::BOB_ID && $remoteId === self::ALICE_ID && $follower) {
					return $this->follow($actorId, $remoteId, true);
				}
				throw new FollowNotFoundException();
			});

		$links = $this->service->getLinksBetweenPersons($this->alice(), $this->person(self::BOB_ID, 'bob'));

		$this->assertSame(['follower' => $follower, 'following' => $following], $links);
	}

	// followers / following

	public function testGetFollowersAndFollowingDelegateWithActorId(): void {
		$followers = [$this->follow(self::BOB_ID, self::ALICE_ID, true)];
		$following = [$this->follow(self::ALICE_ID, self::CAROL_ID, true)];
		$this->followsRequest->method('getFollowersByActorId')->with(self::ALICE_ID)->willReturn($followers);
		$this->followsRequest->method('getFollowingByActorId')->with(self::ALICE_ID)->willReturn($following);

		$this->assertSame($followers, $this->service->getFollowers($this->alice()));
		$this->assertSame($following, $this->service->getFollowing($this->alice()));
	}

	public function testGetFollowersFromFollowIdDelegates(): void {
		$followers = [$this->follow(self::BOB_ID, self::ALICE_ID, true)];
		$this->followsRequest->method('getFollowersByFollowId')
			->with(self::ALICE_ID . '/followers')
			->willReturn($followers);

		$this->assertSame($followers, $this->service->getFollowersFromFollowId(self::ALICE_ID . '/followers'));
	}

	public function testGetFollowersCollectionDescribesFollowersEndpoint(): void {
		$alice = $this->alice();
		$alice->setDetailArray('count', ['followers' => 12, 'following' => 4]);
		$this->urlGenerator->expects($this->once())
			->method('linkToRouteAbsolute')
			->with('social.ActivityPub.followers', ['username' => 'alice'])
			->willReturn('https://cloud.example/apps/social/@alice/followers');

		$collection = $this->service->getFollowersCollection($alice);

		$this->assertSame('OrderedCollection', $collection->getType());
		$this->assertSame(self::ALICE_ID . '/followers', $collection->getId());
		$this->assertSame(12, $collection->getTotalItems());
		$this->assertSame('https://cloud.example/apps/social/@alice/followers?page=1', $collection->getFirst());
		// 12 followers at 40 to a page is one page, and `last` has to name a
		// page that exists
		$this->assertSame('https://cloud.example/apps/social/@alice/followers?page=1', $collection->getLast());
	}

	public function testGetFollowingCollectionDescribesFollowingEndpoint(): void {
		$alice = $this->alice();
		$alice->setDetailArray('count', ['followers' => 12, 'following' => 4]);
		$this->urlGenerator->expects($this->once())
			->method('linkToRouteAbsolute')
			->with('social.ActivityPub.following', ['username' => 'alice'])
			->willReturn('https://cloud.example/apps/social/@alice/following');

		$collection = $this->service->getFollowingCollection($alice);

		$this->assertSame(self::ALICE_ID . '/following', $collection->getId());
		$this->assertSame(4, $collection->getTotalItems());
		$this->assertSame('https://cloud.example/apps/social/@alice/following?page=1', $collection->getFirst());
	}

	public function testFollowersPageListsTheFollowerActorUris(): void {
		$alice = $this->alice();
		$this->urlGenerator->method('linkToRouteAbsolute')
			->willReturn('https://cloud.example/apps/social/@alice/followers');
		$this->followsRequest->expects($this->once())
			->method('getFollowersByActorId')
			->with(self::ALICE_ID, OrderedCollection::PAGE_SIZE, 0)
			->willReturn([
				$this->follow(self::BOB_ID, self::ALICE_ID, true),
				$this->follow('https://remote.example/users/carol', self::ALICE_ID, true),
			]);

		$page = $this->service->getFollowersPage($alice, 1);

		$this->assertSame('OrderedCollectionPage', $page->getType());
		$this->assertSame('https://cloud.example/apps/social/@alice/followers?page=1', $page->getId());
		$this->assertSame(self::ALICE_ID . '/followers', $page->getPartOf());
		$this->assertSame(
			[self::BOB_ID, 'https://remote.example/users/carol'], $page->getOrderedItems()
		);
		$this->assertSame('', $page->getNext(), 'a page that is not full is the last one');
		$this->assertSame('', $page->getPrev());
	}

	public function testASecondFollowersPageIsOffsetAndPointsBack(): void {
		$this->urlGenerator->method('linkToRouteAbsolute')
			->willReturn('https://cloud.example/apps/social/@alice/followers');
		$this->followsRequest->expects($this->once())
			->method('getFollowersByActorId')
			->with(self::ALICE_ID, OrderedCollection::PAGE_SIZE, OrderedCollection::PAGE_SIZE)
			->willReturn(array_fill(
				0,
				OrderedCollection::PAGE_SIZE,
				$this->follow(self::BOB_ID, self::ALICE_ID, true)
			));

		$page = $this->service->getFollowersPage($this->alice(), 2);

		$this->assertSame('https://cloud.example/apps/social/@alice/followers?page=1', $page->getPrev());
		$last = $this->follow(self::BOB_ID, self::ALICE_ID, true);
		$this->assertSame(
			'https://cloud.example/apps/social/@alice/followers?page=true&max_id=' . FollowsRequest::cursorAfter($last),
			$page->getNext(),
			'a full page cannot know it is the last, and the next one starts after its last row'
		);
	}

	/** The page after a cursor is read from the cursor, not at an offset. */
	public function testAFollowersCursorPageIsReadAfterItsCursor(): void {
		$this->urlGenerator->method('linkToRouteAbsolute')
			->willReturn('https://cloud.example/apps/social/@alice/followers');
		$cursor = '1790000000-' . md5('https://remote.example/follow/1');
		$this->followsRequest->expects($this->once())
			->method('getFollowersByActorId')
			->with(self::ALICE_ID, OrderedCollection::PAGE_SIZE, 0, $cursor)
			->willReturn([$this->follow(self::BOB_ID, self::ALICE_ID, true)]);

		$page = $this->service->getFollowersPage($this->alice(), 1, $cursor);

		$this->assertSame(
			'https://cloud.example/apps/social/@alice/followers?page=true&max_id=' . rawurlencode($cursor), $page->getId()
		);
		$this->assertSame([self::BOB_ID], $page->getOrderedItems());
		$this->assertSame('', $page->getNext(), 'a short page is the last');
		$this->assertSame('', $page->getPrev());
	}

	public function testAFollowingCursorPageIsReadAfterItsCursor(): void {
		$this->urlGenerator->method('linkToRouteAbsolute')
			->willReturn('https://cloud.example/apps/social/@alice/following');
		$cursor = '1790000000-' . md5('https://remote.example/follow/1');
		$this->followsRequest->expects($this->once())
			->method('getFollowingByActorId')
			->with(self::ALICE_ID, OrderedCollection::PAGE_SIZE, 0, $cursor)
			->willReturn([$this->follow(self::ALICE_ID, self::BOB_ID, true)]);

		$this->assertSame([self::BOB_ID], $this->service->getFollowingPage($this->alice(), 1, $cursor)->getOrderedItems());
	}

	public function testFollowingPageListsTheFollowedActorUris(): void {
		$this->urlGenerator->method('linkToRouteAbsolute')
			->willReturn('https://cloud.example/apps/social/@alice/following');
		$this->followsRequest->method('getFollowingByActorId')
			->with(self::ALICE_ID, OrderedCollection::PAGE_SIZE, 0)
			->willReturn([$this->follow(self::ALICE_ID, self::BOB_ID, true)]);

		$page = $this->service->getFollowingPage($this->alice(), 1);

		$this->assertSame(self::ALICE_ID . '/following', $page->getPartOf());
		$this->assertSame([self::BOB_ID], $page->getOrderedItems());
	}

	public function testTheLastPageOfALargeCollectionIsTheHighestThatExists(): void {
		$alice = $this->alice();
		$alice->setDetailArray('count', ['followers' => OrderedCollection::PAGE_SIZE * 3 + 1]);
		$this->urlGenerator->method('linkToRouteAbsolute')
			->willReturn('https://cloud.example/apps/social/@alice/followers');

		$this->assertSame(
			'https://cloud.example/apps/social/@alice/followers?page=4',
			$this->service->getFollowersCollection($alice)->getLast()
		);
	}

	public function testCollectionsWithoutCachedCountsReportZero(): void {
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example/x');

		$this->assertSame(0, $this->service->getFollowersCollection($this->alice())->getTotalItems());
		$this->assertSame(0, $this->service->getFollowingCollection($this->alice())->getTotalItems());
	}

	// setViewer() / getRelationships()

	public function testSetViewerIsPassedToFollowsRequest(): void {
		$alice = $this->alice();
		$this->followsRequest->expects($this->once())->method('setViewer')->with($this->identicalTo($alice));

		$this->service->setViewer($alice);
	}

	/**
	 * The relationship queries are asked once for a whole page — see
	 * `FollowsRequest::getBetweenMany()`. These helpers say the same thing the
	 * old per-pair mocks said, in the shape the batch reads.
	 *
	 * @param array<string, bool> $following actor id => accepted
	 * @param array<string, bool> $followedBy actor id => accepted
	 */
	private function followsBetween(array $following, array $followedBy = []): void {
		$map = static function (array $pairs, bool $mineFirst): array {
			$out = [];
			foreach ($pairs as $actorId => $accepted) {
				$follow = new Follow();
				$follow->setActorId($mineFirst ? self::ALICE_ID : $actorId);
				$follow->setObjectId($mineFirst ? $actorId : self::ALICE_ID);
				$follow->setAccepted($accepted);
				$out[$actorId] = $follow;
			}

			return $out;
		};

		$this->followsRequest->method('getBetweenMany')->willReturn([
			'following' => $map($following, true),
			'followedBy' => $map($followedBy, false),
		]);
	}

	/**
	 * The count is the point. Built one at a time this was six round trips per
	 * account — two follow rows, the blocks and mutes, the note, the mute's
	 * expiry — so a client asking about a page of forty paid two hundred and
	 * forty. Asserted rather than described, because an N+1 comes back by
	 * somebody adding one innocent lookup inside the loop.
	 */
	public function testAPageOfRelationshipsCostsTheSameQueriesAsOne(): void {
		$this->service->setViewer($this->alice());
		$people = [];
		for ($nid = 2; $nid <= 21; $nid++) {
			$people[] = $this->person('https://remote.example/users/p' . $nid, 'p' . $nid, $nid);
		}
		$this->cacheActorService->method('getFromNids')->willReturn($people);

		// each of these answers the whole page, and each may be asked once
		$this->followsRequest->expects($this->once())->method('getBetweenMany')
			->willReturn(['following' => [], 'followedBy' => []]);
		$this->actorRelationRequest->expects($this->once())->method('getBetweenMany')->willReturn([]);
		$this->followsRequest->expects($this->never())->method('getByPersons');
		$this->actorRelationRequest->expects($this->never())->method('getBetween');
		$this->accountRelationService->expects($this->once())->method('decorateMany');

		$relationships = $this->service->getRelationships(array_map('strval', range(2, 21)));

		$this->assertCount(20, $relationships);
	}

	public function testGetRelationshipsResolvesNidsAndUrlsAndSkipsTheViewer(): void {
		$alice = $this->alice();
		$bob = $this->person(self::BOB_ID, 'bob', 2);
		$carol = $this->person(self::CAROL_ID, 'carol', 3);
		$this->service->setViewer($alice);

		$this->cacheActorService->expects($this->once())
			->method('getFromNids')
			->with([2, 1])
			->willReturn([$bob, $alice]);
		$this->cacheActorService->expects($this->once())
			->method('getFromId')
			->with(self::CAROL_ID)
			->willReturn($carol);
		// alice follows bob (accepted), bob follows alice (accepted),
		// alice asked to follow carol (pending), carol does not follow alice
		$this->followsBetween(
			[self::BOB_ID => true, self::CAROL_ID => false],
			[self::BOB_ID => true]
		);

		$relationships = $this->service->getRelationships(['2', self::CAROL_ID, '1']);

		$this->assertCount(2, $relationships);

		$this->assertSame(2, $relationships[0]->getId());
		$this->assertTrue($relationships[0]->isFollowing());
		$this->assertTrue($relationships[0]->isFollowedBy());
		$this->assertFalse($relationships[0]->isRequested());

		$this->assertSame(3, $relationships[1]->getId());
		$this->assertFalse($relationships[1]->isFollowing());
		$this->assertFalse($relationships[1]->isFollowedBy());
		$this->assertTrue($relationships[1]->isRequested());
	}

	public function testAPendingIncomingFollowIsReportedAsRequestedBy(): void {
		// the same row /api/v1/follow_requests lists: a client reads
		// `requested_by` to offer approve and reject on the profile
		$alice = $this->alice();
		$bob = $this->person(self::BOB_ID, 'bob', 2);
		$this->service->setViewer($alice);
		$this->cacheActorService->method('getFromNids')->willReturn([$bob]);
		$this->followsBetween([], [self::BOB_ID => false]);

		$relationship = $this->service->getRelationships(['2'])[0];

		$this->assertTrue($relationship->isRequestedBy());
		$this->assertFalse($relationship->isFollowedBy());
		$this->assertFalse($relationship->isRequested());
	}

	public function testAnAcceptedIncomingFollowIsNotAPendingRequest(): void {
		$alice = $this->alice();
		$bob = $this->person(self::BOB_ID, 'bob', 2);
		$this->service->setViewer($alice);
		$this->cacheActorService->method('getFromNids')->willReturn([$bob]);
		$this->followsBetween([], [self::BOB_ID => true]);

		$relationship = $this->service->getRelationships(['2'])[0];

		$this->assertTrue($relationship->isFollowedBy());
		$this->assertFalse($relationship->isRequestedBy());
	}

	public function testGetRelationshipsSkipsUnknownActors(): void {
		$this->service->setViewer($this->alice());
		$this->cacheActorService->method('getFromNids')->with([])->willReturn([]);
		$this->cacheActorService->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());
		$this->followsRequest->expects($this->never())->method('getByPersons');

		$this->assertSame([], $this->service->getRelationships(['https://gone.example/users/x', '0', 'abc']));
	}

	/**
	 * The relations getBetween() reports for viewer/actor, against the flags the
	 * relationship entity must carry.
	 *
	 * @return array<string, array{array<int, array{string, bool}>, array<string, bool>}>
	 */
	public static function relationFlagsProvider(): array {
		return [
			'viewer blocks the actor' => [
				[[ActorRelation::TYPE_BLOCK, true]],
				['blocking' => true, 'blocked_by' => false, 'muting' => false, 'muting_notifications' => false],
			],
			'actor blocks the viewer' => [
				[[ActorRelation::TYPE_BLOCKED_BY, true]],
				['blocking' => false, 'blocked_by' => true, 'muting' => false, 'muting_notifications' => false],
			],
			'muted keeping notifications' => [
				[[ActorRelation::TYPE_MUTE, false]],
				['blocking' => false, 'blocked_by' => false, 'muting' => true, 'muting_notifications' => false],
			],
			'muted hiding notifications' => [
				[[ActorRelation::TYPE_MUTE, true]],
				['blocking' => false, 'blocked_by' => false, 'muting' => true, 'muting_notifications' => true],
			],
			'no relation' => [
				[],
				['blocking' => false, 'blocked_by' => false, 'muting' => false, 'muting_notifications' => false, 'notifying' => false],
			],
			// `notifying` was hardcoded false, so a client that had turned the
			// bell on was told it was off and drew it that way
			'the bell is on' => [
				[[AccountRelationService::TYPE_NOTIFY, true]],
				['notifying' => true, 'blocking' => false, 'muting' => false],
			],
			'endorsed and subscribed to' => [
				[[AccountRelationService::TYPE_ENDORSE, true], [AccountRelationService::TYPE_NOTIFY, true]],
				['notifying' => true, 'endorsed' => true],
			],
			'endorsed without the bell' => [
				[[AccountRelationService::TYPE_ENDORSE, true]],
				['notifying' => false, 'endorsed' => true],
			],
		];
	}

	/**
	 * @param array<int, array{string, bool}> $relations
	 * @param array<string, bool> $expected
	 */
	#[DataProvider('relationFlagsProvider')]
	public function testGetRelationshipsCarriesTheBlockAndMuteFlags(array $relations, array $expected): void {
		$this->service->setViewer($this->alice());
		$this->cacheActorService->method('getFromNids')->willReturn([$this->person(self::BOB_ID, 'bob', 2)]);
		$this->followsBetween([]);

		$this->actorRelationRequest->expects($this->once())
			->method('getBetweenMany')
			->with(self::ALICE_ID, [self::BOB_ID])
			->willReturn([self::BOB_ID => array_map(
				fn (array $relation): ActorRelation => (new ActorRelation())
					->setType($relation[0])
					->setNotifications($relation[1]),
				$relations
			)]);

		$relationships = $this->service->getRelationships(['2']);

		$this->assertCount(1, $relationships);
		$serialized = $relationships[0]->jsonSerialize();
		foreach ($expected as $flag => $value) {
			$this->assertSame($value, $serialized[$flag], 'flag ' . $flag);
		}
	}

	public function testGetRelationshipWithAlwaysReturnsAnEntryCarryingTheFlags(): void {
		$this->service->setViewer($this->alice());
		$this->cacheActorService->expects($this->never())->method('getFromNids');
		// one account goes down the same batched path as a page of them, which
		// is the point: two implementations would be two answers
		$this->followsBetween([]);
		$this->actorRelationRequest->expects($this->once())
			->method('getBetweenMany')
			->with(self::ALICE_ID, [self::BOB_ID])
			->willReturn([self::BOB_ID => [(new ActorRelation())->setType(ActorRelation::TYPE_BLOCK)]]);

		$relationship = $this->service->getRelationshipWith($this->person(self::BOB_ID, 'bob', 2));

		$this->assertSame(2, $relationship->getId());
		$this->assertTrue($relationship->isBlocking());
		$this->assertFalse($relationship->isFollowing());
	}

	public function testGetRelationshipsDoesNotFlagPendingFollowerAsFollowedBy(): void {
		$alice = $this->alice();
		$bob = $this->person(self::BOB_ID, 'bob', 2);
		$this->service->setViewer($alice);
		$this->cacheActorService->method('getFromNids')->willReturn([$bob]);
		$this->followsRequest->method('getByPersons')
			->willReturnCallback(function (string $actorId, string $remoteId): Follow {
				if ($actorId === self::BOB_ID) {
					return $this->follow($actorId, $remoteId, false);
				}
				throw new FollowNotFoundException();
			});

		$relationships = $this->service->getRelationships([2]);

		$this->assertCount(1, $relationships);
		$this->assertFalse($relationships[0]->isFollowedBy());
		$this->assertFalse($relationships[0]->isFollowing());
		$this->assertFalse($relationships[0]->isRequested());
	}

	public function testGetPendingRequestsResolvesTheRequestingAccounts(): void {
		$this->service->setViewer($this->alice());
		$bob = $this->person(self::BOB_ID, 'bob', 2);

		$this->followsRequest->expects($this->once())
			->method('getPendingByObjectId')
			->with(self::ALICE_ID, 0, '', '')
			->willReturn([
				$this->follow(self::BOB_ID, self::ALICE_ID, false),
				$this->follow(self::CAROL_ID, self::ALICE_ID, false),
			]);
		$this->cacheActorService->method('getFromId')
			->willReturnCallback(function (string $id) use ($bob): Person {
				if ($id === self::BOB_ID) {
					return $bob;
				}
				// carol's server is gone: her request is skipped, not fatal
				throw new CacheActorDoesNotExistException();
			});

		$this->assertSame([$bob], $this->service->getPendingRequests());
	}

	public function testTheDashboardsLimitReachesTheQuery(): void {
		$this->service->setViewer($this->alice());

		$this->followsRequest->expects($this->once())
			->method('getPendingByObjectId')
			->with(self::ALICE_ID, 7, '', '')
			->willReturn([]);

		$this->assertSame([], $this->service->getPendingRequests(7));
	}

	/**
	 * A request whose account cannot be resolved is left off the page, but
	 * the page is still the rows it read: the cursor has to move past it and
	 * the next page has to be offered.
	 */
	public function testAPageIsCutOnTheFollowRowsNotOnTheAccountsResolved(): void {
		$this->service->setViewer($this->alice());
		$bob = $this->person(self::BOB_ID, 'bob', 2);
		$first = $this->follow(self::BOB_ID, self::ALICE_ID, false)
			->setCreation(1767268800)->setIdPrim(str_repeat('b', 32));
		$last = $this->follow(self::CAROL_ID, self::ALICE_ID, false)
			->setCreation(1767268800)->setIdPrim(str_repeat('a', 32));

		$this->followsRequest->expects($this->once())
			->method('getPendingByObjectId')
			->with(self::ALICE_ID, 2, '1767268801-' . str_repeat('c', 32), '')
			->willReturn([$first, $last]);
		$this->cacheActorService->method('getFromId')
			->willReturnCallback(function (string $id) use ($bob): Person {
				if ($id === self::BOB_ID) {
					return $bob;
				}
				throw new CacheActorDoesNotExistException();
			});

		$page = $this->service->getPendingRequestPage(2, '1767268801-' . str_repeat('c', 32));

		$this->assertSame([$bob], $page['accounts']);
		$this->assertSame(2, $page['rows']);
		$this->assertSame('1767268800-' . str_repeat('b', 32), $page['first']);
		$this->assertSame('1767268800-' . str_repeat('a', 32), $page['last']);
	}

	public function testAnEmptyPageHasNoCursors(): void {
		$this->service->setViewer($this->alice());
		$this->followsRequest->method('getPendingByObjectId')->willReturn([]);

		$this->assertSame(
			['accounts' => [], 'rows' => 0, 'first' => '', 'last' => ''],
			$this->service->getPendingRequestPage(40)
		);
	}

	public function testAuthorizeFollowRequestConfirmsThePendingFollow(): void {
		$this->service->setViewer($this->alice());
		$pending = $this->follow(self::BOB_ID, self::ALICE_ID, false);
		$this->followsRequest->method('getByPersons')
			->with(self::BOB_ID, self::ALICE_ID)
			->willReturn($pending);

		$this->followInterface->expects($this->once())
			->method('confirmFollowRequest')->with($this->identicalTo($pending));

		$this->service->authorizeFollowRequest($this->person(self::BOB_ID, 'bob', 2));
	}

	public function testAuthorizeFollowRequestIsIdempotentOnAnAcceptedFollow(): void {
		$this->service->setViewer($this->alice());
		$this->followsRequest->method('getByPersons')
			->willReturn($this->follow(self::BOB_ID, self::ALICE_ID, true));

		$this->followInterface->expects($this->never())->method('confirmFollowRequest');

		$this->service->authorizeFollowRequest($this->person(self::BOB_ID, 'bob', 2));
	}

	public function testAuthorizeFollowRequestWithoutARequestThrows(): void {
		$this->service->setViewer($this->alice());
		$this->followsRequest->method('getByPersons')->willThrowException(new FollowNotFoundException());

		$this->followInterface->expects($this->never())->method('confirmFollowRequest');

		$this->expectException(FollowNotFoundException::class);

		$this->service->authorizeFollowRequest($this->person(self::BOB_ID, 'bob', 2));
	}

	public function testRejectFollowRequestRejectsThePendingFollow(): void {
		$this->service->setViewer($this->alice());
		$pending = $this->follow(self::BOB_ID, self::ALICE_ID, false);
		$this->followsRequest->method('getByPersons')
			->with(self::BOB_ID, self::ALICE_ID)
			->willReturn($pending);

		$this->followInterface->expects($this->once())
			->method('rejectFollowRequest')->with($this->identicalTo($pending));

		$this->service->rejectFollowRequest($this->person(self::BOB_ID, 'bob', 2));
	}

	public function testRejectFollowRequestWithoutARequestThrows(): void {
		$this->service->setViewer($this->alice());
		$this->followsRequest->method('getByPersons')->willThrowException(new FollowNotFoundException());

		$this->followInterface->expects($this->never())->method('rejectFollowRequest');

		$this->expectException(FollowNotFoundException::class);

		$this->service->rejectFollowRequest($this->person(self::BOB_ID, 'bob', 2));
	}
}
