<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command\Atproto;

use OCA\Social\Atproto\Sync\AtprotoNotifications;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class NotificationsCommand extends Command {

	public function __construct(
		private readonly AtprotoNotifications $notifications,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('social:atproto:notifications')
			->setDescription('Fetch Bluesky notifications for local accounts')
			->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Number of accounts to process per batch', 50)
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be fetched without doing it');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$io = new SymfonyStyle($input, $output);
		$batch = (int)$input->getOption('batch');
		$dryRun = $input->getOption('dry-run');

		$io->title('AT Protocol Notifications');

		if ($dryRun) {
			$io->text('Running in dry-run mode');
		}

		try {
			$stats = $this->notifications->run($batch, $dryRun);

			$io->table(['Metric', 'Value'], [
				['Accounts checked', $stats['accounts_checked'] ?? 0],
				['New notifications', $stats['new_notifications'] ?? 0],
				['Errors', $stats['errors'] ?? 0],
				['Duration (ms)', $stats['duration_ms'] ?? 0]
			]);

			return Command::SUCCESS;
		} catch (\Throwable $e) {
			$io->error('Notifications fetch failed: ' . $e->getMessage());
			$this->logger->error('Atproto notifications failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
			return Command::FAILURE;
		}
	}
}
