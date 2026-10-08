<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Service\RelayClient;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ social:atproto:crawl`: asks the configured relays to subscribe to
 * this instance's firehose now.
 */
class AtprotoCrawl extends SocialCommand {
	public function __construct(
		private AtprotoConfig $config,
		private RelayClient $relays,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:atproto:crawl')
			->setDescription('Send com.atproto.sync.requestCrawl to every configured relay');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->config->isEnabled()) {
			$output->writeln('<error>Bluesky is not enabled for this instance (atproto_enabled)</error>');

			return 1;
		}
		$results = $this->relays->requestCrawl();
		if ($results === []) {
			$output->writeln('<error>no relay is configured (atproto_relays)</error>');

			return 1;
		}
		foreach ($results as $relay => $result) {
			$output->writeln(($result === 'ok' ? '+ ' : '! ') . $relay . ': ' . $result);
		}

		return in_array('ok', $results, true) ? 0 : 1;
	}
}
