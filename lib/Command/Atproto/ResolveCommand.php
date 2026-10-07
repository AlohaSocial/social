<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command\Atproto;

use OCA\Social\Atproto\AppViewClient;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\PlcClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ResolveCommand extends Command {
	public function __construct(
		private readonly IdentityService $identities,
		private readonly PlcClient $plc,
		private readonly AppViewClient $appview,
	) {
		parent::__construct();
	}
	#[\Override]
	protected function configure(): void {
		$this->setName('social:atproto:resolve')->setDescription('Resolve a Bluesky handle or DID')->addArgument('identifier', InputArgument::REQUIRED, 'Handle (alice.bsky.social) or DID (did:plc:...)');
	}
	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$io = new SymfonyStyle($input, $output);
		$identifier = (string)$input->getArgument('identifier');
		try {
			$identity = str_starts_with($identifier, 'did:') ? $this->identities->getIdentityByDid($identifier) : $this->identities->getIdentityByHandle($identifier);
			if ($identity !== null) {
				$io->table(['DID', 'Handle', 'State'], [[$identity['did'], $identity['handle'], $identity['state']]]);
				return Command::SUCCESS;
			}
			if (str_starts_with($identifier, 'did:plc:')) {
				$document = $this->plc->resolveDid($identifier) ?? throw new \RuntimeException('DID not found');
				$io->writeln(json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
			} else {
				$profile = $this->appview->get('app.bsky.actor.getProfile', ['actor' => mb_substr($identifier, 0, 255)]);
				$io->table(['DID', 'Handle'], [[$profile['did'], $profile['handle']]]);
			}
			return Command::SUCCESS;
		} catch (\Throwable $e) {
			$io->error($e->getMessage());
			return Command::FAILURE;
		}
	}
}
