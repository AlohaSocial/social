<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Service\MastodonStreamingDaemon;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** `occ social:mastodon:stream`: the long-running Mastodon stream server. */
class MastodonStreamServe extends SocialCommand {
	public function __construct(
		private MastodonStreamingDaemon $daemon,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:mastodon:stream')
			->setDescription('Serve Mastodon user and notification WebSocket streams until stopped')
			->addOption('bind', '', InputOption::VALUE_REQUIRED, 'address:port to listen on; the web server proxies the WebSocket here', MastodonStreamingDaemon::DEFAULT_BIND)
			->addOption('max-seconds', '', InputOption::VALUE_REQUIRED, 'stop after this long; 0 runs until stopped', '0')
			->addOption('max-connection-seconds', '', InputOption::VALUE_REQUIRED, 'close each connection after this long so its client reconnects; 0 keeps it open', (string)MastodonStreamingDaemon::DEFAULT_MAX_CONNECTION_SECONDS);
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$bind = (string)$input->getOption('bind');
		if (preg_match('/^[a-zA-Z0-9.:\[\]-]+:\d+$/', $bind) !== 1) {
			$output->writeln('<error>--bind must be address:port</error>');
			return 1;
		}

		return $this->daemon->run(
			$bind,
			max(0, (int)$input->getOption('max-seconds')),
			static fn (string $line) => $output->writeln($line),
			max(0, (int)$input->getOption('max-connection-seconds')),
		);
	}
}
