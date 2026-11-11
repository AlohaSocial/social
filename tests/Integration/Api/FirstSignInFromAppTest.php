<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Atproto\Protocol\Encoding;
use OCA\Social\Controller\AccountApiController;
use OCA\Social\Controller\ListController;
use OCA\Social\Controller\LocalController;
use OCA\Social\Controller\MigrationController;
use OCA\Social\Controller\ModerationListController;
use OCA\Social\Controller\OAuthController;
use OCA\Social\Controller\ReviewController;
use OCA\Social\Db\ClientRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AccountService;
use OCP\AppFramework\Services\IInitialState;
use OCP\Server;

/**
 * Somebody whose first visit is from an app: signed in to Nextcloud, with no
 * account here yet. The sign-in page asks for their username before the
 * consent, every client route tells them where to set it up, and once the
 * account exists the same sign-in request goes on to a code and a token.
 */
class FirstSignInFromAppTest extends ApiTestCase {
	private const APP_ID = 'apictr-first-sign-in-id';
	private const APP_SECRET = 'apictr-first-sign-in-secret';
	private const REDIRECT = 'https://app.example/callback';

	/** @var array<string, mixed> what the last page was handed, by key */
	private array $states = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->removeApp();
		$client = new SocialClient();
		$client->setAppName('Skeets')
			->setAppWebsite('https://app.example')
			->setAppRedirectUris([self::REDIRECT])
			->setAppScopes(['read', 'write', 'follow'])
			->setAppClientId(self::APP_ID)
			->setAppClientSecret(self::APP_SECRET);
		Server::get(ClientRequest::class)->saveApp($client);
		$this->userWithoutAccount('dora');
	}

	#[\Override]
	protected function tearDown(): void {
		$this->removeApp();
		parent::tearDown();
	}

	#[\Override]
	protected function accountNames(): array {
		return ['dora'];
	}

	private function removeApp(): void {
		try {
			Server::get(ClientRequest::class)->deleteApp(self::APP_ID);
		} catch (\Throwable) {
		}
	}

	/**
	 * `GET /oauth/authorize` in dora's browser, signed in to Nextcloud.
	 *
	 * @param array<string, string> $override
	 */
	private function authorizePage(string $challenge, array $override = []): ApiResponse {
		$this->states = [];
		$state = $this->createStub(IInitialState::class);
		$state->method('provideInitialState')->willReturnCallback(
			function (string $key, mixed $data): void {
				$this->states[$key] = $data;
			}
		);

		return $this->call(
			OAuthController::class, 'authorize', [], $override + $this->request($challenge), 'dora',
			webSession: true, services: [IInitialState::class => $state],
		);
	}

	/** @return array<string, string> the app's sign-in request, as it puts it in the address */
	private function request(string $challenge): array {
		return [
			'client_id' => self::APP_ID,
			'redirect_uri' => self::REDIRECT,
			'response_type' => 'code',
			'scope' => 'read write',
			'state' => 'st-42',
			'code_challenge' => $challenge,
			'code_challenge_method' => 'S256',
		];
	}

	public function testTheFirstSignInFromAnAppSetsTheAccountUpOnTheWay(): void {
		$verifier = Encoding::base64UrlEncode(random_bytes(32));
		$challenge = Encoding::base64UrlEncode(hash('sha256', $verifier, true));

		// no account yet: the page asks for a username, and grants nothing
		$setup = $this->authorizePage($challenge);
		$this->assertSame(200, $setup->status);
		$this->assertSame('oauth2', $setup->template);
		$this->assertArrayHasKey('setup', $this->states);
		$this->assertArrayNotHasKey('account', $this->states);
		$this->assertSame(self::PREFIX . 'dora', $this->states['setup']['suggestedHandle']);
		$this->assertSame('Skeets', $this->states['appName']);
		$this->assertSame(self::REDIRECT, $this->states['redirectUri']);

		// a request that would be refused with an account is refused without one
		$forged = $this->authorizePage($challenge, ['redirect_uri' => 'https://evil.example/steal']);
		$this->assertSame(400, $forged->status);
		$this->assertSame('invalid_request', $forged->body->error);
		$this->assertSame([], $this->states);

		// the account, made as the setup page makes it
		$created = $this->call(
			LocalController::class, 'accountCreate', [], ['username' => self::PREFIX . 'dora'], 'dora', 'POST', webSession: true,
		);
		$this->assertSame(200, $created->status, json_encode($created->body) ?: '');

		// the same address again: the consent, for that account
		$consent = $this->authorizePage($challenge);
		$this->assertSame(200, $consent->status);
		$this->assertSame('oauth2', $consent->template);
		$this->assertArrayNotHasKey('setup', $this->states);
		$this->assertStringStartsWith('@' . self::PREFIX . 'dora@', $this->states['account']['handle']);

		// granted, the request as it came in is what the code is bound to
		$granted = $this->call(OAuthController::class, 'authorizing', [], $this->request($challenge), 'dora', 'POST', webSession: true);
		$this->assertSame(303, $granted->status, json_encode($granted->body) ?: '');
		parse_str((string)parse_url($granted->headers['Location'], PHP_URL_QUERY), $callback);
		$this->assertStringStartsWith(self::REDIRECT . '?', $granted->headers['Location']);
		$this->assertSame('st-42', $callback['state']);

		$token = $this->call(OAuthController::class, 'token', [], [
			'grant_type' => 'authorization_code',
			'client_id' => self::APP_ID,
			'client_secret' => self::APP_SECRET,
			'redirect_uri' => self::REDIRECT,
			'code' => $callback['code'],
			'code_verifier' => $verifier,
		], null, 'POST');
		$this->assertSame(200, $token->status, json_encode($token->body) ?: '');

		$me = $this->call(AccountApiController::class, 'verifyCredentials', [], [], 'dora', bearer: $token->body->access_token);
		$this->assertSame(200, $me->status, json_encode($me->body) ?: '');
		$this->assertSame(self::PREFIX . 'dora', $me->body->username);
	}

	/**
	 * Before the account exists, every way a client signs in with Nextcloud
	 * credentials is told where to set it up, on each kind of route.
	 */
	public function testEveryClientRouteTellsAUserWithoutAnAccountWhereToSetItUp(): void {
		$answers = [
			'a Mastodon route, web session' => $this->call(AccountApiController::class, 'verifyCredentials', [], [], 'dora', webSession: true),
			'a Mastodon route, app password' => $this->call(AccountApiController::class, 'verifyCredentials', [], [], 'dora', appPassword: true),
			'a client-API route, web session' => $this->call(ListController::class, 'index', [], [], 'dora', webSession: true),
			'a client-API route, app password' => $this->call(ListController::class, 'index', [], [], 'dora', appPassword: true),
			'a route of the web app' => $this->call(LocalController::class, 'postDelete', [], ['id' => 'https://cloud.example/@dora/1'], 'dora', 'DELETE', webSession: true),
			'held posts' => $this->call(ReviewController::class, 'held', [], [], 'dora', webSession: true),
			'a shared moderation list' => $this->call(ModerationListController::class, 'subscribe', [], ['url' => 'https://lists.example/mute.csv'], 'dora', 'POST', webSession: true),
			'the address to leave behind' => $this->call(MigrationController::class, 'switchAnnouncement', [], [], 'dora', webSession: true),
		];

		foreach ($answers as $what => $answer) {
			$this->assertSame(403, $answer->status, $what . ': ' . (json_encode($answer->body) ?: ''));
			$this->assertSame('account-not-set-up', $answer->body->code, $what);
			$this->assertStringEndsWith('/apps/social/', $answer->body->setup_url, $what);
			$this->assertStringStartsWith('http', $answer->body->setup_url, $what);
		}

		// and none of them made one on the way
		$this->expectException(ActorDoesNotExistException::class);
		Server::get(AccountService::class)->getActorFromUserId(self::PREFIX . 'dora');
	}
}
