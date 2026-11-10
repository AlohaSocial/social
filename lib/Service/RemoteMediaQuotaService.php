<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCP\ICache;
use OCP\ICacheFactory;

/**
 * How much of this instance's disk one other server may occupy.
 *
 * Every picture on a post somebody here follows is fetched and kept, and
 * nothing bounded that by where it came from: one server posting large images
 * at a high rate fills the disk of every instance that follows anybody on it,
 * and the retention sweep only catches up a day at a time and only for posts
 * old enough. An administrator found out from the disk.
 *
 * **Measured on a schedule, spent in the moment.** Walking the store to total
 * a domain's bytes is a `stat` per file and cannot happen on a fetch, so the
 * quota is read from the figure `MediaUsageService` takes on the cron, plus
 * what this instance has cached from that domain since. The stored figure is
 * up to a day old and the counter covers the day: together they are close
 * enough for a disk quota, and wrong in the direction of refusing early rather
 * than late.
 *
 * Without a memcache there is nothing to count the day in, so only the daily
 * figure applies — the quota still holds, a day at a time. **Off by default**:
 * an instance that has been federating for a year and acquires a quota on
 * upgrade would start refusing the pictures of the servers it talks to most,
 * which is not an upgrade note anybody reads in time.
 */
class RemoteMediaQuotaService {
	/** No quota, which is the default: the only limit is the disk. */
	public const UNLIMITED = 0;

	private const CACHE_PREFIX = 'social_domain_media';

	/** Long enough to cover the gap between two measurements, and no longer. */
	private const COUNTER_TTL = 172800;

	private ?ICache $cache;

	public function __construct(
		private ConfigService $configService,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->isAvailable()
			? $cacheFactory->createDistributed(self::CACHE_PREFIX) : null;
	}

	/** The quota in megabytes, or `UNLIMITED`. */
	public function quota(): int {
		$quota = (int)$this->configService->getAppValue(ConfigService::SOCIAL_DOMAIN_MEDIA_QUOTA);

		return ($quota > 0) ? $quota : self::UNLIMITED;
	}

	/** Whether this many more bytes from this host would still fit. */
	public function fits(string $host, int $size): bool {
		$quota = $this->quota();
		if ($quota === self::UNLIMITED || $host === '') {
			return true;
		}

		return ($this->used($host) + max(0, $size)) <= ($quota * 1024 * 1024);
	}

	/** How many bytes of this host's media are held here, as far as is known. */
	public function used(string $host): int {
		return $this->measured($host) + $this->since($host);
	}

	/**
	 * Records bytes just written, so the rest of the day is counted rather
	 * than waiting for the next measurement.
	 */
	public function record(string $host, int $size): void {
		if ($this->cache === null || $host === '' || $size < 1) {
			return;
		}

		$key = $this->key($host);
		$this->cache->set($key, (int)$this->cache->get($key) + $size, self::COUNTER_TTL);
	}

	/**
	 * Starts the day's counter again for a host the last measurement has now
	 * accounted for.
	 *
	 * Called as the walk passes, because everything it counted is in the
	 * figure it just wrote — leaving the counter would charge those bytes
	 * twice and shrink the quota a little more with every pass.
	 */
	public function forget(string $host): void {
		$this->cache?->remove($this->key($host));
	}

	/**
	 * The same for every host a measurement has just accounted for.
	 *
	 * @param string[] $hosts
	 */
	public function forgetAll(array $hosts): void {
		foreach ($hosts as $host) {
			$this->forget($host);
		}
	}

	/**
	 * The host a cached document is charged to: the one its bytes came from,
	 * which is what its `url` names.
	 *
	 * Not the host of its id. An attachment that arrives without an id of its
	 * own -- Mastodon's and Nextcloud Social's both do -- is given one under
	 * this instance's address (see `Document::import()`), so keyed by id every
	 * such picture from every server was charged to this instance, as one
	 * domain: with a quota set, once they added up to it, every remote picture
	 * from anywhere was refused as too large. The id stands in only for a
	 * document with no url.
	 */
	public static function chargedHost(string $url, string $id): string {
		$host = self::host($url);

		return ($host !== '') ? $host : self::host($id);
	}

	/** The host an address names, lower-cased and without a port. */
	private static function host(string $address): string {
		$host = parse_url($address, PHP_URL_HOST);

		return is_string($host) ? strtolower($host) : '';
	}

	/**
	 * What the last measurement said this host was holding.
	 *
	 * Read out of the stored figure rather than from `MediaUsageService`: the
	 * walk resets this service's day counters as it finishes, and a service
	 * cannot be constructed by the thing it constructs.
	 */
	private function measured(string $host): int {
		$stored = $this->configService->getLazyAppValue(ConfigService::SOCIAL_MEDIA_USAGE);
		if ($stored === '') {
			return 0;
		}

		$usage = json_decode($stored, true);
		$domains = is_array($usage) ? ($usage['domains'] ?? []) : [];

		return is_array($domains) ? (int)($domains[$host] ?? 0) : 0;
	}

	/** And what has been cached from it since. */
	private function since(string $host): int {
		return ($this->cache === null) ? 0 : (int)$this->cache->get($this->key($host));
	}

	private function key(string $host): string {
		return 'bytes/' . $host;
	}
}
