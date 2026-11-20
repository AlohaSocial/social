<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\VideoLadderService;
use OCA\Social\Service\VideoLadderWorker;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes stored videos at a ladder of smaller sizes, off unless an
 * administrator turned it on.
 *
 * Separate from `Cron\Transcode`, so that the three encodes of a ladder never
 * hold up the transcoder. One video per run, at a longer interval: the
 * ladder only makes existing uploads nicer to watch.
 */
class Ladder extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private ?VideoLadderService $videoLadderService = null,
		private ?VideoLadderWorker $worker = null,
		private ?LoggerInterface $logger = null,
	) {
		parent::__construct($time);

		$this->setInterval(30 * 60);
	}

	/**
	 * @param mixed $argument
	 */
	#[\Override]
	protected function run($argument) {
		if ($this->worker === null || $this->videoLadderService === null) {
			return;
		}

		if (!$this->videoLadderService->isEnabled()) {
			return;
		}

		try {
			$this->worker->ladderNext();
		} catch (Throwable $e) {
			// one bad file must not stop the job from running again
			$this->logger?->warning('[Cron\\Ladder] a video could not be laddered', [
				'exception' => $e,
			]);
		}
	}
}
