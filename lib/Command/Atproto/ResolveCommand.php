<?php
declare(strict_types=1);

namespace OCA\Social\Command\Atproto;

use OCA\Social\Atproto\Identity\AtprotoDid;
use OCA\Social\Atproto\Identity\HandleMapper;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\PlcClient;
use OCP\IConfig;
use OCP\ILogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ResolveCommand extends Command {
	protected static $defaultName = 'social:atproto:resolve';
	
	public function __construct(
		private readonly AtprotoDid $atprotoDid,
		private readonly HandleMapper $handleMapper,
		private readonly IdentityService $identityService,
		private readonly PlcClient $plcClient,
		private readonly IConfig $config,
		private readonly ILogger $logger
	) {
		parent::__construct();
	}
	
	protected function configure(): void {
		$this->setDescription('Resolve a Bluesky handle or DID')
			->addArgument('identifier', InputArgument::REQUIRED, 'Handle (alice.bsky.social) or DID (did:plc:...)');
	}
	
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$io = new SymfonyStyle($input, $output);
		$identifier = $input->getArgument('identifier');
		
		$io->title('AT Protocol Resolution');
		
		if (str_starts_with($identifier, 'did:')) {
			$this->resolveDid($io, $identifier);
		} else {
			$this->resolveHandle($io, $identifier);
		}
		
		return Command::SUCCESS;
	}
	
	private function resolveDid(SymfonyStyle $io, string $did): void {
		$io->text("Resolving DID: $did");
		
		// Check local identity
		$identity = $this->identityService->getIdentityByDid($did);
		if ($identity) {
			$io->success('Found in local database');
			$io->table(['Property', 'Value'], [
				['DID', $identity['did']],
				['Handle', $identity['handle']],
				['State', $identity['state']],
				['Actor ID', $identity['actor_id']]
			]);
			return;
		}
		
		// Check PLC directory
		$doc = $this->plcClient->resolveDid($did);
		if ($doc) {
			$io->success('Found in PLC directory');
			$io->table(['Property', 'Value'], [
				['DID', $doc['did'] ?? ''],
				['Handle', $doc['handle'] ?? ''],
				['Signing Key', $doc['verificationMethod'][0]['publicKeyMultibase'] ?? ''],
				['PDS Endpoint', $doc['service'][0]['serviceEndpoint'] ?? '']
			]);
			return;
		}
		
		$io->error('DID not found');
	}
	
	private function resolveHandle(SymfonyStyle $io, string $handle): void {
		$io->text("Resolving handle: $handle");
		
		// Check local identity
		$identity = $this->identityService->getIdentityByHandle($handle);
		if ($identity) {
			$io->success('Found in local database');
			$io->table(['Property', 'Value'], [
				['DID', $identity['did']],
				['Handle', $identity['handle']],
				['State', $identity['state']],
				['Actor ID', $identity['actor_id']]
			]);
			return;
		}
		
		// Try AppView
		$appView = $this->config->getAppValue('social', 'atproto_appview', 'https://public.api.bsky.app');
		$url = $appView . '/xrpc/com.atproto.identity.resolveHandle?handle=' . urlencode($handle);
		
		$context = stream_context_create(['http' => ['timeout' => 10]]);
		$response = @file_get_contents($url, false, $context);
		
		if ($response) {
			$data = json_decode($response, true);
			if (isset($data['did'])) {
				$io->success('Resolved via AppView');
				$io->table(['Property', 'Value'], [
					['Handle', $handle],
					['DID', $data['did']]
				]);
				return;
			}
		}
		
		// Try DNS/HTTPS directly
		$did = $this->resolveHandleDirect($handle);
		if ($did) {
			$io->success('Resolved via DNS/HTTPS');
			$io->table(['Property', 'Value'], [
				['Handle', $handle],
				['DID', $did]
			]);
			return;
		}
		
		$io->error('Handle not found');
	}
	
	private function resolveHandleDirect(string $handle): ?string {
		// Try HTTPS well-known
		$url = 'https://' . $handle . '/.well-known/atproto-did';
		$context = stream_context_create(['http' => ['timeout' => 5]]);
		$response = @file_get_contents($url, false, $context);
		
		if ($response) {
			return trim($response);
		}
		
		// Try DNS TXT
		$txtRecords = dns_get_record('_atproto.' . $handle, DNS_TXT);
		foreach ($txtRecords as $record) {
			if (isset($record['txt']) && str_starts_with($record['txt'], 'did=')) {
				return substr($record['txt'], 4);
			}
		}
		
		return null;
	}
}