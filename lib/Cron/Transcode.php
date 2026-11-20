<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\VideoTranscodeService;
use OCA\Social\Service\VideoTranscodingWorker;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turning stored videos into the one format the rest of the network plays.
 *
 * A job of its own rather than a step of `Cron\Cache`, whose five-minute
 * budget one conversion can exceed. One video per run, so it is never the
 * reason a cron is slow. New posts do not wait for this:
 * `Cron\TranscodeBeforeDelivery` handles a held post's video; this is the
 * sweep for older uploads and missed jobs. A no-op without ffmpeg, and can be
 * switched off, since re-encoding is lossy and replaces the original.
 */
class Transcode extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private ?VideoTranscodeService $videoTranscodeService = null,
		private ?VideoTranscodingWorker $worker = null,
		private ?LoggerInterface $logger = null,
	) {
		parent::__construct($time);

		// often enough that a backlog moves, rarely enough that a server
		// converting one video at a time is never busy with this
		$this->setInterval(15 * 60);
	}

	/**
	 * @param mixed $argument
	 */
	#[\Override]
	protected function run($argument) {
		if ($this->worker === null || $this->videoTranscodeService === null) {
			return;
		}

		if (!$this->videoTranscodeService->isEnabled()) {
			return;
		}

		try {
			$this->worker->convertNext();
		} catch (Throwable $e) {
			// one bad file must not stop the job from running again
			$this->logger?->warning('[Cron\\Transcode] a video could not be converted', [
				'exception' => $e,
			]);
		}
	}
}
