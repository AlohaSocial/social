<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command\Atproto;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Repository\Repository;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class CrawlCommand extends Command {

	public function __construct(
		private readonly PlcClient $plcClient,
		private readonly IdentityService $identityService,
		private readonly Repository $repository,
		private readonly IDBConnection $db,
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
		private readonly \OCP\Http\Client\IClientService $clients,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('social:atproto:crawl')
			->setDescription('Request relay crawl for all or specific repositories')
			->addArgument('did', InputArgument::OPTIONAL, 'Specific DID to request crawl for');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$io = new SymfonyStyle($input, $output);
		$did = $input->getArgument('did');

		$io->title('Request Relay Crawl');

		$relays = json_decode($this->config->getAppValue('social', 'atproto_relays', '["https://bsky.network"]'), true);
		if (!is_array($relays) || !array_is_list($relays) || $relays === [] || array_filter($relays, static fn ($relay): bool => !is_string($relay)) !== []) {
			$io->error('No valid relay list is configured; no crawl has been requested. Set atproto_relays to a nonempty JSON list of HTTPS relay origins.');
			return Command::FAILURE;
		}

		if ($did && !$this->identityService->getIdentityByDid($did)) {
			$io->error('DID is not owned by this instance');
			return Command::FAILURE;
		}
		if (!$this->identityService->isEnabled()) {
			$io->error('AT Protocol is disabled');
			return Command::FAILURE;
		}
		$failed = false;
		foreach ($relays as $relay) {
			$success = $this->requestCrawl($relay, \OCA\Social\Service\ConfigService::authorityOf($this->identityService->getPdsEndpoint()));
			$io->text($relay . ': ' . ($success ? 'Accepted' : 'FAILED'));
			$failed = $failed || !$success;
		}
		return $failed ? Command::FAILURE : Command::SUCCESS;
	}

	private function requestCrawl(string $relay, string $did): bool {
		if (parse_url($relay, PHP_URL_SCHEME) !== 'https' || parse_url($relay, PHP_URL_USER) !== null) {
			return false;
		}
		try {
			$response = $this->clients->newClient()->post(rtrim($relay, '/') . '/xrpc/com.atproto.sync.requestCrawl', ['timeout' => 15, 'allow_redirects' => false,
				'headers' => ['Content-Type' => 'application/json'], 'body' => json_encode(['hostname' => $did], JSON_THROW_ON_ERROR)]);
			return $response->getStatusCode() === 200;
		} catch (\Throwable $e) {
			$this->logger->warning('AT Protocol relay crawl request failed', ['relay' => $relay, 'exception' => $e]);
			return false;
		}
	}
}
