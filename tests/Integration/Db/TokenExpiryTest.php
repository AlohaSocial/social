<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use DateTime;
use OCA\Social\Db\ClientRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Exceptions\ClientException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Tests\Helper\FrozenClock;
use OCA\Social\Tests\Integration\Helper\CreatesUsers;
use OCA\Social\Tests\Integration\Helper\FreezesTheClock;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The client API's OAuth lifetimes walked to the second on a frozen clock,
 * through `ClientService` and the real tables.
 *
 * Three clocks run on one authorization: the code's ten minutes from consent,
 * the token's idle limit from its last use (`TIME_TOKEN_TTL`, 355 days), and
 * `token_max_days` from the grant however much it is used. Each is exchanged
 * or presented on its last good second and on the one after. `TokenLifecycleTest` covers the rest of
 * a token's life; this is only about where each one ends.
 *
 * Where a token runs out the sweep of idle authorizations runs too, and it
 * takes every authorization on the instance idle by the clock's reckoning:
 * those tests stand in the past, where that is nobody's but theirs.
 */
class TokenExpiryTest extends TestCase {
	use CreatesUsers;
	use FreezesTheClock;

	private const APP = 'token-expiry-test';
	private const OOB = 'urn:ietf:wg:oauth:2.0:oob';
	private const USERS = ['te-alice', 'te-bob'];

	private ClientService $clientService;
	private ClientRequest $clientRequest;
	private SocialClient $app;
	private FrozenClock $clock;
	private string $maxDays = '';

	protected function setUp(): void {
		parent::setUp();
		$this->clientRequest = Server::get(ClientRequest::class);
		$this->maxDays = Server::get(ConfigService::class)->getAppValue(ConfigService::SOCIAL_TOKEN_MAX_DAYS);
		$this->clock = $this->freezeClock(new FrozenClock(time()));
		$this->clientService = $this->clocked(ClientService::class);
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
		Server::get(ConfigService::class)->setAppValue(ConfigService::SOCIAL_TOKEN_MAX_DAYS, $this->maxDays);
		$this->thawClock();
		parent::tearDown();
	}

	public function testACodeIsGoodForItsTenMinutesAndNotASecondMore(): void {
		$consent = $this->clock->getTime();
		$last = $this->authorize('te-alice', $consent);
		$late = $this->authorize('te-bob', $consent);

		$this->clock->set($consent + ClientService::TIME_CODE_TTL);
		$token = $this->clientService->exchangeCode($this->app, $last, '', self::OOB)->getToken();
		$this->assertSame('te-alice', $this->clientService->getFromToken($token)->getAuthUserId());

		$this->clock->advance(1);
		$this->expectException(ClientException::class);
		$this->expectExceptionMessage('code expired');
		$this->clientService->exchangeCode($this->app, $late, '', self::OOB);
	}

	public function testAnIdleTokenLivesItsIdleLimitToTheSecond(): void {
		Server::get(ConfigService::class)->setAppValue(ConfigService::SOCIAL_TOKEN_MAX_DAYS, '0');
		$used = time() - 2 * ClientService::TIME_TOKEN_TTL;
		$this->clock->set($used);
		$alice = $this->signIn('te-alice', $used);
		$bob = $this->signIn('te-bob', $used);

		$this->clock->set($used + ClientService::TIME_TOKEN_TTL);
		$this->assertSame('te-alice', $this->clientService->getFromToken($alice)->getAuthUserId(), 'on its last second');

		$this->clock->advance(1);
		$this->assertSame('te-alice', $this->clientService->getFromToken($alice)->getAuthUserId(), 'that use was a use');
		$this->assertRefused($bob, 'idle TIME_TOKEN_TTL and a second');

		$this->clientService->sweep();
		$this->assertSame(['te-alice'], $this->authorizedUsers(), 'the sweep takes the idle one and leaves the used one');
	}

	/**
	 * `token_max_days` counted in seconds from the grant, across a leap day, a
	 * month end and a daylight-saving change.
	 *
	 * @return array<string, array{string, int}>
	 */
	public static function grants(): array {
		return [
			'thirty days to a leap day' => ['2024-01-30T12:00:00Z', 30],
			'a year over a leap day' => ['2023-03-01T00:00:00Z', 366],
			'a day out of October' => ['2024-10-31T23:59:59Z', 1],
			'a week over the European autumn change' => ['2024-10-23T00:30:00Z', 7],
		];
	}

	#[DataProvider('grants')]
	public function testATokenStopsOnTheSecondItsMaximumAgeIsPassed(string $grantedAt, int $days): void {
		Server::get(ConfigService::class)->setAppValue(ConfigService::SOCIAL_TOKEN_MAX_DAYS, (string)$days);
		$granted = FrozenClock::at($grantedAt)->getTime();
		$this->clock->set($granted);
		$alice = $this->signIn('te-alice', $granted);
		$bob = $this->signIn('te-bob', $granted);
		$ends = $granted + $days * 86400;
		$this->assertSame($ends, $this->clientService->expiresAt($this->clientService->getFromToken($alice)));

		// used every hundred days up to the end, so the idle limit never
		// comes into it
		for ($used = $granted; $used < $ends - 1;) {
			$used = min($used + 100 * 86400, $ends - 1);
			$this->clock->set($used);
			$this->clientService->getFromToken($alice);
			$this->clientService->getFromToken($bob);
		}
		$this->clock->set($ends);
		$this->assertSame('te-alice', $this->clientService->getFromToken($alice)->getAuthUserId(), 'on its last second');

		$this->clock->advance(1);
		$this->assertRefused($bob, 'a second past its maximum age', 'the access_token has expired');
		$this->assertSame(['te-alice'], $this->authorizedUsers(), 'the expired token is revoked');
	}

	/** Authorizes the app for the account as of a moment, and answers with the code. */
	private function authorize(string $userId, int $at): string {
		$client = clone $this->app;
		$client->setAuthUserId($userId)
			->setAuthAccount($userId)
			->setAuthScopes(['read'])
			->setAuthRedirectUri(self::OOB);
		$this->clientService->authClient($client);
		$this->stamp($userId, $at);

		return $client->getAuthCode();
	}

	private function signIn(string $userId, int $at): string {
		$token = $this->clientService->exchangeCode($this->app, $this->authorize($userId, $at), '', self::OOB)->getToken();
		$this->stamp($userId, $at);

		return $token;
	}

	/**
	 * Dates the account's authorization: its grant and its last use, which the
	 * table writes from the wall clock.
	 */
	private function stamp(string $userId, int $at): void {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update(CoreRequestBuilder::TABLE_CLIENT_AUTH)
			->set('creation', $qb->createNamedParameter(new DateTime('@' . $at), IQueryBuilder::PARAM_DATE))
			->set('last_update', $qb->createNamedParameter(new DateTime('@' . $at), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	private function assertRefused(string $token, string $when, string $message = ''): void {
		try {
			$this->clientService->getFromToken($token);
			$this->fail('the token was accepted ' . $when);
		} catch (ClientNotFoundException $e) {
			if ($message !== '') {
				$this->assertSame($message, $e->getMessage(), $when);
			}
		}
	}

	/** @return string[] */
	private function authorizedUsers(): array {
		$users = [];
		foreach (self::USERS as $userId) {
			if ($this->clientService->getAuthorizationsOf($userId) !== []) {
				$users[] = $userId;
			}
		}

		return $users;
	}

	private function cleanup(): void {
		foreach (self::USERS as $userId) {
			foreach (Server::get(ClientService::class)->getAuthorizationsOf($userId) as $authorization) {
				Server::get(ClientService::class)->revokeAuthorizationOf($userId, $authorization->getAuthId());
			}
		}
		$this->clientRequest->deleteApp(self::APP . '-id');
	}
}
