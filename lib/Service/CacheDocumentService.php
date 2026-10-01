<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use Gumlet\ImageResize;
use Gumlet\ImageResizeException;
use OCA\Social\Db\MediaBlocksRequest;
use OCA\Social\Exceptions\CacheContentDecodeException;
use OCA\Social\Exceptions\CacheContentException;
use OCA\Social\Exceptions\CacheContentMimeTypeException;
use OCA\Social\Exceptions\CacheContentSizeException;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\Client\AttachmentMeta;
use OCA\Social\Model\Client\AttachmentMetaDim;
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCA\Social\Tools\Traits\TArrayTools;
use OCA\Social\Tools\Traits\TStringTools;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;
use Throwable;

class CacheDocumentService {
	use TArrayTools;
	use TStringTools;

	public const RESIZED_WIDTH = 800;
	public const RESIZED_HEIGHT = 800;

	/**
	 * Ceiling on the pixel count of an image this app will decode.
	 *
	 * A decoded image costs roughly four bytes a pixel in GD, so the size of
	 * the file says nothing about the memory the decode needs: a 400 KB
	 * 30000x30000 PNG wants about 3.6 GB. The dimensions are read from the
	 * header before any decode happens, and anything past this is refused.
	 */
	public const MAX_PIXELS = 50000000; // 50 MP, ~200 MB decoded

	/** How much of a stored file `sniffStored()` reads to recognise it. */
	private const SNIFF_BYTES = 4096;

	public function __construct(
		private IAppData $appData,
		private CurlService $curlService,
		private BlurService $blurService,
		private ConfigService $configService,
		private ImageConversionService $imageConversionService,
		private VideoThumbnailService $videoThumbnailService,
		private ITempManager $tempManager,
		private MediaBlocksRequest $mediaBlocksRequest,
		private VideoQuotaService $videoQuotaService,
		private RemoteMediaQuotaService $remoteMediaQuotaService,
		private ExternalMediaQuota $externalMediaQuota,
		private LoggerInterface $logger,
		private VideoTranscodeService $videoTranscodeService,
	) {
	}

	/**
	 * Makes the poster a video should already have.
	 *
	 * Same frame, same JPEG, same `resized_copy` as an upload gets on its way
	 * in -- see `saveMediaFromTemp()`, which this exists to apply after the
	 * fact to the videos that were stored before there was any such thing, or
	 * on a server that had no ffmpeg at the time. `occ social:media:posters`
	 * is what calls it.
	 *
	 * ffmpeg wants a path and the stored copy is an `ISimpleFile`, so the
	 * bytes go to a temporary file a chunk at a time: a video is the one thing
	 * in this app that must never be read into a string.
	 *
	 * The document is written back only when a frame came out, so a video
	 * ffmpeg cannot read is left exactly as it was and can be tried again.
	 *
	 * @return bool whether a poster was made
	 */
	public function generatePoster(Document $document): bool {
		if ($document->getLocalCopy() === '' || $document->getResizedCopy() !== '' || $document->isStreamed()) {
			return false;
		}

		$tmpPath = $this->tempManager->getTemporaryFile('.video');
		if ($tmpPath === false) {
			return false;
		}

		try {
			$source = $this->getFromUuid($document->getLocalCopy())->read();
			if ($source === false) {
				return false;
			}

			$target = fopen($tmpPath, 'w');
			if ($target === false) {
				fclose($source);

				return false;
			}

			stream_copy_to_stream($source, $target);
			fclose($source);
			fclose($target);
		} catch (Exception $e) {
			// the row names a file that is not there any more: nothing to make
			// a still out of, and nothing this can do about it
			return false;
		}

		$poster = $this->videoThumbnailService->poster($tmpPath);
		@unlink($tmpPath);
		if ($poster === null) {
			return false;
		}

		$document->setResizedCopy($this->generateFileFromContent($poster['content']));

		$meta = $document->getMeta() ?? new AttachmentMeta();
		if ($poster['duration'] > 0) {
			$meta->setDuration((float)$poster['duration']);
		}
		if ($poster['width'] > 0 && $poster['height'] > 0) {
			// persisted here rather than left to `convertToMediaAttachment()`:
			// the copy sizes it reads live only as long as the object does,
			// and a backfilled poster has to survive the command that made it
			$dimensions = new AttachmentMetaDim([$poster['width'], $poster['height']]);
			$meta->setOriginal($dimensions);
			$meta->setSmall($dimensions);
			$document->setResizedCopySize($poster['width'], $poster['height']);
			$document->setLocalCopySize($poster['width'], $poster['height']);
		}
		$document->setMeta($meta);

		return true;
	}

	/** the largest cover picture taken for a video, in bytes */
	public const MAX_POSTER_BYTES = 10 * 1024 * 1024;

	/**
	 * Uses a picture the uploader chose as a video's poster, in place of the
	 * frame ffmpeg picked -- Mastodon's `thumbnail` on a media upload, and the
	 * cover a short is posted with.
	 *
	 * The picture goes through the same decode and resize every uploaded image
	 * does, and comes out as a new JPEG, so nothing the camera wrote into the
	 * original survives and a picture the server cannot read is refused rather
	 * than stored. The poster it replaces is removed from appdata.
	 *
	 * @param Document $document a video that has been stored
	 * @param string $imagePath the uploaded picture
	 *
	 * @return bool whether the cover was taken
	 */
	public function applyCustomPoster(Document $document, string $imagePath): bool {
		if (!str_starts_with($document->getMediaType(), 'video/') || !is_readable($imagePath)) {
			return false;
		}

		$content = @file_get_contents($imagePath, false, null, 0, self::MAX_POSTER_BYTES + 1);
		if ($content === false || $content === '' || strlen($content) > self::MAX_POSTER_BYTES) {
			return false;
		}

		try {
			$this->assertDecodable($content);
			$image = ImageResize::createFromString($content);
			$image->quality_jpg = 82;
			$image->resizeToBestFit(self::RESIZED_WIDTH, self::RESIZED_HEIGHT);
			$poster = $image->getImageAsString(IMAGETYPE_JPEG);
		} catch (Throwable $e) {
			$this->logger->debug('a cover picture could not be read', ['exception' => $e]);

			return false;
		}

		if ($poster === '') {
			return false;
		}

		$previous = $document->getResizedCopy();
		$document->setResizedCopy($this->generateFileFromContent($poster));
		$document->setResizedCopySize($image->getDestWidth(), $image->getDestHeight());
		$gd = @imagecreatefromstring($poster);
		if ($gd !== false) {
			$document->setBlurHash($this->blurService->generateBlurHash($gd));
		}
		if ($previous !== '' && $previous !== $document->getLocalCopy()) {
			$this->removeFromCache($previous);
		}

		return true;
	}

	/**
	 * A file on somebody else's server, fetched and then stored exactly as an
	 * upload is.
	 *
	 * The bytes go to a temporary file rather than into a string so that this
	 * ends in `saveFromTempToCache()` like every other way a file enters this
	 * app. That is the whole point of the detour: the fetch path used to run
	 * the mime allow-list and nothing else, so the moderator's list of refused
	 * files did not cover federated pictures although it says it does, a
	 * remote HEIC was stored unconverted and then failed to decode for ever,
	 * and the only ceiling on the download was the one meant for ActivityPub
	 * documents.
	 *
	 * @param string $mime filled with what the content turned out to be
	 *
	 * @throws CacheContentDecodeException
	 * @throws CacheContentMimeTypeException
	 * @throws CacheContentSizeException
	 * @throws MalformedArrayException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 */
	public function saveRemoteFileToCache(Document $document, string &$mime = ''): void {
		$tmpPath = $this->tempManager->getTemporaryFile('.media');
		if ($tmpPath === false) {
			throw new NotFoundException('could not open a temporary file');
		}

		try {
			$this->downloadToTemp($document, $tmpPath);
			$this->saveFromTempToCache($document, $tmpPath);
		} finally {
			@unlink($tmpPath);
		}

		$mime = $document->getMimeType();
	}

	/**
	 * The remote file, on disk, a chunk at a time.
	 *
	 * Read as a stream with a ceiling rather than into a string: a video is
	 * the one thing here that must never be held whole, and the ceiling has to
	 * be the one that applies to what the document says it is -- an attachment
	 * cut off at the size meant for an ActivityPub document is how every
	 * federated video ended up marked `ERROR_SIZE`. What the bytes actually
	 * turn out to be is checked again by `filterSize()`, against the sniffed
	 * type, which is the only one that decides what happens to them.
	 *
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 * @throws MalformedArrayException
	 */
	private function downloadToTemp(Document $document, string $tmpPath): void {
		$this->assertFetchable($document->getUrl());

		$max = $this->sizeLimit($document->getMediaType());
		$opened = $this->openRemoteFile($document);
		$stream = $opened['stream'];

		$target = fopen($tmpPath, 'w');
		if ($target === false) {
			fclose($stream);

			throw new RequestServerException('could not write the downloaded file');
		}

		try {
			$copied = stream_copy_to_stream($stream, $target, $max + 1);
		} finally {
			fclose($stream);
			fclose($target);
		}

		if ($copied === false) {
			throw new RequestServerException('could not read ' . $document->getUrl());
		}

		if ($copied > $max) {
			throw new RequestResultSizeException();
		}
	}

	/**
	 * @throws CacheContentDecodeException
	 * @throws CacheContentMimeTypeException
	 * @throws CacheContentSizeException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function saveFromTempToCache(Document $document, string $tmpPath) {
		$mime = mime_content_type($tmpPath);

		$this->filterMimeTypes($mime);
		$size = (int)filesize($tmpPath);
		$this->filterSize($mime, $size);
		$this->filterQuota($document, $mime, $size);
		$this->filterBlockedMedia($tmpPath);

		if (!str_starts_with($mime, 'image/')) {
			$this->saveMediaFromTemp($document, $tmpPath, $mime);
			$this->recordDomainQuota($document, $size);

			return;
		}

		$file = fopen($tmpPath, 'r');
		$content = fread($file, filesize($tmpPath));
		fclose($file);

		// First, because everything below it decodes: a phone photo carrying an
		// Exif orientation is turned by decoding all of it, and a hundred
		// megapixels under the size ceiling is a fatal error rather than a
		// refusal.
		$this->assertWithinPixelLimit($content);

		// Then the camera's metadata comes off, and a format no browser can
		// draw becomes one it can. Both can change the mime, so the document is
		// told afterwards rather than before.
		[$content, $mime] = $this->imageConversionService->prepareForStorage($content, $mime);

		$document->setMediaType($mime);
		$document->setMimeType($mime);

		$filename = $this->generateFileFromContent($content);
		$document->setLocalCopy($filename);
		// what the stored file weighs, recorded once here rather than measured
		// on every serialisation: PeerTube drops a video link that carries no
		// `size`, and the per-account quota asks the same question
		$document->setSizeBytes(strlen($content));

		$this->resizeImage($document, $content);
		$resized = $this->generateFileFromContent($content);
		$document->setResizedCopy($resized);
		$this->recordDomainQuota($document, $size);
	}

	/**
	 * The per-account video quota, applied before anything is written.
	 *
	 * Local uploads only. This one funnel carries both an upload and a
	 * *fetched remote attachment*, and charging somebody's quota for a video
	 * this instance chose to cache on their behalf would be a limit nobody
	 * could explain — the bytes are there because a post they follow had a
	 * video in it.
	 *
	 * @throws CacheContentSizeException
	 */
	public function filterQuota(Document $document, string $mime, int $size): void {
		if (!$document->isLocal()) {
			$this->filterDomainQuota($document, $size);

			return;
		}

		// what a self-registered external user may upload in all, on top of
		// the video quota every account has
		$account = $document->getAccount();
		if (!$this->externalMediaQuota->fits($account, $size)) {
			throw new CacheContentSizeException(
				'this account has used its ' . $this->externalMediaQuota->quota()
				. 'MB of media storage on this instance'
			);
		}

		if (!str_starts_with($mime, 'video/')) {
			return;
		}

		if ($this->videoQuotaService->fits($account, $size)) {
			return;
		}

		throw new CacheContentSizeException(
			'this account has used its ' . $this->videoQuotaService->quota()
			. 'MB of video storage on this instance'
		);
	}

	/**
	 * How much of this instance's disk the server a picture came from may take.
	 *
	 * The twin of the per-account video quota, for the other direction: every
	 * picture on a post somebody here follows is fetched and kept, and nothing
	 * bounded that by where it came from. One server posting large images at a
	 * high rate filled the disk of every instance following anybody on it.
	 *
	 * Refused before the bytes are written, and the bytes that are written are
	 * counted, so the next fetch from that host knows about this one.
	 *
	 * @throws CacheContentSizeException
	 */
	private function filterDomainQuota(Document $document, int $size): void {
		$host = RemoteMediaQuotaService::chargedHost($document->getUrl(), $document->getId());
		if ($this->remoteMediaQuotaService->fits($host, $size)) {
			return;
		}

		$this->logger->info('[CacheDocumentService] a domain reached its media quota', [
			'host' => $host, 'quota' => $this->remoteMediaQuotaService->quota(),
		]);

		throw new CacheContentSizeException(
			$host . ' has used the ' . $this->remoteMediaQuotaService->quota()
			. 'MB of media storage this instance allows one server'
		);
	}

	/**
	 * Charge a remote server only after its file has passed validation and
	 * both cached copies (where applicable) have been written. A rejected or
	 * unreadable attachment must not consume quota: otherwise repeated invalid
	 * media could make the quota refuse later valid images without using disk.
	 */
	private function recordDomainQuota(Document $document, int $size): void {
		if ($document->isLocal()) {
			return;
		}

		$this->remoteMediaQuotaService->record(
			RemoteMediaQuotaService::chargedHost($document->getUrl(), $document->getId()),
			$size,
		);
	}

	/**
	 * A picture a moderator has refused, wherever it came from.
	 *
	 * Checked on the bytes as they are written, which is the one place both
	 * an upload and a fetched remote attachment pass through — a list that
	 * only stopped local uploads would be a list that stops the one source a
	 * moderator can already deal with by other means.
	 *
	 * The hash is of the file as it arrived, before the metadata is stripped
	 * and before any conversion: two files that differ only in their Exif are
	 * a different hash, and this is deliberately not clever. It answers "this
	 * exact file, again" — which is what re-posting is — and it does not claim
	 * to answer "a picture that looks like this one".
	 *
	 * @throws CacheContentMimeTypeException the same refusal an unacceptable
	 *                                       type gets: the caller has one way
	 *                                       to say no to a file, and a second
	 *                                       would be a second thing every
	 *                                       caller had to catch
	 */
	private function filterBlockedMedia(string $tmpPath): void {
		// false when the file cannot be read; a successful hash is always the
		// full 64 characters, so there is no empty case to test for
		$hash = @hash_file('sha256', $tmpPath);
		if (!is_string($hash)) {
			return;
		}

		if ($this->mediaBlocksRequest->isBlocked($hash)) {
			$this->logger->notice('a refused picture was offered again', ['hash' => $hash]);

			throw new CacheContentMimeTypeException('this file is not accepted on this instance');
		}
	}

	/**
	 * The size ceiling, applied to what the content turned out to be.
	 *
	 * The request-time check (`MediaApiController::refuseOversized()`) can only go
	 * on the type the *client* declared, and video is allowed to be far larger
	 * than anything else — because it is copied to storage a chunk at a time
	 * and never held in memory. A file that declared `video/mp4` to get past
	 * that check and then sniffs as a PNG would be read whole by the image
	 * path: two gigabytes of it, into memory, which is the one thing the
	 * split exists to prevent.
	 *
	 * So the ceiling is applied again here, against the sniffed type, which is
	 * the only one that decides what actually happens to the bytes.
	 *
	 * @throws CacheContentSizeException
	 */
	public function filterSize(string $mime, int $size): void {
		$max = $this->sizeLimit($mime);
		if ($size > $max) {
			throw new CacheContentSizeException(
				'content is larger than the ' . (int)($max / 1048576) . 'MB limit for ' . $mime
			);
		}
	}

	/** How many bytes of one kind of file this instance stores, at most. */
	private function sizeLimit(string $mime): int {
		$video = str_starts_with(strtolower($mime), 'video/');
		$megabytes = $video
			? $this->configService->getAppValueInt(ConfigService::SOCIAL_MAX_VIDEO_SIZE)
			: $this->configService->getAppValueInt(ConfigService::SOCIAL_MAX_SIZE);

		if ($megabytes <= 0) {
			$megabytes = $video ? 2048 : 10;
		}

		return $megabytes * 1048576;
	}

	/**
	 * A video or a sound file: copied without ever being held whole, and given
	 * a poster frame if the server can make one.
	 *
	 * Images go the other way on purpose -- they are read into a string because
	 * the metadata stripping, the HEIC conversion and the resize all work on
	 * one -- and an image is a few megabytes. A video is not: `fread()` of a
	 * two-gigabyte upload is two gigabytes of memory, and PHP's limit is the
	 * only thing that ever stopped it. Nothing here needs the bytes, so nothing
	 * here reads them.
	 *
	 * The poster is the *resized copy* of the video rather than a row of its
	 * own. That is what `resized_copy` means -- the small image standing in for
	 * the file -- and unlike a federated video's thumbnail, which is a document
	 * on somebody else's server with a URL and a cache lifetime of its own,
	 * this one is derived from bytes already here and has nothing else to be.
	 */
	private function saveMediaFromTemp(Document $document, string $tmpPath, string $mime): void {
		$document->setMediaType($mime);
		$document->setMimeType($mime);

		$document->setLocalCopy($this->generateFileFromPath($tmpPath));
		$document->setSizeBytes((int)filesize($tmpPath));

		if (!str_starts_with($mime, 'video/')) {
			return;
		}

		// asked while the bytes are still on disk here: an MP4 that is already
		// H.264 is recorded as needing nothing, so neither the post it goes out
		// on nor the transcoder has to copy it back out of storage to find
		// that out — and the post's delivery does not wait for a job that
		// would do nothing
		if ($this->videoTranscodeService->isEnabled()
			&& !$this->videoTranscodeService->needsConversion($mime, $tmpPath)) {
			$document->setTranscoded(VideoTranscodingWorker::NOT_NEEDED);
		}

		$poster = $this->videoThumbnailService->poster($tmpPath);
		if ($poster === null) {
			// no ffmpeg, or nothing it could read. Every reader of a preview
			// already copes with there not being one.
			return;
		}

		$document->setResizedCopy($this->generateFileFromContent($poster['content']));

		if ($poster['duration'] > 0) {
			// what the scrub bar shows before a frame has loaded, and what a
			// federated `Video` states
			$meta = $document->getMeta() ?? new AttachmentMeta();
			$meta->setDuration((float)$poster['duration']);
			$document->setMeta($meta);
		}

		if ($poster['width'] > 0 && $poster['height'] > 0) {
			// the poster is the video scaled down, so its aspect is the
			// video's -- which is what a client sizes the player from before a
			// frame has loaded
			$document->setResizedCopySize($poster['width'], $poster['height']);
			$document->setLocalCopySize($poster['width'], $poster['height']);
		}
	}

	/**
	 * Copies a file into app storage a chunk at a time.
	 *
	 * @return string the uuid it was stored under
	 *
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	/**
	 * Puts a file on disk into app storage and says what it was stored under.
	 *
	 * The public face of `generateFileFromPath()`, for the transcoder: it has
	 * a converted video in a temporary file and needs it where the original
	 * was, and reading the whole thing into a string first is the one thing
	 * that does not work for a video.
	 *
	 * @return string the uuid it was stored under
	 *
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function storeFile(string $path): string {
		return $this->generateFileFromPath($path);
	}

	private function generateFileFromPath(string $path): string {
		$source = fopen($path, 'rb');
		if ($source === false) {
			throw new NotFoundException('could not read the uploaded file');
		}

		try {
			return $this->generateFileFromStream($source);
		} finally {
			fclose($source);
		}
	}

	/**
	 * @param resource $source
	 *
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	private function generateFileFromStream($source): string {
		$filename = $this->uuid();
		$cache = $this->newCacheFile($filename);

		$target = $cache->write();
		if (!is_resource($target)) {
			// a storage backend that cannot be written as a stream still takes
			// the whole thing; only the memory saving is lost
			$cache->putContent(stream_get_contents($source));

			return $filename;
		}

		try {
			stream_copy_to_stream($source, $target);
		} finally {
			fclose($target);
		}

		return $filename;
	}

	/**
	 * @param string $content
	 *
	 * @return string
	 * @throws NotPermittedException
	 * @throws NotFoundException
	 */
	private function generateFileFromContent(string $content): string {
		$filename = $this->uuid();
		$this->newCacheFile($filename)->putContent($content);

		return $filename;
	}

	/**
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	private function newCacheFile(string $filename): ISimpleFile {
		$path = $this->generatePath($filename);

		try {
			$folder = $this->appData->getFolder($path);
		} catch (NotFoundException $e) {
			$folder = $this->appData->newFolder($path);
		}

		return $folder->newFile($filename);
	}

	/**
	 * creating a path aa/bb/cc/dd/ from the filename aabbccdd-0123-[...]
	 *
	 * @param string $filename
	 *
	 * @return string
	 */
	private function generatePath(string $filename): string {
		return chunk_split(substr($filename, 0, 8), 2, '/');
	}

	/**
	 *
	 * @param string $mime
	 *
	 * @throws CacheContentMimeTypeException
	 */
	public function filterMimeTypes(string $mime) {
		$allowedMimeType = [
			'image/jpeg',
			'image/gif',
			'image/png',
			'image/webp',
			// A browser cannot draw these two, but a phone produces them: HEIC
			// is the iPhone camera default. They are accepted here and
			// converted on the way in -- see ImageConversionService. Refusing
			// them meant telling most of an iPhone's owners to convert their
			// own photos before posting.
			'image/avif',
			'image/heic',
			'image/heif',
			'video/mp4',
			'video/webm',
			'video/quicktime',
			'audio/mpeg',
			'audio/mp4',
			'audio/ogg',
			'audio/opus',
			'audio/wav',
			'audio/x-wav',
			'audio/flac',
			'audio/aac',
		];

		if (in_array($mime, $allowedMimeType, true) || in_array($mime, self::DOCUMENT_MIME_TYPES, true)) {
			return;
		}

		throw new CacheContentMimeTypeException();
	}

	/**
	 * The files a post may carry that are not media: what people on a
	 * Nextcloud actually have to share. Stored as they are, served as
	 * downloads, shown as a file card; on the wire an ActivityPub `Document`
	 * with its mime, which Mastodon renders as an `unknown` attachment (a
	 * link) and other Nextclouds render as a file. Nothing a browser would
	 * execute: no HTML, no SVG, no scripts.
	 */
	public const DOCUMENT_MIME_TYPES = [
		'application/pdf',
		'text/plain',
		'text/markdown',
		'text/csv',
		'application/zip',
		'application/epub+zip',
		'application/vnd.oasis.opendocument.text',
		'application/vnd.oasis.opendocument.spreadsheet',
		'application/vnd.oasis.opendocument.presentation',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation',
	];

	/** Whether a mime is one of the file kinds above rather than a picture, a video or a sound. */
	public static function isDocumentMime(string $mime): bool {
		return in_array($mime, self::DOCUMENT_MIME_TYPES, true);
	}

	/**
	 * Builds the preview copy, and refuses to try on content that only claims
	 * to be an image.
	 *
	 * The mime type is sniffed from magic bytes alone, so a PNG header followed
	 * by garbage arrives here looking like a picture. Decoding it fails, and the
	 * failure has to surface as an exception the caller can record against the
	 * document: an unreported failure here used to escape as an `Error` and
	 * abandon caching for every document queued behind this one.
	 *
	 * @throws CacheContentDecodeException
	 */
	private function resizeImage(Document $document, string &$content): void {
		$this->assertDecodable($content);

		try {
			$image = ImageResize::createFromString($content);
		} catch (ImageResizeException $e) {
			throw new CacheContentDecodeException('cannot read image: ' . $e->getMessage());
		} catch (Throwable $e) {
			// the library reaches into GD, which reports some failures as a
			// warning plus a false return rather than as an exception
			throw new CacheContentDecodeException('cannot read image: ' . $e->getMessage());
		}

		try {
			$image->quality_jpg = 80;
			$image->quality_png = 7;
			$image->quality_webp = 80;

			$image->resizeToBestFit(self::RESIZED_WIDTH, self::RESIZED_HEIGHT);
			$newContent = $image->getImageAsString();

			if ($newContent) {
				$content = $newContent;
			}
		} catch (ImageResizeException $e) {
			// the original is still usable as the full copy; only the preview
			// is lost, so keep the sizes we do know and carry on
		}

		$document->setLocalCopySize($image->getSourceWidth(), $image->getSourceHeight());
		$document->setResizedCopySize($image->getDestWidth(), $image->getDestHeight());

		$gd = @imagecreatefromstring($content);
		if ($gd !== false) {
			$document->setBlurHash($this->blurService->generateBlurHash($gd));
		}
	}

	/**
	 * Refuses content that is not the image it claims to be, and anything
	 * whose decode would not fit in memory -- from the header, before a single
	 * pixel is decoded.
	 *
	 * @throws CacheContentDecodeException
	 */
	private function assertDecodable(string $content): void {
		$size = @getimagesizefromstring($content);
		if ($size === false) {
			throw new CacheContentDecodeException('content is not a readable image');
		}

		if (($size[0] ?? 0) < 1 || ($size[1] ?? 0) < 1) {
			throw new CacheContentDecodeException('image has no usable dimensions');
		}

		$this->assertWithinPixelLimit($content);
	}

	/**
	 * The pixel ceiling alone, for the point in the upload path that comes
	 * before the conversion.
	 *
	 * Says nothing about a format whose header PHP cannot read: an HEIC
	 * arrives here as what an iPhone wrote, which `getimagesizefromstring()`
	 * does not know and which is a picture all the same. Whether such a file
	 * is one at all is decided by `assertDecodable()`, after it has been
	 * converted into something that can be drawn.
	 *
	 * @throws CacheContentDecodeException
	 */
	private function assertWithinPixelLimit(string $content): void {
		$size = @getimagesizefromstring($content);
		if ($size === false) {
			return;
		}

		$width = $size[0] ?? 0;
		$height = $size[1] ?? 0;
		if ($width * $height > self::MAX_PIXELS) {
			throw new CacheContentDecodeException(
				'image is too large to decode: ' . $width . 'x' . $height
			);
		}
	}

	/**
	 * Removes a cached copy from appdata; a missing or empty filename is a
	 * no-op — retention must never abort on a file that is already gone.
	 *
	 * The three sentinels are not filenames and never were: `avatar` and
	 * `header` are served from Nextcloud's own avatar, and a streamed document
	 * is a pointer at bytes on another server (`Document::COPY_STREAMED`).
	 * Same list `cachedFileSize()` keeps, for the same reason.
	 */
	public function removeFromCache(string $filename): void {
		if ($filename === '' || $filename === 'avatar' || $filename === 'header'
			|| $filename === Document::COPY_STREAMED) {
			return;
		}

		try {
			$this->appData->getFolder($this->generatePath($filename))
				->getFile($filename)
				->delete();
		} catch (Exception $e) {
		}
	}

	/**
	 * How many bytes one cached copy occupies in appdata, or null when there
	 * is no file behind the name: an empty name, the `avatar`/`header`
	 * placeholders of a local account (served from Nextcloud's own avatar,
	 * never copied), a streamed document (`Document::COPY_STREAMED`, a pointer
	 * at somebody else's bytes) — or a name whose file has gone missing,
	 * which is the case `occ social:media:usage` reports.
	 */
	public function cachedFileSize(string $filename): ?int {
		if ($filename === '' || $filename === 'avatar' || $filename === 'header'
			|| $filename === Document::COPY_STREAMED) {
			return null;
		}

		try {
			return (int)$this->appData->getFolder($this->generatePath($filename))
				->getFile($filename)
				->getSize();
		} catch (Exception $e) {
			return null;
		}
	}

	/**
	 * What a stored copy turns out to be, read back from its first bytes.
	 *
	 * For the rows that were written with no type at all: the file is the only
	 * place the answer still exists. A header's worth is enough for libmagic
	 * and is all that is read -- the file may be a video.
	 *
	 * @return string '' when there is no file, or nothing could be made of it
	 */
	public function sniffStored(string $uuid): string {
		if ($uuid === '' || $uuid === 'avatar' || $uuid === 'header'
			|| $uuid === Document::COPY_STREAMED) {
			return '';
		}

		try {
			$stream = $this->getFromUuid($uuid)->read();
		} catch (Exception $e) {
			return '';
		}

		if (!is_resource($stream)) {
			return '';
		}

		try {
			$head = (string)stream_get_contents($stream, self::SNIFF_BYTES);
		} finally {
			fclose($stream);
		}

		$mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($head);

		return is_string($mime) ? $mime : '';
	}

	public function getContentFromCache(string $filename): ISimpleFile {
		if ($filename === '') {
			throw new CacheDocumentDoesNotExistException();
		}

		// right now, we do not handle cache for local avatar, we need to change this
		// so the current avatar is cached, or a new avatar is uploaded
		if ($filename === 'avatar') {
			throw new CacheContentException();
		}

		try {
			return $this->appData->getFolder($this->generatePath($filename))
				->getFile($filename);
		} catch (Exception $e) {
			throw new CacheContentException();
		}
	}

	public function getFromUuid(string $uuid): ISimpleFile {
		try {
			return $this->appData->getFolder($this->generatePath($uuid))
				->getFile($uuid);
		} catch (NotFoundException $e) {
			throw new NotFoundException('document not found');
		}
	}

	/**
	 * @param string $url
	 *
	 * @return string
	 * @throws MalformedArrayException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 */
	/**
	 * Opens a streamed document's file at its origin, without storing any of it.
	 *
	 * The counterpart of `retrieveContent()` for the one kind of document that
	 * is deliberately never copied here -- see `Document::COPY_STREAMED`. The
	 * client's `Range` is forwarded so that seeking in a long video asks the
	 * origin for the part that was seeked to, and the origin's answer comes
	 * back untouched for the caller to pass on.
	 *
	 * @param string $range the client's own `Range` header, or ''
	 *
	 * @return array{stream: resource, status: int, headers: array<string, string[]>}
	 *
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 */
	public function openRemoteFile(Document $document, string $range = ''): array {
		$url = $document->getUrl();
		$parsed = parse_url($url);
		if (!is_array($parsed)) {
			throw new RequestServerException('unreadable url');
		}

		if (!in_array(strtolower($parsed['scheme'] ?? ''), ['http', 'https'], true)) {
			throw new RequestServerException('unsupported scheme');
		}

		$headers = [];
		// only the shape a media element actually sends, and only that shape:
		// the header is written by whoever is reading and reaches another
		// server as it was written
		if ($range !== '' && preg_match('/^bytes=\d*-\d*(, ?\d*-\d*)*$/', $range) === 1) {
			$headers['Range'] = $range;
		}

		return $this->curlService->openStream($url, $headers, self::referringHost($document));
	}

	/**
	 * A remote file read whole, up to a ceiling.
	 *
	 * For the one thing this app fetches from a streamed document rather than
	 * passing on: an HLS playlist, which is text, is kilobytes, and has to be
	 * rewritten before a player sees it. The ceiling is what stops a server
	 * that answers a `.m3u8` with a film from being read into memory.
	 *
	 * @throws RequestServerException
	 */
	public function readRemoteFile(Document $document, int $max): string {
		$opened = $this->openRemoteFile($document);
		$body = stream_get_contents($opened['stream'], $max + 1);
		fclose($opened['stream']);

		if ($body === false) {
			throw new RequestServerException('could not read the file');
		}

		if (strlen($body) > $max) {
			throw new RequestServerException('that file is larger than a playlist should be');
		}

		return $body;
	}

	public function retrieveContent(string $url): string {
		$this->assertFetchable($url);

		// the url is fetched as it is written: a signed CDN link carries its
		// credentials in the query string, and re-encoding one turned every
		// such attachment into a 403
		return $this->curlService->doRequest('get', $url, ['json_headers' => false]);
	}

	/**
	 * Whether an address is one this app may go and read.
	 *
	 * @throws MalformedArrayException
	 * @throws RequestServerException
	 */
	/**
	 * The instance that named this file, which is not always the one serving
	 * it: a post on `mastodon.xyz` carries pictures on `6-28.mastodon.xyz`,
	 * and object storage or a CDN is common besides. The post's own address is
	 * what says which instance published it; its author's stands in where an
	 * attachment arrived without a parent.
	 */
	private static function referringHost(Document $document): string {
		foreach ([$document->getParentId(), $document->getAccount(), $document->getId()] as $address) {
			$host = parse_url($address, PHP_URL_HOST);
			if (is_string($host) && $host !== '') {
				return strtolower($host);
			}
		}

		return '';
	}

	private function assertFetchable(string $url): void {
		$parsed = parse_url($url);
		if (!is_array($parsed)) {
			throw new RequestServerException('unreadable url');
		}

		$scheme = strtolower($parsed['scheme'] ?? '');
		if (!in_array($scheme, ['http', 'https'], true)) {
			// every other scheme curl or the client could be talked into
			// (file://, gopher://, …) reads something that is not the web
			throw new RequestServerException('unsupported scheme: ' . $scheme);
		}

		$this->mustContains(['path', 'host', 'scheme'], $parsed);
	}
}
