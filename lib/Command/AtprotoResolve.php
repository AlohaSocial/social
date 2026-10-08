<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ social:atproto:resolve`: what a handle or DID is, here and at the
 * directory.
 */
class AtprotoResolve extends SocialCommand {
	public function __construct(
		private IdentityService $identities,
		private PlcClient $plc,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:atproto:resolve')
			->setDescription('Resolve a handle or DID: the local identity, and the DID document the directory serves')
			->addArgument('identifier', InputArgument::REQUIRED, 'a handle or a did:plc');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$identifier = trim((string)$input->getArgument('identifier'));
		$answer = ['identifier' => $identifier, 'local' => null, 'directory' => null];
		try {
			$identity = Syntax::isDid($identifier)
				? $this->identities->getByDid($identifier)
				: $this->identities->getByHandle(Syntax::normalizeHandle($identifier));
			$answer['local'] = $identity->toArray();
			$did = $identity->did;
		} catch (AtprotoIdentityNotFoundException) {
			$did = Syntax::isDid($identifier) ? $identifier : '';
		}
		if ($did !== '') {
			$answer['directory'] = $this->plc->document($did);
		}
		$this->writeMixedInOutputFormat($input, $output, $answer);

		return $answer['local'] === null && $answer['directory'] === null ? 1 : 0;
	}
}
