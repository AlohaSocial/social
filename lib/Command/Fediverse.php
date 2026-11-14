<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use Exception;
use InvalidArgumentException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Service\BlocklistImportService;
use OCA\Social\Service\FediverseService;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Class Fediverse
 *
 * @package OCA\Social\Command
 */
class Fediverse extends SocialCommand {
	private const MAX_IMPORT_DOMAINS = 10000;

	/**
	 * The biggest CSV worth reading, in bytes.
	 *
	 * The cap above bounds unique domains and is reached by parsing the whole
	 * file first, so a file of any size at all was read row by row before it
	 * could be refused. Ten thousand hostnames is a few hundred kilobytes; a
	 * published blocklist with comments is well inside this.
	 */
	private const MAX_IMPORT_BYTES = 8 * 1024 * 1024;

	private FediverseService $fediverseService;
	private ?InputInterface $input = null;
	private ?OutputInterface $output = null;

	public function __construct(
		FediverseService $fediverseService,
		private BlocklistImportService $importService,
	) {
		parent::__construct();
		$this->fediverseService = $fediverseService;
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:fediverse')
			->addOption(
				'type', 't', InputArgument::OPTIONAL,
				'Change the type of access management', ''
			)
			->addOption(
				'dry-run', null, InputOption::VALUE_NONE,
				'For import: read the CSV and print what it would add, without changing anything'
			)
			->addOption(
				'force', 'f', InputOption::VALUE_NONE,
				'For import: skip the confirmation prompt (required with --no-interaction)'
			)
			->addArgument('action', InputArgument::OPTIONAL, 'add/remove/import/test/silence/unsilence/silenced address', '')
			->addArgument('address', InputArgument::OPTIONAL, 'address/host or CSV file for import', '')
			->setDescription('Block, allow or silence other servers, and choose between a block list and an allow list');
	}

	/**
	 * @throws Exception
	 */
	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$this->input = $input;
		$this->output = $output;

		if ($this->typeAccess($input->getOption('type'))) {
			return 0;
		}

		$this->output->writeln(
			'Current access type: <info>' . $this->fediverseService->getAccessType() . '</info>'
		);

		switch ($input->getArgument('action')) {
			case '':
				$this->listAddresses(false);
				break;

			case 'list':
				$this->listAddresses(true);
				break;

			case 'add':
				$this->addAddress($input->getArgument('address'));
				break;

			case 'import':
				return $this->importAddresses($input->getArgument('address'), (bool)$input->getOption('dry-run'));
			case 'remove':
				$this->removeAddress($input->getArgument('address'));
				break;

			case 'test':
				$this->testAddress($input->getArgument('address'));
				break;

			case 'reset':
				$this->resetAddresses();
				break;

			case 'silence':
				$this->silenceAddress($input->getArgument('address'));
				break;

			case 'unsilence':
				$this->unsilenceAddress($input->getArgument('address'));
				break;

			case 'silenced':
				$this->listSilenced();
				break;

			default:
				throw new Exception(
					'specify action: add, remove, import, list, reset, silence, unsilence, silenced'
				);
		}

		return 0;
	}

	/**
	 * @throws Exception
	 */
	private function typeAccess(string $type): bool {
		if ($type === '') {
			return false;
		}

		$this->fediverseService->setAccessType($type);

		return true;
	}

	/**
	 * Silencing, which is the tier between blocking an instance and doing
	 * nothing about it: its accounts leave the public and global timelines and
	 * stay readable by whoever follows them, and nothing is deleted.
	 */
	private function silenceAddress(string $address): void {
		if ($address === '') {
			throw new Exception('specify an address to silence');
		}

		$this->fediverseService->silenceAddress($address);
		$this->output->writeln(
			'<info>' . $address . '</info> is silenced: out of the public and global timelines,'
			. ' still readable by the people who follow it.'
		);
	}

	/** Lifts a silence. Nothing was deleted, so everything comes back. */
	private function unsilenceAddress(string $address): void {
		if ($address === '') {
			throw new Exception('specify an address to unsilence');
		}

		$this->fediverseService->unsilenceAddress($address);
		$this->output->writeln('<info>' . $address . '</info> is no longer silenced.');
	}

	private function listSilenced(): void {
		$silenced = $this->fediverseService->getSilencedAddresses();
		if ($silenced === []) {
			$this->output->writeln('- No silenced instance.');

			return;
		}

		$this->output->writeln('- Silenced instances:');
		foreach ($silenced as $address) {
			$this->output->writeln('  <info>' . $address . '</info>');
		}
	}

	private function listAddresses(bool $allKnownAddress = false): void {
		if ($allKnownAddress) {
			$this->output->writeln('- Known address:');
			foreach ($this->fediverseService->getKnownAddresses() as $address) {
				$this->output->writeln('  <info>' . $address . '</info>');
			}
		}

		$this->output->writeln('- List:');
		foreach ($this->fediverseService->getListedAddresses() as $address) {
			$this->output->writeln('  <info>' . $address . '</info>');
		}
	}

	/**
	 * @throws Exception
	 */
	private function addAddress(string $address): void {
		$this->fediverseService->addAddress($address);
		$this->output->writeln('<info>' . $address . '</info> added to the list');
	}

	/**
	 * Import a published block list into an administrator's existing one.
	 *
	 * The reading and the applying are `BlocklistImportService`, which the
	 * admin page and the subscriptions use as well: a list imported by hand
	 * and the same list subscribed to must not turn out to have been read
	 * differently.
	 *
	 * Fail-closed, unlike a subscription: this is a file the administrator
	 * chose, so a row that names no instance is something to look at rather
	 * than something to skip past.
	 */
	private function importAddresses(string $filePath, bool $dryRun): int {
		// before the file is read at all: an allow list is the list of servers
		// this one talks to, and importing somebody's block list into it would
		// be the opposite of what it says
		if ($this->fediverseService->getAccessType() !== 'all_but') {
			$this->output->writeln('<error>CSV imports require blocklist mode (all_but); the access mode was not changed.</error>');

			return 1;
		}

		if ($filePath === '' || !is_file($filePath) || !is_readable($filePath)) {
			$this->output->writeln('<error>Provide a readable CSV file.</error>');

			return 1;
		}

		$size = filesize($filePath);
		if ($size === false || $size > BlocklistImportService::MAX_BYTES) {
			$this->output->writeln(
				'<error>The CSV is larger than ' . intdiv(BlocklistImportService::MAX_BYTES, 1024 * 1024)
				. ' MB. Nothing was read.</error>'
			);

			return 1;
		}

		$body = file_get_contents($filePath);
		if ($body === false) {
			$this->output->writeln('<error>Could not open the CSV file.</error>');

			return 1;
		}

		try {
			$read = $this->importService->parse($body, BlocklistImportService::FORMAT_CSV);
		} catch (InvalidArgumentException $e) {
			$this->output->writeln('<error>' . $e->getMessage() . '. No domains were imported.</error>');

			return 1;
		}

		if ($read['rejected'] !== []) {
			$this->output->writeln(
				'<error>These rows name no instance this list can hold — a top-level'
				. ' domain, a name with no dot in it, or this instance itself: '
				. implode(', ', $read['rejected']) . '. No domains were imported.</error>'
			);

			return 1;
		}

		if ($read['entries'] === []) {
			$this->output->writeln('<error>The CSV did not contain any domains.</error>');

			return 1;
		}

		if ($dryRun) {
			try {
				$would = $this->importService->apply($read['entries'], true);
			} catch (InvalidArgumentException $e) {
				$this->output->writeln('<error>' . $e->getMessage() . '</error>');

				return 1;
			}

			$this->output->writeln(
				'<info>' . $would['blocked'] . ' domains would be blocked and '
				. $would['silenced'] . ' silenced. Nothing was changed.</info>'
			);
			foreach ($read['entries'] as $domain => $severity) {
				$this->output->writeln('  ' . $domain . ' (' . $severity . ')');
			}

			return 0;
		}

		if (!$this->confirmImport(count($read['entries']))) {
			$this->output->writeln('<comment>Nothing was imported.</comment>');

			return 1;
		}

		try {
			$applied = $this->importService->apply($read['entries'], false, $read['comments']);
		} catch (InvalidArgumentException $e) {
			$this->output->writeln('<error>' . $e->getMessage() . '</error>');

			return 1;
		}

		$this->output->writeln(
			'<info>Blocked ' . $applied['blocked'] . ' domains and silenced '
			. $applied['silenced'] . '; ' . ($applied['alreadyBlocked'] + $applied['alreadySilenced'])
			. ' were already listed. Review the source policy before each import.</info>'
		);

		return 0;
	}

	/**
	 * Asks before writing.
	 *
	 * Every domain added queues a `DomainPurge`, which deletes what this
	 * instance holds of that server — so an import is a destructive operation
	 * with a number in front of it, and the number is worth reading before it
	 * runs. `--force` is how a script says it has read it; without one, a
	 * non-interactive run says so and does nothing rather than answering its
	 * own question.
	 *
	 * @param int $count how many domains the file holds
	 *
	 * @return bool whether to go ahead
	 */
	private function confirmImport(int $count): bool {
		if ($this->input === null || $this->output === null) {
			return false;
		}

		if ($this->input->getOption('force')) {
			return true;
		}

		if (!$this->input->isInteractive()) {
			$this->output->writeln(
				'<error>Refusing to run non-interactively without --force:'
				. ' there is nobody here to confirm.</error>'
			);

			return false;
		}

		$helpers = $this->getHelperSet();
		$helper = ($helpers !== null && $helpers->has('question')) ? $helpers->get('question') : null;
		if (!$helper instanceof QuestionHelper) {
			return false;
		}

		return (bool)$helper->ask(
			$this->input,
			$this->output,
			new ConfirmationQuestion(
				'Block ' . $count . ' domains and delete what this instance holds of each? [y/N] ',
				false
			)
		);
	}

	/**
	 * @throws Exception
	 */
	private function removeAddress(string $address): void {
		$this->fediverseService->removeAddress($address);
		$this->output->writeln('<info>' . $address . '</info> removed from the list');
	}

	/**
	 * @throws SocialAppConfigException
	 */
	private function testAddress(string $address) {
		try {
			$this->fediverseService->authorized($address);
			$this->output->writeln('<info>Authorized</info>');
		} catch (UnauthorizedFediverseException $e) {
			$this->output->writeln('<comment>Unauthorized</comment>');
		}
	}

	private function resetAddresses() {
		$this->fediverseService->resetAddresses();
		$this->output->writeln('list is now empty');
	}
}
