<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use Exception;
use OCA\Social\Service\MigrationService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Moves a local account to one on another server, Mastodon-style: the target
 * has to list this account in its `alsoKnownAs`, a `Move` goes out to every
 * follower, and the account here is marked as moved.
 */
class AccountMove extends SocialCommand {
	public function __construct(
		private MigrationService $migrationService,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:account:move')
			->addArgument('userId', InputArgument::REQUIRED, 'Nextcloud user whose actor moves away')
			->addArgument(
				'target', InputArgument::REQUIRED,
				'the new account, as @you@new.example or its https://… address; it must list this one in alsoKnownAs'
			)
			->addOption(
				'force', 'f', InputOption::VALUE_NONE,
				'skip the confirmation prompt (required with --no-interaction)'
			)
			->setDescription('Move a local account to another server and tell its followers');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = (string)$input->getArgument('userId');
		$target = (string)$input->getArgument('target');

		$output->writeln(
			'<error>Beware: the followers of ' . $userId . ' will be told to follow ' . $target
			. ' instead, and the account here will be marked as moved.</error>'
		);
		$output->writeln('');

		if (!$input->isInteractive() && !$input->getOption('force')) {
			// Not a silent no-op: a script that asked for this has to hear that
			// nothing happened.
			$output->writeln(
				'<error>Refusing to run non-interactively without --force:'
				. ' there is nobody here to confirm.</error>'
			);

			return 1;
		}

		if (!$this->confirm($input, $output)) {
			$output->writeln('cancelled, nothing was moved.');

			return 0;
		}

		try {
			$output->write('checking that ' . $target . ' lists this account... ');
			// an administrator's command is not held to the thirty-day cooldown
			// the person's own button is: they are the one who can judge why
			$moved = $this->migrationService->move($userId, $target, false);
		} catch (Exception $e) {
			$output->writeln('');
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return 1;
		}

		$output->writeln('<info>ok</info>');
		$output->writeln('Move queued for the followers of ' . $userId . '.');
		$output->writeln('The account is now marked as moved to <info>' . $moved->getId() . '</info>'
			. ($moved->getAccount() !== '' ? ' (' . $moved->getAccount() . ')' : '') . '.');
		$output->writeln('Followers on other servers re-follow the new account as their servers'
			. ' process the Move; the ones on this server were re-followed just now.');

		return 0;
	}

	private function confirm(InputInterface $input, OutputInterface $output): bool {
		if ($input->getOption('force')) {
			$output->writeln('<comment>--force given, not asking.</comment>');

			return true;
		}

		$helper = $this->questionHelper();
		$question = new ConfirmationQuestion(
			'<info>Do you confirm this operation?</info> (y/N) ', false, '/^(y|Y)/i'
		);

		return (bool)$helper->ask($input, $output, $question);
	}
}
