<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Atproto\Reader\Jetstream\JetstreamListener;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ social:atproto:listen`: the Jetstream listener (§9.4).
 */
class AtprotoListen extends SocialCommand {
	public function __construct(
		private JetstreamListener $listener,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:atproto:listen')
			->setDescription('Read the followed Bluesky accounts from Jetstream as they post, until stopped')
			->addOption('max-seconds', '', InputOption::VALUE_REQUIRED, 'stop after this long; 0 runs until stopped', '0')
			->addOption('once', '', InputOption::VALUE_NONE, 'read what is there now and return');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		return $this->listener->listen(
			static fn (string $line) => $output->writeln($line),
			max(0, (int)$input->getOption('max-seconds')),
			(bool)$input->getOption('once'),
		);
	}
}
