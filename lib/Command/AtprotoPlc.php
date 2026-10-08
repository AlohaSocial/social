<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ social:atproto:plc`: this app's log of PLC operations beside the
 * directory's view, and the resend of what never got through.
 */
class AtprotoPlc extends SocialCommand {
	public function __construct(
		private IdentityService $identities,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:atproto:plc')
			->setDescription('Show the PLC operations of an identity beside what the directory holds, or resend the unconfirmed ones')
			->addArgument('did', InputArgument::OPTIONAL, 'the DID or handle to show; none with --repair')
			->addOption('repair', '', InputOption::VALUE_NONE, 'send every operation the directory never confirmed again');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($input->getOption('repair')) {
			$confirmed = $this->identities->repair();
			$output->writeln('- ' . $confirmed . ' operations confirmed');

			return 0;
		}

		$who = (string)$input->getArgument('did');
		if ($who === '') {
			$output->writeln('<error>a DID or handle, or --repair</error>');

			return 1;
		}
		try {
			$identity = str_starts_with($who, 'did:') ? $this->identities->getByDid($who) : $this->identities->getByHandle($who);
		} catch (AtprotoIdentityNotFoundException) {
			$output->writeln('<error>no identity for ' . $who . '</error>');

			return 1;
		}

		$compared = $this->identities->compare($identity);
		$this->writeMixedInOutputFormat($input, $output, [
			'did' => $identity->did,
			'handle' => $identity->handle,
			'state' => $identity->state,
			'log' => array_map(static fn (array $row): array => [
				'cid' => $row['cid'],
				'type' => $row['operation']['type'] ?? '',
				'created' => gmdate('Y-m-d H:i:s', $row['creation']),
				'sent' => $row['sent'] > 0 ? gmdate('Y-m-d H:i:s', $row['sent']) : '-',
				'confirmed' => $row['confirmed'] > 0 ? gmdate('Y-m-d H:i:s', $row['confirmed']) : '-',
			], $compared['log']),
			'directory' => $compared['directory'] ?? 'unknown to the directory',
		]);

		return 0;
	}
}
