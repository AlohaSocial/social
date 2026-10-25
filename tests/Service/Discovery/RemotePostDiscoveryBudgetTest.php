<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service\Discovery;

use OCA\Social\Service\Discovery\RemotePostDiscoveryBudget;
use OCA\Social\Service\DurableCache;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

class RemotePostDiscoveryBudgetTest extends TestCase {
	public function testCapsOutboundRequestsAcrossTheFixedHourWindow(): void {
		$now = 1_801;
		$claimed = [];
		$attempts = 0;
		$cache = $this->createStub(DurableCache::class);
		$cache->method('addShared')->willReturnCallback(static function (string $namespace, string $key, mixed $value, int $ttl) use (&$claimed, &$attempts): bool {
			self::assertSame('social.post-discovery.budget', $namespace);
			self::assertSame(1, $value);
			self::assertSame(1_799, $ttl);
			$attempts++;
			if (isset($claimed[$key])) {
				return false;
			}
			$claimed[$key] = true;

			return true;
		});
		$clock = $this->createStub(ITimeFactory::class);
		$clock->method('getTime')->willReturn($now);
		$budget = new RemotePostDiscoveryBudget($cache, $clock, new NullLogger());

		for ($request = 1; $request <= RemotePostDiscoveryBudget::MAX_REQUESTS_PER_HOUR; $request++) {
			$this->assertTrue($budget->reserve());
		}
		$this->assertFalse($budget->reserve());
		$this->assertCount(RemotePostDiscoveryBudget::MAX_REQUESTS_PER_HOUR, $claimed);
		$attemptsAtExhaustion = $attempts;
		$this->assertFalse($budget->reserve());
		$this->assertSame($attemptsAtExhaustion, $attempts, 'exhaustion is remembered for the process and bucket');
	}

	public function testBudgetStoreFailureFailsClosed(): void {
		$cache = $this->createMock(DurableCache::class);
		$cache->expects($this->once())->method('addShared')->willThrowException(new RuntimeException('cache unavailable'));
		$clock = $this->createStub(ITimeFactory::class);
		$clock->method('getTime')->willReturn(3_599);
		$budget = new RemotePostDiscoveryBudget($cache, $clock, new NullLogger());

		$this->assertFalse($budget->reserve());
	}

	public function testSlotExpiryEndsAtTheFixedHourBoundary(): void {
		$cache = $this->createMock(DurableCache::class);
		$cache->expects($this->once())->method('addShared')->with(
			'social.post-discovery.budget', $this->matchesRegularExpression('/^0\/[0-9]+$/'), 1, 1
		)->willReturn(true);
		$clock = $this->createStub(ITimeFactory::class);
		$clock->method('getTime')->willReturn(3_599);
		$budget = new RemotePostDiscoveryBudget($cache, $clock, new NullLogger());

		$this->assertTrue($budget->reserve());
	}
}
