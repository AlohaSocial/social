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
	 * The bounding box of an actor icon's `resized_copy`, in pixels: what the
	 * web UI draws at 64 px and less, at twice that for a high-density screen.
	 * The original stays the `avatar` a Mastodon app is given.
	 */
	public const AVATAR_THUMBNAIL = 128;

	/**
	 * Ceiling on the pixel count of an image this app will decode, read from
	 * the header first: GD needs about four bytes a pixel, so a 400 KB
	 * 30000x30000 PNG wants about 3.6 GB.
	 */
	public const MAX_PIXELS = 50000000; // 50 MP, ~200 MB decoded

	/** How much of a stored file `sniffStored()` reads to recognise it. */
	private const SNIFF_BYTES = 4096;

	/**
	 * The most bytes one ranged request for a streamed file asks its origin
	 * for. The server's client receives a whole answer before it can be
	 * passed on, so a player's open-ended `bytes=0-` is answered with this
	 * much and a `206` naming the rest, and the player asks for what follows.
	 */
	public const STREAM_CHUNK = 8 * 1024 * 1024;

	/**
	 * The names the copies being written take, by role (`local`, `resized`),
	 * while `restoreRemoteCopy()` runs; empty otherwise, and a fresh uuid is
	 * drawn for each copy.
	 *
	 * @var array<string, string>
	 */
	private array $restoring = [];

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
		private ImageMetadataService $imageMetadataService,
	) {
	}

	/**
	 * Makes the poster a video should already have, as `saveMediaFromTemp()`
	 * makes one on upload, for videos stored without one
	 * (`occ social:media:posters`). The stored copy goes to a temporary file a
	 * chunk at a time for ffmpeg; the document is written back only when a
	 * frame came out, so a failure can be tried again.
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
			// persisted here: the copy sizes `convertToMediaAttachment()` reads live
			// only as long as the object, and a backfilled poster must outlast the
			// command
			$dimensions = new AttachmentMetaDim([$poster['width'], $poster['height']]);
			$meta->setOriginal($dimensions);
			$meta->setSmall($dimensions);
			$document->setResizedCopySize($poster['width'], $poster['height']);
			$document->setLocalCopySize($poster['width'], $poster['height']);
		}
		$document->setMeta($meta);

		return true;
	}

	/** the largest cover image taken for a video, in bytes */
	public const MAX_POSTER_BYTES = 10 * 1024 * 1024;

	/**
	 * Uses an image the uploader chose as a video's poster instead of
	 * ffmpeg's frame: Mastodon's `thumbnail` on a media upload, and a short's
	 * cover. It is decoded and resized like any uploaded image into a new JPEG,
	 * so no camera metadata survives and an unreadable one is refused; the
	 * poster it replaces is removed.
	 *
	 * @param Document $document a video that has been stored
	 * @param string $imagePath the uploaded image
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
	 * A file on somebody else's server, fetched to a temporary file and then
	 * stored exactly as an upload is, through `saveFromTempToCache()`, so the
	 * refused-files list, the HEIC conversion and the right size ceiling apply
	 * to federated media too.
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
	 * Fetches a cached remote file again, into the names its row already has,
	 * for a copy the media cache purge removed (`MediaPurgeService::purgeCache()`)
	 * or that went missing: clients hold `/media/{uuid}` links to those names.
	 * A copy the new fetch does not produce stays missing; one the row had no
	 * name for gets a fresh one.
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
	public function restoreRemoteCopy(Document $document): void {
		$this->restoring = array_filter(
			['local' => $document->getLocalCopy(), 'resized' => $document->getResizedCopy()],
			static fn (string $name): bool => Document::isStoredName($name)
		);

		try {
			$this->saveRemoteFileToCache($document);
		} finally {
			$this->restoring = [];
		}
	}

	/**
	 * The remote file, on disk, a chunk at a time, with the ceiling for what
	 * the document says it is (a video's, not an ActivityPub document's).
	 * `filterSize()` checks again against the sniffed type.
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
		$max = $this->downloadPlan($document)['max'];
		$opened = $this->openRemoteFile($document, '', $max);
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
	 * How the file of a remote document is to be fetched: its address, checked
	 * to be one this app may read, the ceiling `downloadToTemp()` holds it to,
	 * and the instance it is fetched on behalf of. For a caller that fetches
	 * several at once (`CurlService::downloadMany()`) and hands each file to
	 * `saveFromTempToCache()`, the second half of `saveRemoteFileToCache()`.
	 *
	 * @return array{url: string, max: int, on_behalf_of: string}
	 *
	 * @throws MalformedArrayException
	 * @throws RequestServerException
	 */
	public function downloadPlan(Document $document): array {
		$this->assertFetchable($document->getUrl());

		return [
			'url' => $document->getUrl(),
			'max' => $this->sizeLimit($document->getMediaType()),
			'on_behalf_of' => self::referringHost($document),
		];
	}

	/**
	 * Stores a file byte for byte for ATProto uploads and repository moves,
	 * whose records name the hash of exactly these bytes. The type, size and
	 * quota checks of any upload apply; nothing is stripped or converted.
	 *
	 * @throws CacheContentMimeTypeException
	 * @throws CacheContentSizeException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function saveAsIsFromTemp(Document $document, string $tmpPath): void {
		$mime = (string)mime_content_type($tmpPath);
		$this->filterMimeTypes($mime);
		$size = (int)filesize($tmpPath);
		$this->filterSize($mime, $size);
		$this->filterQuota($document, $mime, $size);
		$this->filterBlockedMedia($tmpPath);

		$document->setMediaType($mime);
		$document->setMimeType($mime);
		$document->setLocalCopy($this->generateFileFromPath($tmpPath, $this->restoring['local'] ?? ''));
		$document->setSizeBytes($size);
		$this->recordDomainQuota($document, $size);
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

		// First, because everything below decodes: an Exif-rotated photo is
		// turned by decoding all of it, and an oversized image is a fatal error
		// rather than a refusal.
		$this->assertWithinPixelLimit($content);

		// The provenance the image states is read off the bytes as they arrived,
		// as the XMP it is in is removed by the conversion below; the row is the
		// only place it survives.
		$document->setAiSource(
			Document::aiSourceFor($this->imageMetadataService->digitalSourceType($content, $mime))
		);

		// Then the camera's metadata comes off, and a format no browser can
		// draw becomes one it can. Both can change the mime, so the document is
		// told afterwards rather than before.
		[$content, $mime] = $this->imageConversionService->prepareForStorage($content, $mime);

		$document->setMediaType($mime);
		$document->setMimeType($mime);

		$filename = $this->generateFileFromContent($content, $this->restoring['local'] ?? '');
		$document->setLocalCopy($filename);
		// what the stored file weighs, recorded once: PeerTube drops a video
		// link without `size`, and the per-account quota asks the same
		$document->setSizeBytes(strlen($content));

		$this->resizeImage($document, $content);
		$resized = $this->generateFileFromContent($content, $this->restoring['resized'] ?? '');
		$document->setResizedCopy($resized);
		$this->recordDomainQuota($document, $size);
	}

	/**
	 * The per-account video quota, applied before anything is written, to
	 * local uploads only: a remote attachment cached here must not count
	 * against the person who follows its author.
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
	 * How much of this instance's disk the server an image came from may
	 * take, so one server posting large images cannot fill the disk of every
	 * instance following it. Refused before the bytes are written; what is
	 * written is counted against the host.
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
	 * its cached copies are written, so invalid media cannot use up the quota.
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
	 * An image a moderator has refused, wherever it came from, checked on the
	 * bytes as written, the one place uploads and fetched attachments both
	 * pass. The hash is of the file as it arrived, so it matches "this exact
	 * file, again", not a similar image.
	 *
	 * @throws CacheContentMimeTypeException the same refusal an unacceptable
	 *                                       type gets, so callers catch one
	 *                                       exception
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
	 * The size ceiling, applied to the sniffed type: the request-time check
	 * (`MediaApiController::refuseOversized()`) only knows the declared type,
	 * and a file declared `video/mp4` that sniffs as a PNG would otherwise be
	 * read whole by the image path.
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

	/** The most bytes this instance stores of any one file, whatever its kind. */
	public function largestFileBytes(): int {
		return max($this->sizeLimit('video/'), $this->sizeLimit(''));
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
	 * A video or a sound file: copied without ever being held whole, without
	 * the metadata its container carries, and given a poster frame if the
	 * server can make one. The poster is the video's `resized_copy`, the small
	 * image standing in for the file.
	 */
	private function saveMediaFromTemp(Document $document, string $tmpPath, string $mime): void {
		$stripped = $this->videoTranscodeService->withoutMetadata($tmpPath, $mime);
		try {
			$this->saveMediaFileFromTemp($document, $stripped ?? $tmpPath, $mime);
		} finally {
			if ($stripped !== null) {
				@unlink($stripped);
			}
		}
	}

	/**
	 * `saveMediaFromTemp()` for the file that is kept: the upload rewritten
	 * without its container metadata where ffmpeg could do that, the upload
	 * itself where it could not.
	 */
	private function saveMediaFileFromTemp(Document $document, string $tmpPath, string $mime): void {
		$document->setMediaType($mime);
		$document->setMimeType($mime);

		$document->setLocalCopy($this->generateFileFromPath($tmpPath, $this->restoring['local'] ?? ''));
		$document->setSizeBytes((int)filesize($tmpPath));

		if (!str_starts_with($mime, 'video/')) {
			return;
		}

		// asked while the bytes are still on disk: an MP4 already in H.264 is
		// recorded as needing nothing, so neither delivery nor the transcoder
		// has to read it back from storage
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

		$document->setResizedCopy(
			$this->generateFileFromContent($poster['content'], $this->restoring['resized'] ?? '')
		);

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
	 * Puts a file on disk into app storage and says what it was stored under:
	 * `generateFileFromPath()` for the transcoder, whose converted video must
	 * not be read into a string.
	 *
	 * @return string the uuid it was stored under
	 *
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function storeFile(string $path): string {
		return $this->generateFileFromPath($path);
	}

	private function generateFileFromPath(string $path, string $name = ''): string {
		$source = fopen($path, 'rb');
		if ($source === false) {
			throw new NotFoundException('could not read the uploaded file');
		}

		try {
			return $this->generateFileFromStream($source, $name);
		} finally {
			fclose($source);
		}
	}

	/**
	 * @param resource $source
	 * @param string $name the name to store under; a fresh uuid when ''
	 *
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	private function generateFileFromStream($source, string $name = ''): string {
		$filename = ($name !== '') ? $name : $this->uuid();
		$cache = $this->newCacheFile($filename, $name !== '');

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
	private function generateFileFromContent(string $content, string $name = ''): string {
		$filename = ($name !== '') ? $name : $this->uuid();
		$this->newCacheFile($filename, $name !== '')->putContent($content);

		return $filename;
	}

	/**
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	private function newCacheFile(string $filename, bool $reused = false): ISimpleFile {
		$path = $this->generatePath($filename);

		try {
			$folder = $this->appData->getFolder($path);
		} catch (NotFoundException $e) {
			$folder = $this->appData->newFolder($path);
		}

		// a restored copy reuses its name, and may find the file it replaces
		// still there when only the other copy of the pair had gone
		if ($reused && $folder->fileExists($filename)) {
			$folder->getFile($filename)->delete();
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
			// A browser cannot draw these two, but phones produce them (HEIC is the
			// iPhone camera default), so they are converted on the way in; see
			// ImageConversionService.
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
	 * The files a post may carry that are not media. Stored as they are,
	 * served as downloads, shown as a file card; on the wire a `Document`
	 * with its mime, which Mastodon shows as an `unknown` attachment. Nothing
	 * a browser would execute: no HTML, no SVG, no scripts.
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

	/** Whether a mime is one of the file kinds above rather than an image, a video or a sound. */
	public static function isDocumentMime(string $mime): bool {
		return in_array($mime, self::DOCUMENT_MIME_TYPES, true);
	}

	/**
	 * Builds the preview copy, and refuses content that only claims to be an
	 * image: the type is sniffed from magic bytes alone, and a failed decode
	 * must surface as an exception the caller records against the document,
	 * not an `Error` that stops caching for the documents queued behind it.
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

		$icon = $document->isActorIcon();
		try {
			$image->quality_jpg = 80;
			$image->quality_png = 7;
			$image->quality_webp = 80;

			if ($icon) {
				$image->resizeToBestFit(self::AVATAR_THUMBNAIL, self::AVATAR_THUMBNAIL);
				$newContent = $image->getImageAsString(self::thumbnailType());
			} else {
				$image->resizeToBestFit(self::RESIZED_WIDTH, self::RESIZED_HEIGHT);
				$newContent = $image->getImageAsString();
			}

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
	 * What an icon thumbnail is written as: WebP where this PHP's GD can
	 * write it, which keeps a transparent avatar transparent at a fraction of
	 * a PNG's weight; the original's own format otherwise.
	 */
	private static function thumbnailType(): ?int {
		return (function_exists('imagewebp') && (imagetypes() & IMG_WEBP) !== 0) ? IMAGETYPE_WEBP : null;
	}

	/**
	 * Makes a cached actor icon's `resized_copy` the small thumbnail a new icon
	 * is stored with, from the original on disk, for icons whose resized copy
	 * is the 800 px preview. Written under the copy's existing name, so links
	 * keep working; one already no larger is left alone.
	 *
	 * @return bool whether a thumbnail was written
	 *
	 * @throws CacheContentDecodeException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function rebuildIconThumbnail(Document $document): bool {
		if (!Document::isStoredName($document->getLocalCopy())
			|| !str_starts_with($document->getMediaType(), 'image/')) {
			return false;
		}

		$resized = $document->getResizedCopy();
		if ($resized === $document->getLocalCopy() || !Document::isStoredName($resized)) {
			$resized = '';
		}

		if ($resized !== '' && $this->isThumbnail($resized)) {
			return false;
		}

		$content = $this->getFromUuid($document->getLocalCopy())->getContent();
		$document->setActorIcon(true);
		$this->resizeImage($document, $content);
		$document->setResizedCopy($this->generateFileFromContent($content, $resized));

		return true;
	}

	/** Whether a stored copy already fits the icon thumbnail's box. */
	private function isThumbnail(string $uuid): bool {
		try {
			$size = @getimagesizefromstring($this->getFromUuid($uuid)->getContent());
		} catch (Exception $e) {
			return false;
		}

		return $size !== false
			&& $size[0] <= self::AVATAR_THUMBNAIL && $size[1] <= self::AVATAR_THUMBNAIL;
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
	 * The pixel ceiling alone, before the conversion in the upload path. A
	 * format PHP cannot read the header of, such as HEIC, passes; whether it is
	 * an image at all is decided by `assertDecodable()` after conversion.
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
	 * A name that is not a stored file (`Document::isStoredName()`) is never looked up.
	 */
	public function removeFromCache(string $filename): void {
		if (!Document::isStoredName($filename)) {
			return;
		}

		try {
			$folder = $this->appData->getFolder($this->generatePath($filename));
		} catch (Exception $e) {
			return;
		}

		try {
			$folder->getFile($filename)->delete();
		} catch (Exception $e) {
		}

		// The innermost `aa/bb/cc/dd` folder holds only this file, so it goes
		// too; the levels above are kept, as a listing here shows files only
		// and cannot tell whether they are empty.
		try {
			if ($folder->getDirectoryListing() === []) {
				$folder->delete();
			}
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
		if (!Document::isStoredName($filename)) {
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
	 * What a stored copy turns out to be, read from its first bytes, for rows
	 * written without a type. Only a header's worth is read, as the file may
	 * be a video.
	 *
	 * @return string '' when there is no file, or nothing could be made of it
	 */
	public function sniffStored(string $uuid): string {
		if (!Document::isStoredName($uuid)) {
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
		if ($filename === Document::COPY_LOCAL_AVATAR) {
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
	 * Opens a streamed document's file at its origin, without storing any of
	 * it (`Document::COPY_STREAMED`). The client's `Range` is forwarded,
	 * shortened to `STREAM_CHUNK` bytes, so seeking asks the origin for that
	 * part, and the answer comes back untouched for the caller to pass on.
	 *
	 * @param string $range the client's own `Range` header, or ''
	 * @param int|null $max the most bytes taken without a range, the download
	 *                      ceiling when null
	 * @param bool $sameOrigin whether a redirect may only lead to the file's
	 *                         own scheme, host and port
	 *
	 * @return array{stream: resource, status: int, headers: array<string, string[]>}
	 *
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 */
	public function openRemoteFile(Document $document, string $range = '', ?int $max = null, bool $sameOrigin = false): array {
		$url = $document->getUrl();
		$parsed = parse_url($url);
		if (!is_array($parsed)) {
			throw new RequestServerException('unreadable url');
		}

		if (!in_array(strtolower($parsed['scheme'] ?? ''), ['http', 'https'], true)) {
			throw new RequestServerException('unsupported scheme');
		}

		$headers = [];
		$range = self::boundedRange($range);
		if ($range !== '') {
			$headers['Range'] = $range;
			$max = self::STREAM_CHUNK;
		}

		return $this->curlService->openStream(
			$url, $headers, self::referringHost($document), max: $max, sameOrigin: $sameOrigin
		);
	}

	/**
	 * A client's `Range` as it is sent on: one range, of the shape a media
	 * element sends, at most `STREAM_CHUNK` bytes long; '' for anything else,
	 * which asks for the whole file. The header is written by whoever is
	 * reading and reaches another server.
	 */
	public static function boundedRange(string $range): string {
		if (preg_match('/^bytes=(\d{0,18})-(\d{0,18})$/', trim($range), $m) !== 1 || ($m[1] === '' && $m[2] === '')) {
			return '';
		}

		if ($m[1] === '') {
			return 'bytes=-' . min((int)$m[2], self::STREAM_CHUNK);
		}

		$start = (int)$m[1];
		$last = $start + self::STREAM_CHUNK - 1;
		if ($m[2] !== '') {
			if ((int)$m[2] < $start) {
				return '';
			}
			$last = min((int)$m[2], $last);
		}

		return 'bytes=' . $start . '-' . $last;
	}

	/**
	 * A remote file read whole, up to a ceiling: an HLS playlist of a
	 * streamed document, which has to be rewritten before a player sees it.
	 *
	 * @throws RequestServerException
	 */
	public function readRemoteFile(Document $document, int $max): string {
		$opened = $this->openRemoteFile($document, '', $max);
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
	 * it (a media subdomain, object storage, a CDN): the post's own address,
	 * or its author's for an attachment without a parent.
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
