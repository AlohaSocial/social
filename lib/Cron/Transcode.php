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
 * A job of its own rather than a step of `Cron\Cache`, for one reason: that
 * job budgets five minutes for eleven steps that each do a little, and
 * converting one video can take longer than the whole budget. A step that
 * regularly overran would starve every step behind it.
 *
 * **One video per run.** The job is not trying to drain a backlog quickly; it
 * is trying never to be the reason a server's cron is slow. A hundred videos
 * are a day's work at this rate, which is the right speed for something that
 * makes old uploads travel better and is not urgent for any of them.
 *
 * Off unless an administrator turned it on. Re-encoding is lossy and it is
 * somebody's file.
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
