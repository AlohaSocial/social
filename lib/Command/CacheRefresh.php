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
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\HashtagService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CacheRefresh extends SocialCommand {
	private CacheActorService $cacheActorService;
	private HashtagService $hashtagService;

	public function __construct(
		private AccountService $accountService,
		CacheActorService $cacheActorService,
		private DocumentService $documentService,
		HashtagService $hashtagService,
	) {
		parent::__construct();
		$this->cacheActorService = $cacheActorService;
		$this->hashtagService = $hashtagService;
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:cache:refresh')
			->setDescription('Refresh the cached accounts, documents and hashtags now instead of waiting for the cron')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'enforce update of cached account')
			->addOption(
				'rotate-keys', '', InputOption::VALUE_NONE,
				'renew the key pair of actors older than ' . AccountService::KEY_PAIR_LIFESPAN
				. ' days (blind rotation: remote servers pick the new key up on their next fetch)'
			);
	}

	/**
	 * @throws Exception
	 */
	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		// Deliberately opt-in and not part of the cron: rotation invalidates the
		// key remote servers have cached, and they only recover by re-fetching
		// the actor (most do so on the next failed signature check).
		if ($input->getOption('rotate-keys')) {
			$result = $this->accountService->blindKeyRotation();
			$output->writeLn($result . ' key pairs refreshed');
		}

		$result = $this->accountService->manageDeletedActors();
		$output->writeLn($result . ' local accounts deleted');

		$result = $this->accountService->manageCacheLocalActors();
		$output->writeLn($result . ' local accounts regenerated');

		$result = $this->cacheActorService->manageCacheRemoteActors($input->getOption('force'));
		$output->writeLn($result . ' remote accounts updated');

		$result = $this->cacheActorService->manageDetailsRemoteActors($input->getOption('force'));
		$output->writeLn($result . ' remote accounts details updated');

		$result = $this->documentService->manageCacheDocuments();
		$output->writeLn($result . ' documents cached');

		$result = $this->hashtagService->manageHashtags();
		$output->writeLn($result . ' hashtags updated');

		return 0;
	}
}
