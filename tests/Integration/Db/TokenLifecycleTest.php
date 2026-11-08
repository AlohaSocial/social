<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Controller\StoryController;
use OCA\Social\Db\ClientRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Exceptions\ClientException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Model\Client\Story;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\StoryService;
use OCA\Social\Tests\Integration\Helper\CreatesUsers;
use OCP\AppFramework\Http;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * An OAuth token from the moment its code is issued to the moment it is
 * revoked, through `ClientService` and the real tables.
 *
 * `ClientAuthMultiUserTest` pins the SQL; this pins what a client sees of
 * it: a code that works once and not after ten minutes, a revoked token
 * that no longer names anybody while another account's authorization of
 * the same app keeps working, and a token granted only `read` refused by a
 * route that writes — with the scopes read back from the database rather
 * than set on a test double.
 */
class TokenLifecycleTest extends TestCase {
	use CreatesUsers;

	private const APP = 'token-lifecycle-test';
	private const OTHER_APP = 'token-lifecycle-other';

	private ClientService $clientService;
	private ClientRequest $clientRequest;
	private SocialClient $app;

	protected function setUp(): void {
		parent::setUp();
		$this->clientService = Server::get(ClientService::class);
		$this->clientRequest = Server::get(ClientRequest::class);
		$this->cleanup();
		$this->ensureUsers('tl-alice', 'tl-bob');

		$this->app = $this->register(self::APP);
	}

	protected function tearDown(): void {
		$this->cleanup();
		$this->removeCreatedUsers();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach (['tl-alice', 'tl-bob'] as $userId) {
			foreach ($this->clientService->getAuthorizationsOf($userId) as $authorization) {
				$this->clientService->revokeAuthorizationOf($userId, $authorization->getAuthId());
			}
		}

		$this->clientRequest->deleteApp(self::APP . '-id');
		$this->clientRequest->deleteApp(self::OTHER_APP . '-id');
	}

	private function register(string $name): SocialClient {
		$client = new SocialClient();
		$client->setAppName($name)
			->setAppWebsite('https://app.example')
			->setAppRedirectUris(['urn:ietf:wg:oauth:2.0:oob'])
			->setAppScopes(['read', 'write'])
			->setAppClientId($name . '-id')
			->setAppClientSecret($name . '-secret');
		$this->clientRequest->saveApp($client);

		return $this->clientService->getFromClientId($name . '-id');
	}

	/** The code an account's consent issues to the app. */
	private function authorize(string $userId, array $scopes, ?SocialClient $app = null): string {
		$client = $app ?? $this->app;
		$client->setAuthUserId($userId)
			->setAuthAccount($userId)
			->setAuthScopes($scopes)
			->setAuthRedirectUri('urn:ietf:wg:oauth:2.0:oob');
		$this->clientService->authClient($client);

		return $client->getAuthCode();
	}

	private function signIn(string $userId, array $scopes = ['read', 'write'], ?SocialClient $app = null): string {
		$code = $this->authorize($userId, $scopes, $app);

		return $this->clientService->exchangeCode($app ?? $this->app, $code, '', 'urn:ietf:wg:oauth:2.0:oob')->getToken();
	}

	public function testARevokedTokenNoLongerNamesAnybody(): void {
		$alice = $this->signIn('tl-alice');
		$this->assertSame('tl-alice', $this->clientService->getFromToken($alice)->getAuthUserId());

		$this->clientService->revokeToken($this->app, $alice);

		$this->expectException(ClientNotFoundException::class);
		$this->clientService->getFromToken($alice);
	}

	/** Disabling the Nextcloud user stops the token; enabling it again brings it back. */
	public function testATokenOfADisabledUserIsRefusedUntilTheUserIsEnabledAgain(): void {
		$alice = $this->signIn('tl-alice');
		$user = Server::get(IUserManager::class)->get('tl-alice');
		$this->assertNotNull($user);

		$user->setEnabled(false);
		try {
			$this->clientService->getFromToken($alice);
			$this->fail('the token of a disabled user worked');
		} catch (ClientNotFoundException) {
		}
		$this->assertCount(1, $this->clientService->getAuthorizationsOf('tl-alice'), 'the authorization is kept');

		$user->setEnabled(true);
		$this->assertSame('tl-alice', $this->clientService->getFromToken($alice)->getAuthUserId());
	}

	/** One device signing out signs out nobody else who uses the same app. */
	public function testRevokingOneAccountsTokenLeavesAnotherAccountsAuthorization(): void {
		$alice = $this->signIn('tl-alice');
		$bob = $this->signIn('tl-bob');

		$this->clientService->revokeToken($this->app, $alice);

		$this->assertSame('tl-bob', $this->clientService->getFromToken($bob)->getAuthUserId());
	}

	/** An app may revoke the tokens it was issued and nobody else's. */
	public function testAnAppCannotRevokeATokenIssuedToAnotherApp(): void {
		$other = $this->register(self::OTHER_APP);
		$alice = $this->signIn('tl-alice', ['read'], $other);

		try {
			$this->clientService->revokeToken($this->app, $alice);
			$this->fail('a token of another app was revoked');
		} catch (ClientException $e) {
		}

		$this->assertSame('tl-alice', $this->clientService->getFromToken($alice)->getAuthUserId());
	}

	/** The authorization id is a counter; somebody else's is not theirs to take back. */
	public function testAnAccountCannotRevokeAnotherAccountsAuthorization(): void {
		$alice = $this->signIn('tl-alice');
		$this->signIn('tl-bob');
		$aliceAuth = $this->clientService->getFromToken($alice)->getAuthId();

		$this->assertFalse($this->clientService->revokeAuthorizationOf('tl-bob', $aliceAuth));
		$this->assertSame('tl-alice', $this->clientService->getFromToken($alice)->getAuthUserId());
	}

	public function testACodeIsExchangedOnce(): void {
		$code = $this->authorize('tl-alice', ['read']);
		$first = $this->clientService->exchangeCode($this->app, $code, '', 'urn:ietf:wg:oauth:2.0:oob');

		try {
			$this->clientService->exchangeCode($this->app, $code, '', 'urn:ietf:wg:oauth:2.0:oob');
			$this->fail('the code was exchanged twice');
		} catch (ClientNotFoundException $e) {
		}

		$this->assertSame('tl-alice', $this->clientService->getFromToken($first->getToken())->getAuthUserId());
	}

	/** A code is plumbing between two requests, and ten minutes is its life. */
	public function testACodeOlderThanTenMinutesIsRefused(): void {
		$code = $this->authorize('tl-alice', ['read']);
		$this->ageAuthorizationsOf('tl-alice', ClientService::TIME_CODE_TTL + 60);

		$this->expectException(ClientException::class);
		$this->expectExceptionMessage('code expired');

		$this->clientService->exchangeCode($this->app, $code, '', 'urn:ietf:wg:oauth:2.0:oob');
	}

	public function testACodeJustInsideItsLifeIsAccepted(): void {
		$code = $this->authorize('tl-alice', ['read']);
		$this->ageAuthorizationsOf('tl-alice', ClientService::TIME_CODE_TTL - 60);

		$token = $this->clientService->exchangeCode($this->app, $code, '', 'urn:ietf:wg:oauth:2.0:oob')->getToken();

		$this->assertSame(['read'], $this->clientService->getFromToken($token)->getAuthScopes());
	}

	/**
	 * The scopes come back from the authorization row, and a route that
	 * changes something refuses a token that may only read — before the
	 * service behind it is reached.
	 */
	public function testAReadOnlyTokenIsRefusedByAWriteRoute(): void {
		$token = $this->signIn('tl-alice', ['read']);
		$stories = $this->createMock(StoryService::class);
		$stories->expects($this->never())->method('add');
		$stories->expects($this->never())->method('delete');

		$controller = $this->storyController($token, $stories);

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->add(1)->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->destroy(1)->getStatus());
	}

	public function testTheSameRouteServesATokenGrantedWrite(): void {
		$token = $this->signIn('tl-alice', ['read', 'write']);
		$stories = $this->createMock(StoryService::class);
		$stories->expects($this->once())->method('add')->willReturn($this->createStub(Story::class));

		$response = $this->storyController($token, $stories)->add(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testARevokedTokenIsAnsweredAsNoCredentials(): void {
		$token = $this->signIn('tl-alice', ['read', 'write']);
		$this->clientService->revokeToken($this->app, $token);

		$response = $this->storyController($token, $this->createStub(StoryService::class))->add(1);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	/** Moves the authorization's timestamp back, as if it was written that long ago. */
	private function ageAuthorizationsOf(string $userId, int $seconds): void {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update(CoreRequestBuilder::TABLE_CLIENT_AUTH)
			->set('last_update', $qb->createNamedParameter(new \DateTime('-' . $seconds . ' seconds'), 'datetime'))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	/**
	 * The story routes with the real `ClientService` deciding who the bearer
	 * is; the account lookup is stood in for, since these users have no
	 * Nextcloud account.
	 */
	private function storyController(string $token, StoryService $stories): StoryController {
		$request = $this->createStub(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => ($name === 'Authorization') ? 'Bearer ' . $token : ''
		);

		$accountService = $this->createStub(AccountService::class);
		$accountService->method('getActorFromUserId')->willReturnCallback(static function (string $userId): Person {
			$person = new Person();
			$person->setId('http://localhost:8080/apps/social/users/' . $userId);

			return $person;
		});

		return new StoryController(
			$request,
			$this->createStub(IUserSession::class),
			new NullLogger(),
			$accountService,
			$this->clientService,
			$this->createStub(CacheActorService::class),
			$stories,
		);
	}
}
