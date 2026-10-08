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
use OCA\Social\Atproto\OAuth\AuthorizationServer;
use OCA\Social\Atproto\OAuth\ClientAuthenticator;
use OCA\Social\Atproto\OAuth\ClientMetadataService;
use OCA\Social\Atproto\OAuth\DpopNonce;
use OCA\Social\Atproto\OAuth\DpopVerifier;
use OCA\Social\Atproto\OAuth\OAuthException;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\CurlService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class AuthorizationServerTest extends TestCase {
	private const ISSUER = 'https://social.test';
	private const CLIENT = 'https://app.example.com/oauth-client-metadata.json';
	private const REDIRECT = 'https://app.example.com/callback';
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	private int $now = 1760000000;
	private PrivateKey $dpopKey;
	private DpopNonce $nonce;
	private InMemoryOAuthRequest $store;
	private Identity $alice;
	private AuthorizationServer $server;

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
		$curl->method('retrieveJson')->willReturnCallback(static function (string $method, string $url, array $options, ?int &$status = null, ?string &$type = null): array {
			$status = 200;
			$type = 'application/json';

			return OAuthKit::clientDocument();
		});
		$cache = $this->createMock(ICacheFactory::class);
		$identities = $this->createMock(IdentityService::class);
		$identities->method('forActor')->willReturnCallback(fn (): Identity => $this->alice);
		$identities->method('getByDid')->willReturnCallback(fn (): Identity => $this->alice);
		$serviceKey = PrivateKey::generate(Curve::K256);
		$keys = $this->createMock(InstanceKeyService::class);
		$keys->method('serviceKey')->willReturn($serviceKey);
		$actors = $this->createMock(ActorsRequest::class);
		$actors->method('getFromUserId')->willReturn(new Person());
		$this->server = new AuthorizationServer(
			$config, $this->store, new ClientMetadataService($curl, $cache),
			new ClientAuthenticator($this->store, $curl, $time), new DpopVerifier($this->nonce, $this->store, $time),
			$identities, $keys, $actors, $time,
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

	public function testAnExpiredRequestCannotBeApproved(): void {
		[$requestUri] = $this->pushed();
		$this->now += 600;

		$this->expectException(OAuthException::class);
		$this->server->approve(self::CLIENT, $requestUri, 'alice');
	}

	public function testOnlyOfferedScopesAreGranted(): void {
		$this->assertSame(['atproto', 'transition:generic'], AuthorizationServer::granted('atproto transition:generic transition:chat.bsky repo:app.bsky.feed.post'));
	}
}
