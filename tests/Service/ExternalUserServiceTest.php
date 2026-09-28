<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Cron\ExternalPromoted;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\ExternalSignupsRequest;
use OCA\Social\Db\ExternalUsersRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\ExternalUserException;
use OCA\Social\External\ExternalUserBackend;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccessBlockService;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\ExternalUserService;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\IAccountProperty;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Security\ISecureRandom;
use OCP\User\Backend\ABackend;
use OCP\User\Backend\ICreateUserBackend;
use OCP\User\Backend\IPasswordHashBackend;
use OCP\User\Events\BeforeUserCreatedEvent;
use OCP\User\Events\UserCreatedEvent;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class ExternalUserServiceTest extends TestCase {
	private array $app = [];
	private ConfigService&MockObject $configService;
	private ExternalUsersRequest&MockObject $usersRequest;
	private CacheDocumentsRequest&MockObject $cacheDocumentsRequest;
	private ExternalSignupsRequest&MockObject $signupsRequest;
	private ExternalUserBackend&MockObject $userBackend;
	private IUserManager&MockObject $userManager;
	private IAccountManager&MockObject $accountManager;
	private IConfig&MockObject $config;
	private IAppManager&MockObject $appManager;
	private IDBConnection&MockObject $connection;
	private IEventDispatcher&MockObject $dispatcher;
	private IJobList&MockObject $jobList;
	private AccountService&MockObject $accountService;
	private AccessBlockService&MockObject $accessBlockService;
	private int $count = 0;

	protected function setUp(): void {
		$this->app = [
			ConfigService::SOCIAL_EXTERNAL_ENABLED => '1',
			ConfigService::SOCIAL_EXTERNAL_MAX => '10',
		];
		$this->configService = $this->createMock(ConfigService::class);
		$defaults = (new \ReflectionClass(ConfigService::class))->newInstanceWithoutConstructor()->defaults;
		$this->configService->method('getAppValue')->willReturnCallback(
			fn (string $key): string => (string)($this->app[$key] ?? $defaults[$key] ?? '')
		);
		$this->configService->method('getAppValueInt')->willReturnCallback(
			fn (string $key): int => (int)($this->app[$key] ?? $defaults[$key] ?? 0)
		);
		$this->configService->method('setAppValue')->willReturnCallback(
			function (string $key, $value): void {
				$this->app[$key] = (string)$value;
			}
		);
		$this->usersRequest = $this->createMock(ExternalUsersRequest::class);
		$this->cacheDocumentsRequest = $this->createMock(CacheDocumentsRequest::class);
		$this->usersRequest->method('count')->willReturnCallback(fn (): int => $this->count);
		$this->signupsRequest = $this->createMock(ExternalSignupsRequest::class);
		$this->signupsRequest->method('getByEmail')->willReturn(null);
		$this->userBackend = $this->createMock(ExternalUserBackend::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager->method('getByEmail')->willReturn([]);
		$this->accountManager = $this->createMock(IAccountManager::class);
		$this->config = $this->createMock(IConfig::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('getAppRestriction')->willReturn([]);
		$this->connection = $this->createMock(IDBConnection::class);
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->accountService->method('getActor')->willThrowException(new ActorDoesNotExistException());
		$this->accessBlockService = $this->createMock(AccessBlockService::class);
	}

	private function service(): ExternalUserService {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $args = []): string => vsprintf($text, $args));
		$random = $this->createStub(ISecureRandom::class);
		$random->method('generate')->willReturn(str_repeat('x', 60));
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1000);

		return new ExternalUserService(
			$this->configService,
			$this->usersRequest,
			$this->signupsRequest,
			$this->userBackend,
			$this->cacheDocumentsRequest,
			$this->userManager,
			$this->accountManager,
			$this->config,
			$this->appManager,
			$this->connection,
			$this->dispatcher,
			$this->createStub(ILockingProvider::class),
			$random,
			$this->jobList,
			$time,
			$l10n,
			$this->accountService,
			$this->accessBlockService,
			new NullLogger(),
		);
	}

	private function refusedField(callable $call): string {
		try {
			$call();
		} catch (ExternalUserException $e) {
			return $e->getField();
		}

		$this->fail('it was accepted');
	}

	public function testAHandleIsFoldedToTheFormItIsRegisteredIn(): void {
		$this->assertSame('alice', $this->service()->assertHandleAvailable('@Alice '));
		$this->assertSame('a.b-c_d', $this->service()->assertHandleAvailable('a.b-c_d'));
	}

	public static function badHandles(): array {
		return [
			'empty' => [''],
			'too long' => [str_repeat('a', 65)],
			'a space' => ['al ice'],
			'a leading dot' => ['.alice'],
			'a trailing dash' => ['alice-'],
			'an at sign inside' => ['al@ice'],
			'a slash' => ['team/alice'],
			'non-latin' => ['алиса'],
		];
	}

	#[DataProvider('badHandles')]
	public function testAHandleNoFediverseServerWouldTakeIsRefused(string $handle): void {
		$this->assertSame('handle', $this->refusedField(fn () => $this->service()->assertHandleAvailable($handle)));
	}

	public function testReservedHandlesAreTakenBuiltInOrChosen(): void {
		$this->app[ConfigService::SOCIAL_EXTERNAL_RESERVED] = '["ceo"]';

		$this->assertSame('handle', $this->refusedField(fn () => $this->service()->assertHandleAvailable('admin')));
		$this->assertSame('handle', $this->refusedField(fn () => $this->service()->assertHandleAvailable('CEO')));
	}

	public function testAHandleSomebodyHasAnywhereIsTaken(): void {
		$this->userManager->method('userExists')->willReturnCallback(static fn (string $uid): bool => $uid === 'bob');
		$this->assertSame('handle', $this->refusedField(fn () => $this->service()->assertHandleAvailable('bob')));
	}

	public function testAHandleHeldByASocialActorIsTakenDeletedOnesIncluded(): void {
		$this->accountService = $this->createMock(AccountService::class);
		$this->accountService->method('getActor')->with('carol')->willReturn(new Person());

		$this->assertSame('handle', $this->refusedField(fn () => $this->service()->assertHandleAvailable('carol')));
	}

	public function testAHandleReservedByAnotherRegistrationIsTakenButNotByItsOwn(): void {
		$this->signupsRequest = $this->createMock(ExternalSignupsRequest::class);
		$this->signupsRequest->method('getByHandle')->willReturn(['id' => 7] + $this->signupRow());

		$this->assertSame('handle', $this->refusedField(fn () => $this->service()->assertHandleAvailable('dave')));
		$this->assertSame('dave', $this->service()->assertHandleAvailable('dave', 7));
	}

	public function testAnEmailMustBeValidFreeAndNotOfABlockedDomain(): void {
		$this->accessBlockService->method('isBlockedEmail')->willReturnCallback(static fn (string $e): bool => str_ends_with($e, '@spam.example'));
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager->method('getByEmail')->willReturnCallback(
			fn (string $e): array => ($e === 'used@example.org') ? [$this->createStub(IUser::class)] : []
		);

		$this->assertSame('ok@example.org', $this->service()->assertEmailAvailable(' OK@Example.org'));
		$this->assertSame('email', $this->refusedField(fn () => $this->service()->assertEmailAvailable('not an address')));
		$this->assertSame('email', $this->refusedField(fn () => $this->service()->assertEmailAvailable('x@spam.example')));
		$this->assertSame('email', $this->refusedField(fn () => $this->service()->assertEmailAvailable('used@example.org')));
	}

	public function testThereIsRoomOnlyWhileSwitchedOnAndBelowTheMaximum(): void {
		$this->count = 9;
		$this->assertTrue($this->service()->hasRoom());

		$this->count = 10;
		$this->assertFalse($this->service()->hasRoom());

		$this->count = 0;
		$this->app[ConfigService::SOCIAL_EXTERNAL_ENABLED] = '0';
		$this->assertFalse($this->service()->hasRoom());
	}

	public function testANewAccountIsMadeAndKeptPrivate(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('erin');
		$user->expects($this->once())->method('setSystemEMailAddress')->with('erin@example.org');
		$this->userManager->method('get')->with('erin')->willReturn($user);

		$scopes = [];
		$properties = [];
		foreach ([IAccountManager::PROPERTY_DISPLAYNAME, IAccountManager::PROPERTY_EMAIL, IAccountManager::PROPERTY_PHONE, IAccountManager::PROPERTY_WEBSITE] as $name) {
			$property = $this->createMock(IAccountProperty::class);
			$property->method('getName')->willReturn($name);
			$property->method('setScope')->willReturnCallback(function (string $scope) use ($name, &$scopes, $property): IAccountProperty {
				$scopes[$name] = $scope;

				return $property;
			});
			$properties[] = $property;
		}
		$account = $this->createMock(IAccount::class);
		$account->method('getAllProperties')->willReturnCallback(static fn () => yield from $properties);
		$account->expects($this->once())->method('setProperty')
			->with(IAccountManager::PROPERTY_PROFILE_ENABLED, '0', IAccountManager::SCOPE_PRIVATE, IAccountManager::NOT_VERIFIED);
		$this->accountManager->method('getAccount')->willReturn($account);
		$this->accountManager->expects($this->once())->method('updateAccount')->with($account);

		$this->usersRequest->expects($this->once())->method('create')->with('erin', 'HASH', 'erin', 'open', 1000, true);
		$events = [];
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(function ($event) use (&$events): void {
			$events[] = $event::class;
		});
		$values = [];
		$this->config->expects($this->exactly(4))->method('setUserValue')->willReturnCallback(
			static function (string $uid, string $app, string $key, string $value) use (&$values): void {
				$values[] = [$uid, $app, $key, $value];
			}
		);
		$this->accountService->expects($this->once())->method('createActor')->with('erin', 'erin');

		$notice = 'Instance rules and privacy notice';
		$version = hash('sha256', $notice);
		$this->assertSame($user, $this->service()->createAccount('Erin', 'erin@example.org', 'HASH', 'open', 0, true, $version, 900, $notice));
		$this->assertContains(['erin', 'core', 'defaultapp', 'social'], $values);
		$this->assertContains(['erin', 'social', ConfigService::USER_EXTERNAL_SIGNUP_NOTICE_VERSION, $version], $values);
		$this->assertContains(['erin', 'social', ConfigService::USER_EXTERNAL_SIGNUP_NOTICE_ACCEPTED, '900'], $values);
		$this->assertContains(['erin', 'social', ConfigService::USER_EXTERNAL_SIGNUP_NOTICE_SNAPSHOT, $notice], $values);

		$this->assertSame([BeforeUserCreatedEvent::class, UserCreatedEvent::class], $events);
		$this->assertSame([
			IAccountManager::PROPERTY_DISPLAYNAME => IAccountManager::SCOPE_LOCAL,
			IAccountManager::PROPERTY_EMAIL => IAccountManager::SCOPE_LOCAL,
			IAccountManager::PROPERTY_PHONE => IAccountManager::SCOPE_PRIVATE,
			IAccountManager::PROPERTY_WEBSITE => IAccountManager::SCOPE_PRIVATE,
		], $scopes);
	}

	public function testTheExternalAccountFiltersAreAppliedBeforeAResultPageIsReturned(): void {
		$rows = [
			['uid' => 'alice', 'displayname' => 'Alice', 'creation' => 10, 'origin' => 'open', 'emailVerified' => null],
			['uid' => 'bob', 'displayname' => 'Bob', 'creation' => 20, 'origin' => 'invite:7', 'emailVerified' => true],
			['uid' => 'carol', 'displayname' => 'Carol', 'creation' => 30, 'origin' => 'open', 'emailVerified' => false],
		];
		$users = [];
		foreach (['alice' => [false, 0], 'bob' => [true, 0], 'carol' => [true, 100]] as $uid => [$enabled, $lastLogin]) {
			$user = $this->createStub(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getDisplayName')->willReturn(ucfirst($uid));
			$user->method('getEMailAddress')->willReturn($uid . '@example.org');
			$user->method('isEnabled')->willReturn($enabled);
			$user->method('getLastLogin')->willReturn($lastLogin);
			$users[$uid] = $user;
		}
		$this->userManager->method('get')->willReturnCallback(static fn (string $uid): ?IUser => $users[$uid] ?? null);
		$this->usersRequest->expects($this->once())->method('search')->with('car', 100, 0, [
			'status' => 'enabled', 'source' => 'open', 'lastLogin' => 'seen', 'minimumMediaBytes' => 1024,
			'noticeAcceptance' => 'any', 'registeredAfter' => 5, 'registeredBefore' => 40,
		])->willReturn($rows);
		$this->cacheDocumentsRequest->method('localBytesByAccount')->willReturn(['alice' => 2048, 'bob' => 4096, 'carol' => 2048]);
		$this->config->method('getUserValue')->willReturnCallback(static fn (string $uid, string $app, string $key, string $default = ''): string => $default);

		$page = $this->service()->listPage('car', 50, 0, [
			'status' => 'enabled', 'source' => 'open', 'lastLogin' => 'seen', 'minimumMediaBytes' => 1024,
			'registeredAfter' => 5, 'registeredBefore' => 40,
		]);

		$this->assertSame(['carol'], array_column($page['users'], 'uid'));
		$this->assertFalse($page['hasMore']);
		$this->assertSame(3, $page['nextOffset']);
	}

	public function testNoAccountIsMadeOnceTheServerIsFull(): void {
		$this->count = 10;
		$this->usersRequest->expects($this->never())->method('create');

		$this->expectException(ExternalUserException::class);
		$this->service()->createAccount('erin', 'erin@example.org', 'HASH', 'open');
	}

	public function testAnAccountThatCannotBeFinishedIsRemovedAgain(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('erin');
		$user->expects($this->once())->method('delete');
		$this->userManager->method('get')->willReturn($user);
		$this->accountManager->method('getAccount')->willReturn($this->createStub(IAccount::class));
		$this->accountService->method('createActor')->willThrowException(new \RuntimeException('no keys'));

		$this->expectException(ExternalUserException::class);
		$this->service()->createAccount('erin', 'erin@example.org', 'HASH', 'open');
	}

	public function testSettingsOutOfRangeWriteNothing(): void {
		$before = $this->app;

		foreach ([
			[true, -1, 0, 'open', true, 0],
			[true, 10, -5, 'open', true, 0],
			[true, 10, 0, 'whoever', true, 0],
			[true, 10, 0, 'open', true, 120],
		] as [$enabled, $max, $quota, $mode, $verify, $age]) {
			try {
				$this->service()->saveSettings($enabled, $max, $quota, $mode, $verify, $age, [], false);
				$this->fail('accepted ' . json_encode([$max, $quota, $mode, $age]));
			} catch (\InvalidArgumentException $e) {
			}
		}

		$this->assertSame($before, $this->app);
	}

	public function testSettingsAreWrittenAndReservedHandlesNormalised(): void {
		$settings = $this->service()->saveSettings(true, 50, 2048, 'invite', false, 18, ['@CEO', ' ', 'ceo', 'Board'], true);

		$this->assertTrue($settings['enabled']);
		$this->assertSame(50, $settings['max']);
		$this->assertSame(2048, $settings['quota']);
		$this->assertSame('invite', $settings['mode']);
		$this->assertFalse($settings['verifyEmail']);
		$this->assertSame(18, $settings['minAge']);
		$this->assertSame(['ceo', 'board'], $settings['reserved']);
		$this->assertTrue($settings['userInvites']);
		$this->assertFalse($settings['signupNoticeRequired']);
	}

	public function testSwitchingOnLetsExternalUsersIntoARestrictedSocial(): void {
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('getAppRestriction')->willReturn(['staff']);
		$this->appManager->expects($this->once())->method('enableAppForGroups')->with('social', ['staff', 'social-external']);

		$this->service()->saveSettings(true, 10, 0, 'open', true, 0, [], false);
	}

	public function testPromotionMovesTheLoginToTheDatabaseBackendWithItsHash(): void {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('frank');
		$user->method('getBackend')->willReturn($this->userBackend);
		$this->userManager->method('get')->willReturn($user);
		$this->userBackend->method('getPasswordHash')->willReturn('THEHASH');
		$this->userBackend->method('getDisplayName')->willReturn('Frank K');

		$database = $this->createMock(DatabaseBackendDouble::class);
		$database->method('getBackendName')->willReturn('Database');
		$database->expects($this->once())->method('createUser')->with('frank', $this->stringStartsWith('Aa1!'))->willReturn(true);
		$database->expects($this->once())->method('setPasswordHash')->with('frank', 'THEHASH')->willReturn(true);
		$database->expects($this->once())->method('setDisplayName')->with('frank', 'Frank K')->willReturn(true);
		$this->userManager->method('getBackends')->willReturn([$this->userBackend, $database]);

		$this->connection->expects($this->once())->method('beginTransaction');
		$this->connection->expects($this->once())->method('commit');
		$this->usersRequest->expects($this->once())->method('delete')->with('frank');
		$this->config->method('getUserValue')->willReturn('social');
		$this->config->expects($this->once())->method('deleteUserValue')->with('frank', 'core', 'defaultapp');
		$this->jobList->expects($this->once())->method('add')->with(ExternalPromoted::class, ['uid' => 'frank']);

		$this->service()->promote('frank');
	}

	public function testAFailedPromotionLeavesTheExternalUserAsTheyWere(): void {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('frank');
		$user->method('getBackend')->willReturn($this->userBackend);
		$this->userManager->method('get')->willReturn($user);
		$database = $this->createMock(DatabaseBackendDouble::class);
		$database->method('getBackendName')->willReturn('Database');
		$database->method('createUser')->willReturn(false);
		$this->userManager->method('getBackends')->willReturn([$database]);

		$this->connection->expects($this->once())->method('rollBack');
		$this->usersRequest->expects($this->never())->method('delete');
		$this->jobList->expects($this->never())->method('add');

		$this->expectException(ExternalUserException::class);
		$this->service()->promote('frank');
	}

	public function testOnlyAnExternalUserCanBePromoted(): void {
		$user = $this->createStub(IUser::class);
		$user->method('getBackend')->willReturn(null);
		$this->userManager->method('get')->willReturn($user);
		$this->connection->expects($this->never())->method('beginTransaction');

		$this->expectException(ExternalUserException::class);
		$this->service()->promote('bob');
	}

	private function signupRow(): array {
		return ['id' => 1, 'handle' => 'dave', 'email' => 'd@example.org', 'password' => 'H', 'token' => '', 'verified' => true,
			'approval' => false, 'inviteId' => 0, 'ipHash' => '', 'creation' => 0];
	}
}

/** Nextcloud's database backend, as far as promotion uses it. */
abstract class DatabaseBackendDouble extends ABackend implements ICreateUserBackend, IPasswordHashBackend, \OCP\User\Backend\ISetDisplayNameBackend {
}
