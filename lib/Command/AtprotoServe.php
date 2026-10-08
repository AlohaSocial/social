<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Atproto\Firehose\FirehoseDaemon;
use OCA\Social\Atproto\Service\AtprotoConfig;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ social:atproto:serve`: the firehose daemon.
 */
class AtprotoServe extends SocialCommand {
	public function __construct(
		private AtprotoConfig $config,
		private FirehoseDaemon $daemon,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:atproto:serve')
			->setDescription('Serve the Bluesky firehose (com.atproto.sync.subscribeRepos) until stopped')
			->addOption('bind', '', InputOption::VALUE_REQUIRED, 'address:port to listen on; the web server proxies the WebSocket here', FirehoseDaemon::DEFAULT_BIND)
			->addOption('max-seconds', '', InputOption::VALUE_REQUIRED, 'stop after this long; 0 runs until stopped', '0')
			->addOption('once', '', InputOption::VALUE_NONE, 'serve what is queued now to whoever is connected and return');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->config->isEnabled()) {
			$output->writeln('<error>Bluesky is not enabled for this instance (atproto_enabled)</error>');

			return 1;
		}
		$bind = (string)$input->getOption('bind');
		if (preg_match('/^[a-zA-Z0-9.:\[\]-]+:\d+$/', $bind) !== 1) {
			$output->writeln('<error>--bind must be address:port</error>');

			return 1;
		}

		return $this->daemon->run(
			$bind,
			max(0, (int)$input->getOption('max-seconds')),
			(bool)$input->getOption('once'),
			static fn (string $line) => $output->writeln($line),
		);
	}
}
