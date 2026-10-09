<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `occ social:atproto:identities`: gives every local account its Bluesky
 * identity now rather than on first need, and writes the profile record.
 * An identity its owner switched off stays off: it is listed with its
 * state, never made again nor switched back on.
 */
class AtprotoIdentities extends SocialCommand {
	public function __construct(
		private AtprotoConfig $config,
		private IdentityService $identities,
		private Publisher $publisher,
		private ActorsRequest $actorsRequest,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:atproto:identities')
			->setDescription('Create the Bluesky identity of every local account that has none, and publish its profile')
			->addOption('user', '', InputOption::VALUE_REQUIRED, 'only this Nextcloud user')
			->addOption('list', '', InputOption::VALUE_NONE, 'list the identities instead of creating any');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($input->getOption('list')) {
			return $this->list($input, $output);
		}
		if (!$this->config->isEnabled()) {
			$output->writeln('<error>Bluesky is not enabled for this instance (atproto_enabled)</error>');

			return 1;
		}

		$user = (string)$input->getOption('user');
		$actors = [];
		if ($user !== '') {
			try {
				$actors[] = $this->actorsRequest->getFromUserId($user);
			} catch (ActorDoesNotExistException) {
				$output->writeln('<error>' . $user . ' has no Social account</error>');

				return 1;
			}
		} else {
			$actors = $this->actorsRequest->getAll();
		}

		$made = 0;
		$failed = 0;
		foreach ($actors as $actor) {
			if (!$actor instanceof Person || $actor->getMovedTo() !== '') {
				continue;
			}
			try {
				$identity = $this->identities->forActor($actor, false);
				$fresh = $identity === null;
				$identity ??= $this->identities->create($actor);
				$this->publisher->publishProfile($actor);
				$output->writeln(sprintf('%s %s  %s  %s', $fresh ? '+' : '=', $actor->getPreferredUsername(), $identity->handle, $identity->did)
					. ($identity->isActive() ? '' : '  (' . $identity->state . ')'));
				if ($fresh) {
					$made++;
				}
			} catch (Throwable $e) {
				$failed++;
				$output->writeln('<error>! ' . $actor->getPreferredUsername() . ': ' . $e->getMessage() . '</error>');
			}
		}
		$output->writeln(sprintf('- %d identities made, %d accounts already had one, %d failed', $made, count($actors) - $made - $failed, $failed));

		return $failed === 0 ? 0 : 1;
	}

	private function list(InputInterface $input, OutputInterface $output): int {
		$rows = [];
		foreach ($this->identities->getAll() as $identity) {
			$rows[] = [
				'handle' => $identity->handle,
				'did' => $identity->did,
				'state' => $identity->state,
				'recovery_key' => $identity->recoveryPublic !== '' ? 'yes' : 'no',
				'created' => gmdate('Y-m-d H:i', $identity->creation),
			];
		}
		$this->writeTableInOutputFormat($input, $output, $rows);

		return 0;
	}
}
