<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ social:atproto:repo`: a user's repository — its head, what it holds,
 * and whether it checks out.
 */
class AtprotoRepo extends SocialCommand {
	public function __construct(
		private AtprotoConfig $config,
		private IdentityService $identities,
		private RepositoryService $repositories,
		private AtprotoRepoRequest $repoRequest,
		private ActorsRequest $actorsRequest,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:atproto:repo')
			->setDescription('Show a user\'s Bluesky repository: head commit, record counts, and with --verify whether the tree and signature check')
			->addArgument('user', InputArgument::REQUIRED, 'the Nextcloud user id, handle or DID')
			->addOption('verify', '', InputOption::VALUE_NONE, 'recompute the tree from the records and check the head signature');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$who = trim((string)$input->getArgument('user'));
		try {
			if (str_starts_with($who, 'did:')) {
				$identity = $this->identities->getByDid($who);
			} elseif (str_contains($who, '.')) {
				$identity = $this->identities->getByHandle($who);
			} else {
				$identity = $this->identities->getByActorId($this->actorsRequest->getFromUserId($who)->getId());
			}
		} catch (AtprotoIdentityNotFoundException|ActorDoesNotExistException) {
			$output->writeln('<error>no Bluesky identity for ' . $who . '</error>');

			return 1;
		}

		$head = $this->repositories->getHead($identity->did);
		$collections = [];
		foreach ($this->repoRequest->getLeaves($identity->did) as $path => $cid) {
			$collection = explode('/', $path, 2)[0];
			$collections[$collection] = ($collections[$collection] ?? 0) + 1;
		}
		ksort($collections);
		$answer = [
			'did' => $identity->did,
			'handle' => $identity->handle,
			'state' => $identity->state,
			'pds' => $this->config->pdsEndpoint(),
			'head' => $head === null ? null : ['commit' => $head->commitCid, 'rev' => $head->rev, 'records' => $head->recordCount, 'blob_bytes' => $head->blobBytes, 'updated' => gmdate('Y-m-d H:i:s', $head->updated)],
			'collections' => $collections,
		];
		if ($input->getOption('verify')) {
			$problems = $this->repositories->verify($identity->did, $this->identities->signingPublicKey($identity));
			$answer['verify'] = $problems === [] ? 'ok' : $problems;
		}
		$this->writeMixedInOutputFormat($input, $output, $answer);

		return ($answer['verify'] ?? 'ok') === 'ok' ? 0 : 1;
	}
}
