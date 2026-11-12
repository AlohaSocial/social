<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use Exception;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\BoostService;
use OCA\Social\Service\StreamService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class NoteBoost
 *
 * @package OCA\Social\Command
 */
class NoteBoost extends SocialCommand {
	public function __construct(
		private AccountService $accountService,
		private StreamService $streamService,
		private BoostService $boostService,
	) {
		parent::__construct();
	}

	/**
	 *
	 */
	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:debug:note:boost')
			->addArgument('user_id', InputArgument::REQUIRED, 'userId of the author')
			->addArgument('note_id', InputArgument::REQUIRED, 'Note to boost')
			->addOption('unboost', '', InputOption::VALUE_NONE, 'Unboost')
			->setDescription('Boost or unboost a post as a user (for testing)');
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @throws Exception
	 */
	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = $input->getArgument('user_id');
		$noteId = $input->getArgument('note_id');

		$actor = $this->accountService->getActorFromUserId($userId);
		$this->streamService->setViewer($actor);

		$token = '';
		if (!$input->getOption('unboost')) {
			$activity = $this->boostService->create($actor, $noteId, $token);
		} else {
			$activity = $this->boostService->delete($actor, $noteId, $token);
		}

		echo 'object: ' . json_encode($activity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		echo 'token: ' . $token . "\n";

		return 0;
	}
}
