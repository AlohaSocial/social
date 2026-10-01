<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Finding the next video to convert, converting it, and putting it back.
 *
 * Separate from `VideoTranscodeService`, which knows only how to run ffmpeg
 * over a path: this one knows about stored documents, and that separation is
 * what lets the ffmpeg command be tested without a file store and the
 * bookkeeping be tested without ffmpeg.
 *
 * The order of operations is the whole of the care here. The converted file is
 * written to the store **first**, the row is pointed at it **second**, and the
 * original is deleted **last** — so a failure at any point leaves a document
 * pointing at a file that exists. The other order leaves a post with a video
 * that 404s, which is worse than a video in a format some servers will not
 * take.
 *
 * The posts carrying the video are pointed at the new file too, before the
 * original goes. Their attachment copies name the file by its stored uuid and
 * its type, both of which a conversion changes, and a copy left alone was a
 * post whose video 404'd here the moment the original was deleted.
 */
class VideoTranscodingWorker {
	/** The four states `social_cache_doc.transcoded` holds. */
	public const NOT_LOOKED = 0;
	public const CONVERTED = 1;
	public const NOT_NEEDED = 2;
	public const FAILED = 3;

	/** How many candidates are read to find one worth converting. */
	private const PAGE = 20;

	/**
	 * How many pages one call will walk past before giving the run back.
	 * A thousand rows of bookkeeping is a second; the point of the ceiling is
	 * that a cron worker always returns.
	 */
	private const MAX_PAGES = 50;

	/**
	 * How many MP4s one call will read to ask what codec they are before
	 * giving the run back. Each one is copied out of storage for ffprobe,
	 * which is seconds for a large file rather than the nothing it costs to
	 * pass over a row by its type.
	 */
	private const MAX_PROBES = 5;

	public function __construct(
		private CacheDocumentsRequest $cacheDocumentsRequest,
		private CacheDocumentService $cacheDocumentService,
		private VideoTranscodeService $videoTranscodeService,
		private ITempManager $tempManager,
		private LoggerInterface $logger,
		private StreamRequest $streamRequest,
	) {
	}

	/**
	 * Converts the next video that wants it.
	 *
	 * Anything that cannot need it is marked as not needing it as it is passed
	 * over, which is what stops the same page being read on every run for
	 * ever. An MP4 has to be read to know — it may be HEVC inside — so a few
	 * of those are probed per call, and an H.264 one is marked the same way.
	 *
	 * It **pages** rather than looking at one page and giving up. An instance
	 * whose first twenty videos are all MP4s already — which is the ordinary
	 * case, because MP4 is what most things upload — would otherwise report
	 * that there was nothing to do while a `.mov` sat behind them. Found on
	 * devel: the run said "nothing was waiting" with a `video/quicktime`
	 * plainly in the table.
	 *
	 * Bounded all the same: each page either converts something and returns,
	 * or marks every row on it, so the walk moves forward and a library of
	 * MP4s is passed over once in the life of the instance rather than on
	 * every run.
	 *
	 * @return bool whether a video was actually converted
	 */
	public function convertNext(): bool {
		$after = 0;
		$probed = 0;

		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$documents = $this->cacheDocumentsRequest->getVideosToTranscode(self::PAGE, $after);
			if ($documents === []) {
				return false;
			}

			foreach ($documents as $document) {
				$after = \OCA\Social\Tools\Nid::compare($after, $document->getNid()) > 0 ? $after : $document->getNid();

				if (!$this->videoTranscodeService->mayNeedConversion($document->getMediaType())) {
					$this->cacheDocumentsRequest->setTranscoded($document->getNid(), self::NOT_NEEDED);
					continue;
				}

				$converted = $this->convert($document);
				if ($converted || $this->videoTranscodeService->shouldConvert($document->getMediaType())) {
					return $converted;
				}

				// an MP4 that turned out to be H.264 already, or that could
				// not be read: marked either way, and the walk goes on
				if (++$probed >= self::MAX_PROBES) {
					return false;
				}
			}
		}

		return false;
	}

	/**
	 * One video: out of the store, through ffmpeg, back into the store —
	 * when it needs it. An MP4 whose video is H.264 already is marked as not
	 * needing it and left exactly as it is.
	 *
	 * @return bool whether a video was actually converted
	 */
	public function convert(Document $document): bool {
		$source = null;

		try {
			$source = $this->toTempFile($document);
			if ($source === null) {
				// the bytes are not there to convert. Not a failure of the
				// conversion — marked as tried so the job moves on rather than
				// reading the same missing file for ever.
				$this->cacheDocumentsRequest->setTranscoded($document->getNid(), self::FAILED);

				return false;
			}

			if (!$this->videoTranscodeService->needsConversion($document->getMediaType(), $source)) {
				$this->cacheDocumentsRequest->setTranscoded($document->getNid(), self::NOT_NEEDED);

				return false;
			}

			$converted = $this->videoTranscodeService->convert($source);
			if ($converted === null) {
				$this->logger->info('a video could not be converted and was left as it is', [
					'document' => $document->getId(), 'type' => $document->getMediaType(),
				]);
				$this->cacheDocumentsRequest->setTranscoded($document->getNid(), self::FAILED);

				return false;
			}

			// written first, pointed at second, and the original deleted last:
			// a failure anywhere in here leaves the document pointing at a
			// file that exists
			$stored = $this->cacheDocumentService->storeFile($converted);
			@unlink($converted);

			$was = $document->getLocalCopy();
			$this->cacheDocumentsRequest->replaceVideo(
				$document->getNid(), $stored, VideoTranscodeService::TARGET_TYPE
			);
			$this->repointPosts($document, $stored);

			if ($was !== '' && $was !== $stored) {
				try {
					$this->cacheDocumentService->removeFromCache($was);
				} catch (Throwable $e) {
					// the row already points at the new file, so this is disk
					// left behind rather than anything a reader can see
					$this->logger->debug('the original of a converted video could not be deleted', [
						'document' => $document->getId(), 'exception' => $e,
					]);
				}
			}

			return true;
		} catch (Throwable $e) {
			$this->logger->warning('a video could not be converted', [
				'document' => $document->getId(), 'exception' => $e,
			]);
			$this->cacheDocumentsRequest->setTranscoded($document->getNid(), self::FAILED);

			return false;
		} finally {
			if ($source !== null) {
				@unlink($source);
			}
		}
	}

	/**
	 * The posts that carry this video, pointed at the converted file.
	 *
	 * A failure here is logged and not thrown: the document already names
	 * the new file, and undoing a finished conversion over it would lose
	 * more than it saves.
	 */
	private function repointPosts(Document $document, string $stored): void {
		$converted = clone $document;
		$converted->setLocalCopy($stored);
		$converted->setMediaType(VideoTranscodeService::TARGET_TYPE);
		$converted->setMimeType(VideoTranscodeService::TARGET_TYPE);
		$converted->setTranscoded(self::CONVERTED);
		$converted->setLaddered(0);

		try {
			$this->streamRequest->updateLocalAttachmentCopies($converted);
		} catch (Throwable $e) {
			$this->logger->warning('the posts carrying a converted video could not be updated', [
				'document' => $document->getId(), 'exception' => $e,
			]);
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

		// copied a chunk at a time: a video is the one thing this app stores
		// that will not fit in a string
		stream_copy_to_stream($source, $target);
		fclose($target);
		fclose($source);

		return (filesize($path) > 0) ? $path : null;
	}
}
