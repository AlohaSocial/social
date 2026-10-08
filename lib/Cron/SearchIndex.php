<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Db\SearchTermsRequest;
use OCA\Social\Service\ConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Adds the posts stored before `social_search_term` existed to it.
 *
 * Queued once by `Version1000Date20261008000401`, so — like `DomainPurge` —
 * it is not registered in `info.xml`. It walks `social_stream` in nid order
 * from the cursor it keeps in the app settings, a chunk at a time and for a
 * bounded time per run, and puts itself back on the list until a chunk comes
 * back short: that is the newest post, and every post stored since the table
 * existed was indexed when it was stored. It then marks the index ready and
 * is not queued again; the search stops scanning from that request on.
 *
 * A run that fails keeps the cursor of the last chunk it finished, so the
 * next run repeats one chunk at most, and the unique (term, nid) index makes
 * the repeat harmless.
 */
class SearchIndex extends QueuedJob {
	/** Posts per chunk; each chunk's words go in as a few multi-row inserts. */
	public const CHUNK = 500;

	/** How long one run may keep going, in seconds: a cron slot is shared. */
	public const SECONDS_PER_RUN = 60;

	public function __construct(
		ITimeFactory $time,
		private SearchTermsRequest $searchTermsRequest,
		private ConfigService $configService,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run($argument): void {
		if ($this->searchTermsRequest->isReady()) {
			return;
		}

		$deadline = $this->time->getTime() + self::SECONDS_PER_RUN;
		$cursor = $this->configService->getAppValue(SearchTermsRequest::CURSOR);
		$cursor = ctype_digit($cursor) ? $cursor : '0';

		try {
			do {
				$chunk = $this->searchTermsRequest->backfill($cursor, self::CHUNK);
				$cursor = $chunk['last'];
				$this->configService->setAppValue(SearchTermsRequest::CURSOR, $cursor);

				if ($chunk['read'] < self::CHUNK) {
					$this->configService->setAppValue(SearchTermsRequest::READY, '1');
					$this->logger->info('[Cron\\SearchIndex] every stored post is in the search index');

					return;
				}
			} while ($this->time->getTime() < $deadline);
		} catch (\Throwable $e) {
			$this->logger->warning('[Cron\\SearchIndex] could not index the posts after ' . $cursor
				. '; the search keeps scanning recent posts until a later run gets past them', [
					'exception' => $e,
				]);
		}

		$this->jobList->add(self::class);
	}
}
