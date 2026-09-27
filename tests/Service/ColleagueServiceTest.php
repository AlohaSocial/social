<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ColleagueService;
use OCA\Social\Service\ConfigService;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\IAccountProperty;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The `fediverse` field of this Nextcloud's profiles, read as who to follow.
 */
#[AllowMockObjectsWithoutExpectations]
class ColleagueServiceTest extends TestCase {
	private IUserManager|Stub $userManager;
	private IAccountManager|Stub $accountManager;
	private CacheActorService|Stub $cacheActorService;
	private ColleagueService $service;

	/** @var array<string, array{string, string}> uid => [fediverse value, scope] */
	private array $profiles = [];
	/** @var string[] handles this instance already has in its actor cache */
	private array $cached = [];
	/** @var string[] the handles the cache was asked for, and whether it could fetch */
	private array $asked = [];

	protected function setUp(): void {
		$this->userManager = $this->createStub(IUserManager::class);
		$this->userManager->method('searchDisplayName')
			->willReturnCallback(fn (): array => array_map(
				fn (string $uid): IUser => $this->user($uid), array_keys($this->profiles)
			));
		$this->userManager->method('get')
			->willReturnCallback(fn (string $uid): ?IUser
				=> isset($this->profiles[$uid]) ? $this->user($uid) : null);

		$this->accountManager = $this->createStub(IAccountManager::class);
		$this->accountManager->method('getAccount')
			->willReturnCallback(function (IUser $user): IAccount {
				[$value, $scope] = $this->profiles[$user->getUID()];
				$property = $this->createStub(IAccountProperty::class);
				$property->method('getValue')->willReturn($value);
				$property->method('getScope')->willReturn($scope);
				$account = $this->createMock(IAccount::class);
				$account->method('getProperty')->willReturn($property);

				return $account;
			});

		$configService = $this->createStub(ConfigService::class);
		$configService->method('getSocialAddress')->willReturn('cloud.example');

		$this->cacheActorService = $this->createStub(CacheActorService::class);
		$this->cacheActorService->method('getFromLocalAccount')
			->willReturnCallback(fn (string $username): Person
				=> $this->person('https://cloud.example/users/' . $username));
		$this->cacheActorService->method('getFromAccount')
			->willReturnCallback(function (string $handle, bool $retrieve): Person {
				$this->asked[] = $handle . ($retrieve ? ' (fetching)' : '');
				if (!in_array($handle, $this->cached, true)) {
					throw new CacheActorDoesNotExistException();
				}

				return $this->person('https://' . explode('@', $handle)[1] . '/users/' . explode('@', $handle)[0]);
			});

		$this->service = new ColleagueService(
			$this->userManager, $this->accountManager, $configService, $this->cacheActorService, new NullLogger()
		);
	}

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}

	private function person(string $id): Person {
		$person = new Person();
		$person->setId($id);

		return $person;
	}

	public function testReadsTheHandlesPeopleWroteDown(): void {
		$this->profiles = [
			'alice' => ['alice@cloud.example', IAccountManager::SCOPE_LOCAL],
			'bob' => ['@bob@mastodon.social', IAccountManager::SCOPE_FEDERATED],
			'carol' => ['', IAccountManager::SCOPE_LOCAL],
		];

		$this->assertSame(
			['alice' => 'alice@cloud.example', 'bob' => 'bob@mastodon.social'],
			$this->service->handles()
		);
	}

	/** Their choice, and this page is not the place it stops being one. */
	public function testLeavesAPrivateFieldAlone(): void {
		$this->profiles = [
			'alice' => ['alice@cloud.example', IAccountManager::SCOPE_PRIVATE],
			'bob' => ['bob@cloud.example', IAccountManager::SCOPE_LOCAL],
		];

		$this->assertSame(['bob' => 'bob@cloud.example'], $this->service->handles());
	}

	public function testLeavesTheReaderOut(): void {
		$this->profiles = [
			'alice' => ['alice@cloud.example', IAccountManager::SCOPE_LOCAL],
			'bob' => ['bob@cloud.example', IAccountManager::SCOPE_LOCAL],
		];

		$this->assertSame(['bob' => 'bob@cloud.example'], $this->service->handles('alice'));
	}

	/** @return array<string, array{string, string}> */
	public static function provideWrittenHandles(): array {
		return [
			'bare' => ['alice@cloud.example', 'alice@cloud.example'],
			'with the leading @ people type' => ['@alice@cloud.example', 'alice@cloud.example'],
			'padded' => ['  alice@cloud.example ', 'alice@cloud.example'],
			'a Mastodon profile URL' => ['https://mastodon.social/@alice', 'alice@mastodon.social'],
			'an actor URL' => ['https://mastodon.social/users/alice', 'alice@mastodon.social'],
			'a display name is not a handle' => ['Alice Wonder', ''],
			'a host alone is not a handle' => ['mastodon.social', ''],
		];
	}

	#[DataProvider('provideWrittenHandles')]
	public function testReadsWhateverShapeAPersonTyped(string $written, string $handle): void {
		$this->profiles = ['alice' => [$written, IAccountManager::SCOPE_LOCAL]];

		$this->assertSame($handle, $this->service->handleOf('alice'));
	}

	public function testALocalHandleIsTheAccountItself(): void {
		$this->profiles = ['alice' => ['alice@cloud.example', IAccountManager::SCOPE_LOCAL]];

		$accounts = $this->service->accounts();

		$this->assertCount(1, $accounts);
		$this->assertSame('https://cloud.example/users/alice', $accounts[0]->getId());
		// the cache was never asked to look anything up remotely
		$this->assertSame([], $this->asked);
	}

	/**
	 * Resolving a handle is a WebFinger lookup and an actor fetch against
	 * somebody else's server, and this is read while drawing a page.
	 */
	public function testARemoteHandleIsLookedUpInTheCacheAndNowhereElse(): void {
		$this->profiles = [
			'bob' => ['bob@mastodon.social', IAccountManager::SCOPE_LOCAL],
			'dan' => ['dan@pixelfed.social', IAccountManager::SCOPE_LOCAL],
		];
		$this->cached = ['bob@mastodon.social'];

		$accounts = $this->service->accounts();

		$this->assertCount(1, $accounts);
		$this->assertSame('https://mastodon.social/users/bob', $accounts[0]->getId());
		$this->assertSame(['bob@mastodon.social', 'dan@pixelfed.social'], $this->asked);
	}

	public function testTwoProfilesNamingOneAccountSuggestItOnce(): void {
		$this->profiles = [
			'alice' => ['team@mastodon.social', IAccountManager::SCOPE_LOCAL],
			'bob' => ['@team@mastodon.social', IAccountManager::SCOPE_LOCAL],
		];
		$this->cached = ['team@mastodon.social'];

		$this->assertCount(1, $this->service->accounts());
	}

	public function testKnowsWhichHandlesAreThisInstance(): void {
		$this->assertTrue($this->service->isLocal('alice@cloud.example'));
		$this->assertTrue($this->service->isLocal('alice@Cloud.Example'));
		$this->assertFalse($this->service->isLocal('alice@mastodon.social'));
	}
}
