<?php

declare(strict_types=1);

namespace OCA\Social\Command\Atproto;

use OCA\Social\Atproto\Sync\AtprotoSync;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class SyncCommand extends Command {

	public function __construct(
		private readonly AtprotoSync $sync,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('social:atproto:sync')
			->setDescription('Sync Bluesky posts from followed authors')
			->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Number of authors to sync per batch', 50)
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be synced without doing it');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$io = new SymfonyStyle($input, $output);
		$batch = (int)$input->getOption('batch');
		$dryRun = $input->getOption('dry-run');

		$io->title('AT Protocol Sync');

		if ($dryRun) {
			$io->text('Running in dry-run mode');
		}

		try {
			$stats = $this->sync->run($batch, $dryRun);

			$io->table(['Metric', 'Value'], [
				['Authors checked', $stats['authors_checked'] ?? 0],
				['New posts', $stats['new_posts'] ?? 0],
				['Updated posts', $stats['updated_posts'] ?? 0],
				['Errors', $stats['errors'] ?? 0],
				['Duration (ms)', $stats['duration_ms'] ?? 0]
			]);

			return Command::SUCCESS;
		} catch (\Throwable $e) {
			$io->error('Sync failed: ' . $e->getMessage());
			$this->logger->error('Atproto sync failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
			return Command::FAILURE;
		}
	}
}
