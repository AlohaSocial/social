<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Tools\Nid;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Thumbnails for the actor icons cached before icons were stored with one.
 *
 * A new icon is written with a `CacheDocumentService::AVATAR_THUMBNAIL` px
 * resized copy; one cached earlier has the 800 px preview there, which is the
 * whole original for a 400 px avatar. `Cron\Cache` calls this once a pass:
 * `BATCH` icons in nid order from the `avatar_thumb_cursor` app setting, each
 * once, until a page comes back short and the cursor reads `done`.
 *
 * Only icons whose files are on disk are walked. One the media cache purge
 * took is given its thumbnail when it is fetched again on demand
 * (`DocumentService::restoreCopy()`), under the names its row already has.
 */
class AvatarThumbnailBackfill {
	/** How many icons one pass looks at: each is a read and a resize of a small picture. */
	public const BATCH = 100;

	public const DONE = 'done';

	public function __construct(
		private CacheDocumentsRequest $cacheDocumentsRequest,
		private CacheDocumentService $cacheDocumentService,
		private ConfigService $configService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Gives the next few cached icons their thumbnail.
	 *
	 * @return int how many were written
	 */
	public function next(int $limit = self::BATCH): int {
		$cursor = (string)$this->configService->getAppValue(ConfigService::SOCIAL_AVATAR_THUMB_CURSOR);
		if ($cursor === self::DONE) {
			return 0;
		}
		$cursor = ctype_digit($cursor) ? $cursor : '0';

		$limit = max(1, $limit);
		$page = $this->cacheDocumentsRequest->getCachedActorIcons($limit, $cursor);
		$made = 0;
		foreach ($page as $icon) {
			if (Nid::compare($icon->getNid(), $cursor) > 0) {
				$cursor = (string)$icon->getNid();
			}

			try {
				if ($this->cacheDocumentService->rebuildIconThumbnail($icon)) {
					$this->cacheDocumentsRequest->updateThumbnail($icon);
					$made++;
				}
			} catch (Throwable $e) {
				$this->logger->info('no thumbnail could be made for a cached avatar', [
					'document' => $icon->getId(), 'exception' => $e->getMessage(),
				]);
			}
		}

		$this->configService->setAppValue(
			ConfigService::SOCIAL_AVATAR_THUMB_CURSOR,
			count($page) < $limit ? self::DONE : $cursor
		);

		return $made;
	}
}
