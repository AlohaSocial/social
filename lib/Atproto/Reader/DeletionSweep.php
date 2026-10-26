<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Service\ConfigService;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Notices posts deleted on Bluesky (§9.5). Bluesky has no `Delete` this
 * app is sent, and a deleted post simply stops appearing, so stored Bluesky
 * posts are asked about a page at a time — one `getPosts` per run — including
 * older posts. Each cycle snapshots its highest NID so a steady stream of new
 * posts cannot keep moving the end ahead of the cursor and starve old checks.
 */
class DeletionSweep {
	public const PAGE = 25;

	public function __construct(
		private StreamRequest $streams,
		private PostStore $store,
		private ConfigService $configService,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @return int how many posts were deleted
	 */
	public function run(): int {
		$after = (int)$this->configService->getAppValue(ConfigService::ATPROTO_DELETE_CURSOR);
		$through = (int)$this->configService->getAppValue(ConfigService::ATPROTO_DELETE_MAX_NID);
		if ($through <= 0) {
			$through = $this->streams->getBlueskyPostMaxNid();
			if ($through <= 0) {
				$this->configService->setAppValue(ConfigService::ATPROTO_DELETE_CURSOR, '0');
				$this->configService->setAppValue(ConfigService::ATPROTO_DELETE_MAX_NID, '0');
				return 0;
			}
			$after = 0;
			$this->configService->setAppValue(ConfigService::ATPROTO_DELETE_MAX_NID, (string)$through);
		}
		$ids = $this->streams->getBlueskyPostIds(0, $after, self::PAGE, $through);
		if ($ids === []) {
			$this->configService->setAppValue(ConfigService::ATPROTO_DELETE_CURSOR, '0');
			$this->configService->setAppValue(ConfigService::ATPROTO_DELETE_MAX_NID, '0');

			return 0;
		}
		$deleted = $this->store->deleteGone(array_values($ids));
		if ($deleted === null) {
			return 0;
		}
		$this->configService->setAppValue(ConfigService::ATPROTO_DELETE_CURSOR, (string)max(array_keys($ids)));

		return $deleted;
	}
}
