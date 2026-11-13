<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use Exception;
use OCA\Social\Service\GifService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The shared pictures this instance offers in the composer.
 *
 * A small set curated for a whole instance, not something every reader adds
 * to. An administrator fills it from here or from the GIF library card of the
 * admin page, which calls the same `GifService`.
 */
class Gif extends SocialCommand {
	public function __construct(
		private GifService $gifService,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		parent::configure();
		$this->setName('social:gif')
			->addArgument('action', InputArgument::OPTIONAL, 'list/add/remove', 'list')
			->addArgument('slug', InputArgument::OPTIONAL, 'the name it is offered and served under', '')
			->addArgument('file', InputArgument::OPTIONAL, 'the picture to add', '')
			->addOption('title', 't', InputOption::VALUE_REQUIRED, 'what the picker searches on', '')
			->setDescription('Manage the shared pictures the composer offers');
	}

	/**
	 * @throws Exception
	 */
	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$slug = (string)$input->getArgument('slug');

		switch ((string)$input->getArgument('action')) {
			case 'list':
				$this->listGifs($output);
				break;

			case 'add':
				$gif = $this->gifService->add(
					$slug,
					(string)$input->getArgument('file'),
					(string)$input->getOption('title')
				);
				$output->writeln(
					'<info>' . $gif->getSlug() . '</info> is in the library, at ' . $gif->getUrl()
				);
				break;

			case 'remove':
				if (!$this->gifService->remove($slug)) {
					throw new Exception('there is no library picture called ' . $slug);
				}
				$output->writeln(
					'<info>' . $slug . '</info> is out of the library. Posts that used it are '
					. 'unchanged: an attachment is a copy taken when the post was written.'
				);
				break;

			default:
				throw new Exception('specify action: list, add, remove');
		}

		return 0;
	}

	private function listGifs(OutputInterface $output): void {
		$gifs = $this->gifService->all();
		if ($gifs === []) {
			$output->writeln('- The library is empty.');

			return;
		}

		foreach ($gifs as $gif) {
			$output->writeln(
				'- <info>' . $gif->getSlug() . '</info>'
				. ($gif->getTitle() === '' ? '' : ' — ' . $gif->getTitle())
				. ' ' . $gif->getUrl()
			);
		}
	}
}
