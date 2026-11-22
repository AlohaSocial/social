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
 * How much of this instance's disk one other server may occupy, so one
 * server posting large images fast cannot fill it.
 *
 * Totalling a domain's bytes is a `stat` per file, so the quota uses the
 * figure `MediaUsageService` measures on the cron plus what was cached from
 * the domain since (a day counter, which needs memcache). It errs towards
 * refusing early. Off by default, so an upgrade does not start refusing the
 * pictures of the servers an instance talks to most.
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
	 * Starts the day's counter again for a host the last measurement now
	 * includes, so its bytes are not charged twice.
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
	 * The host a cached document is charged to: the one its `url` names. Not the
	 * host of its id, since attachments without an id of their own (Mastodon's)
	 * get one under this instance's address (`Document::import()`); the id is
	 * used only for a document with no url.
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
	 * What the last measurement said this host was holding, read from the
	 * stored figure rather than `MediaUsageService`, which constructs this
	 * service.
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
