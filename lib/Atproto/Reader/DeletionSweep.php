<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DurableCache;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Notices posts deleted on Bluesky (§9.5). Bluesky has no `Delete` this
 * app is sent, and a deleted post simply stops appearing, so stored Bluesky
 * posts are asked about a page at a time with `getPosts`. Two cursors share
 * a run: a fast one over the posts published in the last RECENT_WINDOW,
 * several pages within a time budget, where deletions are most likely and
 * most visible, and a slow one, one page a run, over the whole history.
 * Each slow cycle snapshots its highest NID so a steady stream of new posts
 * cannot keep moving the end ahead of the cursor and starve old checks. An
 * instance with no Bluesky post at all is not asked again for an hour.
 */
class DeletionSweep {
	public const PAGE = 25;
	/** the posts the fast cursor walks: those published this recently */
	public const RECENT_WINDOW = 7 * 86400;
	/** pages the fast cursor checks per run at most */
	public const RECENT_PAGES = 8;
	/** seconds the fast cursor may spend per run */
	public const RECENT_BUDGET = 20;
	private const CACHE = 'social.atproto.deletion';
	private const EMPTY_TTL = 3600;

	public function __construct(
		private StreamRequest $streams,
		private PostStore $store,
		private ConfigService $configService,
		private ITimeFactory $time,
		private DurableCache $durableCache,
	) {
	}

	/**
	 * @return int how many posts were deleted
	 */
	public function run(): int {
		if ($this->durableCache->getShared(self::CACHE, 'empty') === true) {
			return 0;
		}
		$deleted = $this->full();
		if ($deleted === null) {
			return 0;
		}

		return $deleted + $this->recent();
	}

	/**
	 * One page of the whole history.
	 *
	 * @return int|null how many posts were deleted; null when no Bluesky post is stored
	 */
	private function full(): ?int {
		$after = (int)$this->configService->getAppValue(ConfigService::ATPROTO_DELETE_CURSOR);
		$through = (int)$this->configService->getAppValue(ConfigService::ATPROTO_DELETE_MAX_NID);
		if ($through <= 0) {
			$through = $this->streams->getBlueskyPostMaxNid();
			if ($through <= 0) {
				$this->configService->setAppValue(ConfigService::ATPROTO_DELETE_CURSOR, '0');
				$this->configService->setAppValue(ConfigService::ATPROTO_DELETE_MAX_NID, '0');
				$this->durableCache->setShared(self::CACHE, 'empty', true, self::EMPTY_TTL);

				return null;
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

	/**
	 * Pages of the posts published within RECENT_WINDOW, until the budget is
	 * spent; at their end the cursor starts over.
	 *
	 * @return int how many posts were deleted
	 */
	private function recent(): int {
		$start = $this->time->getTime();
		$since = $start - self::RECENT_WINDOW;
		$after = (int)$this->configService->getAppValue(ConfigService::ATPROTO_DELETE_RECENT_CURSOR);
		$deleted = 0;
		for ($page = 0; $page < self::RECENT_PAGES && $this->time->getTime() < $start + self::RECENT_BUDGET; $page++) {
			$ids = $this->streams->getBlueskyPostIds($since, $after, self::PAGE);
			if ($ids === []) {
				$after = 0;
				break;
			}
			$gone = $this->store->deleteGone(array_values($ids));
			if ($gone === null) {
				break;
			}
			$deleted += $gone;
			$after = max(array_keys($ids));
		}
		$this->configService->setAppValue(ConfigService::ATPROTO_DELETE_RECENT_CURSOR, (string)$after);

		return $deleted;
	}
}
