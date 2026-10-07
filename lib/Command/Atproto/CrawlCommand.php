<?php
declare(strict_types=1);

namespace OCA\Social\Command\Atproto;

use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Repository\Repository;
use OCP\IConfig;
use OCP\ILogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class CrawlCommand extends Command {
	protected static $defaultName = 'social:atproto:crawl';
	
	public function __construct(
		private readonly PlcClient $plcClient,
		private readonly IdentityService $identityService,
		private readonly Repository $repository,
		private readonly IConfig $config,
		private readonly ILogger $logger
	) {
		parent::__construct();
	}
	
	protected function configure(): void {
		$this->setDescription('Request relay crawl for all or specific repositories')
			->addArgument('did', InputArgument::OPTIONAL, 'Specific DID to request crawl for');
	}
	
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$io = new SymfonyStyle($input, $output);
		$did = $input->getArgument('did');
		
		$io->title('Request Relay Crawl');
		
		$relays = json_decode($this->config->getAppValue('social', 'atproto_relays', '["https://bsky.network"]'), true);
		
		if ($did) {
			$dids = [$did];
		} else {
			// Get all active DIDs
			$qb = $this->db->getQueryBuilder();
			$qb->select('did')
				->from('social_atproto_identity')
				->where($qb->expr()->eq('state', $qb->createNamedParameter('active')));
			$dids = array_column($qb->executeQuery()->fetchAllAssociative(), 'did');
		}
		
		foreach ($relays as $relay) {
			$io->text("Requesting crawl from $relay...");
			foreach ($dids as $targetDid) {
				$success = $this->requestCrawl($relay, $targetDid);
				$io->text("  $targetDid: " . ($success ? 'OK' : 'FAILED'));
			}
		}
		
		return Command::SUCCESS;
	}
	
	private function requestCrawl(string $relay, string $did): bool {
		// Send com.atproto.sync.requestCrawl to relay
		// This would use HTTP client to call the relay's requestCrawl endpoint
		return true; // Placeholder
	}
}