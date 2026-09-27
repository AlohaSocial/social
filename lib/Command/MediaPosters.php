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
 * Poster frames for the videos that were stored before there were any.
 *
 * A video uploaded here is given a still on its way in, and a client shows it
 * instead of a black rectangle until somebody presses play. Videos stored
 * before that existed — or on a server that had no ffmpeg when they arrived —
 * have none, and nothing was ever going to give them one: the still is made
 * from the bytes as they are written, and those bytes are only written once.
 *
 * So this reads them back and makes the still now. It is the same frame, the
 * same JPEG and the same `resized_copy` an upload gets, and it changes nothing
 * about the video itself.
 *
 * Deliberately a command rather than a background job: it is a one-off after
 * an upgrade, it spends a subprocess and a temporary copy per video, and an
 * administrator with ten thousand of them should be the one deciding when that
 * happens.
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
	 * Hands the new posters to the posts that show the videos.
	 *
	 * A post keeps its own copy of its attachments, written when it was saved,
	 * and that copy is what a client is served. Making the poster therefore
	 * changes nothing anybody can see until the copies are rewritten from the
	 * documents they were taken from, which is what this does.
	 *
	 * Every attachment of every post with one is rebuilt, not only the videos:
	 * the copy is derived from the document either way, so rebuilding it all
	 * is both simpler and self-correcting. A post is only written when the
	 * rebuild actually differs from what was stored.
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
	 * One stored attachment copy, taken again from the document it came from.
	 *
	 * What is stored is returned unchanged whenever the document behind it
	 * cannot be found: an attachment whose row is gone is still the only
	 * record of what the post showed, and dropping it would take the picture
	 * out of the post.
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
