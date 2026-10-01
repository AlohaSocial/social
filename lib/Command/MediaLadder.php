<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Service\VideoLadderService;
use OCA\Social\Service\VideoLadderWorker;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Building the ladders now, rather than one every half-hour.
 *
 * The same reasoning as `social:media:transcode`: the job is paced so that a
 * server working through a backlog is never the reason its cron is slow, and
 * an administrator who has just turned the setting on and would rather not
 * wait a fortnight has this instead.
 *
 * Slower per video than the transcoder by however many rungs the ladder has,
 * which is worth saying out loud before somebody starts it on a library of ten
 * thousand.
 */
class MediaLadder extends SocialCommand {
	public function __construct(
		private VideoLadderService $videoLadderService,
		private VideoLadderWorker $worker,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:media:ladder')
			->setDescription('Write stored videos at a ladder of smaller sizes, as HLS')
			->addOption(
				'limit', '', InputOption::VALUE_REQUIRED,
				'how many videos to ladder before stopping', '10'
			);
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->videoLadderService->isAvailable()) {
			$output->writeln('<error>ffmpeg and ffprobe were not both found; no ladder can be built</error>');

			return 1;
		}

		if (!$this->videoLadderService->isEnabled()) {
			$output->writeln(
				'<comment>Building ladders is off. Turn it on under Administration → Aloha Social,'
				. ' or with: occ config:app:set social video_ladder --value=1</comment>'
			);

			return 1;
		}

		$limit = max(1, (int)$input->getOption('limit'));
		$output->writeln(
			'Laddering up to ' . $limit . ' video(s) at '
			. implode(', ', $this->videoLadderService->heights())
			. ' pixels tall — rungs at or above a video\'s own height are skipped.'
		);

		$built = 0;
		for ($i = 0; $i < $limit; $i++) {
			if (!$this->worker->ladderNext()) {
				break;
			}

			$built++;
			// one line per video: this runs for minutes each and an
			// administrator watching it wants to see that it is moving
			$output->writeln('  laddered ' . $built);
		}

		$output->writeln(
			($built === 0)
				? 'Nothing was waiting for a ladder.'
				: $built . ' video(s) laddered.'
		);

		return 0;
	}
}
