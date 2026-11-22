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
 * Converts the stored videos now, one file at a time, instead of waiting for
 * the background job's one every quarter-hour. Stops when nothing is left or
 * the limit is reached.
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
			// one line per file: each video takes minutes
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
