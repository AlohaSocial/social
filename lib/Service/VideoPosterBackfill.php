<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Tools\Nid;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Poster frames for the stored videos that have none, a few at a time, called
 * by `Cron\VideoPosters` every run (`occ social:media:posters` does all at
 * once). Uploads get a poster on the way in (`CacheDocumentService`), but
 * older ones, or ones stored without ffmpeg, have none.
 *
 * Walks in nid order from `poster_backfill_cursor`, so a video that fails is
 * tried once; without ffmpeg the cursor waits. The poster is the same one an
 * upload gets (`CacheDocumentService::generatePoster()`), and only the posts
 * carrying each video are rewritten.
 */
class VideoPosterBackfill {
	/** How many videos one run looks at: each is a copy out of storage and an ffmpeg. */
	public const BATCH = 5;

	public function __construct(
		private CacheDocumentsRequest $cacheDocumentsRequest,
		private CacheDocumentService $cacheDocumentService,
		private VideoThumbnailService $videoThumbnailService,
		private StreamRequest $streamRequest,
		private ConfigService $configService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Makes the posters of the next few videos that have none.
	 *
	 * @return int how many were made
	 */
	public function next(int $limit = self::BATCH): int {
		if (!$this->videoThumbnailService->isAvailable()) {
			return 0;
		}

		$cursor = (string)$this->configService->getAppValue(ConfigService::SOCIAL_POSTER_CURSOR);
		$cursor = ctype_digit($cursor) ? $cursor : '0';

		$made = 0;
		foreach ($this->cacheDocumentsRequest->getVideosWithoutPoster(max(1, $limit), $cursor) as $document) {
			if (Nid::compare($document->getNid(), $cursor) > 0) {
				$cursor = (string)$document->getNid();
			}

			try {
				if (!$this->cacheDocumentService->generatePoster($document)) {
					continue;
				}

				$this->cacheDocumentsRequest->updatePoster($document);
				$made++;

				// a post keeps its own copy of its attachments, and that copy
				// is what a client is shown
				$this->streamRequest->updateLocalAttachmentCopies($document);
				if ($document->getParentId() !== '') {
					$this->streamRequest->updateAttachments($document);
				}
			} catch (Throwable $e) {
				$this->logger->info('no poster could be made for a stored video', [
					'document' => $document->getId(), 'exception' => $e->getMessage(),
				]);
			}
		}

		$this->configService->setAppValue(ConfigService::SOCIAL_POSTER_CURSOR, $cursor);

		return $made;
	}
}
