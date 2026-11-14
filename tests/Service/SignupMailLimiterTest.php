<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Service\DurableCache;
use OCA\Social\Service\SignupMailLimiter;
use OCA\Social\Tests\Helper\FrozenClock;
use OCA\Social\Tests\Helper\InMemoryDurableCacheRequest;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SignupMailLimiterTest extends TestCase {
	private const NOW = 2000000;

	private InMemoryDurableCacheRequest $table;
	private FrozenClock $clock;

	protected function setUp(): void {
		$this->table = new InMemoryDurableCacheRequest();
		$this->clock = new FrozenClock(self::NOW);
	}

	private function limiter(string $secret = 'secret', bool $memcache = false): SignupMailLimiter {
		$cacheFactory = $this->createStub(ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturn($memcache);
		// a memcache that forgets everything, as an evicting one may
		$cacheFactory->method('createDistributed')->willReturn($this->createStub(ICache::class));
		$config = $this->createStub(IConfig::class);
		$config->method('getSystemValueString')->willReturn($secret);

		return new SignupMailLimiter(new DurableCache($cacheFactory, $this->table, $this->clock), $config);
	}

	/** @return array<string, array{string, string}> */
	public static function addresses(): array {
		return [
			'case and blanks' => [' Bob@Example.ORG ', 'bob@example.org'],
			'sub-address' => ['bob+news@example.org', 'bob@example.org'],
			'gmail dots' => ['b.o.b+x@googlemail.com', 'bob@gmail.com'],
			'dots elsewhere count' => ['b.o.b@example.org', 'b.o.b@example.org'],
			'a leading plus is the address' => ['+bob@example.org', '+bob@example.org'],
		];
	}

	#[DataProvider('addresses')]
	public function testAnAddressIsNormalisedToTheInboxItReaches(string $email, string $normalised): void {
		$this->assertSame($normalised, SignupMailLimiter::normalise($email));
	}

	public function testANoticeWaitsForTheCooldownEvenWhereTheMemcacheForgets(): void {
		$limiter = $this->limiter('secret', true);

		$this->assertNotNull($limiter->claim('bob@example.org', SignupMailLimiter::KIND_NOTICE));
		$this->assertNull($limiter->claim('BOB+x@example.org', SignupMailLimiter::KIND_NOTICE));
		$this->assertNotNull($limiter->claim('carol@example.org', SignupMailLimiter::KIND_NOTICE));

		$this->clock->set(self::NOW + SignupMailLimiter::NOTICE_COOLDOWN);
		$this->assertNotNull($limiter->claim('bob@example.org', SignupMailLimiter::KIND_NOTICE));
	}

	public function testConfirmationLinksAreOnlyCappedAndHoldBackNotices(): void {
		$limiter = $this->limiter();

		for ($i = 0; $i < SignupMailLimiter::DAILY_CAP; $i++) {
			$this->assertNotNull($limiter->claim('bob@example.org', SignupMailLimiter::KIND_VERIFICATION));
		}
		$this->assertNull($limiter->claim('bob@example.org', SignupMailLimiter::KIND_VERIFICATION));
		$this->assertNull($limiter->claim('bob@example.org', SignupMailLimiter::KIND_NOTICE));
	}

	public function testAReleasedClaimIsGivenBack(): void {
		$limiter = $this->limiter();
		$claim = $limiter->claim('bob@example.org', SignupMailLimiter::KIND_NOTICE);
		$this->assertNotNull($claim);

		$limiter->release($claim);

		$this->assertNotNull($limiter->claim('bob@example.org', SignupMailLimiter::KIND_NOTICE));
	}

	public function testTheAddressIsNotStoredAndItsKeyDependsOnTheSecret(): void {
		$this->limiter('one')->claim('bob@example.org', SignupMailLimiter::KIND_NOTICE);

		$this->assertStringNotContainsString('bob', serialize($this->table));
		$this->assertNotNull($this->limiter('two')->claim('bob@example.org', SignupMailLimiter::KIND_NOTICE));
	}
}
