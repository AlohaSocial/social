<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\InstanceKeyService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `occ social:atproto:rotate-key`: a new instance rotation key, and a PLC
 * update for every identity so the new key is the one in charge.
 */
class AtprotoRotateKey extends SocialCommand {
	/** between PLC operations, so the directory's limits are respected */
	private const PAUSE_MICROSECONDS = 200000;

	public function __construct(
		private AtprotoConfig $config,
		private InstanceKeyService $instanceKeys,
		private IdentityService $identities,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:atproto:rotate-key')
			->setDescription('Make a new instance rotation key and re-register every Bluesky identity with it; the old key is kept for the 72-hour recovery window')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'do it without asking');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->config->isEnabled()) {
			$output->writeln('<error>Bluesky is not enabled for this instance (atproto_enabled)</error>');

			return 1;
		}
		$count = $this->identities->count();
		if (!$input->getOption('force')) {
			if (!$input->isInteractive()) {
				$output->writeln('<error>no terminal to ask on; use --force</error>');

				return 1;
			}
			$question = sprintf('Rotate the instance key and send a PLC operation for %d identities? (y/N) ', $count);
			if (!$this->questionHelper()->ask($input, $output, new \Symfony\Component\Console\Question\ConfirmationQuestion($question, false))) {
				return 0;
			}
		}

		[$new, $old] = $this->instanceKeys->rotate();
		$output->writeln('- new rotation key ' . $new->didKey());
		$done = 0;
		$failed = 0;
		$offset = 0;
		while (($batch = $this->identities->getAll(200, $offset)) !== []) {
			foreach ($batch as $identity) {
				if ($identity->state !== \OCA\Social\Atproto\Model\Identity::STATE_ACTIVE) {
					continue;
				}
				try {
					$this->identities->rotateInstanceKey($identity, $new, $old);
					$done++;
				} catch (Throwable $e) {
					$failed++;
					$output->writeln('<error>! ' . $identity->handle . ': ' . $e->getMessage() . '</error>');
				}
				usleep(self::PAUSE_MICROSECONDS);
			}
			$offset += 200;
		}
		$output->writeln(sprintf('- %d identities re-registered, %d failed (the maintenance job resends what the directory did not confirm)', $done, $failed));

		return $failed === 0 ? 0 : 1;
	}
}
