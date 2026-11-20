<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use Exception;
use OCA\Social\Service\EmojiService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The emoji this instance publishes, served by `/api/v1/custom_emojis` and
 * sent as `Emoji` tags on outbound posts.
 */
class Emoji extends SocialCommand {
	public function __construct(
		private EmojiService $emojiService,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		parent::configure();
		$this->setName('social:emoji')
			->addArgument('action', InputArgument::OPTIONAL, 'list/add/remove', 'list')
			->addArgument('shortcode', InputArgument::OPTIONAL, 'the name between colons', '')
			->addArgument('file', InputArgument::OPTIONAL, 'the picture to publish it as', '')
			->addOption('category', 'c', InputOption::VALUE_REQUIRED, 'group it under this in a picker', '')
			->addOption('hidden', null, InputOption::VALUE_NONE, 'usable by name, not offered in a picker')
			->setDescription('Manage the custom emoji this instance publishes');
	}

	/**
	 * @throws Exception
	 */
	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$shortcode = (string)$input->getArgument('shortcode');

		switch ((string)$input->getArgument('action')) {
			case 'list':
				$this->listEmojis($output);
				break;

			case 'add':
				$emoji = $this->emojiService->add(
					$shortcode,
					(string)$input->getArgument('file'),
					(string)$input->getOption('category'),
					!$input->getOption('hidden')
				);
				$output->writeln(
					'<info>:' . $emoji->getShortcode() . ':</info> is published at '
					. $emoji->getUrl()
				);
				break;

			case 'remove':
				if (!$this->emojiService->remove($shortcode)) {
					throw new Exception('no emoji is published as :' . $shortcode . ':');
				}
				$output->writeln(
					'<info>:' . $shortcode . ':</info> is no longer published. Posts that '
					. 'used it keep the shortcode as text: what they carry is the tag they '
					. 'were federated with.'
				);
				break;

			default:
				throw new Exception('specify action: list, add, remove');
		}

		return 0;
	}

	private function listEmojis(OutputInterface $output): void {
		$emojis = $this->emojiService->all();
		if ($emojis === []) {
			$output->writeln('- This instance publishes no emoji.');

			return;
		}

		foreach ($emojis as $emoji) {
			$output->writeln(
				'- <info>:' . $emoji->getShortcode() . ':</info>'
				. ($emoji->getCategory() === '' ? '' : ' (' . $emoji->getCategory() . ')')
				. ($emoji->isVisible() ? '' : ' [not offered in a picker]')
				. ' ' . $emoji->getUrl()
			);
		}
	}
}
