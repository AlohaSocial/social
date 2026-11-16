<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use Exception;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCP\IUserManager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AccountDelete extends SocialCommand {
	public function __construct(
		private IUserManager $userManager,
		private AccountService $accountService,
		private CacheActorService $cacheActorService,
		private ConfigService $configService,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		parent::configure();
		$this->setName('social:account:delete')
			->addArgument('account', InputArgument::REQUIRED, 'Aloha Social Local Account')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'do it without asking (required with --no-interaction)')
			->setDescription('Delete a local Aloha Social account, named by its handle');
	}

	/**
	 * @throws Exception
	 */
	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$account = (string)$input->getArgument('account');

		try {
			$actor = $this->accountService->getActor($account);
		} catch (ActorDoesNotExistException $e) {
			$output->writeln('<error>no local account @' . $account . '</error>');

			return 1;
		}

		$confirmed = $this->confirmDestructive(
			$input, $output,
			'Delete @' . $actor->getPreferredUsername() . ', everything it posted, and send its deletion to every server that knows it? This cannot be undone.'
		);
		if ($confirmed === null) {
			return 1;
		}
		if (!$confirmed) {
			$output->writeln('cancelled, nothing was deleted.');

			return 0;
		}

		$this->accountService->deleteActor($account);
		$output->writeln('@' . $actor->getPreferredUsername() . ' deleted');

		return 0;
	}
}
