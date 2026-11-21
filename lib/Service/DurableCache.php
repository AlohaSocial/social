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
use OCP\IConfig;
use OCP\IMemcache;

/**
 * Short-lived state that must be kept even without a memcache, where
 * `createDistributed()` returns a `NullCache` and counters or replay lists
 * would silently stop working.
 *
 * Uses the distributed cache when there is one, else the
 * `social_durable_cache` table, with the namespace passed on each call:
 *
 *     $this->durableCache->set('social.inbox', $key, 3, 120);
 *     $this->durableCache->get('social.inbox', $key);      // 3, or null
 *     $this->durableCache->inc('social.inbox', $key, 120); // 4
 *     $this->durableCache->remove('social.inbox', $key);
 *
 * - Values are anything `json_encode()` round-trips; `null` reads as a miss.
 * - A TTL is seconds; 0 or less is `ICache::DEFAULT_TTL`. Expired rows are
 *   invisible and deleted by `Cron\Cache`.
 * - `inc()` sets the TTL only on creation. Not atomic on the table: fine for
 *   a ceiling, wrong for an id.
 *
 * APCu is not shared with `occ` or cron, so `getShared()`/`setShared()`
 * always use the table. `getBuffer()`/`setBuffer()`/`removeBuffer()` are for
 * state any worker must see soon after another writes it: a shared
 * `memcache.distributed` (Redis, Memcached) or else the table, never APCu or
 * the in-process array cache.
 */
class DurableCache {
	/** memcache classes whose contents one PHP process cannot see from another */
	private const PER_PROCESS = ['oc\\memcache\\apcu', 'oc\\memcache\\arraycache', 'oc\\memcache\\nullcache'];

	/** @var array<string, ICache> one distributed cache per namespace */
	private array $caches = [];

	public function __construct(
		private ICacheFactory $cacheFactory,
		private DurableCacheRequest $durableCacheRequest,
		private ITimeFactory $timeFactory,
		private ?IConfig $config = null,
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

	/** `getShared()`, from a shared distributed memcache when there is one. */
	public function getBuffer(string $namespace, string $key): mixed {
		$cache = $this->sharedMemcache($namespace);

		return $cache === null ? $this->getShared($namespace, $key) : $cache->get($key);
	}

	/** `setShared()`, into a shared distributed memcache when there is one. */
	public function setBuffer(string $namespace, string $key, mixed $value, int $ttl): void {
		$cache = $this->sharedMemcache($namespace);
		if ($cache === null) {
			$this->setShared($namespace, $key, $value, $ttl);

			return;
		}
		$cache->set($key, $value, $this->ttl($ttl));
	}

	/** `removeShared()`, from a shared distributed memcache when there is one. */
	public function removeBuffer(string $namespace, string $key): void {
		$cache = $this->sharedMemcache($namespace);
		if ($cache === null) {
			$this->removeShared($namespace, $key);

			return;
		}
		$cache->remove($key);
	}

	/**
	 * The distributed memcache, when `memcache.distributed` names one that
	 * every PHP process shares and the server hands out that one; null for
	 * none, for APCu, and for the in-process array cache.
	 */
	private function sharedMemcache(string $namespace): ?ICache {
		if ($this->config === null) {
			return null;
		}
		$class = ltrim($this->config->getSystemValueString('memcache.distributed', ''), '\\');
		if ($class === '' || in_array(strtolower($class), self::PER_PROCESS, true) || !$this->cacheFactory->isAvailable()) {
			return null;
		}

		$cache = $this->cache($namespace);
		foreach (self::PER_PROCESS as $perProcess) {
			if ($cache instanceof $perProcess) {
				return null;
			}
		}

		return $cache;
	}

	/**
	 * Atomically claims a shared key that does not have a live row: unlike
	 * `setShared()`, the table's unique key decides which concurrent insert
	 * wins.
	 */
	public function addShared(string $namespace, string $key, mixed $value, int $ttl): bool {
		$now = $this->now();
		$expires = $now + $this->ttl($ttl);
		$rowKey = $this->rowKey($namespace, $key);
		$encoded = $this->encode($value);

		if ($this->durableCacheRequest->insertIfAbsent($rowKey, $encoded, $expires)) {
			return true;
		}
		if ($this->durableCacheRequest->read($rowKey, $now) !== null) {
			return false;
		}

		// The unique row may be expired but not yet purged. Delete only that
		// expired row; an unqualified delete could erase a concurrent claim.
		$this->durableCacheRequest->deleteExpired($rowKey, $now);

		return $this->durableCacheRequest->insertIfAbsent($rowKey, $encoded, $expires);
	}

	/**
	 * `remove()` from the table whatever memcache there is: what `setShared()`
	 * wrote.
	 */
	public function removeShared(string $namespace, string $key): void {
		$this->durableCacheRequest->delete($this->rowKey($namespace, $key));
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
	 * Deletes the table's expired rows, whatever backs the cache now, since
	 * rows written before a memcache was configured remain.
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
