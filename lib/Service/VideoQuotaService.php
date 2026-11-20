<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\RenditionsRequest;
use OCP\IConfig;

/**
 * How much video one account may keep here, and who is keeping it. A
 * per-account quota, complementing the per-file `max_video_size`.
 *
 * Off by default, so upgrading does not start refusing heavy users' uploads.
 * Counted from the stored `size`, as it is asked on every upload; rows still
 * at `0` are filled in by `MediaUsageService` on the cron.
 */
class VideoQuotaService {
	/** No quota, which is the default: the only limit is the disk. */
	public const UNLIMITED = 0;

	/** The largest quota worth naming, in megabytes. */
	public const MAX_QUOTA_MB = 10485760; // 10 TB

	public function __construct(
		private CacheDocumentsRequest $cacheDocumentsRequest,
		private RenditionsRequest $renditionsRequest,
		private ConfigService $configService,
		private IConfig $config,
	) {
	}

	/** The quota in megabytes, or `UNLIMITED`. */
	public function quota(): int {
		$quota = $this->configService->getAppValueInt(ConfigService::SOCIAL_VIDEO_QUOTA);

		return ($quota > 0) ? $quota : self::UNLIMITED;
	}

	/** How many bytes of video this account is holding. */
	public function used(string $account): int {
		return $this->cacheDocumentsRequest->videoBytesOf($account);
	}

	/**
	 * Whether this account may store another video of this size. Transcoded
	 * renditions are not counted against the quota (the server made them),
	 * but are included in what an administrator is shown.
	 */
	public function fits(string $account, int $incoming): bool {
		$quota = $this->quota();
		if ($quota === self::UNLIMITED || $account === '') {
			return true;
		}

		return ($this->used($account) + $incoming) <= ($quota * 1048576);
	}

	/**
	 * What every account is holding, most first, with the ladders included.
	 *
	 * @return array<array{account: string, bytes: int, files: int}>
	 */
	public function byAccount(int $limit = 50): array {
		return $this->cacheDocumentsRequest->videoBytesByAccount($limit);
	}

	/** How much disk every ladder on this instance is holding, together. */
	public function renditionBytes(): int {
		return $this->renditionsRequest->totalSize();
	}

	/**
	 * Where this app's files are: Nextcloud has no per-app object store, so
	 * this reports whether the instance-wide `objectstore` in `config.php`
	 * applies, which is the supported way to move them.
	 *
	 * @return array{object_store: bool, class: string, bucket: string}
	 */
	public function storage(): array {
		$objectStore = $this->config->getSystemValue('objectstore', null);
		$multibucket = $this->config->getSystemValue('objectstore_multibucket', null);
		$configured = is_array($objectStore) ? $objectStore : (is_array($multibucket) ? $multibucket : null);

		return [
			'object_store' => $configured !== null,
			'class' => is_string($configured['class'] ?? null) ? $configured['class'] : '',
			'bucket' => is_string($configured['arguments']['bucket'] ?? null)
				? $configured['arguments']['bucket'] : '',
		];
	}
}
