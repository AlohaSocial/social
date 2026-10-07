<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command\Atproto;

use OCA\Social\Atproto\Repository\Repository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class RepoCommand extends Command {

	public function __construct(
		private readonly Repository $repository,
		private readonly LoggerInterface $logger,
		private readonly \OCA\Social\Atproto\Identity\IdentityService $identities,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('social:atproto:repo')
			->setDescription('Inspect an AT Protocol repository')
			->addArgument('user', InputArgument::REQUIRED, 'User ID or DID')
			->addOption('verify', null, InputOption::VALUE_NONE, 'Recompute MST and compare with stored head');
	}

	#[\Override]
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
			try {
				$identity = $this->identities->getIdentityByDid($did);
				$this->repository->verify($did, $identity['signing_public']);
				$io->success('Commit signature, CIDs, MST and record blocks verified');
			} catch (\Throwable $e) {
				$io->error($e->getMessage());
				return Command::FAILURE;
			}
		}

		return Command::SUCCESS;
	}

	private function resolveDid(string $user): ?string {
		if (str_starts_with($user, 'did:')) {
			return $user;
		}
		$identity = $this->identities->getIdentityByHandle($user);
		if ($identity) {
			return $identity['did'];
		}
		$actor = $this->identities->actorIdForUser($user);
		return $actor === null ? null : ($this->identities->getIdentityByActor($actor)['did'] ?? null);
	}
}
