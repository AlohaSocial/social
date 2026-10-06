<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use Exception;
use OCA\Social\AP;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\ChannelsRequest;
use OCA\Social\Db\ClientAuthRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\ModerationRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\AccountAlreadyExistsException;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\InvalidHandleException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\Actor\PersonInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\Moderation;
use OCA\Social\Service\AccessBlockService;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\ActorService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\SignatureService;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\IAccountProperty;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class AccountServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example.com/apps/social/@alice';

	private IUserManager|MockObject $userManager;
	private IUserSession|Stub $userSession;
	private IAccountManager|Stub $accountManager;
	private ActorsRequest|MockObject $actorsRequest;
	private ClientAuthRequest|MockObject $clientAuthRequest;
	private ChannelsRequest|Stub $channelsRequest;
	private FollowsRequest|MockObject $followsRequest;
	private StreamRequest|MockObject $streamRequest;
	private ActorService|MockObject $actorService;
	private ActivityService|MockObject $activityService;
	private DocumentService|MockObject $documentService;
	private SignatureService|MockObject $signatureService;
	private ConfigService|MockObject $configService;
	private AccessBlockService|Stub $accessBlockService;
	private CacheActorService|MockObject $cacheActorService;
	private \OCA\Social\Db\CacheActorsRequest|MockObject $cacheActorsRequest;
	private ModerationRequest|MockObject $moderationRequest;
	private LoggerInterface|MockObject $logger;

	/** @var string[] the addresses this instance gives no fediverse account to */
	private array $blockedEmails = [];
	/** @var array<string, string> what the service wrote to app config */
	private array $appValues = [];
	private AccountService $service;
	private int $errorReporting;

	protected function setUp(): void {
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userSession = $this->createStub(IUserSession::class);
		$this->accountManager = $this->createStub(IAccountManager::class);
		$this->actorsRequest = $this->createMock(ActorsRequest::class);
		$this->followsRequest = $this->createMock(FollowsRequest::class);
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->actorService = $this->createMock(ActorService::class);
		$this->activityService = $this->createMock(ActivityService::class);
		$this->documentService = $this->createMock(DocumentService::class);
		$this->signatureService = $this->createMock(SignatureService::class);
		$this->configService = $this->createMock(ConfigService::class);
		// the local-actor walk remembers where it got to in app config
		$this->configService->method('getAppValue')->willReturnCallback(
			fn (string $key): string => $this->appValues[$key] ?? ''
		);
		$this->configService->method('setAppValue')->willReturnCallback(
			function (string $key, string $value): void {
				$this->appValues[$key] = $value;
			}
		);

		// AccountService assigns its collaborators to undeclared properties; PHP reports
		// "Creation of dynamic property" for each of them, which PHPUnit would treat as
		// unexpected output. Silence that single known deprecation around construction.
		$this->errorReporting = error_reporting(E_ALL & ~E_DEPRECATED);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->cacheActorsRequest = $this->createMock(\OCA\Social\Db\CacheActorsRequest::class);
		$this->moderationRequest = $this->createMock(ModerationRequest::class);
		$this->clientAuthRequest = $this->createMock(ClientAuthRequest::class);
		$this->channelsRequest = $this->createStub(ChannelsRequest::class);
		$this->accessBlockService = $this->createStub(AccessBlockService::class);
		$this->accessBlockService->method('isBlockedEmail')->willReturnCallback(
			fn (string $email): bool => in_array($email, $this->blockedEmails, true)
		);

		$this->service = new AccountService(
			$this->userManager,
			$this->userSession,
			$this->accountManager,
			$this->actorsRequest,
			$this->channelsRequest,
			$this->clientAuthRequest,
			$this->followsRequest,
			$this->streamRequest,
			$this->actorService,
			$this->activityService,
			$this->documentService,
			$this->signatureService,
			$this->configService,
			$this->accessBlockService,
			$this->cacheActorService,
			$this->cacheActorsRequest,
			$this->moderationRequest,
			$this->logger = $this->createMock(LoggerInterface::class),
		);
		error_reporting($this->errorReporting);
	}

	protected function tearDown(): void {
		AP::set(null);
		error_reporting($this->errorReporting);
	}

	private function user(string $uid, string $email = ''): IUser|MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getEMailAddress')->willReturn($email);

		return $user;
	}

	private function alice(): Person {
		$alice = new Person();
		$alice->setId(self::ALICE);
		$alice->setUserId('alice');
		$alice->setPreferredUsername('alice');
		$alice->setInbox(self::ALICE . '/inbox');
		$alice->setLocal(true);

		return $alice;
	}

	/** @var array<string, string> what the fediverse field held, per test */
	private array $fediverseField = ['value' => '', 'scope' => IAccountManager::SCOPE_LOCAL];
	/** @var string[] every value written to the fediverse field */
	private array $fediverseWrites = [];
	private int $accountUpdates = 0;
	/** the scope of the profile picture; a stock Nextcloud federates it */
	private string $avatarScope = IAccountManager::SCOPE_FEDERATED;

	/**
	 * Point the display-name account property at a value with the given
	 * scope, and give the profile a fediverse field the service can read and
	 * write -- it asks for both now.
	 */
	private function withDisplayName(string $name, string $scope): void {
		$displayName = $this->createStub(IAccountProperty::class);
		$displayName->method('getScope')->willReturn($scope);
		$displayName->method('getValue')->willReturn($name);

		$fediverse = $this->createMock(IAccountProperty::class);
		$fediverse->method('getScope')->willReturnCallback(fn (): string => $this->fediverseField['scope']);
		$fediverse->method('getValue')->willReturnCallback(fn (): string => $this->fediverseField['value']);
		$fediverse->method('setValue')->willReturnCallback(function (string $value) use ($fediverse): IAccountProperty {
			$this->fediverseWrites[] = $value;
			$this->fediverseField['value'] = $value;

			return $fediverse;
		});

		$avatar = $this->createStub(IAccountProperty::class);
		$avatar->method('getScope')->willReturnCallback(fn (): string => $this->avatarScope);

		$account = $this->createStub(IAccount::class);
		$account->method('getProperty')->willReturnCallback(
			fn (string $property): IAccountProperty => match ($property) {
				IAccountManager::PROPERTY_FEDIVERSE => $fediverse,
				IAccountManager::PROPERTY_AVATAR => $avatar,
				default => $displayName,
			}
		);
		$this->accountManager->method('getAccount')->willReturn($account);
		$this->accountManager->method('updateAccount')
			->willReturnCallback(function (): void {
				$this->accountUpdates++;
			});
	}

	public function testGetActorAndGetFromIdDelegate(): void {
		$alice = $this->alice();
		$this->actorsRequest->expects($this->once())->method('getFromUsername')->with('alice')->willReturn($alice);
		$this->actorsRequest->expects($this->once())->method('getFromId')->with(self::ALICE)->willReturn($alice);

		$this->assertSame($alice, $this->service->getActor('alice'));
		$this->assertSame($alice, $this->service->getFromId(self::ALICE));
	}

	public function testConfirmUserIdNormalisesToTheRealUid(): void {
		$this->userManager->method('get')->with('Alice')->willReturn($this->user('alice'));

		$userId = 'Alice';
		$user = $this->service->confirmUserId($userId);

		$this->assertSame('alice', $userId);
		$this->assertSame('alice', $user->getUID());
	}

	public function testGetCurrentViewerRequiresALoggedInUser(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(AccountDoesNotExistException::class);
		$this->expectExceptionMessage('No user is currently logged in');
		$this->service->getCurrentViewer();
	}

	public function testGetCurrentViewerReturnsTheActorOfTheSessionUser(): void {
		$this->userSession->method('getUser')->willReturn($this->user('alice'));
		$this->userManager->method('get')->with('alice')->willReturn($this->user('alice'));
		$alice = $this->alice();
		$this->actorsRequest->method('getFromUserId')->with('alice')->willReturn($alice);

		$this->assertSame($alice, $this->service->getCurrentViewer());
	}

	public function testGetCurrentViewerWrapsAMissingActor(): void {
		$this->userSession->method('getUser')->willReturn($this->user('alice'));
		$this->userManager->method('get')->willReturn($this->user('alice'));
		$this->actorsRequest->method('getFromUserId')->willThrowException(new ActorDoesNotExistException());

		$this->expectException(AccountDoesNotExistException::class);
		$this->expectExceptionMessage('Account not found for current user');
		$this->service->getCurrentViewer();
	}

	public function testGetActorFromUserIdWithoutCreateThrowsForAMissingActor(): void {
		$this->userManager->method('get')->willReturn($this->user('alice'));
		$this->actorsRequest->method('getFromUserId')->willThrowException(new ActorDoesNotExistException());
		$this->actorsRequest->expects($this->never())->method('create');

		$this->expectException(ActorDoesNotExistException::class);
		$this->expectExceptionMessage('Actor not found for user: alice');
		$this->service->getActorFromUserId('alice');
	}

	/**
	 * `social_cache_actor` is what a reader wants — the follower, following and
	 * post counts live only there — and a missing row means the account has not
	 * been looked at yet, not that it does not exist. These three moved here
	 * from `LocalController` when the page started needing the same lookup.
	 */
	public function testGetCachedLocalActorReturnsTheCachedCopy(): void {
		$cached = $this->createStub(Person::class);
		$this->cacheActorService->expects($this->once())
			->method('getFromLocalAccount')->with('alice')->willReturn($cached);
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$this->assertSame($cached, $this->service->getCachedLocalActor('alice'));
	}

	public function testGetCachedLocalActorRebuildsTheCacheOnAMiss(): void {
		$cached = $this->createMock(Person::class);
		$reads = 0;
		$this->cacheActorService->expects($this->exactly(2))
			->method('getFromLocalAccount')
			->with('alice')
			->willReturnCallback(function () use (&$reads, $cached): Person {
				if ($reads++ === 0) {
					throw new CacheActorDoesNotExistException();
				}

				return $cached;
			});
		// the rebuild is cacheLocalActorByUsername(), which reads the actor row
		$this->actorsRequest->method('getFromUsername')
			->willThrowException(new ActorDoesNotExistException());

		$this->assertSame($cached, $this->service->getCachedLocalActor('alice'));
	}

	public function testGetCachedLocalActorGivesUpWhenTheRebuildProducesNothing(): void {
		$this->cacheActorService->method('getFromLocalAccount')
			->willThrowException(new CacheActorDoesNotExistException());
		$this->actorsRequest->method('getFromUsername')
			->willThrowException(new ActorDoesNotExistException());

		$this->expectException(CacheActorDoesNotExistException::class);
		$this->service->getCachedLocalActor('ghost');
	}

	public function testGetActorFromUserIdCreatesTheActorOnDemand(): void {
		$this->userManager->method('get')->willReturn($this->user('alice'));
		$this->actorsRequest->method('getFromUsername')->willThrowException(new ActorDoesNotExistException());
		$created = null;
		$this->actorsRequest->expects($this->once())
			->method('create')
			->willReturnCallback(function (Person $actor) use (&$created) {
				$created = $actor;
			});
		// missing before creation (looked up twice), found afterwards
		$this->actorsRequest->expects($this->exactly(3))
			->method('getFromUserId')
			->with('alice')
			->willReturnCallback(function () use (&$created) {
				if ($created === null) {
					throw new ActorDoesNotExistException();
				}

				return $created;
			});

		$actor = $this->service->getActorFromUserId('alice', true);

		$this->assertSame($created, $actor);
		$this->assertSame('alice', $actor->getUserId());
	}

	public function testCreateActorGeneratesKeysStoresTheActorAndItsLoopbackFollow(): void {
		$this->userManager->method('get')->with('alice')->willReturn($this->user('alice'));
		$this->actorsRequest->method('getFromUsername')->willThrowException(new ActorDoesNotExistException());
		$this->actorsRequest->method('getFromUserId')->willThrowException(new ActorDoesNotExistException());
		$this->signatureService->expects($this->once())
			->method('generateKeys')
			->willReturnCallback(function (Person $actor) {
				$actor->setPublicKey('PUB');
				$actor->setPrivateKey('PRIV');
			});
		$created = null;
		$this->actorsRequest->expects($this->once())
			->method('create')
			->willReturnCallback(function (Person $actor) use (&$created) {
				$created = $actor;
			});
		$this->followsRequest->expects($this->once())
			->method('generateLoopbackAccount')
			->with($this->callback(function (Person $actor) use (&$created) {
				return $actor === $created;
			}));

		$this->service->createActor('alice', 'alice');

		$this->assertInstanceOf(Person::class, $created);
		$this->assertSame('alice', $created->getUserId());
		$this->assertSame('alice', $created->getPreferredUsername());
		$this->assertSame('PUB', $created->getPublicKey());
		$this->assertSame('PRIV', $created->getPrivateKey());
	}

	public function testCreateActorRefusesATakenUsername(): void {
		$this->userManager->method('get')->willReturn($this->user('bob'));
		$this->actorsRequest->method('getFromUsername')->with('alice')->willReturn($this->alice());
		$this->actorsRequest->expects($this->never())->method('create');

		$this->expectException(AccountAlreadyExistsException::class);
		$this->expectExceptionMessage('already exist');
		$this->service->createActor('bob', 'alice');
	}

	public function testCreateActorRefusesAUsernameStillInDeletionRetention(): void {
		$this->userManager->method('get')->willReturn($this->user('bob'));
		$deleted = $this->alice();
		$deleted->setDeleted(time() - 10);
		$this->actorsRequest->method('getFromUsername')->willReturn($deleted);

		$this->expectException(AccountAlreadyExistsException::class);
		$this->expectExceptionMessage('retention');
		$this->service->createActor('bob', 'alice');
	}

	public function testCreateActorRefusesASecondAccountForTheSameUser(): void {
		$this->userManager->method('get')->willReturn($this->user('alice'));
		$this->actorsRequest->method('getFromUsername')->willThrowException(new ActorDoesNotExistException());
		$this->actorsRequest->method('getFromUserId')->with('alice')->willReturn($this->alice());
		$this->actorsRequest->expects($this->never())->method('create');

		$this->expectException(AccountAlreadyExistsException::class);
		$this->expectExceptionMessage('account for this user already exist');
		$this->service->createActor('alice', 'alice2');
	}

	public function testDeleteActorMarksDeletesAndBroadcasts(): void {
		$alice = $this->alice();
		$this->actorsRequest->method('getFromUsername')->with('alice')->willReturn($alice);
		$this->actorsRequest->expects($this->once())->method('setAsDeleted')->with('alice');
		$personInterface = $this->createMock(PersonInterface::class);
		$personInterface->expects($this->once())->method('delete')->with($this->identicalTo($alice));
		$ap = $this->createMock(AP::class);
		$ap->method('getInterfaceFromType')->with(Person::TYPE)->willReturn($personInterface);
		AP::set($ap);
		$this->signatureService->expects($this->once())
			->method('signObject')
			->with($this->identicalTo($alice), $this->isInstanceOf(Delete::class));
		$this->activityService->expects($this->once())
			->method('request')
			->with($this->callback(function (Delete $delete) {
				$this->assertSame(self::ALICE . '#delete', $delete->getId());
				$this->assertSame(self::ALICE, $delete->getActorId());
				$this->assertSame(self::ALICE, $delete->getObjectId());
				$this->assertSame([ACore::CONTEXT_PUBLIC], $delete->getToArray());
				$paths = $delete->getInstancePaths();
				$this->assertCount(1, $paths);
				$this->assertSame(self::ALICE . '/inbox', $paths[0]->getUri());
				$this->assertSame(InstancePath::TYPE_ALL, $paths[0]->getType());
				$this->assertSame(InstancePath::PRIORITY_LOW, $paths[0]->getPriority());

				return true;
			}))
			->willReturn('token');

		$this->service->deleteActor('alice');
	}

	// --- deleting your own account --------------------------------------

	/**
	 * The handle typed out is the whole of the guard, and deliberately not a
	 * password: an account signed in through SSO has none to give, and asking
	 * for one would have made this an administrator's job again for exactly
	 * the installations that federate most.
	 */
	public function testDeletingYourOwnAccountNeedsTheHandleTypedOut(): void {
		$alice = $this->alice();
		$alice->setAccount('alice@cloud.example');
		$this->aliceIsKnown($alice);
		$this->actorsRequest->method('getFromUsername')->with('alice')->willReturn($alice);
		$this->actorsRequest->expects($this->once())->method('setAsDeleted')->with('alice');
		$this->deleteThrough($alice);

		$this->service->deleteOwnAccount('alice', 'alice@cloud.example');
	}

	public function testExternalAccountDeletionCanValidateTheHandleWithoutDeletingTheActorFirst(): void {
		$alice = $this->alice();
		$alice->setAccount('alice@cloud.example');
		$this->aliceIsKnown($alice);
		$this->actorsRequest->method('getFromUsername')->with('alice')->willReturn($alice);
		$this->actorsRequest->expects($this->never())->method('setAsDeleted');
		$this->activityService->expects($this->never())->method('request');

		$this->service->assertOwnAccountDeletionConfirmed('alice', 'alice@cloud.example');
	}

	public function testTheShortHandleConfirmsItToo(): void {
		$alice = $this->alice();
		$alice->setAccount('alice@cloud.example');
		$this->aliceIsKnown($alice);
		$this->actorsRequest->method('getFromUsername')->with('alice')->willReturn($alice);
		$this->actorsRequest->expects($this->once())->method('setAsDeleted')->with('alice');
		$this->deleteThrough($alice);

		$this->service->deleteOwnAccount('alice', '@Alice');
	}

	public function testDeletingYourOwnAccountWithTheWrongHandleDeletesNothing(): void {
		$alice = $this->alice();
		$alice->setAccount('alice@cloud.example');
		$this->aliceIsKnown($alice);
		$this->actorsRequest->expects($this->never())->method('setAsDeleted');
		$this->activityService->expects($this->never())->method('request');

		$this->expectException(InvalidResourceException::class);

		$this->service->deleteOwnAccount('alice', 'bob@cloud.example');
	}

	public function testAnEmptyConfirmationDeletesNothing(): void {
		$alice = $this->alice();
		$alice->setAccount('alice@cloud.example');
		$this->aliceIsKnown($alice);
		$this->actorsRequest->expects($this->never())->method('setAsDeleted');

		$this->expectException(InvalidResourceException::class);

		$this->service->deleteOwnAccount('alice', '   ');
	}

	/** The rest of the deletion path, which these tests do not re-assert. */
	private function deleteThrough(Person $actor): void {
		$personInterface = $this->createStub(PersonInterface::class);
		$ap = $this->createMock(AP::class);
		$ap->method('getInterfaceFromType')->with(Person::TYPE)->willReturn($personInterface);
		AP::set($ap);
		$this->activityService->method('request')->willReturn('token');
	}

	/**
	 * A token that outlived the account is a token that would bring it back:
	 * `verify_credentials` creates an actor for a user who has none, so a
	 * phone left running would quietly re-make the account under a derived
	 * handle a minute after it was deleted. Found on devel, where one call to
	 * `verify_credentials` after a delete answered with `deletetest_2`.
	 */
	public function testDeletingAnAccountTakesTheAppsSignedInToItWithIt(): void {
		$alice = $this->alice();
		$this->actorsRequest->method('getFromUsername')->with('alice')->willReturn($alice);
		$this->clientAuthRequest->expects($this->once())->method('deleteRelatedId')->with('alice');
		$this->deleteThrough($alice);

		$this->service->deleteActor('alice');
	}

	public function testDeleteActorIgnoresUnknownHandles(): void {
		$this->actorsRequest->method('getFromUsername')->willThrowException(new ActorDoesNotExistException());
		$this->actorsRequest->expects($this->never())->method('setAsDeleted');
		$this->activityService->expects($this->never())->method('request');

		$this->service->deleteActor('nobody');
	}

	public function testCacheLocalActorByUsernameEnrichesAndCachesTheActor(): void {
		$alice = $this->alice();
		$this->actorsRequest->method('getFromUsername')->with('alice')->willReturn($alice);
		$this->userManager->method('get')->with('alice')->willReturn($this->user('alice'));
		$this->withDisplayName('Alice Wonder', IAccountManager::SCOPE_PUBLISHED);
		$this->documentService->expects($this->once())->method('cacheLocalAvatarByUsername')->with($this->identicalTo($alice))->willReturn('icon-42');
		$this->actorService->method('getCachedHeader')->with($this->identicalTo($alice))->willReturn('https://cloud.example.com/header.jpg');
		$this->followsRequest->method('countFollowers')->with(self::ALICE)->willReturn(3);
		$this->followsRequest->method('countFollowing')->with(self::ALICE)->willReturn(2);
		$this->streamRequest->method('countNotesFromActorId')->with(self::ALICE)->willReturn(7);
		$lastNote = new Note();
		$lastNote->setPublishedTime(mktime(12, 0, 0, 9, 1, 2026));
		$this->streamRequest->method('lastNoteFromActorId')->with(self::ALICE)->willReturn($lastNote);
		$this->actorService->expects($this->once())->method('cacheLocalActor')->with($this->identicalTo($alice));

		$this->service->cacheLocalActorByUsername('alice');

		$this->assertSame('Alice Wonder', $alice->getName());
		$this->assertSame('icon-42', $alice->getIconId());
		$this->assertSame('https://cloud.example.com/header.jpg', $alice->getHeader());
		$this->assertSame(
			['followers' => 3, 'following' => 2, 'follow_requests' => 0, 'post' => 7],
			$alice->getDetails('count')
		);
		$this->assertSame('2026-09-01', $alice->getDetailsAll()['last_post_creation']);
	}

	/**
	 * Suspending a local account drops the cached copy of its actor, which is
	 * what the actor document, the WebFinger answer and the timelines are
	 * served from. This is reached from the cache cron, from every profile
	 * change and from the first reader who finds the copy missing, and it used
	 * to put it straight back: a local account a moderator had suspended was
	 * served again a few minutes later, with an empty outbox.
	 */
	public function testASuspendedLocalAccountIsNotCachedBackIntoBeingServed(): void {
		$alice = $this->alice();
		$this->actorsRequest->method('getFromUsername')->with('alice')->willReturn($alice);
		$this->moderationRequest->method('levelOf')->with(self::ALICE)->willReturn(Moderation::SUSPEND);

		$this->cacheActorsRequest->expects($this->once())->method('deleteCacheById')->with(self::ALICE);
		$this->actorService->expects($this->never())->method('cacheLocalActor');

		$this->service->cacheLocalActorByUsername('alice');
	}

	public function testASilencedAccountIsStillServed(): void {
		$alice = $this->alice();
		$this->actorsRequest->method('getFromUsername')->willReturn($alice);
		$this->userManager->method('get')->willReturn($this->user('alice'));
		$this->withDisplayName('Alice Wonder', IAccountManager::SCOPE_PUBLISHED);
		$this->documentService->method('cacheLocalAvatarByUsername')->willThrowException(new ItemUnknownException());
		$this->streamRequest->method('lastNoteFromActorId')->willThrowException(new StreamNotFoundException());
		$this->moderationRequest->method('levelOf')->willReturn(Moderation::SILENCE);

		$this->cacheActorsRequest->expects($this->never())->method('deleteCacheById');
		$this->actorService->expects($this->once())->method('cacheLocalActor');

		$this->service->cacheLocalActorByUsername('alice');
	}

	public function testCacheLocalActorByUsernameKeepsAPrivateDisplayNameOut(): void {
		$alice = $this->alice();
		$alice->setName('alice');
		$this->actorsRequest->method('getFromUsername')->willReturn($alice);
		$this->userManager->method('get')->willReturn($this->user('alice'));
		$this->withDisplayName('Alice Wonder', IAccountManager::SCOPE_PRIVATE);
		$this->documentService->method('cacheLocalAvatarByUsername')->willThrowException(new ItemUnknownException());
		$this->streamRequest->method('lastNoteFromActorId')->willThrowException(new StreamNotFoundException());
		$this->actorService->expects($this->once())->method('cacheLocalActor');

		$this->service->cacheLocalActorByUsername('alice');

		$this->assertSame('alice', $alice->getName());
		$this->assertSame('', $alice->getIconId());
		$this->assertSame('', $alice->getDetailsAll()['last_post_creation']);
	}

	/**
	 * Every caller has an account in hand and passes its handle, so a handle
	 * nothing answers to is worth saying out loud: in silence it hid a caller
	 * passing a user id instead, and with it the profile updates of every
	 * account whose handle is not its user id.
	 */
	public function testCacheLocalActorByUsernameSaysWhenNoActorAnswersToTheHandle(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$service = new AccountService(
			$this->userManager,
			$this->userSession,
			$this->accountManager,
			$this->actorsRequest,
			$this->channelsRequest,
			$this->clientAuthRequest,
			$this->followsRequest,
			$this->streamRequest,
			$this->actorService,
			$this->activityService,
			$this->documentService,
			$this->signatureService,
			$this->configService,
			$this->accessBlockService,
			$this->cacheActorService,
			$this->cacheActorsRequest,
			$this->moderationRequest,
			$logger,
		);

		$this->actorsRequest->method('getFromUsername')->willThrowException(new ActorDoesNotExistException());
		$this->actorService->expects($this->never())->method('cacheLocalActor');
		$logger->expects($this->once())->method('warning')->with(
			'no Social account under the handle to cache',
			$this->callback(static fn (array $context): bool => $context['handle'] === 'nobody')
		);

		$service->cacheLocalActorByUsername('nobody');
	}

	public function testCacheLocalActorDetailCountOnlyAppliesToLocalActors(): void {
		$remote = new Person();
		$remote->setId('https://remote.example/users/bob');
		$this->actorService->expects($this->never())->method('cacheLocalActorDetails');

		$this->service->cacheLocalActorDetailCount($remote);
	}

	public function testCacheLocalActorDetailCountStoresTheCounters(): void {
		$alice = $this->alice();
		$this->followsRequest->method('countFollowers')->willReturn(1);
		$this->followsRequest->method('countFollowing')->willReturn(4);
		$this->followsRequest->method('countPendingRequests')->willReturn(2);
		$this->streamRequest->method('countNotesFromActorId')->willReturn(9);
		$this->streamRequest->method('lastNoteFromActorId')->willThrowException(new StreamNotFoundException());
		$this->actorService->expects($this->once())->method('cacheLocalActorDetails')->with($this->identicalTo($alice));

		$this->service->cacheLocalActorDetailCount($alice);

		$this->assertSame(
			['followers' => 1, 'following' => 4, 'follow_requests' => 2, 'post' => 9],
			$alice->getDetails('count')
		);
	}

	public function testSetLockedStoresTheFlagAndRefreshesTheCache(): void {
		$alice = $this->alice();
		$this->userManager->method('get')->with('alice')->willReturn($this->user('alice'));
		$this->actorsRequest->method('getFromUserId')->with('alice')->willReturn($alice);
		$this->actorsRequest->method('getFromUsername')->with('alice')->willReturn($alice);
		$this->actorsRequest->expects($this->once())
			->method('updateLocked')
			->willReturnCallback(function (Person $actor): void {
				$this->assertTrue($actor->isLocked());
			});
		// the refreshed cache document is what federates manuallyApprovesFollowers
		$this->actorService->expects($this->once())->method('cacheLocalActor')
			->with($this->identicalTo($alice));

		$this->service->setLocked('alice', true);

		$this->assertTrue($alice->isLocked());
	}

	public function testSetLockedCanUnlock(): void {
		$alice = $this->alice();
		$alice->setLocked(true);
		$this->userManager->method('get')->with('alice')->willReturn($this->user('alice'));
		$this->actorsRequest->method('getFromUserId')->with('alice')->willReturn($alice);
		$this->actorsRequest->method('getFromUsername')->with('alice')->willReturn($alice);
		$this->actorsRequest->expects($this->once())->method('updateLocked');

		$this->service->setLocked('alice', false);

		$this->assertFalse($alice->isLocked());
	}

	public function testManageCacheLocalActorsRefreshesEveryLocalActor(): void {
		$alice = $this->alice();
		$bob = $this->alice();
		$bob->setPreferredUsername('bob');
		// one page, then the end of the table
		$this->actorsRequest->method('getPage')->willReturnCallback(
			static fn (int $limit, string $after = ''): array => ($after === '') ? [$alice, $bob] : []
		);
		$refreshed = [];
		$this->actorsRequest->expects($this->exactly(2))
			->method('getFromUsername')
			->willReturnCallback(function (string $username) use (&$refreshed): never {
				$refreshed[] = $username;

				throw new ActorDoesNotExistException();
			});

		$this->assertSame(2, $this->service->manageCacheLocalActors());
		$this->assertSame(['alice', 'bob'], $refreshed);
	}

	/**
	 * The walk used to read every local account into one array on every cron
	 * pass. At a million accounts that array does not fit in memory, and the
	 * dozen queries and avatar read behind each of them are hours of work in a
	 * job that fires every twelve minutes — silently, because nothing reports
	 * a cron that is merely falling behind.
	 */
	public function testTheLocalActorWalkStopsAtTheDeadlineAndResumesWhereItStopped(): void {
		$pages = [];
		$this->actorsRequest->method('getPage')->willReturnCallback(
			function (int $limit, string $after = '') use (&$pages): array {
				$pages[] = $after;
				// always another page, so only the deadline can stop it
				$actor = $this->alice();
				$actor->setId('https://cloud.example/@a' . count($pages));

				return [$actor];
			}
		);
		$this->actorsRequest->method('getFromUsername')
			->willThrowException(new ActorDoesNotExistException());

		// a deadline already past: one page, then stop
		$this->service->manageCacheLocalActors(time() - 1);

		$this->assertCount(1, $pages, 'the pass stopped at its deadline');
		$this->assertSame('', $pages[0], 'and it began where the last one left off');
		$this->assertSame(
			md5('https://cloud.example/@a1'),
			$this->appValues[ConfigService::SOCIAL_LOCAL_ACTOR_CURSOR] ?? null,
			'the cursor is where the next pass carries on from'
		);
	}

	public function testManageDeletedActorsSkipsLiveActors(): void {
		$this->actorsRequest->method('getDeletedBefore')->willReturn([]);
		$this->actorsRequest->expects($this->never())->method('delete');

		$this->assertSame(0, $this->service->manageDeletedActors());
	}

	/**
	 * The reaper asks the database for the expired deletions only, in a
	 * bounded batch: reading every local account with its key pair to find
	 * them is a memory-limit fatal on a large instance.
	 */
	public function testManageDeletedActorsReadsOnlyTheExpiredDeletions(): void {
		$gone = $this->alice();
		$this->actorsRequest->expects($this->never())->method('getAll');
		$this->actorsRequest->expects($this->once())->method('getDeletedBefore')
			->with(
				$this->callback(fn (int $before): bool => abs($before - (time() - AccountService::TIME_RETENTION)) <= 2),
				AccountService::DELETED_ACTOR_BATCH
			)
			->willReturn([$gone]);
		$this->actorsRequest->expects($this->once())->method('delete')->with($gone->getPreferredUsername());

		$this->assertSame(1, $this->service->manageDeletedActors());
	}

	public function testBlindKeyRotationWithoutActorsDoesNothing(): void {
		$this->actorsRequest->method('getAll')->willReturn([]);
		$this->signatureService->expects($this->never())->method('generateKeys');

		$this->assertSame(0, $this->service->blindKeyRotation());
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function displayNameScopeProvider(): array {
		return [
			// the default scope on a stock Nextcloud: requiring SCOPE_PUBLISHED
			// meant no local actor ever federated a display name at all
			'federated is published' => [IAccountManager::SCOPE_FEDERATED, true],
			'published is published' => [IAccountManager::SCOPE_PUBLISHED, true],
			'local is kept back' => [IAccountManager::SCOPE_LOCAL, false],
			'private is kept back' => [IAccountManager::SCOPE_PRIVATE, false],
		];
	}

	#[DataProvider('displayNameScopeProvider')]
	public function testTheDisplayNameFederatesUnlessTheUserAskedOtherwise(
		string $scope,
		bool $expectPublished,
	): void {
		$alice = $this->alice();
		$alice->setName('alice');
		$this->actorsRequest->method('getFromUsername')->willReturn($alice);
		$this->userManager->method('get')->willReturn($this->user('alice'));
		$this->withDisplayName('Alice Wonder', $scope);
		$this->documentService->method('cacheLocalAvatarByUsername')
			->willThrowException(new ItemUnknownException());
		$this->streamRequest->method('lastNoteFromActorId')
			->willThrowException(new StreamNotFoundException());

		$this->service->cacheLocalActorByUsername('alice');

		$this->assertSame($expectPublished ? 'Alice Wonder' : 'alice', $alice->getName());
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function avatarScopeProvider(): array {
		return [
			'federated is published' => [IAccountManager::SCOPE_FEDERATED, true],
			'published is published' => [IAccountManager::SCOPE_PUBLISHED, true],
			'local is kept back' => [IAccountManager::SCOPE_LOCAL, false],
			'private is kept back' => [IAccountManager::SCOPE_PRIVATE, false],
		];
	}

	/**
	 * Reported in 2019 (#440): a picture set to "local only" was published to
	 * every server and every anonymous visitor regardless. The display name
	 * had honoured the setting for a while; the picture -- the one part of it
	 * a reader on another server can actually see -- did not.
	 */
	#[DataProvider('avatarScopeProvider')]
	public function testThePictureFederatesUnlessTheUserAskedOtherwise(
		string $scope,
		bool $expectPublished,
	): void {
		$alice = $this->alice();
		$this->actorsRequest->method('getFromUsername')->willReturn($alice);
		$this->userManager->method('get')->willReturn($this->user('alice'));
		$this->withDisplayName('Alice Wonder', IAccountManager::SCOPE_FEDERATED);
		$this->avatarScope = $scope;
		$this->documentService->method('cacheLocalAvatarByUsername')->willReturn('icon-42');
		$this->streamRequest->method('lastNoteFromActorId')
			->willThrowException(new StreamNotFoundException());

		$this->service->cacheLocalActorByUsername('alice');

		$this->assertSame($expectPublished ? 'icon-42' : '', $alice->getIconId());
	}

	// the fediverse field of the Nextcloud profile

	/** An account this app created is an answer the person did not have to type. */
	public function testWritesTheHandleIntoAnEmptyFediverseField(): void {
		$alice = $this->alice();
		$this->actorsRequest->method('getFromUsername')->willReturn($alice);
		$this->userManager->method('get')->willReturn($this->user('alice'));
		$this->configService->method('getSocialAddress')->willReturn('cloud.example');
		$this->withDisplayName('Alice', IAccountManager::SCOPE_FEDERATED);
		$this->documentService->method('cacheLocalAvatarByUsername')
			->willThrowException(new ItemUnknownException());
		$this->streamRequest->method('lastNoteFromActorId')
			->willThrowException(new StreamNotFoundException());

		$this->service->cacheLocalActorByUsername('alice');

		$this->assertSame(['alice@cloud.example'], $this->fediverseWrites);
		$this->assertSame(1, $this->accountUpdates);
	}

	/** A value already there is theirs -- a Mastodon account, most likely. */
	public function testLeavesAFediverseFieldSomebodyFilledAlone(): void {
		$alice = $this->alice();
		$this->actorsRequest->method('getFromUsername')->willReturn($alice);
		$this->userManager->method('get')->willReturn($this->user('alice'));
		$this->configService->method('getSocialAddress')->willReturn('cloud.example');
		$this->fediverseField = ['value' => 'alice@mastodon.social', 'scope' => IAccountManager::SCOPE_LOCAL];
		$this->withDisplayName('Alice', IAccountManager::SCOPE_FEDERATED);
		$this->documentService->method('cacheLocalAvatarByUsername')
			->willThrowException(new ItemUnknownException());
		$this->streamRequest->method('lastNoteFromActorId')
			->willThrowException(new StreamNotFoundException());

		$this->service->cacheLocalActorByUsername('alice');

		$this->assertSame([], $this->fediverseWrites);
		$this->assertSame(0, $this->accountUpdates);
	}

	/**
	 * The second pass -- the write fires UserUpdatedEvent, whose listener runs
	 * this again -- finds the field filled and writes nothing.
	 */
	public function testTheSecondPassAfterWritingIsSilent(): void {
		$alice = $this->alice();
		$this->actorsRequest->method('getFromUsername')->willReturn($alice);
		$this->userManager->method('get')->willReturn($this->user('alice'));
		$this->configService->method('getSocialAddress')->willReturn('cloud.example');
		$this->withDisplayName('Alice', IAccountManager::SCOPE_FEDERATED);
		$this->documentService->method('cacheLocalAvatarByUsername')
			->willThrowException(new ItemUnknownException());
		$this->streamRequest->method('lastNoteFromActorId')
			->willThrowException(new StreamNotFoundException());

		$this->service->cacheLocalActorByUsername('alice');
		$this->service->cacheLocalActorByUsername('alice');

		$this->assertSame(['alice@cloud.example'], $this->fediverseWrites);
		$this->assertSame(1, $this->accountUpdates);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function handleProvider(): array {
		return [
			// already usable: left exactly as it is, so existing installs keep
			// the handles their actors already have
			'a plain user id is kept' => ['alice', 'alice'],
			'digits and underscore are fine' => ['alice_99', 'alice_99'],
			'a dot inside is fine' => ['alice.wonder', 'alice.wonder'],
			// these produce an acct: no remote server can resolve
			'a space is folded' => ['alice wonder', 'alice_wonder'],
			'an email-shaped id is folded' => ['alice@example.org', 'alice_example.org'],
			'an ldap uuid is folded' => ['{8f3a1b2c-dead}', '8f3a1b2c-dead'],
			// already valid, so untouched: lookups are case-insensitive and
			// rewriting it would change handles that already work
			'a valid id keeps its case' => ['ALICE', 'ALICE'],
			'runs collapse and edges are trimmed' => ['--alice???wonder--', 'alice_wonder'],
		];
	}

	#[DataProvider('handleProvider')]
	public function testGenerateHandleFromUserId(string $userId, string $expected): void {
		$this->actorsRequest->method('getFromUsername')
			->willThrowException(new ActorDoesNotExistException());

		$this->assertSame($expected, $this->service->generateHandleFromUserId($userId));
	}

	public function testGenerateHandleFallsBackWhenNothingUsableSurvives(): void {
		$this->actorsRequest->method('getFromUsername')
			->willThrowException(new ActorDoesNotExistException());

		// a purely non-latin id leaves nothing in the allowed set
		$handle = $this->service->generateHandleFromUserId('這是一個帳號');

		$this->assertMatchesRegularExpression('/^user_[0-9a-f]{12}$/', $handle);
	}

	public function testGenerateHandleIsStableForTheSameUserId(): void {
		$this->actorsRequest->method('getFromUsername')
			->willThrowException(new ActorDoesNotExistException());

		$this->assertSame(
			$this->service->generateHandleFromUserId('這是一個帳號'),
			$this->service->generateHandleFromUserId('這是一個帳號')
		);
	}

	public function testGenerateHandleAvoidsAHandleThatIsTakenAlready(): void {
		$taken = $this->alice();
		$this->actorsRequest->method('getFromUsername')
			->willReturnCallback(function (string $username) use ($taken): Person {
				if ($username === 'alice') {
					return $taken;
				}

				throw new ActorDoesNotExistException();
			});

		$this->assertSame('alice_2', $this->service->generateHandleFromUserId('alice'));
	}

	public function testCreateActorRefusesAHandleTheFediverseCannotResolve(): void {
		$this->userManager->method('get')->willReturn($this->user('alice'));
		$this->expectException(InvalidHandleException::class);

		$this->service->createActor('alice', 'alice wonder');
	}

	public function testCreateActorRefusesAnEmptyHandle(): void {
		$this->userManager->method('get')->willReturn($this->user('alice'));
		$this->expectException(InvalidHandleException::class);

		$this->service->createActor('alice', '');
	}

	// --- setActorFlags -------------------------------------------------

	private function aliceIsKnown(Person $alice): void {
		$this->userManager->method('get')->with('alice')->willReturn($this->user('alice'));
		$this->actorsRequest->method('getFromUserId')->with('alice')->willReturn($alice);
		$this->actorsRequest->method('getFromUsername')->with('alice')->willReturn($alice);
	}

	public function testSetActorFlagsStoresBothFlagsAndRefreshesTheCache(): void {
		$alice = $this->alice();
		$this->aliceIsKnown($alice);
		$this->actorsRequest->expects($this->once())->method('updateFlags')
			->willReturnCallback(function (Person $actor): void {
				$this->assertTrue($actor->isDiscoverable());
				$this->assertTrue($actor->isIndexable());
			});
		// the refreshed cache document is what federates the flags
		$this->actorService->expects($this->once())->method('cacheLocalActor')
			->with($this->identicalTo($alice));

		$this->service->setActorFlags('alice', ['discoverable' => true, 'indexable' => true]);
	}

	public function testSetActorFlagsChangesOnlyTheKeysItIsGiven(): void {
		$alice = $this->alice();
		$alice->setDiscoverable(true)->setIndexable(true);
		$this->aliceIsKnown($alice);
		$this->actorsRequest->expects($this->once())->method('updateFlags');

		$this->service->setActorFlags('alice', ['indexable' => false]);

		$this->assertTrue($alice->isDiscoverable(), 'a key that was not sent is not a key that was cleared');
		$this->assertFalse($alice->isIndexable());
	}

	public function testSetActorFlagsIgnoresUnknownKeysAndNonBooleans(): void {
		$alice = $this->alice();
		$this->aliceIsKnown($alice);
		$this->actorsRequest->expects($this->once())->method('updateFlags');

		$this->service->setActorFlags('alice', ['discoverable' => 'true', 'locked' => true, 'bot' => true]);

		$this->assertTrue($alice->isDiscoverable(), 'the string forms clients send are accepted');
		$this->assertFalse($alice->isLocked(), 'locked has its own setter and is not a flag here');
	}

	public function testSetActorFlagsWithNothingToChangeWritesNothing(): void {
		$alice = $this->alice();
		$this->aliceIsKnown($alice);
		$this->actorsRequest->expects($this->never())->method('updateFlags');
		$this->actorService->expects($this->never())->method('cacheLocalActor');

		// `bot` is a flag now; this is about a key that is not one at all
		$this->service->setActorFlags('alice', ['favourite_colour' => 'blue']);
	}

	/**
	 * Mastodon's `bot`, which a client's profile editor sends with every save.
	 * It was accepted and dropped, so an account marked as automated came back
	 * unmarked on the next read — and the actor went on publishing `Person`,
	 * which is the only thing that says so to a peer.
	 */
	public function testSetActorFlagsMarksAnAccountAutomated(): void {
		$alice = $this->alice();
		$this->aliceIsKnown($alice);
		$this->actorsRequest->expects($this->once())
			->method('updateFlags')
			->with($this->callback(static fn (Person $actor): bool => $actor->isBot()
				&& $actor->getType() === 'Service'));

		$this->service->setActorFlags('alice', ['bot' => true]);
	}

	// --- aliases and moves ---------------------------------------------

	public function testSetAlsoKnownAsStoresTheListAndRefreshesTheCache(): void {
		$alice = $this->alice();
		$this->aliceIsKnown($alice);
		$this->actorsRequest->expects($this->once())->method('updateAlsoKnownAs')
			->willReturnCallback(function (Person $actor): void {
				$this->assertSame(['https://old.example/users/alice'], $actor->getAlsoKnownAs());
			});
		$this->actorService->expects($this->once())->method('cacheLocalActor')
			->with($this->identicalTo($alice));

		$this->service->setAlsoKnownAs('alice', ['https://old.example/users/alice']);
	}

	// --- the bio ---------------------------------------------------------

	public function testSetSummaryStoresThePlainTextAndRefreshesTheCache(): void {
		$alice = $this->alice();
		$this->aliceIsKnown($alice);
		$this->actorsRequest->expects($this->once())->method('updateSummary')
			->willReturnCallback(function (Person $actor): void {
				$this->assertSame('I keep bees.', $actor->getSummary());
			});
		// the refreshed cache document is what carries the bio to the actor
		// document and to the account entity
		$this->actorService->expects($this->once())->method('cacheLocalActor')
			->with($this->identicalTo($alice));

		$this->service->setSummary('alice', 'I keep bees.');

		$this->assertSame('I keep bees.', $alice->getSummary());
	}

	public function testSetSummaryTrimsAndNormalisesNewlinesOnly(): void {
		$alice = $this->alice();
		$this->aliceIsKnown($alice);
		$this->actorsRequest->expects($this->once())->method('updateSummary');

		$this->service->setSummary('alice', "  I keep bees.\r\n& goats.  ");

		$this->assertSame("I keep bees.\n& goats.", $alice->getSummary());
	}

	/**
	 * A bio is plain text, and `<` is a character people type. Stripping tags
	 * on the way in read the bare `<` as the start of one and swallowed the
	 * rest of the line: `Maths: a<b and b>c` was stored as `Maths: ac`.
	 */
	#[DataProvider('plainTextBios')]
	public function testSetSummaryStoresThePlainTextAsItWasTyped(string $typed): void {
		$alice = $this->alice();
		$this->aliceIsKnown($alice);
		$this->actorsRequest->expects($this->once())->method('updateSummary');

		$this->service->setSummary('alice', $typed);

		$this->assertSame($typed, $alice->getSummary());
	}

	/** @return iterable<string, array{string}> */
	public static function plainTextBios(): iterable {
		yield 'an unclosed angle bracket' => ['Maths: a<b and b>c'];
		yield 'a bare less-than' => ['I <3 cats & dogs'];
		yield 'an ampersand entity as typed' => ['bees &amp; goats'];
		yield 'something that looks like markup' => ['<not a tag> and </neither>'];
	}

	/**
	 * The write path was only half of it: the row is flattened again every time
	 * it is read, so a bio survived being saved and was destroyed on the next
	 * page load.
	 */
	#[DataProvider('plainTextBios')]
	public function testAStoredBioSurvivesComingBackOutOfTheDatabase(string $typed): void {
		$alice = $this->alice();

		$alice->importFromDatabase([
			'preferred_username' => 'alice',
			'summary' => $typed,
		]);

		$this->assertSame($typed, $alice->getSummary());
	}

	/**
	 * Nothing is stripped on the way in, so the escaping on the way out is the
	 * whole of the defence: a bio is text, and must never become an element.
	 */
	public function testAStoredBioIsEscapedWhenItIsRendered(): void {
		$alice = $this->alice();
		$this->aliceIsKnown($alice);
		$this->actorsRequest->expects($this->once())->method('updateSummary');

		$this->service->setSummary('alice', '<script>alert(1)</script>');

		$rendered = $alice->exportAsActivityPub()['summary'] ?? '';
		$this->assertStringNotContainsString('<script', $rendered);
		$this->assertStringContainsString('&lt;script&gt;', $rendered);
	}

	public function testSetSummaryTruncatesAtTheMastodonLimit(): void {
		$alice = $this->alice();
		$this->aliceIsKnown($alice);
		$this->actorsRequest->expects($this->once())->method('updateSummary');

		$this->service->setSummary('alice', str_repeat('é', 600));

		$this->assertSame(500, mb_strlen($alice->getSummary()), 'counted in characters, not bytes');
	}

	public function testSetSummaryCanClearTheBio(): void {
		$alice = $this->alice();
		$alice->setSummary('I keep bees.');
		$this->aliceIsKnown($alice);
		$this->actorsRequest->expects($this->once())->method('updateSummary');

		$this->service->setSummary('alice', '');

		$this->assertSame('', $alice->getSummary());
	}

	public function testSetSummaryTellsTheFollowersAboutIt(): void {
		$alice = $this->alice();
		$this->aliceIsKnown($alice);
		$this->activityService->expects($this->once())->method('updateActivity')
			->willReturnCallback(function (Person $actor, Person $item): string {
				$this->assertSame(self::ALICE, $actor->getId());
				$this->assertSame('I keep bees.', $item->getSummary());
				$paths = $item->getInstancePaths();
				$this->assertCount(1, $paths);
				$this->assertSame(InstancePath::TYPE_FOLLOWERS, $paths[0]->getType());
				$this->assertSame(self::ALICE, $paths[0]->getUri());

				return 'token';
			});

		$this->service->setSummary('alice', 'I keep bees.');
	}

	public function testSetSummarySurvivesAFailedFederation(): void {
		$alice = $this->alice();
		$this->aliceIsKnown($alice);
		$this->actorsRequest->expects($this->once())->method('updateSummary');
		$this->activityService->method('updateActivity')
			->willThrowException(new Exception('no route to host'));

		$this->service->setSummary('alice', 'I keep bees.');

		$this->assertSame('I keep bees.', $alice->getSummary(), 'the bio is stored even if nobody could be told');
	}

	public function testCacheLocalActorByUsernameKeepsTheStoredBio(): void {
		$alice = $this->alice();
		$alice->setSummary('I keep bees.');
		$this->userManager->method('get')->with('alice')->willReturn($this->user('alice'));
		$this->actorsRequest->method('getFromUsername')->with('alice')->willReturn($alice);
		$this->withDisplayName('Alice', IAccountManager::SCOPE_FEDERATED);
		$this->actorService->expects($this->once())->method('cacheLocalActor')
			->willReturnCallback(function (Person $actor): void {
				$this->assertSame(
					'I keep bees.',
					$actor->getSummary(),
					'a re-sync rebuilds the actor from Nextcloud and must not blank the bio'
				);
			});

		$this->service->cacheLocalActorByUsername('alice');
	}

	public function testSetMovedToStoresTheTargetAndRefreshesTheCache(): void {
		$alice = $this->alice();
		$this->aliceIsKnown($alice);
		$this->actorsRequest->expects($this->once())->method('updateMovedTo')
			->willReturnCallback(function (Person $actor): void {
				$this->assertSame('https://new.example/users/alice', $actor->getMovedTo());
			});
		$this->actorService->expects($this->once())->method('cacheLocalActor')
			->with($this->identicalTo($alice));

		$this->service->setMovedTo('alice', 'https://new.example/users/alice');
	}
	// getDefaultPrivacy() / setDefaultPrivacy()

	public function testTheDefaultAudienceIsPublicUntilSomebodyChangesIt(): void {
		$this->configService->method('getValueForUser')->willReturn('');

		$this->assertSame('public', $this->service->getDefaultPrivacy('alice'));
	}

	public function testTheStoredDefaultAudienceIsWhatAPostGetsWithoutOne(): void {
		$this->configService->method('getValueForUser')
			->with('alice', 'default_privacy')->willReturn('private');

		$this->assertSame('private', $this->service->getDefaultPrivacy('alice'));
	}

	public function testAStoredAudienceThisAppCannotPostWithIsIgnored(): void {
		// guessing at it would publish to the wrong audience; `public` is what
		// the account had before anyone set anything
		$this->configService->method('getValueForUser')->willReturn('friends');

		$this->assertSame('public', $this->service->getDefaultPrivacy('alice'));
	}

	public function testSettingTheDefaultAudienceStoresIt(): void {
		$this->configService->expects($this->once())
			->method('setValueForUser')->with('alice', 'default_privacy', 'private');

		$this->service->setDefaultPrivacy('alice', '  Private ');
	}

	public function testAnAudienceThisAppCannotPostWithIsRefused(): void {
		$this->configService->expects($this->never())->method('setValueForUser');

		$this->expectException(InvalidActionException::class);

		$this->service->setDefaultPrivacy('alice', 'friends');
	}

	public function testAnAudienceThisAppCannotPostWithIsRefusedBeforeAnythingIsWritten(): void {
		$this->configService->expects($this->never())->method('setValueForUser');

		$this->expectException(InvalidActionException::class);

		$this->service->assertDefaultPrivacy('friends');
	}

	public function testAKnownAudienceIsAccepted(): void {
		$this->service->assertDefaultPrivacy('  Unlisted ');

		$this->addToAssertionCount(1);
	}

	// what the followers are told

	/**
	 * Only the bio used to send an `Update{Person}`: a new display name,
	 * profile fields, a lock or a directory flag stayed on this server until
	 * a peer happened to fetch the actor again.
	 */
	#[DataProvider('profileChanges')]
	public function testAProfileChangeTellsTheFollowers(string $change): void {
		$alice = $this->alice();
		$user = $this->user('alice');
		$user->method('canChangeDisplayName')->willReturn(true);
		$this->userManager->method('get')->with('alice')->willReturn($user);
		$this->actorsRequest->method('getFromUserId')->with('alice')->willReturn($alice);
		$this->actorsRequest->method('getFromUsername')->with('alice')->willReturn($alice);
		$this->withDisplayName('Alice Liddell', IAccountManager::SCOPE_FEDERATED);

		$this->activityService->expects($this->once())->method('updateActivity')
			->willReturnCallback(function (Person $actor, Person $item) use ($change): string {
				$this->assertSame(self::ALICE, $actor->getId());
				$this->assertSame('Alice Liddell', $item->getName(), 'the Update names the account as it is shown');
				if ($change === 'fields') {
					$this->assertSame('https://alice.example', $item->getFields()[0]['value'] ?? '');
				}
				$paths = $item->getInstancePaths();
				$this->assertCount(1, $paths);
				$this->assertSame(InstancePath::TYPE_FOLLOWERS, $paths[0]->getType());

				return 'token';
			});

		match ($change) {
			'display name' => $this->service->setDisplayName('alice', 'Alice Liddell'),
			'fields' => $this->service->setFields('alice', [['name' => 'Web', 'value' => 'https://alice.example']]),
			'locked' => $this->service->setLocked('alice', true),
			'flags' => $this->service->setActorFlags('alice', ['discoverable' => true]),
		};
	}

	/** @return iterable<string, array{string}> */
	public static function profileChanges(): iterable {
		yield 'a display name' => ['display name'];
		yield 'profile fields' => ['fields'];
		yield 'a lock' => ['locked'];
		yield 'a directory flag' => ['flags'];
	}

	/**
	 * Mastodon handles several `Update`s of one actor in parallel under a lock
	 * per actor, and keeps whichever wins: a name, a bio and fields sent as
	 * three Updates arrived as a name and a bio.
	 */
	public function testSeveralChangesAtOnceAreToldInOneUpdateCarryingAllOfThem(): void {
		$alice = $this->alice();
		$this->aliceIsKnown($alice);

		$this->activityService->expects($this->once())->method('updateActivity')
			->willReturnCallback(function (Person $actor, Person $item): string {
				$this->assertSame('I keep bees.', $item->getSummary());
				$this->assertSame('https://alice.example', $item->getFields()[0]['value'] ?? '');
				$this->assertTrue($item->isLocked());

				return 'token';
			});

		$answer = $this->service->changingProfile('alice', function (): string {
			$this->service->setSummary('alice', 'I keep bees.');
			$this->service->setFields('alice', [['name' => 'Web', 'value' => 'https://alice.example']]);
			$this->service->setLocked('alice', true);

			return 'done';
		});

		$this->assertSame('done', $answer);
	}

	public function testNothingChangedTellsNobody(): void {
		$this->activityService->expects($this->never())->method('updateActivity');

		$this->service->changingProfile('alice', static fn (): bool => false);
	}

	public function testChangesMadeBeforeAFailureAreStillTold(): void {
		$alice = $this->alice();
		$this->aliceIsKnown($alice);
		$this->activityService->expects($this->once())->method('updateActivity')->willReturn('token');

		try {
			$this->service->changingProfile('alice', function (): void {
				$this->service->setSummary('alice', 'I keep bees.');
				throw new Exception('the avatar backend is down');
			});
			$this->fail('the failure is the caller\'s to see');
		} catch (Exception $e) {
			$this->assertSame('the avatar backend is down', $e->getMessage());
		}
	}

	public function testAProfileChangeIsStoredEvenIfNobodyCanBeTold(): void {
		$alice = $this->alice();
		$this->aliceIsKnown($alice);
		$this->activityService->method('updateActivity')->willThrowException(new Exception('no route to host'));
		$this->actorsRequest->expects($this->once())->method('updateLocked');

		$this->service->setLocked('alice', true);

		$this->assertTrue($alice->isLocked());
	}

	// setDisplayName()

	/**
	 * Core stores the name with the backend and then lets its own listener
	 * re-validate the profile, which throws when the `fediverse` property
	 * cannot be verified over the network. The stored name is kept.
	 */
	public function testADisplayNameIsKeptWhenAProfileListenerFailsAfterStoringIt(): void {
		$alice = $this->alice();
		$user = $this->user('alice');
		$user->method('canChangeDisplayName')->willReturn(true);
		$user->expects($this->once())->method('setDisplayName')->with(' Alice Liddell ')
			->willThrowException(new \InvalidArgumentException('fediverse'));
		$user->method('getDisplayName')->willReturn('Alice Liddell');
		$this->userManager->method('get')->with('alice')->willReturn($user);
		$this->actorsRequest->method('getFromUserId')->with('alice')->willReturn($alice);
		$this->actorsRequest->method('getFromUsername')->with('alice')->willReturn($alice);
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('the display name is stored'));
		// the actor is refreshed all the same, so the new name reaches it
		$this->actorService->expects($this->once())->method('cacheLocalActor')
			->with($this->identicalTo($alice));

		$this->service->setDisplayName('alice', ' Alice Liddell ');
	}

	public function testADisplayNameThatWasNotStoredIsAnError(): void {
		$user = $this->user('alice');
		$user->method('canChangeDisplayName')->willReturn(true);
		$user->method('setDisplayName')->willThrowException(new \RuntimeException('backend down'));
		$user->method('getDisplayName')->willReturn('Alice');
		$this->userManager->method('get')->with('alice')->willReturn($user);
		$this->actorService->expects($this->never())->method('cacheLocalActor');

		$this->expectException(\RuntimeException::class);

		$this->service->setDisplayName('alice', 'Alice Liddell');
	}

	public function testADisplayNameTheBackendOwnsIsRefusedBeforeAnythingIsWritten(): void {
		$user = $this->user('alice');
		$user->method('canChangeDisplayName')->willReturn(false);
		$user->expects($this->never())->method('setDisplayName');
		$this->userManager->method('get')->with('alice')->willReturn($user);

		$this->expectException(InvalidActionException::class);

		$this->service->assertDisplayNameWritable('alice');
	}

	// email-domain blocks

	/**
	 * Mastodon's email-domain block refuses a sign-up. There is no sign-up
	 * here — the server decides who gets a Nextcloud account — so what it
	 * refuses is the one decision this app makes: whether that account gets a
	 * fediverse identity at all.
	 */
	public function testAnAccountAtABlockedDomainIsGivenNoFediverseIdentity(): void {
		$this->userManager->method('get')->with('alice')
			->willReturn($this->user('alice', 'alice@throwaway.example'));
		$this->blockedEmails = ['alice@throwaway.example'];
		$this->actorsRequest->expects($this->never())->method('create');

		$this->expectException(InvalidHandleException::class);
		$this->expectExceptionMessage('addresses at that domain');

		$this->service->createActor('alice', 'alice');
	}

	/**
	 * A server that stores no addresses would otherwise hand out no fediverse
	 * accounts at all.
	 */
	public function testAnAccountWithNoAddressIsNotRefused(): void {
		$this->userManager->method('get')->with('alice')->willReturn($this->user('alice'));
		$this->actorsRequest->method('getFromUsername')
			->willThrowException(new ActorDoesNotExistException());
		$this->actorsRequest->method('getFromUserId')
			->willThrowException(new ActorDoesNotExistException());
		$this->blockedEmails = ['alice@throwaway.example'];

		$this->service->createActor('alice', 'alice');
		$this->addToAssertionCount(1);
	}

	// assertHandleAvailable() / linkExternalHandle() / linkedHandle(): the setup screen

	public function testAHandleMayBeTheOwnUserIdOrAnythingNobodyElseHolds(): void {
		$this->userManager->method('userExists')->willReturnCallback(fn (string $uid): bool => in_array($uid, ['alice', 'bob'], true));
		$this->actorsRequest->method('getFromUsername')->willThrowException(new ActorDoesNotExistException());

		$this->service->assertHandleAvailable('alice', 'alice');
		$this->service->assertHandleAvailable('alice', 'Alice');
		$this->service->assertHandleAvailable('alice', 'ali.ce_2');
		$this->addToAssertionCount(3);
	}

	public function testAnotherPersonsUserIdIsNotAHandleToTake(): void {
		// or one person publishes as another
		$this->userManager->method('userExists')->willReturnCallback(fn (string $uid): bool => $uid === 'bob');

		$this->expectException(InvalidHandleException::class);
		$this->service->assertHandleAvailable('alice', 'bob');
	}

	public function testAHandleAnActorAlreadyHoldsIsTaken(): void {
		$this->userManager->method('userExists')->willReturn(false);
		$this->actorsRequest->method('getFromUsername')->with('taken')->willReturn(new Person());

		$this->expectException(AccountAlreadyExistsException::class);
		$this->service->assertHandleAvailable('alice', 'taken');
	}

	public function testAHandleThatIsNotOneIsRefusedBeforeAnythingIsLookedUp(): void {
		$this->userManager->expects($this->never())->method('userExists');

		$this->expectException(InvalidHandleException::class);
		$this->service->assertHandleAvailable('alice', 'not a handle');
	}

	public function testLinkingAnAccountElsewhereWritesTheProfileFieldAndNothingElse(): void {
		$this->userManager->method('get')->with('alice')->willReturn($this->user('alice'));
		$this->fediverseField = ['scope' => IAccountManager::SCOPE_FEDERATED, 'value' => ''];
		$this->withDisplayName('Alice', IAccountManager::SCOPE_FEDERATED);
		$this->configService->method('getSocialAddress')->willReturn('cloud.example');
		$this->actorsRequest->expects($this->never())->method('create');

		$this->assertSame('alice@mastodon.social', $this->service->linkExternalHandle('alice', ' @alice@mastodon.social '));

		$this->assertSame(['alice@mastodon.social'], $this->fediverseWrites);
		$this->assertSame(1, $this->accountUpdates);
	}

	public function testLinkingRefusesWhatIsNotAnAddressOrIsThisServer(): void {
		$this->userManager->method('get')->willReturn($this->user('alice'));
		$this->configService->method('getSocialAddress')->willReturn('cloud.example');

		foreach (['alice', 'alice@', '@mastodon.social', 'alice@cloud.example', 'a b@x.example'] as $bad) {
			try {
				$this->service->linkExternalHandle('alice', $bad);
				$this->fail($bad . ' was accepted');
			} catch (InvalidHandleException $e) {
				$this->addToAssertionCount(1);
			}
		}
		$this->assertSame([], $this->fediverseWrites);
	}

	public function testTheLinkedHandleIsReadOffTheProfile(): void {
		$this->userManager->method('get')->with('alice')->willReturn($this->user('alice'));
		$this->fediverseField = ['scope' => IAccountManager::SCOPE_FEDERATED, 'value' => ' @alice@mastodon.social '];
		$this->withDisplayName('Alice', IAccountManager::SCOPE_FEDERATED);

		$this->assertSame('alice@mastodon.social', $this->service->linkedHandle('alice'));
	}
}
