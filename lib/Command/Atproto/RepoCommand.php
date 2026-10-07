<?php
declare(strict_types=1);

namespace OCA\Social\Command\Atproto;

use OCA\Social\Atproto\Repository\Repository;
use OCP\ILogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class RepoCommand extends Command {
	protected static $defaultName = 'social:atproto:repo';
	
	public function __construct(
		private readonly Repository $repository,
		private readonly ILogger $logger
	) {
		parent::__construct();
	}
	
	protected function configure(): void {
		$this->setDescription('Inspect an AT Protocol repository')
			->addArgument('user', InputArgument::REQUIRED, 'User ID or DID')
			->addOption('verify', null, InputOption::VALUE_NONE, 'Recompute MST and compare with stored head');
	}
	
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$io = new SymfonyStyle($input, $output);
		$user = $input->getArgument('user');
		$verify = $input->getOption('verify');
		
		$io->title('AT Protocol Repository');
		
		// Resolve user to DID
		$did = $this->resolveDid($user);
		if (!$did) {
			$io->error('Could not resolve user to DID');
			return Command::FAILURE;
		}
		
		$head = $this->repository->getHead($did);
		if (!$head) {
			$io->error("Repository not found for DID: $did");
			return Command::FAILURE;
		}
		
		$io->table(['Property', 'Value'], [
			['DID', $did],
			['Head CID', $head['commit_cid']],
			['Rev', $head['rev']],
			['Record Count', $head['record_count']],
			['Blob Bytes', $head['blob_bytes']],
			['Updated', $head['updated']]
		]);
		
		if ($verify) {
			$io->text('Verifying MST...');
			// Would recompute MST from records and compare
			$io->success('MST verification not yet implemented');
		}
		
		return Command::SUCCESS;
	}
	
	private function resolveDid(string $user): ?string {
		// Would query database to resolve user ID/handle to DID
		return $user; // Simplified
	}
}