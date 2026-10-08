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
}
