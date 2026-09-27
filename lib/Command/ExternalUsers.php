<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Exceptions\ExternalUserException;
use OCA\Social\Service\ExternalUserService;
use OCP\Security\IHasher;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Self-registered external users from the command line: list them, add one
 * without the registration form, or promote one to a local user.
 */
class ExternalUsers extends SocialCommand {
	public function __construct(
		private ExternalUserService $externalUserService,
		private IHasher $hasher,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:external')
			->setDescription('List, add or promote self-registered external users')
			->addArgument('action', InputArgument::REQUIRED, 'list, add or promote')
			->addArgument('handle', InputArgument::OPTIONAL, 'the user id and handle, for add and promote')
			->addOption('email', '', InputOption::VALUE_REQUIRED, 'the email address, for add')
			->addOption('password-from-env', '', InputOption::VALUE_NONE, 'read the password of a new user from the OC_PASS environment variable');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$action = (string)$input->getArgument('action');
		$handle = (string)($input->getArgument('handle') ?? '');

		try {
			return match ($action) {
				'list' => $this->list($input, $output),
				'add' => $this->add($handle, (string)($input->getOption('email') ?? ''), (bool)$input->getOption('password-from-env'), $output),
				'promote' => $this->promote($handle, $output),
				default => $this->unknown($action, $output),
			};
		} catch (ExternalUserException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return 1;
		}
	}

	private function list(InputInterface $input, OutputInterface $output): int {
		$users = $this->externalUserService->list('', 200);

		if ($input->getOption('output') !== self::OUTPUT_FORMAT_PLAIN) {
			$this->writeArrayInOutputFormat($input, $output, $users, '');

			return 0;
		}

		$output->writeln(count($users) . ' of at most ' . $this->externalUserService->maxAccounts() . ' external user(s)');
		foreach ($users as $user) {
			$output->writeln(sprintf(
				'  %-24s %-32s %s%s',
				$user['uid'],
				$user['email'],
				date('Y-m-d', $user['created']),
				$user['enabled'] ? '' : '  (disabled)'
			));
		}

		return 0;
	}

	/**
	 * @throws ExternalUserException
	 */
	private function add(string $handle, string $email, bool $passwordFromEnv, OutputInterface $output): int {
		if ($handle === '' || $email === '') {
			$output->writeln('<error>add needs a handle and --email</error>');

			return 1;
		}

		$password = $passwordFromEnv ? (string)getenv('OC_PASS') : '';
		if ($password === '') {
			$output->writeln('<error>add needs --password-from-env with the password in OC_PASS</error>');

			return 1;
		}

		$user = $this->externalUserService->createAccount($handle, $email, $this->hasher->hash($password), 'occ');
		$output->writeln('created the external user ' . $user->getUID());

		return 0;
	}

	/**
	 * @throws ExternalUserException
	 */
	private function promote(string $handle, OutputInterface $output): int {
		if ($handle === '') {
			$output->writeln('<error>promote needs a handle</error>');

			return 1;
		}

		$this->externalUserService->promote($handle);
		$output->writeln($handle . ' is now a local user');

		return 0;
	}

	private function unknown(string $action, OutputInterface $output): int {
		$output->writeln('<error>unknown action "' . $action . '": use list, add or promote</error>');

		return 1;
	}
}
