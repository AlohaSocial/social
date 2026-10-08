<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use InvalidArgumentException;
use OCA\Social\Atproto\Moderation\Blocklist;
use OCA\Social\Atproto\Moderation\BlocklistManager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ social:atproto:block <host|did>`: the administrator's Bluesky block
 * list — a DID, or a PDS host and every account on it.
 */
class AtprotoBlock extends SocialCommand {
	public function __construct(
		private Blocklist $blocklist,
		private BlocklistManager $manager,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:atproto:block')
			->setDescription('Block a Bluesky account (DID) or a PDS host, or list the blocks')
			->addArgument('target', InputArgument::OPTIONAL, 'A DID, or a PDS host such as pds.example.com')
			->addOption('unblock', '', InputOption::VALUE_NONE, 'Remove the target from the list')
			->addOption('reason', '', InputOption::VALUE_REQUIRED, 'Why, for the list', '')
			->addOption('list', '', InputOption::VALUE_NONE, 'List the blocks');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$target = (string)($input->getArgument('target') ?? '');
		if ($input->getOption('list') || $target === '') {
			$this->writeTableInOutputFormat($input, $output, array_map(static fn (array $row): array => [
				'kind' => $row['kind'],
				'value' => $row['value'],
				'reason' => $row['reason'],
				'created' => $row['creation'] > 0 ? gmdate('Y-m-d H:i', $row['creation']) : '',
			], $this->blocklist->list()));

			return 0;
		}
		try {
			if ($input->getOption('unblock')) {
				$output->writeln($this->manager->unblock($target) ? 'unblocked ' . $target : $target . ' was not blocked');

				return 0;
			}
			$purged = $this->manager->block($target, (string)$input->getOption('reason'));
		} catch (InvalidArgumentException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return 1;
		}
		$output->writeln('blocked ' . $target . ($purged > 0 ? ', ' . $purged . ' followed account(s) purged' : ''));

		return 0;
	}
}
