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
 * Daily housekeeping of stored media, in three bounded passes that resume by
 * running again (the first for SECONDS, the second one page, the third for
 * PURGE_SECONDS):
 *
 * - documents whose parent (post, cached actor or short) no longer exists;
 * - rows stored without their type, which a client would hide;
 * - cached copies of other servers' media past `media_cache_days` (default 30)
 *   or over `media_cache_mb` (default 5120); 0 turns either off. A purged copy
 *   is fetched again when next looked at (`MediaPurgeService::purgeCache()`).
 */
class MediaSweep extends TimedJob {
	private const INTERVAL = 86400;

	/**
	 * How old a row has to be before its missing parent is taken as final: an
	 * attachment is written during the import of its post, before the post's
	 * own row exists.
	 */
	public const DAYS = 30;

	/**
	 * How old an upload no post was made with has to be before it goes: the
	 * composer keeps a draft, and the uploads it names, for a week.
	 */
	public const UNATTACHED_DAYS = 8;

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
			'sweepUnattached' => fn (): int => $this->mediaPurgeService->sweepUnattached(
				self::UNATTACHED_DAYS, self::BATCH, $this->time->getTime() + self::SECONDS
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
