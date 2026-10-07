<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command\Atproto;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\PlcClient;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class PlcCommand extends Command {

	public function __construct(
		private readonly PlcClient $plcClient,
		private readonly IdentityService $identityService,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('social:atproto:plc')
			->setDescription('Manage PLC operations')
			->addArgument('action', InputArgument::REQUIRED, 'Action: log, view, repair')
			->addArgument('did', InputArgument::OPTIONAL, 'DID to operate on')
			->addOption('repair', null, InputOption::VALUE_NONE, 'Repair discrepancies between log and directory');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$io = new SymfonyStyle($input, $output);
		$action = $input->getArgument('action');
		$did = $input->getArgument('did');
		$repair = $input->getOption('repair');

		$io->title('PLC Operations');

		switch ($action) {
			case 'log':
				$this->showLog($io, $did);
				break;
			case 'view':
				$this->showDirectoryView($io, $did);
				break;
			case 'repair':
				$this->repair($io, $did);
				break;
			default:
				$io->error('Unknown action: ' . $action);
				return Command::FAILURE;
		}

		return Command::SUCCESS;
	}

	private function showLog(SymfonyStyle $io, ?string $did): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('social_atpds_plc_log')
			->orderBy('id', 'DESC')
			->setMaxResults(50);

		if ($did) {
			$qb->andWhere($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		}

		$log = $qb->executeQuery()->fetchAllAssociative();

		$rows = [];
		foreach ($log as $entry) {
			$op = json_decode($entry['operation'], true);
			$rows[] = [
				$entry['id'],
				$entry['did'],
				$op['type'] ?? 'unknown',
				$entry['sent'] ? 'sent' : 'pending',
				$entry['confirmed'] ? 'confirmed' : 'pending'
			];
		}

		$io->table(['ID', 'DID', 'Type', 'Sent', 'Confirmed'], $rows);
	}

	private function showDirectoryView(SymfonyStyle $io, ?string $did): void {
		if (!$did) {
			$io->error('DID required for view action');
			return;
		}

		$directory = $this->plcClient->resolveDid($did);
		if (!$directory) {
			$io->error('DID not found in directory');
			return;
		}

		$io->table(['Property', 'Value'], [
			['DID', $directory['id'] ?? ''],
			['Handle', implode(', ', $directory['alsoKnownAs'] ?? [])],
			['Signing Key', $directory['verificationMethod'][0]['publicKeyMultibase'] ?? ''],
			['Rotation Keys', implode(', ', array_column($directory['rotationKeys'] ?? [], 'publicKeyMultibase'))],
			['PDS Endpoint', $directory['service'][0]['serviceEndpoint'] ?? ''],
			['Updated', $directory['updatedAt'] ?? '']
		]);
	}

	private function repair(SymfonyStyle $io, ?string $did): void {
		if (!$did || !$this->identityService->getIdentityByDid($did)) {
			throw new \InvalidArgumentException('An owned DID is required');
		}
		if (!$this->identityService->registerPending($did)) {
			throw new \RuntimeException('PLC registration could not be confirmed');
		}
		$io->success('Pending signed PLC operations submitted and confirmed');
	}
}
