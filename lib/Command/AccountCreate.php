<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use Exception;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCP\IUserManager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AccountCreate extends SocialCommand {
	private AccountService $accountService;
	private ConfigService $configService;
	private MiscService $miscService;

	public function __construct(
		private IUserManager $userManager,
		AccountService $accountService,
		private CacheActorService $cacheActorService,
		ConfigService $configService,
		MiscService $miscService,
	) {
		parent::__construct();
		$this->accountService = $accountService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:account:create')
			->addArgument('userId', InputArgument::REQUIRED, 'Nextcloud username of the account')
			->addOption('handle', '', InputOption::VALUE_REQUIRED, 'social handle')
			->setDescription('Create the Aloha Social account of a Nextcloud user');
	}

	/**
	 * @throws Exception
	 */
	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = $input->getArgument('userId');

		if (($handle = $input->getOption('handle')) === null) {
			$handle = $userId;
		}

		if ($this->userManager->get($userId) === null) {
			throw new Exception('Unknown user');
		}

		$this->accountService->createActor($userId, $handle);

		return 0;
	}
}
