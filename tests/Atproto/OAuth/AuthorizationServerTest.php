<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\OAuth;

use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\InstanceKeyService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\OAuthSession;
use OCA\Social\Atproto\OAuth\AuthorizationServer;
use OCA\Social\Atproto\OAuth\ClientAuthenticator;
use OCA\Social\Atproto\OAuth\ClientMetadataService;
use OCA\Social\Atproto\OAuth\DpopNonce;
use OCA\Social\Atproto\OAuth\DpopVerifier;
use OCA\Social\Atproto\OAuth\OAuthException;
use OCA\Social\Atproto\OAuth\PermissionSets;
use OCA\Social\Atproto\Protocol\Encoding;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\EnabledUserService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class AuthorizationServerTest extends TestCase {
	/** @var string[] the Nextcloud accounts an administrator disabled */
	private array $disabled = [];
	private const ISSUER = 'https://social.test';
	private const CLIENT = 'https://app.example.com/oauth-client-metadata.json';
	private const REDIRECT = 'https://app.example.com/callback';
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	private int $now = 1760000000;
	/** the scopes the app's client document declares */
	private string $declared = 'atproto transition:generic';
	private PrivateKey $dpopKey;
	private DpopNonce $nonce;
	private InMemoryOAuthRequest $store;
	private Identity $alice;
	private AuthorizationServer $server;
	private PrivateKey $serviceKey;

	protected function setUp(): void {
		$this->dpopKey = PrivateKey::generate(Curve::P256);
		$this->alice = new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', '', '', '', Identity::STATE_ACTIVE, '', 0);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('calculateHMAC')->willReturnCallback(static fn (string $message): string => hash_hmac('sha256', $message, 'secret', true));
		$this->nonce = new DpopNonce($crypto, $time);
		$this->store = (new \ReflectionClass(InMemoryOAuthRequest::class))->newInstanceWithoutConstructor();
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('pdsEndpoint')->willReturn(self::ISSUER);
		$config->method('serviceDid')->willReturn('did:web:social.test');
		$curl = $this->createMock(CurlService::class);
		$curl->method('retrieveJson')->willReturnCallback(function (string $method, string $url, array $options, ?int &$status = null, ?string &$type = null): array {
			$status = 200;
			$type = 'application/json';

			return OAuthKit::clientDocument(['scope' => $this->declared]);
		});
		$cache = $this->createMock(ICacheFactory::class);
		$identities = $this->createMock(IdentityService::class);
		$identities->method('forActor')->willReturnCallback(fn (): Identity => $this->alice);
		$identities->method('getByDid')->willReturnCallback(fn (): Identity => $this->alice);
		$this->serviceKey = PrivateKey::generate(Curve::K256);
		$keys = $this->createMock(InstanceKeyService::class);
		$keys->method('serviceKey')->willReturn($this->serviceKey);
		$actors = $this->createMock(ActorsRequest::class);
		$actors->method('getFromUserId')->willReturn(new Person());
		$this->disabled = [];
		$enabledUsers = $this->createStub(EnabledUserService::class);
		$enabledUsers->method('isEnabled')->willReturnCallback(fn (string $userId): bool => $userId !== '' && !in_array($userId, $this->disabled, true));
		$sets = $this->createMock(PermissionSets::class);
		$sets->method('expand')->willReturnCallback(static fn (string $nsid, string $aud): ?array => $nsid === 'com.example.authBasic'
			? [['resource' => 'rpc', 'lxm' => ['com.example.getThing'], 'aud' => $aud]]
			: null);
		$this->server = new AuthorizationServer(
			$config, $this->store, new ClientMetadataService($curl, $cache),
			new ClientAuthenticator($this->store, $curl, $time), new DpopVerifier($this->nonce, $this->store, $time),
			$identities, $keys, $actors, $time, $sets, $enabledUsers,
		);
	}

	private function proof(string $url, string $method = 'POST', string $token = '', ?PrivateKey $key = null): string {
		return OAuthKit::proof($key ?? $this->dpopKey, $method, $url, $this->now, $this->nonce->current(), $token);
	}

	/**
	 * @return array{0: string, 1: string} the request_uri and the PKCE verifier
	 */
	private function pushed(array $override = []): array {
		[$verifier, $challenge] = OAuthKit::pkce();
		$answer = $this->server->par($override + [
			'client_id' => self::CLIENT,
			'response_type' => 'code',
			'code_challenge' => $challenge,
			'code_challenge_method' => 'S256',
			'state' => 'st-' . bin2hex(random_bytes(4)),
			'redirect_uri' => self::REDIRECT,
			'scope' => 'atproto transition:generic',
			'login_hint' => 'alice.social.test',
		], $this->proof(self::ISSUER . '/oauth/par'));

		return [$answer['request_uri'], $verifier];
	}

	/**
	 * @return array the token answer
	 */
	private function signedIn(): array {
		[$requestUri, $verifier] = $this->pushed();
		parse_str((string)parse_url($this->server->approve(self::CLIENT, $requestUri, 'alice'), PHP_URL_QUERY), $callback);

		return $this->server->token([
			'grant_type' => 'authorization_code',
			'client_id' => self::CLIENT,
			'code' => $callback['code'],
			'code_verifier' => $verifier,
			'redirect_uri' => self::REDIRECT,
		], $this->proof(self::ISSUER . '/oauth/token'));
	}

	public function testTheMetadataIsAtProtocolsProfile(): void {
		$metadata = $this->server->metadata();

		$this->assertSame(self::ISSUER, $metadata['issuer'], 'the bare origin');
		$this->assertTrue($metadata['require_pushed_authorization_requests']);
		$this->assertTrue($metadata['client_id_metadata_document_supported']);
		$this->assertContains('ES256', $metadata['dpop_signing_alg_values_supported']);
		$this->assertSame(['resource' => self::ISSUER, 'authorization_servers' => [self::ISSUER]], array_intersect_key($this->server->resourceMetadata(), ['resource' => 1, 'authorization_servers' => 1]));
	}

	public function testAnAppSignsInAndActsWithItsDpopBoundToken(): void {
		[$requestUri] = $this->pushed();
		$pending = $this->server->pending(self::CLIENT, $requestUri, 'alice');
		$this->assertSame(['atproto', 'transition:generic'], $pending['scopes']);

		$redirect = $this->server->approve(self::CLIENT, $requestUri, 'alice');
		$this->assertStringStartsWith(self::REDIRECT . '?', $redirect);
		parse_str((string)parse_url($redirect, PHP_URL_QUERY), $callback);
		$this->assertSame(self::ISSUER, $callback['iss'], 'RFC 9207');
		$this->assertMatchesRegularExpression('/^cod-/', $callback['code']);

		$this->now += 5;
		$tokens = $this->signedIn();
		$this->assertSame('DPoP', $tokens['token_type']);
		$this->assertSame(self::DID, $tokens['sub']);
		$this->assertSame('atproto transition:generic', $tokens['scope']);
		$this->assertSame(AuthorizationServer::ACCESS_LIFETIME, $tokens['expires_in']);

		$url = self::ISSUER . '/xrpc/app.bsky.feed.getTimeline';
		$session = $this->server->authenticate('DPoP ' . $tokens['access_token'], $this->proof($url, 'GET', $tokens['access_token']), 'GET', $url);
		$this->assertSame('alice', $session->userId);
		$this->assertTrue($session->may('transition:generic'));

		try {
			$this->server->authenticate('DPoP ' . $tokens['access_token'], $this->proof($url, 'GET', $tokens['access_token'], PrivateKey::generate(Curve::P256)), 'GET', $url);
			$this->fail('another key used the token');
		} catch (OAuthException $e) {
			$this->assertSame(401, $e->status);
			$this->assertStringStartsWith('DPoP error="invalid_token"', $e->headers['WWW-Authenticate']);
		}
	}

	public function testWhatAParMustCarryIsChecked(): void {
		foreach ([
			'no PKCE' => ['code_challenge_method' => 'plain'],
			'no state' => ['state' => ''],
			'an undeclared redirect' => ['redirect_uri' => 'https://evil.example.org/cb'],
			'no atproto scope' => ['scope' => 'transition:generic'],
			'an undeclared scope' => ['scope' => 'atproto transition:email'],
			'a token response' => ['response_type' => 'token'],
		] as $what => $override) {
			try {
				$this->pushed($override);
				$this->fail('taken: ' . $what);
			} catch (OAuthException) {
				$this->addToAssertionCount(1);
			}
		}

		[$verifier, $challenge] = OAuthKit::pkce();
		$this->pushed(['code_challenge' => $challenge]);
		$this->expectException(OAuthException::class);
		$this->expectExceptionMessage('code challenge was used before');
		$this->pushed(['code_challenge' => $challenge]);
	}

	/**
	 * The sign-in page checks the request before it asks somebody without
	 * an account here for a username, so the check needs no identity.
	 */
	public function testARequestIsReadWithoutAnIdentity(): void {
		[$requestUri] = $this->pushed();

		$requested = $this->server->requested(self::CLIENT, $requestUri);

		$this->assertSame(self::REDIRECT, $requested['request']->params['redirect_uri']);
		$this->assertSame(['atproto', 'transition:generic'], $requested['scopes']);
		$this->assertArrayNotHasKey('identity', $requested);

		$this->expectException(OAuthException::class);
		$this->server->requested('https://other.example/client.json', $requestUri);
	}

	public function testTheLoginHintMustBeTheSignedInAccount(): void {
		[$requestUri] = $this->pushed(['login_hint' => 'bob.social.test']);

		$this->expectException(OAuthException::class);
		$this->expectExceptionMessage('signed in as alice.social.test');
		$this->server->pending(self::CLIENT, $requestUri, 'alice');
	}

	public function testACodeIsExchangedOnceWithItsVerifierAndKeyAndASecondUseEndsTheSession(): void {
		[$requestUri, $verifier] = $this->pushed();
		parse_str((string)parse_url($this->server->approve(self::CLIENT, $requestUri, 'alice'), PHP_URL_QUERY), $callback);
		$exchange = fn (array $override = [], ?PrivateKey $key = null): array => $this->server->token($override + [
			'grant_type' => 'authorization_code', 'client_id' => self::CLIENT, 'code' => $callback['code'],
			'code_verifier' => $verifier, 'redirect_uri' => self::REDIRECT,
		], $this->proof(self::ISSUER . '/oauth/token', 'POST', '', $key));

		foreach ([
			'a wrong verifier' => [['code_verifier' => OAuthKit::pkce()[0]], null],
			'another DPoP key' => [[], PrivateKey::generate(Curve::P256)],
			'another redirect' => [['redirect_uri' => 'https://app.example.com/other'], null],
		] as $what => [$override, $key]) {
			try {
				$exchange($override, $key);
				$this->fail('exchanged with ' . $what);
			} catch (OAuthException $e) {
				$this->assertSame('invalid_grant', $e->error, $what);
			}
		}

		$tokens = $exchange();
		$this->assertCount(1, $this->store->sessions);
		try {
			$exchange();
			$this->fail('exchanged twice');
		} catch (OAuthException $e) {
			$this->assertSame('invalid_grant', $e->error);
		}
		$this->assertCount(0, $this->store->sessions, 'the session the code started is gone');
		$this->expectException(OAuthException::class);
		$url = self::ISSUER . '/xrpc/com.atproto.server.getSession';
		$this->server->authenticate('DPoP ' . $tokens['access_token'], $this->proof($url, 'GET', $tokens['access_token']), 'GET', $url);
	}

	public function testARefreshTokenIsUsedOnceAndItsReplayEndsTheSession(): void {
		$first = $this->signedIn();
		$refresh = fn (string $token): array => $this->server->token([
			'grant_type' => 'refresh_token', 'client_id' => self::CLIENT, 'refresh_token' => $token,
		], $this->proof(self::ISSUER . '/oauth/token'));

		$second = $refresh($first['refresh_token']);
		$this->assertNotSame($first['refresh_token'], $second['refresh_token'], 'rotated');
		$this->assertSame(self::DID, $second['sub']);

		try {
			$refresh($first['refresh_token']);
			$this->fail('an old refresh token worked');
		} catch (OAuthException $e) {
			$this->assertSame('invalid_grant', $e->error);
		}
		$this->assertCount(0, $this->store->sessions, 'a replayed refresh token ends the session');
		$this->expectException(OAuthException::class);
		$refresh($second['refresh_token']);
	}

	public function testAPublicClientsSessionEndsAfterTwoWeeks(): void {
		$tokens = $this->signedIn();
		$this->now += 15 * 86400;

		$this->expectException(OAuthException::class);
		$this->server->token(['grant_type' => 'refresh_token', 'client_id' => self::CLIENT, 'refresh_token' => $tokens['refresh_token']], $this->proof(self::ISSUER . '/oauth/token'));
	}

	public function testRevokingOrSigningOutEndsTheSession(): void {
		$tokens = $this->signedIn();
		$this->assertCount(1, $this->server->sessionsOf('alice'));
		$this->server->revoke($tokens['refresh_token']);
		$this->assertSame([], $this->server->sessionsOf('alice'));

		$tokens = $this->signedIn();
		$this->server->revoke($tokens['access_token']);
		$this->assertSame([], $this->server->sessionsOf('alice'), 'by its access token too');

		$this->signedIn();
		$listed = $this->server->sessionsOf('alice')[0];
		$this->assertSame('app.example.com', $listed['client']);
		$this->server->endSession('bob', $listed['id']);
		$this->assertCount(1, $this->server->sessionsOf('alice'), 'only by its own account');
		$this->server->endSession('alice', $listed['id']);
		$this->assertSame([], $this->server->sessionsOf('alice'));
	}

	/**
	 * Switched off for Bluesky, an app's token does nothing, its refresh is
	 * refused, and no app is let in anew.
	 */
	public function testAnAccountSwitchedOffForBlueskyLetsNoAppIn(): void {
		$tokens = $this->signedIn();
		[$requestUri] = $this->pushed();
		$this->alice = new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', '', '', '', Identity::STATE_DEACTIVATED, '', 0);

		$url = self::ISSUER . '/xrpc/app.bsky.feed.getTimeline';
		$refused = [];
		foreach ([
			fn () => $this->server->authenticate('DPoP ' . $tokens['access_token'], $this->proof($url, 'GET', $tokens['access_token']), 'GET', $url),
			fn () => $this->server->token(['grant_type' => 'refresh_token', 'client_id' => self::CLIENT, 'refresh_token' => $tokens['refresh_token']], $this->proof(self::ISSUER . '/oauth/token')),
			fn () => $this->server->approve(self::CLIENT, $requestUri, 'alice'),
		] as $call) {
			try {
				$call();
				$refused[] = '';
			} catch (OAuthException $e) {
				$refused[] = $e->error;
			}
		}
		$this->assertSame(['invalid_token', 'access_denied', 'access_denied'], $refused);
	}

	/**
	 * A disabled Nextcloud account: an app's token does nothing, its
	 * refresh is refused and no app is let in anew; its session is kept
	 * for when the account is enabled again.
	 */
	public function testADisabledNextcloudAccountLetsNoAppIn(): void {
		$tokens = $this->signedIn();
		[$requestUri] = $this->pushed();
		$this->disabled = ['alice'];

		$url = self::ISSUER . '/xrpc/app.bsky.feed.getTimeline';
		$authenticate = fn () => $this->server->authenticate('DPoP ' . $tokens['access_token'], $this->proof($url, 'GET', $tokens['access_token']), 'GET', $url);
		$refused = [];
		foreach ([
			$authenticate,
			fn () => $this->server->token(['grant_type' => 'refresh_token', 'client_id' => self::CLIENT, 'refresh_token' => $tokens['refresh_token']], $this->proof(self::ISSUER . '/oauth/token')),
			fn () => $this->server->approve(self::CLIENT, $requestUri, 'alice'),
		] as $call) {
			try {
				$call();
				$refused[] = '';
			} catch (OAuthException $e) {
				$refused[] = $e->error;
			}
		}
		$this->assertSame(['invalid_token', 'access_denied', 'access_denied'], $refused);
		$this->assertCount(1, $this->server->sessionsOf('alice'), 'the session is kept');

		$this->disabled = [];
		$this->assertSame('alice', $authenticate()->userId);
	}

	public function testAnExpiredRequestCannotBeApproved(): void {
		[$requestUri] = $this->pushed();
		$this->now += 600;

		$this->expectException(OAuthException::class);
		$this->server->approve(self::CLIENT, $requestUri, 'alice');
	}

	public function testOnlyOfferedScopesAreGranted(): void {
		$this->assertSame(['atproto', 'transition:generic', 'transition:chat.bsky', 'repo:app.bsky.feed.post'], AuthorizationServer::granted('atproto transition:generic transition:chat.bsky repo:app.bsky.feed.post repo:not!a!nsid transition:other'));
	}

	public function testGranularScopesAreGrantedAndAnIncludedSetExpanded(): void {
		$scope = 'atproto repo:app.bsky.feed.post?action=create blob:image/* include:com.example.authBasic?aud=did:web:api.bsky.app%23bsky_appview';
		$this->declared = $scope;
		[$requestUri, $verifier] = $this->pushed(['scope' => $scope]);
		parse_str((string)parse_url($this->server->approve(self::CLIENT, $requestUri, 'alice'), PHP_URL_QUERY), $callback);
		$tokens = $this->server->token([
			'grant_type' => 'authorization_code', 'client_id' => self::CLIENT, 'code' => $callback['code'],
			'code_verifier' => $verifier, 'redirect_uri' => self::REDIRECT,
		], $this->proof(self::ISSUER . '/oauth/token'));
		$this->assertSame($scope, $tokens['scope']);

		$url = self::ISSUER . '/xrpc/app.bsky.feed.getTimeline';
		$permissions = $this->server->authenticate('DPoP ' . $tokens['access_token'], $this->proof($url, 'GET', $tokens['access_token']), 'GET', $url)->permissions();

		$this->assertTrue($permissions->mayWrite('app.bsky.feed.post', 'create'));
		$this->assertFalse($permissions->mayWrite('app.bsky.feed.post', 'delete'));
		$this->assertFalse($permissions->mayWrite('app.bsky.feed.like', 'create'));
		$this->assertTrue($permissions->mayUpload('image/png'));
		$this->assertFalse($permissions->mayUpload('video/mp4'));
		$this->assertTrue($permissions->mayCall('com.example.getThing', 'did:web:api.bsky.app#bsky_appview'), 'from the set, at the service the include named');
		$this->assertFalse($permissions->mayCall('app.bsky.feed.getTimeline', 'did:web:api.bsky.app#bsky_appview'));
		$this->assertFalse($permissions->mayReadEmail());
	}

	public function testAScopeThatIsNoneOrASetThatCannotBeHadIsRefusedAtOnce(): void {
		foreach (['repo:not!a!nsid', 'rpc:*?aud=*', 'include:com.example.unknown'] as $scope) {
			$this->declared = 'atproto ' . $scope;
			try {
				$this->pushed(['scope' => 'atproto ' . $scope]);
				$this->fail('taken: ' . $scope);
			} catch (OAuthException $e) {
				$this->assertSame('invalid_scope', $e->error, $scope);
			}
		}
	}

	/**
	 * @return array<string, array{0: callable(array): array{0: array, 1: array, 2: ?PrivateKey}}> what is changed of a genuine token: header, claims, signing key (null for the service key)
	 */
	public static function foreignTokens(): array {
		return [
			'signed by another key' => [static fn (array $h, array $c): array => [$h, $c, PrivateKey::generate(Curve::K256)]],
			'not an access token' => [static fn (array $h, array $c): array => [['typ' => 'JWT'] + $h, $c, null]],
			'from another issuer' => [static fn (array $h, array $c): array => [$h, ['iss' => 'https://evil.example.com'] + $c, null]],
			'for another service' => [static fn (array $h, array $c): array => [$h, ['aud' => 'did:web:api.bsky.app'] + $c, null]],
			'bound to no key' => [static fn (array $h, array $c): array => [$h, array_diff_key($c, ['cnf' => true]), null]],
			'of no session' => [static fn (array $h, array $c): array => [$h, ['sid' => ''] + $c, null]],
			'expired' => [static fn (array $h, array $c): array => [$h, ['exp' => $c['iat'] - 1] + $c, null]],
			'of another account' => [static fn (array $h, array $c): array => [$h, ['sub' => 'did:plc:z72i7hdynmk6r22z27h6tvur'] + $c, null]],
		];
	}

	/**
	 * An access token is this server's own, for this PDS, unexpired, bound
	 * to the key of the proof that comes with it, and of a session that
	 * still is the account's: a token re-signed or re-addressed, or one an
	 * app password session holds, is refused with the DPoP challenge.
	 *
	 * @param callable(array, array): array{0: array, 1: array, 2: ?PrivateKey} $forge
	 */
	#[DataProvider('foreignTokens')]
	public function testATokenThatIsNotThisServersForThisSessionIsRefused(callable $forge): void {
		$tokens = $this->signedIn();
		[$header, $claims] = array_map(static fn (string $part): array => json_decode(Encoding::base64UrlDecode($part), true), array_slice(explode('.', $tokens['access_token']), 0, 2));
		[$header, $claims, $key] = $forge($header, $claims);
		$input = Encoding::base64UrlEncode((string)json_encode($header)) . '.' . Encoding::base64UrlEncode((string)json_encode($claims, JSON_UNESCAPED_SLASHES));
		$forged = $input . '.' . Encoding::base64UrlEncode(($key ?? $this->serviceKey)->sign($input));
		$url = self::ISSUER . '/xrpc/app.bsky.feed.getTimeline';

		try {
			$this->server->authenticate('DPoP ' . $forged, $this->proof($url, 'GET', $forged), 'GET', $url);
			$this->fail('taken');
		} catch (OAuthException $e) {
			$this->assertSame(401, $e->status);
			$this->assertSame('invalid_token', $e->error);
			$this->assertStringStartsWith('DPoP error="invalid_token"', $e->headers['WWW-Authenticate']);
		}
	}

	public function testATokenOfAnEndedSessionOrADeactivatedAccountIsRefused(): void {
		$url = self::ISSUER . '/xrpc/app.bsky.feed.getTimeline';
		$tokens = $this->signedIn();
		$this->server->endSession('alice', $this->server->sessionsOf('alice')[0]['id']);
		foreach ([$tokens, $this->signedIn()] as $i => $pair) {
			if ($i === 1) {
				$this->alice = new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', '', '', '', Identity::STATE_DEACTIVATED, '', 0);
			}
			try {
				$this->server->authenticate('DPoP ' . $pair['access_token'], $this->proof($url, 'GET', $pair['access_token']), 'GET', $url);
				$this->fail($i === 0 ? 'the ended session acted' : 'the deactivated account acted');
			} catch (OAuthException $e) {
				$this->assertSame(401, $e->status);
				$this->assertStringContainsString($i === 0 ? 'ended' : 'deactivated', $e->getMessage());
			}
		}
	}

	/**
	 * A code is good until the moment it expires and not after: one exchanged
	 * a second late, by the same client with the right verifier and key, is
	 * not a session.
	 */
	public function testACodeIsGoodUntilItExpiresAndNotASecondLonger(): void {
		foreach ([300 => true, 301 => false] as $age => $good) {
			[$requestUri, $verifier] = $this->pushed();
			parse_str((string)parse_url($this->server->approve(self::CLIENT, $requestUri, 'alice'), PHP_URL_QUERY), $callback);
			$approved = $this->now;
			$this->now += $age;
			try {
				$tokens = $this->server->token([
					'grant_type' => 'authorization_code', 'client_id' => self::CLIENT, 'code' => $callback['code'],
					'code_verifier' => $verifier, 'redirect_uri' => self::REDIRECT,
				], $this->proof(self::ISSUER . '/oauth/token'));
				$this->assertTrue($good, 'a code ' . $age . ' s old was exchanged');
				$this->assertArrayHasKey('access_token', $tokens);
			} catch (OAuthException $e) {
				$this->assertFalse($good, 'a code ' . $age . ' s old was refused: ' . $e->getMessage());
				$this->assertSame('invalid_grant', $e->error);
			}
			$this->now = $approved;
		}
	}

	/**
	 * A session that has a lifetime ends with it, whatever the access token
	 * says: a token minted before the end is refused after it, and taken up
	 * to the second the session ends.
	 */
	public function testATokenOfASessionPastItsEndIsRefusedThoughTheTokenIsStillGood(): void {
		$url = self::ISSUER . '/xrpc/app.bsky.feed.getTimeline';
		$tokens = $this->signedIn();
		$ending = function (int $expires): void {
			$session = array_values($this->store->sessions)[0];
			$this->store->sessions[$session->sessionId] = new OAuthSession(
				$session->id, $session->sessionId, $session->userId, $session->did, $session->clientId,
				$session->clientAuth, $session->scope, $session->dpopJkt, $session->refreshHash,
				$session->refreshExpires, $expires, $session->creation, $session->lastUsed,
			);
		};

		$ending($this->now);
		$this->assertSame(self::DID, $this->server->authenticate('DPoP ' . $tokens['access_token'], $this->proof($url, 'GET', $tokens['access_token']), 'GET', $url)->identity->did);

		$ending($this->now - 1);
		try {
			$this->server->authenticate('DPoP ' . $tokens['access_token'], $this->proof($url, 'GET', $tokens['access_token']), 'GET', $url);
			$this->fail('the session acted past its end');
		} catch (OAuthException $e) {
			$this->assertSame(401, $e->status);
			$this->assertStringContainsString('ended', $e->getMessage());
		}
	}

	/**
	 * Each lifetime of a Bluesky app's sign-in, on its last second and on the
	 * one after: the pushed request (five minutes), the code (five minutes
	 * from consent), the access token (fifteen minutes) and a public client's
	 * session (two weeks), whose last refresh hands out a token that ends with
	 * it rather than two weeks later.
	 */
	public function testARequestIsApprovedOnItsLastSecondAndNotTheNext(): void {
		$pushedAt = $this->now;
		[$onTime] = $this->pushed();
		[$late] = $this->pushed();

		$this->now = $pushedAt + 300;
		$this->assertStringStartsWith(self::REDIRECT . '?', $this->server->approve(self::CLIENT, $onTime, 'alice'));

		$this->now++;
		$this->expectException(OAuthException::class);
		$this->expectExceptionMessage('has expired');
		$this->server->approve(self::CLIENT, $late, 'alice');
	}

	public function testACodeIsExchangedOnItsLastSecondAndNotTheNext(): void {
		$codes = [];
		foreach (['on time', 'late'] as $which) {
			[$requestUri, $verifier] = $this->pushed();
			parse_str((string)parse_url($this->server->approve(self::CLIENT, $requestUri, 'alice'), PHP_URL_QUERY), $callback);
			$codes[$which] = [$callback['code'], $verifier];
		}
		$approvedAt = $this->now;
		$exchange = fn (array $code): array => $this->server->token([
			'grant_type' => 'authorization_code', 'client_id' => self::CLIENT, 'code' => $code[0],
			'code_verifier' => $code[1], 'redirect_uri' => self::REDIRECT,
		], $this->proof(self::ISSUER . '/oauth/token'));

		$this->now = $approvedAt + 300;
		$this->assertSame(self::DID, $exchange($codes['on time'])['sub']);

		$this->now++;
		try {
			$exchange($codes['late']);
			$this->fail('a code was exchanged a second after its lifetime');
		} catch (OAuthException $e) {
			$this->assertSame('invalid_grant', $e->error);
		}
	}

	public function testAnAccessTokenActsOnItsLastSecondAndNotTheNext(): void {
		$issuedAt = $this->now;
		$tokens = $this->signedIn();
		$url = self::ISSUER . '/xrpc/app.bsky.feed.getTimeline';

		$this->now = $issuedAt + AuthorizationServer::ACCESS_LIFETIME;
		$this->assertSame('alice', $this->server->authenticate('DPoP ' . $tokens['access_token'], $this->proof($url, 'GET', $tokens['access_token']), 'GET', $url)->userId);

		$this->now++;
		try {
			$this->server->authenticate('DPoP ' . $tokens['access_token'], $this->proof($url, 'GET', $tokens['access_token']), 'GET', $url);
			$this->fail('an access token acted a second after it expired');
		} catch (OAuthException $e) {
			$this->assertSame(401, $e->status);
			$this->assertSame('The token has expired', $e->getMessage());
		}
	}

	public function testAPublicClientRefreshesOnTheLastSecondOfItsSessionAndTheTokenEndsWithIt(): void {
		$signedInAt = $this->now;
		$tokens = $this->signedIn();
		$refresh = fn (string $token): array => $this->server->token([
			'grant_type' => 'refresh_token', 'client_id' => self::CLIENT, 'refresh_token' => $token,
		], $this->proof(self::ISSUER . '/oauth/token'));

		$this->now = $signedInAt + 14 * 86400;
		$last = $refresh($tokens['refresh_token']);
		$this->assertSame(self::DID, $last['sub'], 'refreshed on the last second of the session');

		$this->now++;
		try {
			$refresh($last['refresh_token']);
			$this->fail('the session outlived its two weeks');
		} catch (OAuthException $e) {
			$this->assertSame('invalid_grant', $e->error);
		}
	}
}
