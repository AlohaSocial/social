<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\AppView;

use OCA\Social\Atproto\AppView\ServiceAuth;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Protocol\Encoding;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ServiceAuthTest extends TestCase {
	public function testATokenIsSignedByTheUserForOneServiceAndMethod(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1760000000);
		$key = PrivateKey::generate(Curve::K256);
		$token = (new ServiceAuth($time))->token($key, 'did:plc:ewvi7nxzyoun6zhxrhs64oiz', 'did:web:api.bsky.app', 'app.bsky.notification.listNotifications');

		[$header, $payload, $signature] = explode('.', $token);
		$this->assertSame(['typ' => 'JWT', 'alg' => 'ES256K'], json_decode(Encoding::base64UrlDecode($header), true));
		$claims = json_decode(Encoding::base64UrlDecode($payload), true);
		$this->assertSame('did:plc:ewvi7nxzyoun6zhxrhs64oiz', $claims['iss']);
		$this->assertSame('did:web:api.bsky.app', $claims['aud']);
		$this->assertSame('app.bsky.notification.listNotifications', $claims['lxm']);
		$this->assertSame(1760000000, $claims['iat']);
		$this->assertSame(1760000000 + ServiceAuth::LIFETIME, $claims['exp']);
		$this->assertSame(32, strlen((string)$claims['jti']));
		$this->assertTrue($key->publicKey()->verify($header . '.' . $payload, Encoding::base64UrlDecode($signature)), 'the signature covers header and payload');
		$this->assertFalse(PrivateKey::generate(Curve::K256)->publicKey()->verify($header . '.' . $payload, Encoding::base64UrlDecode($signature)));
	}

	public function testATokenThatArrivesIsCheckedForKeyServiceMethodAndTime(): void {
		$now = 1760000000;
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(function () use (&$now) {
			return $now;
		});
		$auth = new ServiceAuth($time);
		$key = PrivateKey::generate(Curve::K256);
		$token = $auth->token($key, 'did:plc:alice', 'did:web:social.example.com', 'com.atproto.server.createAccount');

		$this->assertSame('did:plc:alice', $auth->issuer($token));
		$this->assertTrue($auth->verify($token, $key->publicKey(), 'did:web:social.example.com', 'com.atproto.server.createAccount'));
		$this->assertFalse($auth->verify($token, PrivateKey::generate(Curve::K256)->publicKey(), 'did:web:social.example.com', 'com.atproto.server.createAccount'), 'another key');
		$this->assertFalse($auth->verify($token, PrivateKey::generate(Curve::P256)->publicKey(), 'did:web:social.example.com', 'com.atproto.server.createAccount'), 'another curve');
		$this->assertFalse($auth->verify($token, $key->publicKey(), 'did:web:other.example.com', 'com.atproto.server.createAccount'), 'another service');
		$this->assertFalse($auth->verify($token, $key->publicKey(), 'did:web:social.example.com', 'com.atproto.repo.importRepo'), 'another method');
		[$header, , $signature] = explode('.', $token);
		$forged = $header . '.' . Encoding::base64UrlEncode((string)json_encode(['iss' => 'did:plc:alice', 'aud' => 'did:web:social.example.com', 'lxm' => 'com.atproto.server.createAccount', 'exp' => $now + 3600])) . '.' . $signature;
		$this->assertFalse($auth->verify($forged, $key->publicKey(), 'did:web:social.example.com', 'com.atproto.server.createAccount'), 'claims changed after signing');
		$this->assertSame('', $auth->issuer('not a token'));

		$now += ServiceAuth::LIFETIME;
		$this->assertFalse($auth->verify($token, $key->publicKey(), 'did:web:social.example.com', 'com.atproto.server.createAccount'), 'expired');
	}
}
