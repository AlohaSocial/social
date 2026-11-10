<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Exceptions\RefusalException;
use OCA\Social\Security\RemoteAddress;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\PushSubscriptionService;
use OCP\Lock\ILockingProvider;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class PushSubscriptionServiceTest extends TestCase {
	private ConfigService&Stub $config;
	private ICrypto&Stub $crypto;
	private ILockingProvider&Stub $locks;
	private string $stored = '';

	protected function setUp(): void {
		RemoteAddress::resolveWith(static fn (): array => ['1.1.1.1']);
		$this->config = $this->createStub(ConfigService::class);
		$this->config->method('getUserValue')->willReturnCallback(
			fn (): string => $this->stored
		);
		$this->config->method('setValueForUser')->willReturnCallback(
			function (string $userId, string $key, string $value): void {
				$this->stored = $value;
			}
		);
		$this->crypto = $this->createStub(ICrypto::class);
		$this->crypto->method('encrypt')->willReturnCallback(static fn (string $value): string => base64_encode($value));
		$this->crypto->method('decrypt')->willReturnCallback(static fn (string $value): string => base64_decode($value, true) ?: throw new \RuntimeException('bad cipher text'));
		$this->locks = $this->createStub(ILockingProvider::class);
	}

	protected function tearDown(): void {
		RemoteAddress::resolveWith(null);
	}

	public function testSubscriptionsAreEncryptedScopedToATokenAndCanBeUpdatedAndRemoved(): void {
		$service = $this->service();
		$created = $service->save('alice', 'token-one', 'bearer-one', 'https://push.example.net/endpoint/secret', str_repeat('A', 87), str_repeat('b', 22), ['mention' => true], 'followed');

		$this->assertSame('followed', $created['policy']);
		$this->assertTrue($created['alerts']['mention']);
		$this->assertSame($created, $service->forToken('alice', 'token-one'));
		$this->assertSame('bearer-one', $service->forUser('alice')[0]['accessToken']);
		$this->assertNull($service->forToken('alice', 'token-two'));
		$this->assertStringNotContainsString('push.example.net', $this->stored);
		$this->assertStringNotContainsString('bearer-one', $this->stored);
		$this->assertSame([$created], $this->service()->all('alice'));

		$updated = $service->update('alice', 'token-one', ['follow' => true], 'all');
		$this->assertNotNull($updated);
		$this->assertTrue($updated['alerts']['follow']);
		$this->assertFalse($updated['alerts']['mention']);
		$this->assertSame('all', $updated['policy']);
		$this->assertTrue($service->delete('alice', 'token-one'));
		$this->assertSame([], $service->all('alice'));
	}

	public function testWhetherAnAccountHasSubscriptionsIsAnsweredWithoutDecrypting(): void {
		$service = $this->service();
		$this->assertFalse($service->hasAny('alice'), 'never subscribed');
		$this->assertFalse($service->hasAny(''));

		$service->save('alice', 'token-one', 'bearer-one', 'https://push.example.net/endpoint', str_repeat('A', 87), str_repeat('b', 22), [], 'all');
		$this->assertTrue($service->hasAny('alice'));

		$service->delete('alice', 'token-one');
		$this->assertSame('', $this->stored, 'the last subscription removed leaves nothing stored');
		$this->assertFalse($service->hasAny('alice'));
		$this->assertSame([], $service->forUser('alice'));
	}

	public function testReplacingATokenKeepsItsIdAndEnforcesTheAccountLimit(): void {
		$service = $this->service();
		$first = $service->save('alice', 'token-one', 'bearer-one', 'https://push.example.net/one', str_repeat('A', 87), str_repeat('b', 22), [], 'all');
		$replacement = $service->save('alice', 'token-one', 'bearer-one', 'https://push.example.net/two', str_repeat('C', 87), str_repeat('d', 22), [], 'all');
		$this->assertSame($first['id'], $replacement['id']);

		for ($i = 1; $i < 20; $i++) {
			$service->save('alice', 'token-' . $i, 'bearer-' . $i, 'https://push.example.net/' . $i, str_repeat('E', 87), str_repeat('f', 22), [], 'all');
		}
		$this->assertRefusal(
			'push-subscriptions-full',
			fn () => $service->save('alice', 'token-over-limit', 'bearer-last', 'https://push.example.net/last', str_repeat('G', 87), str_repeat('h', 22), [], 'all'),
			['limit' => 20],
		);
	}

	public function testSubscriptionsUsingTheSameEndpointRemainScopedToTheirOwnTokens(): void {
		$service = $this->service();
		$endpoint = 'https://push.example.net/shared-browser-endpoint';
		$first = $service->save('alice', 'token-one', 'bearer-one', $endpoint, str_repeat('A', 87), str_repeat('b', 22), [], 'followed');
		$second = $service->save('alice', 'token-two', 'bearer-two', $endpoint, str_repeat('C', 87), str_repeat('d', 22), [], 'follower');

		$this->assertCount(2, $service->all('alice'));
		$this->assertSame($first, $service->forToken('alice', 'token-one'));
		$this->assertSame($second, $service->forToken('alice', 'token-two'));
		$this->assertMatchesRegularExpression('/^[1-9][0-9]*$/', $first['id']);
		$this->assertMatchesRegularExpression('/^[1-9][0-9]*$/', $second['id']);
	}

	public function testRefusesAnInsecureOrPrivatePushEndpoint(): void {
		$this->assertRefusal('push-endpoint-unreachable', fn () => $this->save(endpoint: 'http://push.example.net/endpoint'));
	}

	public function testInvalidPushSubscriptionInputsHaveStableCodes(): void {
		$this->assertRefusal('push-auth-required', fn () => $this->save(userId: ''));
		$this->assertRefusal('push-keys-invalid', fn () => $this->save(p256dh: 'bad'));
		$this->assertRefusal('push-policy-invalid', fn () => $this->save(policy: 'sometimes'));
		$this->assertRefusal('push-alerts-invalid', fn () => $this->save(alerts: ['mention' => 'sometimes']));
	}

	private function save(
		string $userId = 'alice',
		?string $p256dh = null,
		array $alerts = [],
		string $policy = 'all',
		string $endpoint = 'https://push.example.net/endpoint',
	): array {
		return $this->service()->save(
			$userId,
			'token-one',
			'bearer-one',
			$endpoint,
			$p256dh ?? str_repeat('A', 87),
			str_repeat('b', 22),
			$alerts,
			$policy,
		);
	}

	private function assertRefusal(string $code, callable $operation, array $params = []): void {
		try {
			$operation();
			$this->fail('Expected refusal ' . $code);
		} catch (RefusalException $e) {
			$this->assertSame($code, $e->errorCode);
			$this->assertSame($params, $e->params);
		}
	}

	private function service(): PushSubscriptionService {
		return new PushSubscriptionService($this->config, $this->crypto, $this->locks, new NullLogger());
	}
}
