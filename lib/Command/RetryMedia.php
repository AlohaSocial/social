<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Service\DocumentService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Retry one attachment which an earlier media-cache pass refused. */
class RetryMedia extends SocialCommand {
	public function __construct(
		private CacheDocumentsRequest $cacheDocumentsRequest,
		private DocumentService $documentService,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:media:retry')
			->setDescription('Retry caching one previously refused remote attachment')
			->addArgument('remote_url', InputArgument::REQUIRED, 'The exact URL shown as remote_url for the attachment');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$url = (string)$input->getArgument('remote_url');
		$parts = parse_url($url);
		if (!is_array($parts)
			|| !isset($parts['host'], $parts['path'])
			|| !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
			$output->writeln('<error>Provide the exact HTTP or HTTPS remote_url for one attachment.</error>');

			return 1;
		}

		try {
			$document = $this->cacheDocumentsRequest->getFailedUncachedByUrl($url);
		} catch (CacheDocumentDoesNotExistException) {
			$output->writeln('<error>No failed, uncached attachment is stored under that remote_url.</error>');

			return 1;
		}

		if (!$this->cacheDocumentsRequest->resetRemoteErrorForRetry($document->getId())) {
			$output->writeln('<error>No failed, uncached remote attachment matches that URL.</error>');

			return 1;
		}

		try {
			$document = $this->documentService->cacheRemoteDocumentInBackground($document->getId());
		} catch (CacheDocumentDoesNotExistException) {
			$output->writeln(
				'<error>The retry did not cache the attachment. It remains subject to this instance\'s media limits.</error>'
			);

			return 1;
		}

		if ($document->getLocalCopy() === '') {
			$output->writeln(
				'<error>The retry did not produce a local copy. Check the Aloha Social log and media limits.</error>'
			);

			return 1;
		}

		$output->writeln('<info>Remote attachment cached successfully.</info>');

		return 0;
	}
}
