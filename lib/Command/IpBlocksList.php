<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Model\AccessBlock;
use OCA\Social\Service\AccessBlockService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The IP blocks, from the command line.
 *
 * The admin API that manages them is served by this app, behind the same
 * middleware that enforces them; this and `social:ip-blocks:remove` need no
 * request at all.
 */
class IpBlocksList extends SocialCommand {
	public function __construct(
		private AccessBlockService $accessBlockService,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:ip-blocks:list')
			->setDescription('List the addresses and ranges Aloha Social refuses');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$rows = array_map(static fn (AccessBlock $block): array => [
			'id' => $block->getId(),
			'range' => $block->getValue(),
			'severity' => $block->getSeverity(),
			'comment' => $block->getComment(),
			'expires' => $block->getExpires() === 0 ? 'never' : gmdate('Y-m-d H:i', $block->getExpires()) . ' UTC',
		], $this->accessBlockService->ipBlocks());

		if ($rows === [] && $input->getOption('output') === self::OUTPUT_FORMAT_PLAIN) {
			$output->writeln('No IP blocks.');

			return 0;
		}
		$this->writeTableInOutputFormat($input, $output, $rows);

		return 0;
	}
}
