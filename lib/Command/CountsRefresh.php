<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Service\RemoteCountService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Asks the servers that hold remote posts for their current likes, boosts and
 * replies, instead of waiting for the cron's two-hourly batches: for counts
 * that look wrong, a backfill, or checking what the servers answer.
 */
class CountsRefresh extends SocialCommand {
	public function __construct(
		private RemoteCountService $remoteCountService,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:counts:refresh')
			->setDescription(
				'Ask where each remote post lives what its likes, boosts and replies are now'
			)
			->addOption(
				'force', 'f', InputOption::VALUE_NONE,
				'ask about every post rather than only those not asked for in the last '
				. intdiv(RemoteCountService::TTL, 3600) . ' hours'
			)
			->addOption(
				'limit', 'l', InputOption::VALUE_REQUIRED,
				'how many remote posts, and as many local posts on Bluesky, to ask about '
				. '(0 is all that are due); default 0, since this '
				. 'is the run that catches up — the cron takes ' . RemoteCountService::BATCH
				. ' a pass'
			);
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$limit = $input->getOption('limit');
		$limit = ($limit === null) ? 0 : (int)$limit;

		$result = $this->remoteCountService->refresh(
			(bool)$input->getOption('force'), $limit
		);

		$output->writeln(sprintf(
			'%d post(s) asked, %d answered with a count',
			$result['asked'], $result['answered']
		));

		return 0;
	}
}
