<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\OAuth;

use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\OAuth\ClientAuthenticator;
use OCA\Social\Atproto\OAuth\Jwk;
use OCA\Social\Atproto\OAuth\OAuthException;
use OCA\Social\Service\CurlService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ClientAuthenticatorTest extends TestCase {
	private const CLIENT = 'https://app.example.com/oauth-client-metadata.json';
	private const ISSUER = 'https://social.test';

	private int $now = 1760000000;
	private PrivateKey $key;
	private array $metadata;
	private ClientAuthenticator $authenticator;

	protected function setUp(): void {
		$this->key = PrivateKey::generate(Curve::P256);
		$this->metadata = OAuthKit::clientDocument([
			'token_endpoint_auth_method' => 'private_key_jwt',
			'jwks' => ['keys' => [OAuthKit::jwk($this->key, ['kid' => 'k1'])]],
		]);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$request = (new \ReflectionClass(InMemoryOAuthRequest::class))->newInstanceWithoutConstructor();
		$this->authenticator = new ClientAuthenticator($request, $this->createMock(CurlService::class), $time);
	}

	private function assertion(array $claims = [], ?PrivateKey $key = null, string $kid = 'k1'): array {
		return [
			'client_assertion_type' => ClientAuthenticator::ASSERTION_TYPE,
			'client_assertion' => OAuthKit::sign($key ?? $this->key, ['alg' => 'ES256', 'kid' => $kid], $claims + [
				'iss' => self::CLIENT, 'sub' => self::CLIENT, 'aud' => self::ISSUER, 'jti' => bin2hex(random_bytes(8)), 'iat' => $this->now,
			]),
		];
	}

	public function testAPublicClientIsItsIdAndMayNotPretendOtherwise(): void {
		$public = OAuthKit::clientDocument();
		$this->assertSame(['method' => 'none'], $this->authenticator->authenticate($public, [], self::ISSUER));

		$this->expectException(OAuthException::class);
		$this->authenticator->authenticate($public, ['client_secret' => 'x'], self::ISSUER);
	}

	public function testAConfidentialClientIsTheKeyItSignedWith(): void {
		$auth = $this->authenticator->authenticate($this->metadata, $this->assertion(), self::ISSUER);

		$this->assertSame(['method' => 'private_key_jwt', 'kid' => 'k1', 'alg' => 'ES256', 'jkt' => Jwk::thumbprint(OAuthKit::jwk($this->key))], $auth);
		$this->assertTrue(ClientAuthenticator::same($auth, $this->authenticator->authenticate($this->metadata, $this->assertion(), self::ISSUER)), 'the next request, the same key');
		$this->assertFalse(ClientAuthenticator::same($auth, ['method' => 'none']));
	}

	public function testABadAssertionIsRefused(): void {
		$replayed = $this->assertion(['jti' => 'once']);
		$this->authenticator->authenticate($this->metadata, $replayed, self::ISSUER);
		foreach ([
			'replayed' => $replayed,
			'none at all' => [],
			'for another server' => $this->assertion(['aud' => 'https://other.test']),
			'of another client' => $this->assertion(['iss' => 'https://evil.example.com/meta.json']),
			'old' => $this->assertion(['iat' => $this->now - 3600]),
			'by a key it does not publish' => $this->assertion([], PrivateKey::generate(Curve::P256)),
			'naming a key it does not publish' => $this->assertion([], null, 'k2'),
		] as $what => $params) {
			try {
				$this->authenticator->authenticate($this->metadata, $params, self::ISSUER);
				$this->fail('taken: ' . $what);
			} catch (OAuthException $e) {
				$this->assertSame('invalid_client', $e->error, $what);
			}
		}
	}
}
