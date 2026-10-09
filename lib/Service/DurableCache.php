<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\DurableCacheRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IMemcache;

/**
 * Short-lived state that has to be kept even where no memcache is.
 *
 * `ICacheFactory::createDistributed()` hands an instance without
 * `memcache.local` or `memcache.distributed` a `NullCache`: every `set()` is
 * dropped and every `get()` answers null. For a computed answer that only
 * costs time. For a counter or a list of what was already accepted it
 * switches the protection off — the inbox throttle reads zero on every
 * delivery, and a replayed signature looks new.
 *
 * So this is the distributed cache when there is one, and a table
 * (`social_durable_cache`) when there is not. The API is the part of `ICache`
 * those callers use, with the namespace passed on each call:
 *
 *     $this->durableCache->set('social.inbox', $key, 3, 120);
 *     $this->durableCache->get('social.inbox', $key);      // 3, or null
 *     $this->durableCache->inc('social.inbox', $key, 120); // 4
 *     $this->durableCache->remove('social.inbox', $key);
 *
 * - A value is anything `json_encode()` round-trips — a scalar or an array.
 *   `null` is what a miss reads as, so it cannot be stored.
 * - A TTL is seconds and always applies; 0 or less is `ICache::DEFAULT_TTL`.
 *   The table's rows are invisible from the moment they expire and are
 *   deleted by `Cron\Cache`.
 * - `inc()` sets the TTL when it creates the key and leaves it alone after,
 *   so a counter lives for one window however often it is counted. It is not
 *   atomic on the table: two requests counting at the same instant can both
 *   read the same value. That is fine for a ceiling and wrong for an id.
 *
 * A memcache configured as `memcache.local` only (APCu) is used as well, and
 * APCu is not shared between the web server and `occ` or cron. State that a
 * background job writes for a web request to read goes through `getShared()`
 * and `setShared()`, which always use the table.
 */
class DurableCache {
	/** @var array<string, ICache> one distributed cache per namespace */
	private array $caches = [];

	public function __construct(
		private ICacheFactory $cacheFactory,
		private DurableCacheRequest $durableCacheRequest,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * Whether a memcache backs this, rather than the table.
	 */
	public function isMemcache(): bool {
		return $this->cacheFactory->isAvailable();
	}

	public function get(string $namespace, string $key): mixed {
		if ($this->isMemcache()) {
			return $this->cache($namespace)->get($key);
		}

		return $this->getShared($namespace, $key);
	}

	public function set(string $namespace, string $key, mixed $value, int $ttl): void {
		$ttl = $this->ttl($ttl);
		if ($this->isMemcache()) {
			$this->cache($namespace)->set($key, $value, $ttl);

			return;
		}

		$this->setShared($namespace, $key, $value, $ttl);
	}

	/**
	 * `get()` from the table whatever memcache there is: what a background
	 * job wrote for a web request.
	 */
	public function getShared(string $namespace, string $key): mixed {
		$stored = $this->durableCacheRequest->read($this->rowKey($namespace, $key), $this->now());

		return ($stored === null) ? null : json_decode($stored, true);
	}

	/**
	 * `set()` into the table whatever memcache there is, for a web request
	 * to read with `getShared()`.
	 */
	public function setShared(string $namespace, string $key, mixed $value, int $ttl): void {
		$this->durableCacheRequest->write(
			$this->rowKey($namespace, $key), $this->encode($value), $this->now() + $this->ttl($ttl)
		);
	}

	/**
	 * Adds `$step` to a counter and answers what it is now; a missing or
	 * expired counter starts from zero and lives for `$ttl`.
	 */
	public function inc(string $namespace, string $key, int $ttl, int $step = 1): int {
		$ttl = $this->ttl($ttl);
		if ($this->isMemcache()) {
			return $this->incMemcache($this->cache($namespace), $key, $ttl, $step);
		}

		$rowKey = $this->rowKey($namespace, $key);
		$now = $this->now();
		$current = $this->durableCacheRequest->read($rowKey, $now);
		if ($current !== null) {
			$count = (int)json_decode($current, true) + $step;
			if ($this->durableCacheRequest->replaceValue($rowKey, (string)$count, $now)) {
				return $count;
			}
		}

		// nothing live under the key: an expired row the purge has not reached
		// yet would make the insert a no-op, so it goes first
		$this->durableCacheRequest->delete($rowKey);
		if ($this->durableCacheRequest->insertIfAbsent($rowKey, (string)$step, $now + $ttl)) {
			return $step;
		}

		// somebody else created it in between; count on top of theirs
		$count = (int)json_decode($this->durableCacheRequest->read($rowKey, $now) ?? '0', true) + $step;
		$this->durableCacheRequest->replaceValue($rowKey, (string)$count, $now);

		return $count;
	}

	public function remove(string $namespace, string $key): void {
		if ($this->isMemcache()) {
			$this->cache($namespace)->remove($key);

			return;
		}

		$this->durableCacheRequest->delete($this->rowKey($namespace, $key));
	}

	/**
	 * Deletes the table's expired rows.
	 *
	 * Run whatever backs the cache today: an instance that has just been given
	 * a memcache still has the rows it wrote before.
	 *
	 * @return int how many rows went
	 */
	public function purgeExpired(): int {
		return $this->durableCacheRequest->purge($this->now());
	}

	private function incMemcache(ICache $cache, string $key, int $ttl, int $step): int {
		if ($cache instanceof IMemcache) {
			// `add()` only writes a key that is not there, so the TTL is set
			// once, by whoever creates the counter
			$cache->add($key, 0, $ttl);
			$count = $cache->inc($key, $step);
			if (is_int($count)) {
				return $count;
			}
		}

		$count = (int)($cache->get($key) ?? 0) + $step;
		$cache->set($key, $count, $ttl);

		return $count;
	}

	private function cache(string $namespace): ICache {
		return $this->caches[$namespace] ??= $this->cacheFactory->createDistributed($namespace);
	}

	private function rowKey(string $namespace, string $key): string {
		return hash('sha256', $namespace . "\n" . $key);
	}

	private function encode(mixed $value): string {
		return json_encode($value, JSON_THROW_ON_ERROR);
	}

	private function ttl(int $ttl): int {
		return ($ttl > 0) ? $ttl : ICache::DEFAULT_TTL;
	}

	private function now(): int {
		return $this->timeFactory->getTime();
	}
}
