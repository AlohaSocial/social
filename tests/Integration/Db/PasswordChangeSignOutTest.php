<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\ClientRequest;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\ClientService;
use OCA\Social\Tests\Integration\Helper\CreatesUsers;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * A password changed through Nextcloud signs the account's Mastodon apps out,
 * through the real event dispatcher and the real tables, and leaves another
 * account's authorization of the same app alone.
 */
class PasswordChangeSignOutTest extends TestCase {
	use CreatesUsers;

	private const APP = 'password-change-test';
	private const OOB = 'urn:ietf:wg:oauth:2.0:oob';
	private const USERS = ['pc-alice', 'pc-bob'];

	private ClientService $clientService;
	private ClientRequest $clientRequest;
	private SocialClient $app;

	protected function setUp(): void {
		parent::setUp();
		$this->clientService = Server::get(ClientService::class);
		$this->clientRequest = Server::get(ClientRequest::class);
		$this->cleanup();
		$this->ensureUsers(...self::USERS);

		$client = new SocialClient();
		$client->setAppName(self::APP)
			->setAppWebsite('https://app.example')
			->setAppRedirectUris([self::OOB])
			->setAppScopes(['read'])
			->setAppClientId(self::APP . '-id')
			->setAppClientSecret(self::APP . '-secret');
		$this->clientRequest->saveApp($client);
		$this->app = $this->clientService->getFromClientId(self::APP . '-id');
	}

	protected function tearDown(): void {
		$this->cleanup();
		$this->removeCreatedUsers();
		parent::tearDown();
	}

	public function testAChangedPasswordRevokesTheAccountsTokensAndNobodyElses(): void {
		$alice = $this->signIn('pc-alice');
		$bob = $this->signIn('pc-bob');

		$user = Server::get(IUserManager::class)->get('pc-alice');
		$this->assertNotNull($user);
		$this->assertTrue($user->setPassword('Itest-' . bin2hex(random_bytes(16)) . 'Bb2!'));

		try {
			$this->clientService->getFromToken($alice);
			$this->fail('the token outlived the password it was granted under');
		} catch (ClientNotFoundException) {
		}
		$this->assertSame([], $this->clientService->getAuthorizationsOf('pc-alice'));
		$this->assertSame('pc-bob', $this->clientService->getFromToken($bob)->getAuthUserId());
	}

	private function signIn(string $userId): string {
		$client = clone $this->app;
		$client->setAuthUserId($userId)
			->setAuthAccount($userId)
			->setAuthScopes(['read'])
			->setAuthRedirectUri(self::OOB);
		$this->clientService->authClient($client);

		return $this->clientService->exchangeCode($this->app, $client->getAuthCode(), '', self::OOB)->getToken();
	}

	private function cleanup(): void {
		foreach (self::USERS as $userId) {
			$this->clientService->revokeAllOf($userId);
		}
		$this->clientRequest->deleteApp(self::APP . '-id');
	}
}
