<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\VideoThumbnailService;
use OCP\IURLGenerator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Makes poster frames for stored videos that have none (stored before ffmpeg
 * was available), the same still an upload gets, leaving the video as is.
 * `Cron\VideoPosters` does a few per run; this does all of them at once and
 * retries those the job failed on.
 */
class MediaPosters extends SocialCommand {
	/** How many are read per query. The work is per video, not per page. */
	private const PAGE = 50;

	public function __construct(
		private CacheDocumentsRequest $cacheDocumentsRequest,
		private CacheDocumentService $cacheDocumentService,
		private VideoThumbnailService $videoThumbnailService,
		private StreamRequest $streamRequest,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:media:posters')
			->setDescription('Generate the missing poster frames of stored videos')
			->addOption(
				'dry-run', '', InputOption::VALUE_NONE,
				'count the videos that have no poster, and change nothing'
			)
			->addOption(
				'limit', '', InputOption::VALUE_REQUIRED,
				'stop after this many videos (0, the default, means all of them)', '0'
			);
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->videoThumbnailService->isAvailable()) {
			$output->writeln(
				'<error>ffmpeg was not found, so no poster can be made. Install it and run this again.</error>'
			);

			return 1;
		}

		$dryRun = (bool)$input->getOption('dry-run');
		$limit = max(0, (int)$input->getOption('limit'));

		$seen = 0;
		$made = 0;
		$failed = 0;
		$after = 0;

		while (true) {
			$page = self::PAGE;
			if ($limit > 0) {
				$page = min($page, $limit - $seen);
			}
			if ($page < 1) {
				break;
			}

			$documents = $this->cacheDocumentsRequest->getVideosWithoutPoster($page, $after);
			if ($documents === []) {
				break;
			}

			foreach ($documents as $document) {
				$seen++;
				$after = \OCA\Social\Tools\Nid::compare($after, $document->getNid()) > 0 ? $after : $document->getNid();

				if ($dryRun) {
					$output->writeln('would make a poster for ' . $document->getId());
					continue;
				}

				try {
					if ($this->cacheDocumentService->generatePoster($document)) {
						$this->cacheDocumentsRequest->updatePoster($document);
						$made++;
						$output->writeln('<info>' . $document->getId() . '</info>');
					} else {
						$failed++;
						$output->writeln('<comment>no frame from ' . $document->getId() . '</comment>');
					}
				} catch (Throwable $e) {
					// one unreadable video must not end the run: every other
					// one in the batch is still fixable
					$failed++;
					$output->writeln(
						'<comment>' . $document->getId() . ': ' . $e->getMessage() . '</comment>'
					);
				}
			}
		}

		if ($dryRun) {
			$output->writeln($seen . ' video(s) have no poster');

			return 0;
		}

		$output->writeln($made . ' poster(s) made, ' . $failed . ' could not be');

		if ($made > 0) {
			$posts = $this->refreshStoredCopies();
			$output->writeln($posts . ' post(s) now show one');
		}

		return 0;
	}

	/**
	 * Rewrites the attachment copies posts keep, so they show the new
	 * posters. Every attachment is rebuilt from its document; a post is only
	 * written when the result differs.
	 *
	 * @return int how many posts were rewritten
	 */
	private function refreshStoredCopies(): int {
		$after = 0;
		$changed = 0;

		while (true) {
			$rows = $this->streamRequest->getStoredAttachmentCopies(self::PAGE, $after);
			if ($rows === []) {
				break;
			}

			foreach ($rows as $row) {
				$after = \OCA\Social\Tools\Nid::compare($after, $row['nid']) > 0 ? $after : $row['nid'];

				$stored = json_decode($row['attachments'], true);
				if (!is_array($stored)) {
					continue;
				}

				$rebuilt = [];
				foreach ($stored as $attachment) {
					$rebuilt[] = $this->rebuild($attachment);
				}

				$json = json_encode($rebuilt, JSON_UNESCAPED_SLASHES);
				if ($json === false || $json === $row['attachments']) {
					continue;
				}

				$this->streamRequest->setStoredAttachmentCopies($row['id'], $json);
				$changed++;
			}
		}

		return $changed;
	}

	/**
	 * One stored attachment copy, rebuilt from its document. Returned
	 * unchanged when the document is gone, as it is then the only record of
	 * what the post showed.
	 *
	 * @param mixed $attachment the stored copy
	 *
	 * @return mixed the copy to store
	 */
	private function rebuild(mixed $attachment): mixed {
		if (!is_array($attachment) || !isset($attachment['id'])) {
			return $attachment;
		}

		try {
			$document = $this->cacheDocumentsRequest->getByNid((string)$attachment['id']);
		} catch (CacheDocumentDoesNotExistException $e) {
			return $attachment;
		}

		return $document->convertToMediaAttachment($this->urlGenerator)->asLocal();
	}
}
