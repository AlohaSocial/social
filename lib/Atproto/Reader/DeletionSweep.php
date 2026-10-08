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
 * app is sent, and a deleted post simply stops appearing, so the stored
 * Bluesky posts of the last week are asked about a page at a time — one
 * `getPosts` per run — and what the AppView no longer has is deleted here.
 * The place it got to is kept, and it starts over when it reaches the end.
 */
class DeletionSweep {
	public const WINDOW = 7 * 86400;
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
		$ids = $this->streams->getBlueskyPostIds($this->time->getTime() - self::WINDOW, $after, self::PAGE);
		if ($ids === []) {
			$this->configService->setAppValue(ConfigService::ATPROTO_DELETE_CURSOR, '0');

			return 0;
		}
		$deleted = $this->store->deleteGone(array_values($ids));
		$this->configService->setAppValue(ConfigService::ATPROTO_DELETE_CURSOR, (string)max(array_keys($ids)));

		return $deleted;
	}
}
