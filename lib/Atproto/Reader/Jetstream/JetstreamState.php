<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader\Jetstream;

use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DurableCache;

/**
 * Where the Jetstream listener got to and its last report, kept in the
 * durable cache table: they are written every few seconds, and an app-config
 * write would invalidate every request's config cache. The table rather than
 * memcache, since `occ social:atproto:listen` writes and the admin page
 * reads. Rows expire after a year, renewed by each write. A value still in
 * app config is moved over on its first read.
 */
class JetstreamState {
	private const NAMESPACE = 'social.atproto.jetstream';
	private const CURSOR = 'cursor';
	private const STATUS = 'status';
	public const TTL = 366 * 86400;

	public function __construct(
		private DurableCache $durableCache,
		private ConfigService $configService,
	) {
	}

	/** the `time_us` of the last event kept, 0 for none */
	public function cursor(): int {
		return (int)($this->read(self::CURSOR, ConfigService::ATPROTO_JETSTREAM_CURSOR) ?? 0);
	}

	public function keepCursor(int $cursor): void {
		$this->durableCache->setShared(self::NAMESPACE, self::CURSOR, $cursor, self::TTL);
	}

	/**
	 * The listener's last report as it wrote it, null for none.
	 */
	public function status(): ?array {
		$status = $this->read(self::STATUS, ConfigService::ATPROTO_JETSTREAM_STATUS);

		return is_array($status) ? $status : null;
	}

	public function report(array $status): void {
		$this->durableCache->setShared(self::NAMESPACE, self::STATUS, $status, self::TTL);
	}

	private function read(string $key, string $appConfigKey): mixed {
		$value = $this->durableCache->getShared(self::NAMESPACE, $key);
		if ($value !== null) {
			return $value;
		}
		$kept = (string)$this->configService->getAppValue($appConfigKey);
		if ($kept === '') {
			return null;
		}
		$value = $key === self::STATUS ? json_decode($kept, true) : (int)$kept;
		if ($value !== null) {
			$this->durableCache->setShared(self::NAMESPACE, $key, $value, self::TTL);
		}
		$this->configService->deleteAppValue($appConfigKey);

		return $value;
	}
}
