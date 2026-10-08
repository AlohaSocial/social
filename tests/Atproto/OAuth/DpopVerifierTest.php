<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\OAuth;

use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\OAuth\DpopNonce;
use OCA\Social\Atproto\OAuth\DpopVerifier;
use OCA\Social\Atproto\OAuth\Jwk;
use OCA\Social\Atproto\OAuth\OAuthException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class DpopVerifierTest extends TestCase {
	private const URL = 'https://social.test/oauth/token';

	private int $now = 1760000000;
	private PrivateKey $key;
	private DpopNonce $nonce;
	private DpopVerifier $verifier;

	protected function setUp(): void {
		$this->key = PrivateKey::generate(Curve::P256);
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('calculateHMAC')->willReturnCallback(static fn (string $message): string => hash_hmac('sha256', $message, 'instance secret', true));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$this->nonce = new DpopNonce($crypto, $time);
		$request = (new \ReflectionClass(InMemoryOAuthRequest::class))->newInstanceWithoutConstructor();
		$this->verifier = new DpopVerifier($this->nonce, $request, $time);
	}

	public function testAGoodProofProvesItsKey(): void {
		$proof = OAuthKit::proof($this->key, 'POST', self::URL, $this->now, $this->nonce->current());

		$this->assertSame(Jwk::thumbprint(OAuthKit::jwk($this->key)), $this->verifier->verify($proof, 'POST', self::URL . '?ignored=1'));
	}

	public function testWithoutTheCurrentNonceTheClientIsGivenIt(): void {
		try {
			$this->verifier->verify(OAuthKit::proof($this->key, 'POST', self::URL, $this->now, 'stale'), 'POST', self::URL);
			$this->fail('taken');
		} catch (OAuthException $e) {
			$this->assertSame('use_dpop_nonce', $e->error);
			$this->assertSame($this->nonce->current(), $e->headers['DPoP-Nonce']);
		}
	}

	public function testANonceIsGoodForTwoWindowsAndNoLonger(): void {
		$nonce = $this->nonce->current();
		$this->now += DpopNonce::WINDOW;
		$this->assertTrue($this->nonce->isValid($nonce), 'across a rotation');
		$this->assertNotSame($nonce, $this->nonce->current(), 'rotated');
		$this->now += DpopNonce::WINDOW;
		$this->assertFalse($this->nonce->isValid($nonce));
	}

	public function testAProofForAnotherRequestOrTokenOrTimeIsRefused(): void {
		$nonce = $this->nonce->current();
		foreach ([
			'another method' => [OAuthKit::proof($this->key, 'GET', self::URL, $this->now, $nonce), ''],
			'another address' => [OAuthKit::proof($this->key, 'POST', 'https://social.test/oauth/par', $this->now, $nonce), ''],
			'another host' => [OAuthKit::proof($this->key, 'POST', 'https://evil.test/oauth/token', $this->now, $nonce), ''],
			'old' => [OAuthKit::proof($this->key, 'POST', self::URL, $this->now - 600, $nonce), ''],
			'from the future' => [OAuthKit::proof($this->key, 'POST', self::URL, $this->now + 600, $nonce), ''],
			'another token' => [OAuthKit::proof($this->key, 'POST', self::URL, $this->now, $nonce, 'other token'), 'the token'],
			'no token named' => [OAuthKit::proof($this->key, 'POST', self::URL, $this->now, $nonce), 'the token'],
			'no proof' => ['', ''],
			'not a JWT' => ['x.y.z', ''],
			'another type' => [OAuthKit::sign($this->key, ['typ' => 'JWT', 'alg' => 'ES256', 'jwk' => OAuthKit::jwk($this->key)], ['jti' => 'a', 'htm' => 'POST', 'htu' => self::URL, 'iat' => $this->now, 'nonce' => $nonce]), ''],
			'signed by another key' => [OAuthKit::sign(PrivateKey::generate(Curve::P256), ['typ' => 'dpop+jwt', 'alg' => 'ES256', 'jwk' => OAuthKit::jwk($this->key)], ['jti' => 'b', 'htm' => 'POST', 'htu' => self::URL, 'iat' => $this->now, 'nonce' => $nonce]), ''],
		] as $what => [$proof, $token]) {
			try {
				$this->verifier->verify($proof, 'POST', self::URL, $token);
				$this->fail('taken: ' . $what);
			} catch (OAuthException $e) {
				$this->assertSame('invalid_dpop_proof', $e->error, $what);
			}
		}

		$this->assertNotSame('', $this->verifier->verify(OAuthKit::proof($this->key, 'POST', self::URL, $this->now, $nonce, 'the token'), 'POST', self::URL, 'the token'), 'the token it names');
	}

	public function testAProofIsUsedOnce(): void {
		$proof = OAuthKit::proof($this->key, 'POST', self::URL, $this->now, $this->nonce->current(), '', 'once');
		$this->verifier->verify($proof, 'POST', self::URL);

		$this->expectException(OAuthException::class);
		$this->expectExceptionMessage('used before');
		$this->verifier->verify($proof, 'POST', self::URL);
	}
}
