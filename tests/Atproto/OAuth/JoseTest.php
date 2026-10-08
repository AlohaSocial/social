<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\OAuth;

use InvalidArgumentException;
use OCA\Social\Atproto\Crypto\BigNum;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\OAuth\Jose;
use OCA\Social\Atproto\OAuth\Jwk;
use OCA\Social\Atproto\Protocol\Encoding;
use PHPUnit\Framework\TestCase;

class JoseTest extends TestCase {
	public function testAJwkIsAPublicKeyOnItsCurveAndHasOneThumbprint(): void {
		foreach ([Curve::P256, Curve::K256] as $curve) {
			$key = PrivateKey::generate($curve);
			$jwk = OAuthKit::jwk($key);

			$this->assertSame($key->publicKey()->x, Jwk::publicKey($jwk)->x);
			$this->assertSame(Jwk::thumbprint($jwk), Jwk::thumbprint(OAuthKit::jwk($key, ['kid' => 'k1', 'use' => 'sig', 'alg' => 'ES256'])), 'only the required members count');
			$this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', Jwk::thumbprint($jwk));
		}
	}

	public function testAPrivateOrOffCurveKeyIsRefused(): void {
		$key = PrivateKey::generate(Curve::P256);
		foreach ([
			'private' => OAuthKit::jwk($key, ['d' => 'secret']),
			'off the curve' => ['y' => Encoding::base64UrlEncode(str_repeat("\x01", 32))] + OAuthKit::jwk($key),
			'RSA' => ['kty' => 'RSA', 'n' => 'x', 'e' => 'AQAB'],
			'another curve' => ['crv' => 'P-384'] + OAuthKit::jwk($key),
		] as $what => $jwk) {
			try {
				Jwk::publicKey($jwk);
				$this->fail('taken: ' . $what);
			} catch (InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testAJoseSignatureMayBeHighSAndUsesItsKeysAlgorithm(): void {
		$key = PrivateKey::generate(Curve::P256);
		$jws = OAuthKit::sign($key, ['alg' => 'ES256', 'typ' => 'JWT'], ['a' => 1]);
		$decoded = Jose::decode($jws);
		$this->assertTrue(Jose::verify($decoded, $key->publicKey()));

		// the same signature with s replaced by n - s: what WebCrypto may make
		$s = BigNum::fromBytes(substr($decoded['signature'], 32));
		$high = substr($decoded['signature'], 0, 32) . Curve::P256->order()->subtract($s)->toBytes(32);
		$this->assertFalse($key->publicKey()->verify($decoded['input'], $high), 'AT Protocol\'s own signatures stay low-S');
		$this->assertTrue(Jose::verify(['signature' => $high] + $decoded, $key->publicKey()), 'a JOSE one need not be');

		$this->assertFalse(Jose::verify(['header' => ['alg' => 'ES256K']] + $decoded, $key->publicKey()), 'not with another curve\'s algorithm');
		$this->assertFalse(Jose::verify(['header' => ['alg' => 'none']] + $decoded, $key->publicKey()));
		$this->assertFalse(Jose::verify($decoded, PrivateKey::generate(Curve::P256)->publicKey()));
	}

	public function testOnlyACompactJwsIsRead(): void {
		$this->expectException(InvalidArgumentException::class);
		Jose::decode('a.b');
	}
}
