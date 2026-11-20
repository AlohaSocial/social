<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Service\TeamService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Gives a Nextcloud group an account to post from. A command, not a route:
 * creating an actor makes an address other servers will keep, which is an
 * administrator's decision. Who may post as it is the group's members,
 * checked live.
 */
class TeamAccount extends SocialCommand {
	public function __construct(
		private TeamService $teamService,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:team')
			->setDescription('Give a Nextcloud group an account to post from')
			->addArgument('group', InputArgument::OPTIONAL, 'the Nextcloud group')
			->addArgument('username', InputArgument::OPTIONAL, 'the handle the team posts under')
			->addOption('list', '', InputOption::VALUE_NONE, 'show the team accounts there are')
			->addOption('remove', '', InputOption::VALUE_REQUIRED, 'stop a handle being a team account');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($input->getOption('list')) {
			return $this->list($output);
		}

		$remove = (string)$input->getOption('remove');
		if ($remove !== '') {
			if (!$this->teamService->delete($remove)) {
				$output->writeln('<error>' . $remove . ' is not a team account</error>');

				return 1;
			}

			$output->writeln(
				$remove . ' is no longer a team account. The account itself is still there'
				. ' — delete it with occ social:account:delete if that is what you want.'
			);

			return 0;
		}

		$group = (string)$input->getArgument('group');
		$username = (string)$input->getArgument('username');
		if ($group === '' || $username === '') {
			$output->writeln('<error>a group and a handle are both needed</error>');
			$output->writeln('  occ social:team <group> <username>');
			$output->writeln('  occ social:team --list');
			$output->writeln('  occ social:team --remove <username>');

			return 1;
		}

		try {
			$actor = $this->teamService->create($group, $username);
		} catch (Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return 1;
		}

		$output->writeln('<info>' . $group . ' now posts as @' . $username . '</info>');
		$output->writeln('  ' . $actor->getId());
		$output->writeln(
			'  Everybody in ' . $group . ' can choose it in the composer. Who wrote each'
			. ' post is recorded and shown to the team and to moderators, and to nobody else.'
		);

		return 0;
	}

	private function list(OutputInterface $output): int {
		$teams = $this->teamService->all();
		if ($teams === []) {
			$output->writeln('No team accounts on this server.');

			return 0;
		}

		foreach ($teams as $team) {
			$output->writeln('  ' . $team['group_id'] . '  →  ' . $team['actor_id']);
		}

		return 0;
	}
}
