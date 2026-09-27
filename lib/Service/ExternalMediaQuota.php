<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\External\ExternalUserBackend;

/**
 * The media quota of self-registered external users.
 *
 * One number for every external user, set by the administrator, counted over
 * what they uploaded: the stored size of every local document their account
 * holds. Media that federated in is not charged to anybody. Internal users
 * have no such quota. The account is a Social handle, which for an external
 * user is also the user id.
 */
class ExternalMediaQuota {
	public function __construct(
		private ExternalUserBackend $userBackend,
		private ConfigService $configService,
		private CacheDocumentsRequest $cacheDocumentsRequest,
	) {
	}

	/** Megabytes; 0 is no quota. */
	public function quota(): int {
		return max(0, $this->configService->getAppValueInt(ConfigService::SOCIAL_EXTERNAL_QUOTA));
	}

	/** Whether one more upload of this size fits the account's quota. */
	public function fits(string $account, int $incoming): bool {
		if ($account === '' || !$this->userBackend->userExists($account)) {
			return true;
		}

		$quota = $this->quota();
		if ($quota === 0) {
			return true;
		}

		return ($this->cacheDocumentsRequest->localBytesOf($account) + $incoming) <= $quota * 1048576;
	}

	/**
	 * The quota as one external user sees it, or null for everybody else.
	 *
	 * @return array{quota: int, used: int}|null quota in MB (0 is none), used in bytes
	 */
	public function usageOf(string $uid): ?array {
		$account = ($uid === '') ? null : $this->userBackend->canonicalUid($uid);
		if ($account === null) {
			return null;
		}

		return [
			'quota' => $this->quota(),
			'used' => $this->cacheDocumentsRequest->localBytesOf($account),
		];
	}
}
