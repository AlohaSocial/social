<?php
declare(strict_types=1);

namespace OCA\Social\Command\Atproto;

use OCA\Social\Atproto\Sync\JetstreamListener;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ListenCommand extends Command {
	
	public function __construct(
		private readonly JetstreamListener $listener,
		private readonly IConfig $config,
		private readonly LoggerInterface $logger
	) {
		parent::__construct();
	}
	
	protected function configure(): void {
		$this->setName('social:atproto:listen')
			->setDescription('Run the AT Protocol Jetstream listener for real-time updates')
			->addOption('once', null, InputOption::VALUE_NONE, 'Process events once and exit')
			->addOption('max-seconds', null, InputOption::VALUE_REQUIRED, 'Maximum seconds to run', 0);
	}
	
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$io = new SymfonyStyle($input, $output);
		$once = $input->getOption('once');
		$maxSeconds = (int)$input->getOption('max-seconds');
		
		$io->title('AT Protocol Jetstream Listener');
		
		$jetstreamUrl = $this->config->getAppValue('social', 'atproto_jetstream', '');
		if (empty($jetstreamUrl)) {
			$io->error('Jetstream endpoint not configured');
			return Command::FAILURE;
		}
		
		$io->text("Connecting to Jetstream: $jetstreamUrl");
		
		if ($once) {
			$io->text('Running in --once mode');
		}
		
		if ($maxSeconds > 0) {
			$io->text("Max runtime: $maxSeconds seconds");
		}
		
		try {
			$this->listener->run($once, $maxSeconds);
			$io->success('Jetstream listener stopped');
			return Command::SUCCESS;
		} catch (\Throwable $e) {
			$io->error('Jetstream listener error: ' . $e->getMessage());
			$this->logger->error('Jetstream listener failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
			return Command::FAILURE;
		}
	}
}