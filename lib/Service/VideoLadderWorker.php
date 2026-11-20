<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\RenditionsRequest;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\VideoRendition;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Finds the next video to ladder, ladders it and keeps the rows honest;
 * `VideoLadderService` runs ffmpeg and knows nothing about the store, as
 * with `VideoTranscodingWorker`. The source video is never replaced, so a
 * failure means no ladder yet; a half-built ladder is torn down, as a
 * playlist naming a missing rung makes the player stall.
 */
class VideoLadderWorker {
	/** The four states `social_cache_doc.laddered` holds. */
	public const NOT_LOOKED = 0;
	public const LADDERED = 1;
	public const NOT_NEEDED = 2;
	public const FAILED = 3;

	/** How many candidates are read at a time. */
	private const PAGE = 20;

	/** How many pages one call walks before giving the run back. */
	private const MAX_PAGES = 50;

	public function __construct(
		private CacheDocumentsRequest $cacheDocumentsRequest,
		private RenditionsRequest $renditionsRequest,
		private CacheDocumentService $cacheDocumentService,
		private VideoLadderService $videoLadderService,
		private ITempManager $tempManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Ladders the next video that wants it, paging and marking as it passes,
	 * so videos too short to ladder cannot hide the ones behind them.
	 *
	 * @return bool whether a ladder was actually built
	 */
	public function ladderNext(): bool {
		$after = 0;

		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$documents = $this->cacheDocumentsRequest->getVideosToLadder(self::PAGE, $after);
			if ($documents === []) {
				return false;
			}

			foreach ($documents as $document) {
				$after = \OCA\Social\Tools\Nid::compare($after, $document->getNid()) > 0 ? $after : $document->getNid();

				$state = $this->attempt($document);
				if ($state === self::NOT_NEEDED) {
					// a video already smaller than the lowest rung costs a
					// read and nothing else, so the walk keeps going rather
					// than spending a whole cron run establishing that
					continue;
				}

				return $state === self::LADDERED;
			}
		}

		return false;
	}

	/**
	 * One video: out of the store, up the ladder, and back into the store.
	 *
	 * @return bool whether a ladder was built
	 */
	public function ladder(Document $document): bool {
		return $this->attempt($document) === self::LADDERED;
	}

	/**
	 * The same, saying which of the four states it left the row in — which is
	 * what `ladderNext()` needs to tell "there was nothing to do here" from
	 * "this run has done its work".
	 *
	 * @return self::NOT_LOOKED|self::LADDERED|self::NOT_NEEDED|self::FAILED
	 */
	private function attempt(Document $document): int {
		$source = null;
		$stored = [];

		try {
			$source = $this->toTempFile($document);
			if ($source === null) {
				// the bytes are not there. Marked as tried so the job moves on
				// rather than reading the same missing file for ever.
				$this->cacheDocumentsRequest->setLaddered($document->getNid(), self::FAILED);

				return self::FAILED;
			}

			$height = $this->videoLadderService->heightOf($source);
			$rungs = $this->videoLadderService->rungs($height);
			if ($rungs === [] || ($rungs === [$height] && $height < $this->lowestRung())) {
				// nothing to gain: either it could not be read, or it is
				// already smaller than the smallest rung an administrator asked
				// for and a ladder would be one copy of the same file
				$this->cacheDocumentsRequest->setLaddered($document->getNid(), self::NOT_NEEDED);

				return self::NOT_NEEDED;
			}

			$renditions = [];
			foreach ($rungs as $rung) {
				$encoded = $this->videoLadderService->encode($source, $rung);
				if ($encoded === null) {
					// one failed rung fails the ladder: see the class comment
					throw new \RuntimeException('a rung could not be encoded at ' . $rung);
				}

				$path = $this->cacheDocumentService->storeFile($encoded['file']);
				@unlink($encoded['file']);
				$stored[] = $path;

				$rendition = new VideoRendition();
				$rendition->setDocNid($document->getNid())
					->setHeight($rung)
					->setBandwidth($encoded['bandwidth'])
					->setSize($encoded['size'])
					->setLocalCopy($path)
					->setPlaylist($encoded['playlist']);
				$renditions[] = $rendition;
			}

			// every rung is in the store before a single row points at one, so
			// a ladder becomes visible complete or not at all
			$orphans = $this->renditionsRequest->deleteForDocument($document->getNid());
			foreach ($renditions as $rendition) {
				$this->renditionsRequest->save($rendition);
			}
			$this->cacheDocumentsRequest->setLaddered($document->getNid(), self::LADDERED);

			// the rungs of a previous ladder, now that nothing names them
			$this->forget($orphans);

			return self::LADDERED;
		} catch (Throwable $e) {
			$this->logger->warning('a video could not be laddered', [
				'document' => $document->getId(), 'exception' => $e,
			]);
			// the rungs that did get written are disk nothing points at
			$this->forget($stored);
			$this->cacheDocumentsRequest->setLaddered($document->getNid(), self::FAILED);

			return self::FAILED;
		} finally {
			if ($source !== null) {
				@unlink($source);
			}
		}
	}

	/** Throws a video's ladder away, files and all. */
	public function tearDown(int|string $docNid): void {
		$this->forget($this->renditionsRequest->deleteForDocument($docNid));
		$this->cacheDocumentsRequest->setLaddered($docNid, self::NOT_LOOKED);
	}

	private function lowestRung(): int {
		$heights = $this->videoLadderService->heights();

		return $heights[0] ?? VideoLadderService::MIN_HEIGHT;
	}

	/**
	 * @param string[] $paths
	 */
	private function forget(array $paths): void {
		foreach ($paths as $path) {
			try {
				$this->cacheDocumentService->removeFromCache($path);
			} catch (Throwable $e) {
				// nothing points at it any more, so this is disk left behind
				// rather than anything a reader can see
				$this->logger->debug('a rung could not be deleted', [
					'path' => $path, 'exception' => $e,
				]);
			}
		}
	}

	/**
	 * The stored bytes on disk, where ffmpeg can read them.
	 *
	 * @return string|null the path, or null when the file is not there
	 */
	private function toTempFile(Document $document): ?string {
		try {
			$file = $this->cacheDocumentService->getContentFromCache($document->getLocalCopy());
		} catch (Throwable $e) {
			return null;
		}

		$path = $this->tempManager->getTemporaryFile('.video');
		if ($path === false) {
			return null;
		}

		$source = $file->read();
		$target = fopen($path, 'wb');
		if (!is_resource($source) || $target === false) {
			@unlink($path);

			return null;
		}

		// a chunk at a time: a video is the one thing this app stores that
		// will not fit in a string
		stream_copy_to_stream($source, $target);
		fclose($target);
		fclose($source);

		return (filesize($path) > 0) ? $path : null;
	}
}
