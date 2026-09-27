<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\External;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\External\ExternalGroupBackend;
use OCA\Social\External\ExternalUserBackend;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\ExternalUserService;
use OCP\Accounts\IAccountManager;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Security\IHasher;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * An external account on a real server, from creation to promotion: that
 * the backends are registered, that Nextcloud logs the person in with the
 * password they chose, and that promotion keeps it.
 */
class ExternalAccountLifecycleTest extends TestCase {
	private string $handle = '';
	private const PASSWORD = 'correct horse battery staple 42';

	private ExternalUserService $service;
	private IUserManager $userManager;
	private ConfigService $configService;
	private array $before = [];

	protected function setUp(): void {
		parent::setUp();
		$this->service = Server::get(ExternalUserService::class);
		$this->userManager = Server::get(IUserManager::class);
		$this->configService = Server::get(ConfigService::class);
		foreach ([ConfigService::SOCIAL_EXTERNAL_ENABLED, ConfigService::SOCIAL_EXTERNAL_MAX] as $key) {
			$this->before[$key] = $this->configService->getAppValue($key);
		}
		$this->configService->setAppValue(ConfigService::SOCIAL_EXTERNAL_ENABLED, '1');
		$this->configService->setAppValue(ConfigService::SOCIAL_EXTERNAL_MAX, '1000');
		// a deleted handle stays reserved for the retention hour, so every
		// run and every test registers a fresh one
		$this->handle = 'itest_ext_' . bin2hex(random_bytes(4));
	}

	protected function tearDown(): void {
		$this->removeUser();
		foreach ($this->before as $key => $value) {
			$this->configService->setAppValue($key, $value);
		}
		parent::tearDown();
	}

	private function removeUser(): void {
		if ($this->handle === '') {
			return;
		}

		// after a promotion, the user manager still holds the user as it was
		// on the external backend for the rest of the request, so a promoted
		// user is removed from the database backend directly
		$external = Server::get(ExternalUserBackend::class);
		$external->forget($this->handle);
		if ($external->userExists($this->handle)) {
			$this->userManager->get($this->handle)?->delete();
		} else {
			foreach ($this->userManager->getBackends() as $backend) {
				if ($backend instanceof \OCP\IUserBackend && $backend->getBackendName() === 'Database' && $backend->userExists($this->handle)) {
					$backend->deleteUser($this->handle);
					Server::get(\OCA\Social\Service\AccountService::class)->deleteActor($this->handle);
					Server::get(IConfig::class)->deleteAllUserValues($this->handle);
				}
			}
		}
		$external->forget($this->handle);
	}

	private function email(): string {
		return $this->handle . '@itest.example';
	}

	public function testAnExternalAccountIsARealUserWithASocialAccountAndPrivateDetails(): void {
		$user = $this->service->createAccount($this->handle, $this->email(), Server::get(IHasher::class)->hash(self::PASSWORD), 'occ');

		$this->assertSame('Social', $user->getBackendClassName());
		$this->assertTrue($this->service->isExternal($this->userManager->get($this->handle)));
		$this->assertTrue(Server::get(IGroupManager::class)->isInGroup($this->handle, ExternalGroupBackend::GROUP_ID));
		$this->assertSame($this->email(), $user->getEMailAddress());

		$checked = $this->userManager->checkPassword($this->handle, self::PASSWORD);
		$this->assertNotFalse($checked);
		$this->assertSame($this->handle, $checked->getUID());
		$this->assertFalse($this->userManager->checkPassword($this->handle, 'wrong'));

		$actor = Server::get(ActorsRequest::class)->getFromUserId($this->handle);
		$this->assertSame($this->handle, $actor->getPreferredUsername());

		$account = Server::get(IAccountManager::class)->getAccount($user);
		$this->assertSame(IAccountManager::SCOPE_LOCAL, $account->getProperty(IAccountManager::PROPERTY_EMAIL)->getScope());
		$this->assertSame(IAccountManager::SCOPE_PRIVATE, $account->getProperty(IAccountManager::PROPERTY_PHONE)->getScope());
		$this->assertSame('social', Server::get(IConfig::class)->getUserValue($this->handle, 'core', 'defaultapp'));
	}

	public function testTheHandleIsTakenOnceItIsSomebodys(): void {
		$this->service->createAccount($this->handle, $this->email(), Server::get(IHasher::class)->hash(self::PASSWORD), 'occ');

		$this->expectException(\OCA\Social\Exceptions\ExternalUserException::class);
		$this->service->assertHandleAvailable(strtoupper($this->handle));
	}

	public function testADeletedAccountsHandleStaysReservedForTheRetentionHour(): void {
		$this->service->createAccount($this->handle, $this->email(), Server::get(IHasher::class)->hash(self::PASSWORD), 'occ');
		$this->removeUser();

		$this->assertFalse($this->userManager->userExists($this->handle));
		$this->expectException(\OCA\Social\Exceptions\ExternalUserException::class);
		$this->service->assertHandleAvailable($this->handle);
	}

	public function testPromotionKeepsTheLoginAndTheSocialAccount(): void {
		$this->service->createAccount($this->handle, $this->email(), Server::get(IHasher::class)->hash(self::PASSWORD), 'occ');

		$this->service->promote($this->handle);

		$this->assertFalse(Server::get(ExternalUserBackend::class)->userExists($this->handle));
		$database = null;
		foreach ($this->userManager->getBackends() as $backend) {
			if ($backend instanceof \OCP\IUserBackend && $backend->getBackendName() === 'Database') {
				$database = $backend;
			}
		}
		$this->assertNotNull($database);
		$this->assertTrue($database->userExists($this->handle));
		$this->assertSame($this->handle, $database->checkPassword($this->handle, self::PASSWORD));
		$this->assertSame($this->handle, Server::get(ActorsRequest::class)->getFromUserId($this->handle)->getPreferredUsername());
		$this->assertSame('', Server::get(IConfig::class)->getUserValue($this->handle, 'core', 'defaultapp'));
	}
}
