<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\VideoDeliveryHold;
use OCA\Social\Service\VideoTranscodeService;
use OCA\Social\Service\VideoTranscodingWorker;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Converts the videos of one new post, then lets the post go out.
 *
 * Queued by `VideoDeliveryHold::hold()` when a post is written with a video
 * that may need converting, so — like `Cron\ActorCleanup` — it is not in
 * `info.xml`: it means nothing without the post. `Cron\Transcode` still sweeps
 * whatever this did not reach, one video a quarter-hour; this is the same
 * conversion, for the one video somebody is waiting on, on the next cron run.
 *
 * The post's deliveries are released **whatever happened**: converted, not
 * needed, failed, or the setting switched off in the meantime. The hold exists
 * so the post goes out with the best file there is, never so that it does not
 * go out. A hold whose job never runs at all runs out on its own deadline.
 */
class TranscodeBeforeDelivery extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private StreamRequest $streamRequest,
		private VideoDeliveryHold $videoDeliveryHold,
		private VideoTranscodeService $videoTranscodeService,
		private VideoTranscodingWorker $worker,
		private ActivityService $activityService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/**
	 * @param mixed $argument
	 */
	#[\Override]
	protected function run($argument): void {
		$postId = is_array($argument) ? (string)($argument['post'] ?? '') : '';
		if ($postId === '') {
			return;
		}

		try {
			if ($this->videoTranscodeService->isEnabled()) {
				$post = $this->streamRequest->getStreamById($postId);
				foreach ($this->videoDeliveryHold->awaited($post) as $document) {
					$this->worker->convert($document);
				}
			}
		} catch (Throwable $e) {
			// the post may be gone, or one file unreadable; either way what
			// is left to do is to let whatever is held go
			$this->logger->info('[Cron\\TranscodeBeforeDelivery] the videos of a post were not converted', [
				'post' => $postId, 'exception' => $e,
			]);
		}

		try {
			$this->activityService->releaseHeld($postId);
		} catch (Throwable $e) {
			// the deadline still releases them: the cron delivers a held row
			// once its time has passed
			$this->logger->warning('[Cron\\TranscodeBeforeDelivery] a held post could not be released', [
				'post' => $postId, 'exception' => $e,
			]);
		}
	}
}
