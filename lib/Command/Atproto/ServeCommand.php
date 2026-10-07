<?php
declare(strict_types=1);

namespace OCA\Social\Command\Atproto;

use OCA\Social\Atproto\Firehose\FirehoseDaemon;
use OCP\IConfig;
use OCP\ILogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ServeCommand extends Command {
	protected static $defaultName = 'social:atproto:serve';
	
	public function __construct(
		private readonly FirehoseDaemon $firehoseDaemon,
		private readonly IConfig $config,
		private readonly ILogger $logger
	) {
		parent::__construct();
	}
	
	protected function configure(): void {
		$this->setDescription('Run the AT Protocol firehose WebSocket server')
			->addOption('once', null, InputOption::VALUE_NONE, 'Drain event queue and exit (for testing)')
			->addOption('max-seconds', null, InputOption::VALUE_REQUIRED, 'Maximum seconds to run', 0)
			->addOption('port', null, InputOption::VALUE_REQUIRED, 'Port to bind to', 8080)
			->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host to bind to', '127.0.0.1');
	}
	
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$io = new SymfonyStyle($input, $output);
		$once = $input->getOption('once');
		$maxSeconds = (int)$input->getOption('max-seconds');
		$port = (int)$input->getOption('port');
		$host = $input->getOption('host');
		
		$io->title('AT Protocol Firehose Server');
		$io->text('Starting firehose server on ' . $host . ':' . $port);
		
		if ($once) {
			$io->text('Running in --once mode (drain and exit)');
		}
		
		if ($maxSeconds > 0) {
			$io->text('Max runtime: ' . $maxSeconds . ' seconds');
		}
		
		// Check if Atproto is enabled
		if (!$this->config->getAppValue('social', 'atproto_enabled', false)) {
			$io->error('AT Protocol is not enabled for this instance');
			return Command::FAILURE;
		}
		
		try {
			$this->firehoseDaemon->run($host, $port, $once, $maxSeconds);
			$io->success('Firehose server stopped');
			return Command::SUCCESS;
		} catch (\Throwable $e) {
			$io->error('Firehose server error: ' . $e->getMessage());
			$this->logger->error('Firehose daemon error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
			return Command::FAILURE;
		}
	}
}