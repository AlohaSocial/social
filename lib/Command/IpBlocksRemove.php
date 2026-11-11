<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\AccessBlock;
use OCA\Social\Service\AccessBlockService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lifts an IP block: the way back in when a block covers the address the
 * administrator, or every request, arrives from, since the admin API that
 * lifts blocks sits behind them too.
 */
class IpBlocksRemove extends SocialCommand {
	public function __construct(
		private AccessBlockService $accessBlockService,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:ip-blocks:remove')
			->addArgument(
				'block', InputArgument::REQUIRED,
				'The id from social:ip-blocks:list, or the range as it lists it',
				null,
				fn (): array => $this->suggestBlocks()
			)
			->setDescription('Lift an IP block, by its id or its range');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$reference = (string)$input->getArgument('block');
		$block = $this->find($reference);
		if ($block === null) {
			$output->writeln('<error>No IP block ' . $reference . '</error>');

			return 2;
		}

		try {
			$this->accessBlockService->unblockIp($block->getId());
		} catch (ItemNotFoundException) {
			$output->writeln('<error>No IP block ' . $reference . '</error>');

			return 2;
		}
		$output->writeln('<info>Lifted the block on ' . $block->getValue() . '</info>');

		return 0;
	}

	/** @return list<string> the ranges blocked now, for shell completion */
	public function suggestBlocks(): array {
		return array_values(array_map(static fn (AccessBlock $block): string => $block->getValue(), $this->accessBlockService->ipBlocks()));
	}

	/** The block an id or a range names; a range is compared in its stored form. */
	private function find(string $reference): ?AccessBlock {
		$reference = trim($reference);
		$range = AccessBlockService::normaliseRange($reference);
		foreach ($this->accessBlockService->ipBlocks() as $block) {
			if ((ctype_digit($reference) && $block->getId() === (int)$reference) || ($range !== '' && $block->getValue() === $range)) {
				return $block;
			}
		}

		return null;
	}
}
