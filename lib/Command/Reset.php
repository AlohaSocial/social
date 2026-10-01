<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use Exception;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\ExternalUsersRequest;
use OCA\Social\Service\CheckService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

class Reset extends SocialCommand {
	private CheckService $checkService;

	private ConfigService $configService;

	public function __construct(
		private CoreRequestBuilder $coreRequestBuilder,
		CheckService $checkService,
		ConfigService $configService,
		private MiscService $miscService,
		private ExternalUsersRequest $externalUsersRequest,
	) {
		parent::__construct();
		$this->checkService = $checkService;
		$this->configService = $configService;
	}

	/**
	 *
	 */
	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:reset')
			->addOption('uninstall', '', InputOption::VALUE_NONE, 'full removing of the app')
			->addOption(
				'uri', '', InputOption::VALUE_REQUIRED,
				'the base address of your cloud to rebuild every id from, instead of being asked for it',
				''
			)
			->addOption(
				'force', 'f', InputOption::VALUE_NONE,
				'skip the confirmation prompts (required with --no-interaction)'
			)
			->setDescription('Reset ALL data related to the Aloha Social App');
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @throws Exception
	 */
	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$output->writeln(
			'<error>Beware, this operation will delete all content from the Aloha Social App.</error>'
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

		// their logins are rows of this app: emptying the table would leave
		// Nextcloud users nobody can log in as, with their data still there
		$externals = $this->externalUsersRequest->count();
		if ($externals > 0) {
			$output->writeln(
				'<error>' . $externals . ' self-registered external user(s) log in through this app.'
				. ' Delete them, or promote them with occ social:external promote, first.</error>'
			);

			return 1;
		}

		if (!$this->confirm($input, $output)) {
			$output->writeln('cancelled, nothing was deleted.');

			return 0;
		}

		if ($input->getOption('uninstall')) {
			try {
				$output->writeln('');
				$output->write('Uninstalling Aloha Social App...');
				$this->fullUninstall();
				$output->writeln('<info>uninstalled</info>');
			} catch (Exception $e) {
				$output->writeln('<error>' . $e->getMessage() . '</error>');

				return 1;
			}

			return 0;
		}

		$output->writeln('');
		$output->write('flushing data... ');
		try {
			$this->coreRequestBuilder->emptyAll();
			$output->writeln('<info>done</info>');
		} catch (Exception $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return 1;
		}

		$this->checkService->checkInstallationStatus(true);
		$output->writeln('');

		$result = $this->setCloudAddress($input, $output);
		// nothing is left that carries the old base URL, so it follows the
		// cloud address, whatever request first set it
		$this->configService->setSocialUrl();

		return $result;
	}

	/**
	 * The two confirmations, and the reason this command has a --force at all:
	 * under `occ --no-interaction` a ConfirmationQuestion returns its default,
	 * which is false, so the command used to print its warning and then exit 0
	 * having done nothing — indistinguishable, to a script, from success.
	 */
	private function confirm(InputInterface $input, OutputInterface $output): bool {
		if ($input->getOption('force')) {
			$output->writeln('<comment>--force given, not asking.</comment>');

			return true;
		}

		$helper = $this->questionHelper();
		$question = new ConfirmationQuestion(
			'<info>Do you confirm this operation?</info> (y/N) ', false, '/^(y|Y)/i'
		);

		if (!$helper->ask($input, $output, $question)) {
			return false;
		}

		$question = new ConfirmationQuestion(
			'<info>Operation is destructive. Are you sure about this?</info> (y/N) ', false,
			'/^(y|Y)/i'
		);

		return (bool)$helper->ask($input, $output, $question);
	}

	/**
	 * Every id Social has written is built from the stored cloud address, so a
	 * flush is the one moment that address can change. --uri says what it
	 * becomes; without it, and with somebody at the keyboard, we ask.
	 */
	private function setCloudAddress(InputInterface $input, OutputInterface $output): int {
		$cloudAddress = $this->configService->getCloudUrl();
		$newCloudAddress = (string)$input->getOption('uri');

		if ($newCloudAddress === '') {
			if (!$input->isInteractive()) {
				$output->writeln('Address left at <info>' . $cloudAddress . '</info>.');

				return 0;
			}

			$helper = $this->questionHelper();
			$question = new Question(
				'<info>Now is a good time to change the base address of your cloud: </info> ('
				. $cloudAddress . ') ',
				$cloudAddress
			);
			$newCloudAddress = (string)$helper->ask($input, $output, $question);
		}

		if ($newCloudAddress === '' || $newCloudAddress === $cloudAddress) {
			return 0;
		}

		$this->configService->setCloudUrl($newCloudAddress);

		$output->writeln('');
		$output->writeln('New address: <info>' . $newCloudAddress . '</info>');

		return 0;
	}

	/**
	 * @param OutputInterface $output
	 */
	private function fullUninstall(): void {
		$this->coreRequestBuilder->uninstallSocialTables();
		$this->coreRequestBuilder->uninstallFromMigrations();
		$this->coreRequestBuilder->uninstallFromJobs();
		$this->configService->unsetAppConfig();
	}
}
