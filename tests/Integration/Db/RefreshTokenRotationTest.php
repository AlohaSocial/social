<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use DateTime;
use OCA\Social\Db\ClientRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\InvalidGrantException;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Tests\Integration\Helper\CreatesUsers;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * OAuth refresh tokens through `ClientService` and the real tables: each one
 * works once, the access token it replaces stops working, a refresh token
 * presented a second time revokes the whole authorization, and an access
 * token past its lifetime is refused while its refresh token still works.
 */
class RefreshTokenRotationTest extends TestCase {
	use CreatesUsers;

	private const APP = 'refresh-rotation-test';
	private const OOB = 'urn:ietf:wg:oauth:2.0:oob';
	private const USER = 'rt-alice';

	private ClientService $clientService;
	private ClientRequest $clientRequest;
	private SocialClient $app;
	private string $maxDays = '';

	protected function setUp(): void {
		parent::setUp();
		$this->clientService = Server::get(ClientService::class);
		$this->clientRequest = Server::get(ClientRequest::class);
		$this->maxDays = Server::get(ConfigService::class)->getAppValue(ConfigService::SOCIAL_TOKEN_MAX_DAYS);
		$this->cleanup();
		$this->ensureUsers(self::USER);

		$client = new SocialClient();
		$client->setAppName(self::APP)
			->setAppWebsite('https://app.example')
			->setAppRedirectUris([self::OOB])
			->setAppScopes(['read', 'write'])
			->setAppClientId(self::APP . '-id')
			->setAppClientSecret(self::APP . '-secret');
		$this->clientRequest->saveApp($client);
		$this->app = $this->clientService->getFromClientId(self::APP . '-id');
	}

	protected function tearDown(): void {
		$this->cleanup();
		$this->removeCreatedUsers();
		Server::get(ConfigService::class)->setAppValue(ConfigService::SOCIAL_TOKEN_MAX_DAYS, $this->maxDays);
		parent::tearDown();
	}

	public function testARefreshTokenWorksOnceAndReplacesTheAccessToken(): void {
		$signedIn = $this->signIn();
		$this->assertNotSame('', $signedIn->getRefreshToken());

		$refreshed = $this->clientService->refresh($this->app, $signedIn->getRefreshToken());

		$this->assertNotSame($signedIn->getToken(), $refreshed->getToken());
		$this->assertNotSame($signedIn->getRefreshToken(), $refreshed->getRefreshToken());
		$this->assertSame(self::USER, $this->clientService->getFromToken($refreshed->getToken())->getAuthUserId());
		$this->assertRefused($signedIn->getToken());
		$this->assertSame(1, $this->clientService->getFromToken($refreshed->getToken())->getRefreshCount());
	}

	/** Whoever presents a spent refresh token signs both holders out. */
	public function testASpentRefreshTokenRevokesTheWholeAuthorization(): void {
		$signedIn = $this->signIn();
		$refreshed = $this->clientService->refresh($this->app, $signedIn->getRefreshToken());

		try {
			$this->clientService->refresh($this->app, $signedIn->getRefreshToken());
			$this->fail('a spent refresh token was accepted');
		} catch (InvalidGrantException) {
		}

		$this->assertRefused($refreshed->getToken());
		$this->assertSame([], $this->clientService->getAuthorizationsOf(self::USER));
		$this->expectException(InvalidGrantException::class);
		$this->clientService->refresh($this->app, $refreshed->getRefreshToken());
	}

	public function testAnAccessTokenPastItsLifetimeCanStillBeRefreshed(): void {
		Server::get(ConfigService::class)->setAppValue(ConfigService::SOCIAL_TOKEN_MAX_DAYS, '30');
		$signedIn = $this->signIn();
		$this->issuedAt(time() - 31 * 86400);

		$this->assertRefused($signedIn->getToken());
		$refreshed = $this->clientService->refresh($this->app, $signedIn->getRefreshToken());
		$this->assertSame(self::USER, $this->clientService->getFromToken($refreshed->getToken())->getAuthUserId());
	}

	public function testRevokingTheRefreshTokenSignsTheAppOut(): void {
		$signedIn = $this->signIn();

		$this->clientService->revokeToken($this->app, $signedIn->getRefreshToken());

		$this->assertRefused($signedIn->getToken());
	}

	private function signIn(): SocialClient {
		$client = clone $this->app;
		$client->setAuthUserId(self::USER)
			->setAuthAccount(self::USER)
			->setAuthScopes(['read', 'write'])
			->setAuthRedirectUri(self::OOB);
		$this->clientService->authClient($client);

		return $this->clientService->exchangeCode($this->app, $client->getAuthCode(), '', self::OOB);
	}

	private function issuedAt(int $at): void {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update(CoreRequestBuilder::TABLE_CLIENT_AUTH)
			->set('token_creation', $qb->createNamedParameter(new DateTime('@' . $at), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::USER)));
		$qb->executeStatement();
	}

	private function assertRefused(string $token): void {
		try {
			$this->clientService->getFromToken($token);
			$this->fail('the access token was accepted');
		} catch (ClientNotFoundException) {
			$this->addToAssertionCount(1);
		}
	}

	private function cleanup(): void {
		$this->clientService->revokeAllOf(self::USER);
		$this->clientRequest->deleteApp(self::APP . '-id');
	}
}
