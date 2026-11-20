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
 * Finds the next video to convert, converts it, and puts it back; running
 * ffmpeg is `VideoTranscodeService`'s, so each can be tested alone.
 *
 * The converted file is written first, the row pointed at it second, and the
 * original deleted last, so a failure always leaves the document on a file
 * that exists. The posts carrying the video are re-pointed before the
 * original goes, since their attachment copies name its uuid and type.
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
	 * How many pages one call walks before giving the run back, so a cron
	 * worker always returns.
	 */
	private const MAX_PAGES = 50;

	/**
	 * How many MP4s one call probes for their codec before giving the run back:
	 * each is copied out of storage for ffprobe.
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
	 * Rows that do not need it are marked as passed over, an MP4 after probing
	 * (it may be HEVC inside), so the walk moves forward and pages past
	 * already-fine MP4s once in the instance's life. Each page converts
	 * something or marks every row.
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

				// another server's video is not this instance's to re-encode;
				// marked so the row never comes up again
				if (!$document->isLocalUpload()
					|| !$this->videoTranscodeService->mayNeedConversion($document->getMediaType())) {
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
	 * One video: out of the store, through ffmpeg, back into the store, unless
	 * it is an MP4 with H.264 video already, which is only marked.
	 *
	 * @return bool whether a video was actually converted
	 */
	public function convert(Document $document): bool {
		$source = null;

		try {
			$source = $this->toTempFile($document);
			if ($source === null) {
				// the bytes are missing: marked as tried so the job moves on
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
	 * The posts that carry this video, pointed at the converted file. A failure
	 * is only logged: the document already names the new file.
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
