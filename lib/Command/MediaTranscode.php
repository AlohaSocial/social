<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Service\VideoTranscodeService;
use OCA\Social\Service\VideoTranscodingWorker;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Converting the stored videos now, rather than one every quarter-hour.
 *
 * The background job exists so that a server converting a backlog is never the
 * reason its cron is slow; that makes it about a hundred videos a day, which
 * is the right speed for something nobody is waiting on. An administrator who
 * has just turned the setting on and would rather not wait a week has this.
 *
 * It is the same conversion the job does, one file at a time, and it stops
 * when there is nothing left or when the limit is reached.
 */
class MediaTranscode extends SocialCommand {
	public function __construct(
		private VideoTranscodeService $videoTranscodeService,
		private VideoTranscodingWorker $worker,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:media:transcode')
			->setDescription('Convert stored videos to H.264 MP4')
			->addOption(
				'limit', '', InputOption::VALUE_REQUIRED,
				'how many videos to convert before stopping', '25'
			);
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->videoTranscodeService->isAvailable()) {
			$output->writeln('<error>ffmpeg was not found; nothing can be converted</error>');

			return 1;
		}

		if (!$this->videoTranscodeService->isEnabled()) {
			$output->writeln(
				'<comment>Converting videos is off. Turn it on under Administration → Aloha Social,'
				. ' or with: occ config:app:set social video_transcode --value=1</comment>'
			);

			return 1;
		}

		$limit = max(1, (int)$input->getOption('limit'));
		$output->writeln(
			'Converting up to ' . $limit . ' video(s) to H.264 MP4, at most '
			. $this->videoTranscodeService->maxHeight() . ' pixels tall.'
		);

		$converted = 0;
		for ($i = 0; $i < $limit; $i++) {
			if (!$this->worker->convertNext()) {
				break;
			}

			$converted++;
			// one line per file: this runs for minutes per video and an
			// administrator watching it wants to see that it is moving
			$output->writeln('  converted ' . $converted);
		}

		$output->writeln(
			($converted === 0)
				? 'Nothing was waiting to be converted.'
				: $converted . ' video(s) converted.'
		);

		return 0;
	}
}
