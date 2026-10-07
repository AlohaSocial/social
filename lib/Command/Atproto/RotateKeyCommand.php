<?php
declare(strict_types=1);

namespace OCA\Social\Command\Atproto;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\KeyManager;
use OCA\Social\Atproto\Identity\PlcClient;
use OCP\IDBConnection;
use OCP\ILogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class RotateKeyCommand extends Command {
	protected static $defaultName = 'social:atproto:rotate-key';
	
	public function __construct(
		private readonly IdentityService $identityService,
		private readonly KeyManager $keyManager,
		private readonly PlcClient $plcClient,
		private readonly IDBConnection $db,
		private readonly ILogger $logger
	) {
		parent::__construct();
	}
	
	protected function configure(): void {
		$this->setDescription('Rotate instance rotation key and update all DIDs via PLC')
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
		
		// Generate new instance rotation key
		$newKey = $this->keyManager->generateRotationKey();
		$io->text("New rotation key: {$newKey['multibase']}");
		
		if ($dryRun) {
			$io->text('Would store new key and queue PLC operations for all identities');
			return Command::SUCCESS;
		}
		
		// Store new key
		$qb = $this->db->getQueryBuilder();
		$qb->insert('social_atproto_instance_key')
			->values([
				'kind' => $qb->createNamedParameter('rotation'),
				'private_key' => $qb->createNamedParameter($this->keyManager->sealPrivateKey($newKey['private'])),
				'public_key' => $qb->createNamedParameter($newKey['multibase']),
				'created' => $qb->createNamedParameter((new \DateTime())->format('Y-m-d H:i:s'))
			])
			->executeStatement();
		
		$io->success('New rotation key stored');
		
		// Queue PLC operations for all active identities
		$qb->select('did, actor_id')
			->from('social_atproto_identity')
			->where($qb->expr()->eq('state', $qb->createNamedParameter('active')));
		
		$identities = $qb->executeQuery()->fetchAllAssociative();
		$io->text("Queuing PLC operations for " . count($identities) . " identities...");
		
		$processed = 0;
		foreach ($identities as $identity) {
			$plcOperation = [
				'type' => 'update',
				'did' => $identity['did'],
				'rotationKeys' => [$newKey['multibase'], $identity['recovery_public']],
				'prev' => null // Would need to get previous operation CID
			];
			
			$qb->insert('social_atproto_plc_log')
				->values([
					'did' => $qb->createNamedParameter($identity['did']),
					'cid' => $qb->createNamedParameter(''),
					'operation' => $qb->createNamedParameter(json_encode($plcOperation)),
					'sent' => $qb->createNamedParameter(null, \PDO::PARAM_NULL),
					'confirmed' => $qb->createNamedParameter(null, \PDO::PARAM_NULL)
				])
				->executeStatement();
			
			$processed++;
			if ($processed % $batch === 0) {
				$io->text("Processed $processed identities...");
			}
		}
		
		$io->success("Queued $processed PLC operations for rotation");
		$io->note('Operations will be sent to PLC directory by the PLC client');
		
		return Command::SUCCESS;
	}
}