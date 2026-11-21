<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Service\StoredMediaMetadataService;
use OCA\Social\Service\VideoTranscodeService;
use OCA\Social\Tools\Nid;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Takes the container metadata off local video and sound files that still
 * carry it (stored before uploads were stripped, or without ffmpeg). Each
 * file is remuxed, not re-encoded.
 */
class MediaStripMetadata extends SocialCommand {
	/** How many are read per query. The work is per file, not per page. */
	private const PAGE = 50;

	public function __construct(
		private CacheDocumentsRequest $cacheDocumentsRequest,
		private StoredMediaMetadataService $storedMediaMetadataService,
		private VideoTranscodeService $videoTranscodeService,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:media:strip-metadata')
			->setDescription('Remove the container metadata (location, device) from stored videos and audio')
			->addOption(
				'dry-run', '', InputOption::VALUE_NONE,
				'list the files that would be rewritten, and change nothing'
			)
			->addOption(
				'limit', '', InputOption::VALUE_REQUIRED,
				'stop after this many files (0, the default, means all of them)', '0'
			);
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->videoTranscodeService->isAvailable()) {
			$output->writeln('<error>ffmpeg was not found, so nothing can be rewritten. Install it and run this again.</error>');

			return 1;
		}

		$dryRun = (bool)$input->getOption('dry-run');
		$limit = max(0, (int)$input->getOption('limit'));

		$tally = [
			StoredMediaMetadataService::STRIPPED => 0,
			StoredMediaMetadataService::ALREADY_CLEAN => 0,
			StoredMediaMetadataService::ON_BLUESKY => 0,
			StoredMediaMetadataService::MISSING => 0,
			StoredMediaMetadataService::FAILED => 0,
		];
		$seen = 0;
		$after = '0';

		while ($limit === 0 || $seen < $limit) {
			$page = ($limit === 0) ? self::PAGE : min(self::PAGE, $limit - $seen);
			$documents = $this->cacheDocumentsRequest->getLocalMediaToStrip($page, $after);
			if ($documents === []) {
				break;
			}

			foreach ($documents as $document) {
				$seen++;
				$after = (Nid::compare($after, $document->getNid()) > 0) ? $after : (string)$document->getNid();

				if ($dryRun) {
					$onBluesky = $this->storedMediaMetadataService->isOnBluesky($document);
					$tally[$onBluesky ? StoredMediaMetadataService::ON_BLUESKY : StoredMediaMetadataService::STRIPPED]++;
					$output->writeln(($onBluesky ? 'would leave (on Bluesky) ' : 'would rewrite ') . $document->getId());
					continue;
				}

				$outcome = $this->storedMediaMetadataService->strip($document);
				$tally[$outcome]++;
				$output->writeln(match ($outcome) {
					StoredMediaMetadataService::STRIPPED => '<info>rewritten</info> ',
					StoredMediaMetadataService::ALREADY_CLEAN => 'already clean ',
					StoredMediaMetadataService::ON_BLUESKY => '<comment>left as it is, its bytes are a Bluesky blob</comment> ',
					StoredMediaMetadataService::MISSING => '<comment>no stored file</comment> ',
					default => '<comment>could not be rewritten, left as it is</comment> ',
				} . '[' . $seen . '] ' . $document->getId());
			}
		}

		if ($dryRun) {
			$output->writeln(
				$tally[StoredMediaMetadataService::STRIPPED] . ' file(s) would be rewritten, '
				. $tally[StoredMediaMetadataService::ON_BLUESKY] . ' left as they are because they are published on Bluesky'
			);

			return 0;
		}

		$output->writeln(
			$tally[StoredMediaMetadataService::STRIPPED] . ' rewritten, '
			. $tally[StoredMediaMetadataService::ALREADY_CLEAN] . ' already clean, '
			. $tally[StoredMediaMetadataService::ON_BLUESKY] . ' left because they are published on Bluesky, '
			. $tally[StoredMediaMetadataService::MISSING] . ' without a stored file, '
			. $tally[StoredMediaMetadataService::FAILED] . ' could not be rewritten'
		);

		return 0;
	}
}
