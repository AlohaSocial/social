<?php
declare(strict_types=1);

namespace OCA\Social\Command\Atproto;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\KeyManager;
use OCA\Social\Atproto\Identity\PlcClient;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class RotateKeyCommand extends Command {
	
	public function __construct(
		private readonly IdentityService $identityService,
		private readonly KeyManager $keyManager,
		private readonly PlcClient $plcClient,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger
	) {
		parent::__construct();
	}
	
	protected function configure(): void {
		$this->setName('social:atproto:rotate-key')
			->setDescription('Rotate instance rotation key and update all DIDs via PLC')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be done without doing it')
			->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Number of DIDs to process per batch', 50);
	}
	
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$io = new SymfonyStyle($input, $output);
		$dryRun = $input->getOption('dry-run');
		$batch = (int)$input->getOption('batch');
		
		$io->title('Rotate Instance Rotation Key');
		
		if ($dryRun) {
			$io->text('Running in dry-run mode');
		}
		
		$io->error('Instance rotation is not available until resumable PLC migration is implemented. No keys or PLC operations were changed.');
		return Command::FAILURE;
	}
}
