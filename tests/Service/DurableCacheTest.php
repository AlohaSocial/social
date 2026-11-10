<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Service\DurableCache;
use OCA\Social\Tests\Helper\InMemoryDurableCacheRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IMemcache;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The same promises on both backends: what is set is read back until it
 * expires, a counter counts, a namespace is its own.
 *
 * The table is what an instance without a memcache gets, and the reason this
 * class exists: there, `createDistributed()` is a cache that forgets every
 * write, and a counter kept in it never leaves zero.
 */
#[AllowMockObjectsWithoutExpectations]
class DurableCacheTest extends TestCase {
	private int $now = 1_760_000_000;
	/** @var array<string, array<string, array{value: mixed, expires: int}>> the memcache, per namespace */
	private array $memcache = [];
	private InMemoryDurableCacheRequest $table;

	protected function setUp(): void {
		$this->table = new InMemoryDurableCacheRequest();
	}

	public static function backends(): iterable {
		yield 'memcache' => [true];
		yield 'table' => [false];
	}

	private function cache(bool $memcache, string $distributed = ''): DurableCache {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn($memcache);
		if ($memcache) {
			$factory->method('createDistributed')->willReturnCallback(
				fn (string $namespace): ICache => $this->memcache($namespace)
			);
		} else {
			$factory->expects($this->never())->method('createDistributed');
		}

		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		$config = $this->createStub(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(
			static fn (string $key, string $default = ''): string => $key === 'memcache.distributed' ? $distributed : $default
		);

		return new DurableCache($factory, $this->table, $time, $config);
	}

	/** An `IMemcache` over an array, expiring on the test's clock. */
	private function memcache(string $namespace): IMemcache {
		$this->memcache[$namespace] ??= [];
		$store = &$this->memcache[$namespace];
		$live = function (string $key) use (&$store): bool {
			return isset($store[$key]) && $store[$key]['expires'] > $this->now;
		};

		$cache = $this->createMock(IMemcache::class);
		$cache->method('get')->willReturnCallback(function (string $key) use (&$store, $live): mixed {
			return $live($key) ? $store[$key]['value'] : null;
		});
		$cache->method('set')->willReturnCallback(function (string $key, mixed $value, int $ttl) use (&$store): bool {
			$store[$key] = ['value' => $value, 'expires' => $this->now + $ttl];

			return true;
		});
		$cache->method('add')->willReturnCallback(function (string $key, mixed $value, int $ttl) use (&$store, $live): bool {
			if ($live($key)) {
				return false;
			}
			$store[$key] = ['value' => $value, 'expires' => $this->now + $ttl];

			return true;
		});
		$cache->method('inc')->willReturnCallback(function (string $key, int $step) use (&$store): int {
			$store[$key]['value'] += $step;

			return $store[$key]['value'];
		});
		$cache->method('remove')->willReturnCallback(function (string $key) use (&$store): bool {
			unset($store[$key]);

			return true;
		});

		return $cache;
	}

	#[DataProvider('backends')]
	public function testWhatIsSetIsReadBack(bool $memcache): void {
		$cache = $this->cache($memcache);
		$cache->set('social.test', 'answer', ['hosts' => ['a.example'], 'n' => 3], 60);

		$this->assertSame(['hosts' => ['a.example'], 'n' => 3], $cache->get('social.test', 'answer'));
		$this->assertNull($cache->get('social.test', 'never-written'));
	}

	#[DataProvider('backends')]
	public function testAnEntryIsGoneOnceItsTtlHasPassed(bool $memcache): void {
		$cache = $this->cache($memcache);
		$cache->set('social.test', 'short', 1, 60);

		$this->now += 59;
		$this->assertSame(1, $cache->get('social.test', 'short'));

		$this->now += 1;
		$this->assertNull($cache->get('social.test', 'short'));
	}

	#[DataProvider('backends')]
	public function testACounterCountsAndKeepsTheWindowItStartedWith(bool $memcache): void {
		$cache = $this->cache($memcache);

		$this->assertSame(1, $cache->inc('social.test', 'bucket', 120));
		$this->now += 100;
		$this->assertSame(2, $cache->inc('social.test', 'bucket', 120));
		$this->assertSame(5, $cache->inc('social.test', 'bucket', 120, 3));

		// counting again did not extend the window: 120 seconds after the first
		// count the bucket is empty, however busy it was
		$this->now += 20;
		$this->assertNull($cache->get('social.test', 'bucket'));
		$this->assertSame(1, $cache->inc('social.test', 'bucket', 120));
	}

	#[DataProvider('backends')]
	public function testNamespacesDoNotShareKeys(bool $memcache): void {
		$cache = $this->cache($memcache);
		$cache->set('social.one', 'key', 'first', 60);
		$cache->set('social.two', 'key', 'second', 60);

		$this->assertSame('first', $cache->get('social.one', 'key'));
		$this->assertSame('second', $cache->get('social.two', 'key'));
	}

	#[DataProvider('backends')]
	public function testARemovedEntryIsGone(bool $memcache): void {
		$cache = $this->cache($memcache);
		$cache->set('social.test', 'key', 'value', 60);
		$cache->remove('social.test', 'key');

		$this->assertNull($cache->get('social.test', 'key'));
	}

	#[DataProvider('backends')]
	public function testAZeroTtlIsTheDefaultRatherThanForever(bool $memcache): void {
		$cache = $this->cache($memcache);
		$cache->set('social.test', 'key', 'value', 0);

		$this->now += ICache::DEFAULT_TTL - 1;
		$this->assertSame('value', $cache->get('social.test', 'key'));
		$this->now += 1;
		$this->assertNull($cache->get('social.test', 'key'));
	}

	public function testWithoutAMemcacheTheTableIsUsedAndTheMemcacheNever(): void {
		$cache = $this->cache(false);
		$cache->set('social.test', 'key', 'value', 60);

		$this->assertFalse($cache->isMemcache());
		$this->assertCount(1, $this->table->rows);
		$this->assertSame([], $this->memcache);
	}

	public function testWithAMemcacheTheTableIsNotWritten(): void {
		$cache = $this->cache(true);
		$cache->set('social.test', 'key', 'value', 60);
		$cache->inc('social.test', 'counter', 60);

		$this->assertTrue($cache->isMemcache());
		$this->assertSame([], $this->table->rows);
	}

	/**
	 * A row past its expiry that the purge has not reached yet still holds the
	 * key; a counter started on top of it must start from one, not refuse to
	 * be written.
	 */
	public function testACounterRestartsOverAnExpiredRowThePurgeHasNotReached(): void {
		$cache = $this->cache(false);
		$cache->inc('social.test', 'bucket', 60);
		$cache->inc('social.test', 'bucket', 60);

		$this->now += 61;

		$this->assertSame(1, $cache->inc('social.test', 'bucket', 60));
		$this->assertCount(1, $this->table->rows);
	}

	public function testThePurgeDeletesWhatHasExpiredAndNothingElse(): void {
		$cache = $this->cache(false);
		$cache->set('social.test', 'old', 1, 10);
		$cache->set('social.test', 'new', 1, 100);

		$this->now += 50;

		$this->assertSame(1, $cache->purgeExpired());
		$this->assertSame(1, $cache->get('social.test', 'new'));
	}

	public function testAKeyOfAnyLengthFitsTheColumn(): void {
		$cache = $this->cache(false);
		$cache->set('social.test', str_repeat('k', 1000), 'value', 60);

		$this->assertSame(64, strlen((string)array_key_first($this->table->rows)));
		$this->assertSame('value', $cache->get('social.test', str_repeat('k', 1000)));
	}

	/** A buffer any PHP worker writes and another reads goes to Redis or Memcached, never to APCu. */
	public function testABufferUsesADistributedMemcacheEveryProcessShares(): void {
		$cache = $this->cache(true, '\\OC\\Memcache\\Redis');
		$cache->setBuffer('social.test', 'early', ['actor' => 'a'], 60);

		$this->assertSame(['actor' => 'a'], $cache->getBuffer('social.test', 'early'));
		$this->assertSame([], $this->table->rows, 'no table row for a buffer Redis holds');

		$cache->removeBuffer('social.test', 'early');
		$this->assertNull($cache->getBuffer('social.test', 'early'));
	}

	/** @return iterable<string, array{string}> */
	public static function perProcessOrNone(): iterable {
		yield 'none' => [''];
		yield 'APCu' => ['\\OC\\Memcache\\APCu'];
		yield 'APCu without a leading backslash' => ['OC\\Memcache\\APCu'];
		yield 'the in-process array cache' => ['\\OC\\Memcache\\ArrayCache'];
	}

	#[DataProvider('perProcessOrNone')]
	public function testABufferUsesTheTableWhenNoMemcacheIsSharedBetweenProcesses(string $distributed): void {
		$cache = $this->cache(true, $distributed);
		$cache->setBuffer('social.test', 'early', ['actor' => 'a'], 60);

		$this->assertSame(['actor' => 'a'], $cache->getBuffer('social.test', 'early'));
		$this->assertCount(1, $this->table->rows);
		$this->assertSame([], $this->memcache);

		$cache->removeBuffer('social.test', 'early');
		$this->assertSame([], $this->table->rows);
	}

	/**
	 * Configured for Redis, the server still hands out its in-process array
	 * cache while PHPUnit runs: a buffer kept there is lost to every other
	 * `DurableCache`, so it goes to the table.
	 */
	public function testABufferUsesTheTableWhenTheServerHandsOutItsArrayCache(): void {
		$arrayCache = $this->createMock(\OC\Memcache\ArrayCache::class);
		$arrayCache->expects($this->never())->method('set');
		$arrayCache->expects($this->never())->method('get');
		$arrayCache->expects($this->never())->method('remove');
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn($arrayCache);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$config = $this->createStub(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(
			static fn (string $key, string $default = ''): string => $key === 'memcache.distributed' ? '\\OC\\Memcache\\Redis' : $default
		);
		$cache = new DurableCache($factory, $this->table, $time, $config);

		$cache->setBuffer('social.test', 'early', ['actor' => 'a'], 60);
		$this->assertSame(['actor' => 'a'], $cache->getBuffer('social.test', 'early'));
		$this->assertCount(1, $this->table->rows);

		$cache->removeBuffer('social.test', 'early');
		$this->assertSame([], $this->table->rows);
	}

	#[DataProvider('backends')]
	public function testSharedStateIsTheTablesWhateverMemcacheThereIs(bool $memcache): void {
		$cache = $this->cache($memcache);
		$cache->setShared('social.test', 'listed', ['a', 'b'], 60);

		$this->assertSame(['a', 'b'], $cache->getShared('social.test', 'listed'));
		$this->assertCount(1, $this->table->rows);
		$this->assertSame([], $this->memcache);

		$this->now += 61;
		$this->assertNull($cache->getShared('social.test', 'listed'));
	}

	#[DataProvider('backends')]
	public function testSharedStateIsRemovedFromTheTableWhateverMemcacheThereIs(bool $memcache): void {
		$cache = $this->cache($memcache);
		$cache->setShared('social.test', 'listed', ['a', 'b'], 60);

		$cache->removeShared('social.test', 'listed');

		$this->assertNull($cache->getShared('social.test', 'listed'));
		$this->assertSame([], $this->table->rows);
	}

	#[DataProvider('backends')]
	public function testSharedAddClaimsAnUnoccupiedSlotAndReclaimsOnlyExpiredRows(bool $memcache): void {
		$cache = $this->cache($memcache);
		$this->assertTrue($cache->addShared('social.test', 'slot', 1, 60));
		$this->assertFalse($cache->addShared('social.test', 'slot', 2, 60));
		$this->assertSame(1, $cache->getShared('social.test', 'slot'));

		$this->now += 61;
		$this->assertTrue($cache->addShared('social.test', 'slot', 3, 60));
		$this->assertSame(3, $cache->getShared('social.test', 'slot'));
	}
}
