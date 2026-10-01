<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Service\AccountService;
use OCA\Social\Service\PostImportService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Brings an account's own posts over from the server it wrote them on.
 *
 * The same importer the Migration page uses, for the archives a browser
 * cannot upload: PHP's own upload ceilings are the reason this exists, and a
 * five-year archive with its pictures is usually past them.
 */
class AccountImportPosts extends SocialCommand {
	public function __construct(
		private AccountService $accountService,
		private PostImportService $postImportService,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:account:import-posts')
			->addArgument('userId', InputArgument::REQUIRED, 'Nextcloud user whose account the posts are written as')
			->addArgument(
				'archive', InputArgument::REQUIRED,
				'path to the export: a zip with an outbox.json (this app\'s, Mastodon\'s, GoToSocial\'s) or a JSON export (an outbox.json on its own, or Pixelfed\'s pixelfed-statuses.json)'
			)
			->addOption(
				'no-media', null, InputOption::VALUE_NONE,
				'do not fetch the pictures an export names only by their address; the ones inside the archive are still restored'
			)
			->addOption(
				'limit', null, InputOption::VALUE_REQUIRED,
				'how many posts to write at most (default and ceiling ' . PostImportService::MAX_POSTS . ')'
			)
			->setDescription('Import an account\'s own posts, with their pictures, from an export');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = (string)$input->getArgument('userId');
		$path = (string)$input->getArgument('archive');
		$limit = (int)($input->getOption('limit') ?: PostImportService::MAX_POSTS);

		if (!is_file($path)) {
			$output->writeln('<error>' . $path . ' is not a file</error>');

			return 1;
		}

		try {
			$actor = $this->accountService->getActorFromUserId($userId);
		} catch (Throwable $e) {
			$output->writeln('<error>' . $userId . ' has no Aloha Social account: ' . $e->getMessage() . '</error>');

			return 1;
		}

		$output->writeln('Importing the posts of ' . $path . ' as ' . $actor->getAccount() . '…');
		$output->writeln('Nothing here is federated: the posts are written, and no delivery is queued.');

		try {
			$tally = $this->postImportService->import(
				$actor, $path, !$input->getOption('no-media'), $limit
			);
		} catch (Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return 1;
		}

		$output->writeln('<info>' . $tally['imported'] . '</info> post(s) written, with '
			. $tally['media'] . ' file(s) of theirs');
		$output->writeln($tally['already'] . ' were already brought over, ' . $tally['skipped']
			. ' were not posts to bring (boosts, direct messages, empty items), '
			. $tally['failed'] . ' could not be written');

		if ($tally['capped']) {
			$output->writeln(
				'<comment>The run stopped at ' . $limit . ' posts. Run it again to carry on where it left off.</comment>'
			);
		}

		return 0;
	}
}
