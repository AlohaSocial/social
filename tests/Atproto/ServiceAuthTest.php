<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto;

use OCA\Social\Atproto\Auth\ServiceAuth;
use OCA\Social\Atproto\Identity\KeyManager;
use PHPUnit\Framework\TestCase;

class ServiceAuthTest extends TestCase {
	public function testServiceTokenBindsTheAccountAudienceMethodAndSixtySecondExpiry(): void {
		$keys = new KeyManager(null, null);
		$key = $keys->generateSigningKey();
		$jwt = ServiceAuth::sign('did:plc:abcdefghijklmnopqrstuvwx', $key['private'], 'app.bsky.notification.listNotifications', $keys, 1800000000);
		[$header, $payload, $signature] = explode('.', $jwt);
		$decode = static fn ($part) => base64_decode(strtr($part, '-_', '+/'));
		self::assertSame('ES256K', json_decode($decode($header), true)['alg']);
		$claims = json_decode($decode($payload), true);
		self::assertSame('did:web:api.bsky.app', $claims['aud']);
		self::assertSame('app.bsky.notification.listNotifications', $claims['lxm']);
		self::assertSame(60, $claims['exp'] - $claims['iat']);
		self::assertTrue($keys->verify($header . '.' . $payload, $decode($signature), $key['didKey']));
		self::assertFalse($keys->verify($header . '.' . ServiceAuth::encode('{"aud":"did:web:attacker.example"}'), $decode($signature), $key['didKey']));
	}
}
