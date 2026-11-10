<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\DocumentService;
use OCA\Social\Service\MediaPurgeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The housekeeping stored media needs and nothing else does.
 *
 * Three passes, all bounded, all resumable by simply being run again — the
 * first page after page for SECONDS, the second one page, the third for
 * PURGE_SECONDS:
 *
 * - the documents whose parent — the post, the cached actor or the story they
 *   hang off — no longer exists. Nothing can ever refer to those files again,
 *   and until the callers were taught to take the files with the rows, every
 *   deleted post and every expired story left its picture in appdata for good.
 * - the rows stored before their type was recorded. A client hides an
 *   attachment whose type it does not know, so those pictures are invisible
 *   until something says what they are, and nothing else ever will: the
 *   caching run only looks at rows with no local copy.
 * - the cached copies of other servers' media, past the age and over the
 *   total size the administrator set (`media_cache_days`, default 30, and
 *   `media_cache_mb`, default 5120; 0 turns either off). Nothing else bounds
 *   them: the two passes above only reach media whose post or account is
 *   gone, and a post somebody here follows is kept for good. A purged copy
 *   is fetched again when somebody next looks at it. See
 *   `MediaPurgeService::purgeCache()`.
 *
 * Daily. Neither answer changes within the hour, the first is a delete per row
 * and the second is a read per row, and a cron slot is worth more to the
 * queue and the caches than to either of these.
 */
class MediaSweep extends TimedJob {
	private const INTERVAL = 86400;

	/**
	 * How old a row has to be before its missing parent is taken as final.
	 *
	 * Generous on purpose: an attachment is written during the import of the
	 * post it belongs to, which is to say before the post's own row exists,
	 * and a month of margin costs nothing but disk that was already spent.
	 */
	public const DAYS = 30;

	/** Rows per page, of each kind. */
	public const BATCH = 500;

	/**
	 * How long the orphan sweep reads on, page after page, in seconds: a
	 * prune or a purge orphans more than one page a day.
	 */
	public const SECONDS = 120;

	/**
	 * How long the media cache purge reads on, in seconds. The first run on
	 * an instance that has been caching for a year has far more to remove
	 * than one a day, and what is left is the next day's.
	 */
	public const PURGE_SECONDS = 900;

	public function __construct(
		ITimeFactory $time,
		private MediaPurgeService $mediaPurgeService,
		private DocumentService $documentService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);

		$this->setInterval(self::INTERVAL);
	}

	#[\Override]
	protected function run($argument): void {
		foreach ([
			'sweepOrphans' => fn (): int => $this->mediaPurgeService->sweepOrphans(
				self::DAYS, self::BATCH, $this->time->getTime() + self::SECONDS
			),
			'fillMissingMediaTypes' => fn (): int => $this->documentService->fillMissingMediaTypes(self::BATCH),
			'purgeMediaCache' => fn (): int => (int)($this->mediaPurgeService->purgeCacheAsConfigured(
				false, $this->time->getTime() + self::PURGE_SECONDS
			)['files'] ?? 0),
		] as $step => $work) {
			try {
				$work();
			} catch (Throwable $e) {
				// a pass that fails is retried tomorrow; nothing here is
				// urgent and nothing here is lost by waiting
				$this->logger->warning('[Cron\\MediaSweep] step "' . $step . '" failed', [
					'step' => $step, 'exception' => $e,
				]);
			}
		}
	}
}
