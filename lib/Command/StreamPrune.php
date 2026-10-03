<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Service\StreamPruneService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class StreamPrune extends SocialCommand {
	public function __construct(
		private StreamPruneService $streamPruneService,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:stream:prune')
			->setDescription(
				'Delete remote statuses older than the retention period that no local user '
				. 'interacted with (see the retention_days app setting; local content is never touched)'
			)
			->addOption(
				'days', 'd', InputOption::VALUE_REQUIRED,
				'override the configured retention period (days)'
			)
			->addOption('dry-run', '', InputOption::VALUE_NONE, 'only count what would be deleted');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$days = $input->getOption('days');
		$days = ($days === null) ? null : (int)$days;
		$dryRun = (bool)$input->getOption('dry-run');

		$result = $this->streamPruneService->prune($days, $dryRun);

		if (($days ?? $this->streamPruneService->getRetentionDays()) <= 0) {
			$output->writeln(
				'retention is disabled: set the retention_days app setting or pass --days'
			);
		} elseif ($dryRun) {
			$output->writeln(sprintf('%d statuses would be pruned', $result['streams']));
		} else {
			$output->writeln(sprintf(
				'%d statuses pruned, %d cached documents removed',
				$result['streams'], $result['documents']
			));
		}

		$output->writeln(sprintf(
			$dryRun ? '%d notifications would be pruned' : '%d notifications pruned',
			$result['notifications']
		));

		return 0;
	}
}
