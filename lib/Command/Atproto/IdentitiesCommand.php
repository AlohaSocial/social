<?php
declare(strict_types=1);

namespace OCA\Social\Command\Atproto;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Repository\Repository;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class IdentitiesCommand extends Command {
	
	public function __construct(
		private readonly IdentityService $identityService,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger
	) {
		parent::__construct();
	}
	
	protected function configure(): void {
		$this->setName('social:atproto:identities')
			->setDescription('Create missing AT Protocol identities for local accounts')
			->addOption('user', null, InputOption::VALUE_REQUIRED, 'Specific user ID to create identity for')
			->addOption('force', null, InputOption::VALUE_NONE, 'Recreate identity even if exists')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be done without doing it');
	}
	
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$io = new SymfonyStyle($input, $output);
		$userId = $input->getOption('user');
		$force = $input->getOption('force');
		$dryRun = $input->getOption('dry-run');
		
		$io->title('AT Protocol Identity Creation');
		
		if ($dryRun) {
			$io->text('Running in dry-run mode');
		}
		
		if ($userId) {
			$users = [(int)$userId];
		} else {
			// Get all local actors without identities
			$qb = $this->db->getQueryBuilder();
			$qb->select('sa.id')
				->from('social_actor', 'sa')
				->leftJoin('sa', 'social_atproto_identity', 'ai', 'sa.id = ai.actor_id')
				->where($qb->expr()->isNull('ai.actor_id'));
			
			$users = array_column($qb->executeQuery()->fetchAllAssociative(), 'id');
		}
		
		$io->text('Found ' . count($users) . ' users without identities');
		
		$created = 0;
		$errors = 0;
		
		foreach ($users as $actorId) {
			try {
				if ($dryRun) {
					$io->text("Would create identity for actor $actorId");
					$created++;
					continue;
				}
				
				$identity = $this->identityService->createIdentity($actorId);
				$io->success("Created identity for actor $actorId: {$identity['did']} ({$identity['handle']})");
				$created++;
			} catch (\Throwable $e) {
				$io->error("Failed to create identity for actor $actorId: " . $e->getMessage());
				$errors++;
				$this->logger->error('Identity creation failed', ['actor_id' => $actorId, 'error' => $e->getMessage()]);
			}
		}
		
		$io->table(['Metric', 'Count'], [
			['Created', $created],
			['Errors', $errors]
		]);
		
		return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
	}
}