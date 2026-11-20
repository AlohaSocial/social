<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Object;

use DateTime;
use Exception;
use JsonSerializable;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\AttachmentMeta;
use OCA\Social\Model\Client\AttachmentMetaDim;
use OCA\Social\Model\Client\AttachmentMetaFocus;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\ImageMetadataService;
use OCP\IURLGenerator;

/**
 * Class Document
 *
 * @package OCA\Social\Model\ActivityPub
 */
class Document extends ACore implements JsonSerializable {
	public const TYPE = 'Document';

	/**
	 * What stands in `localCopy` for a file this instance never mirrors and
	 * streams from its origin instead, such as a federated video. The row still
	 * exists so `/media/stream` can only proxy urls that arrived in an activity.
	 * A sentinel in `local_copy` because the caching cron
	 * (`getNotCachedDocuments()`) only picks up rows where it is empty.
	 */
	public const COPY_STREAMED = 'stream';
	/** the local copy of a local account's avatar: Nextcloud's avatar, at the document's own address */
	public const COPY_LOCAL_AVATAR = 'avatar';

	private string $account = '';
	private string $mediaType = '';
	private float $focusX = 0;
	private float $focusY = 0;
	private string $mimeType = '';
	private string $localCopy = '';
	private string $resizedCopy = '';
	private string $blurHash = '';
	private ?AttachmentMeta $meta = null;
	private string $description = '';
	private int $caching = 0;
	private bool $public = false;
	private int $error = 0;
	private string $parentId = '';
	/** how many bytes the stored file is; 0 for one nothing measured */
	private int $sizeBytes = 0;

	/**
	 * Whether this video has been written at a ladder of smaller sizes (the four
	 * states of `social_cache_doc.laddered`), carried to avoid a query per
	 * attachment.
	 */
	private int $laddered = 0;

	/**
	 * Whether this video has been through the transcoder: the four states of
	 * `social_cache_doc.transcoded` (`VideoTranscodingWorker::NOT_LOOKED` …), so
	 * a new post can tell whether its delivery waits for a conversion.
	 */
	private int $transcoded = 0;

	/** `social_cache_doc.ai_source`: nothing stated, or one of the two IPTC terms. */
	public const AI_SOURCE_NONE = 0;
	public const AI_SOURCE_TRAINED = 1;
	public const AI_SOURCE_COMPOSITE = 2;

	/**
	 * The machine-generation provenance the photo's metadata stated, read before
	 * the metadata was stripped.
	 */
	private int $aiSource = self::AI_SOURCE_NONE;
	private array $localCopySize = [0, 0];
	private array $resizedCopySize = [0, 0];

	/**
	 * Whether this document is the icon of a cached actor, making its
	 * `resized_copy` a `CacheDocumentService::AVATAR_THUMBNAIL` thumbnail rather
	 * than an 800 px preview. Not stored: set by the caller that knows.
	 */
	private bool $actorIcon = false;

	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}

	public function setAccount(string $account): self {
		$this->account = $account;

		return $this;
	}

	public function getAccount(): string {
		return $this->account;
	}

	/**
	 * Whether a `local_copy` or `resized_copy` value names a file in appdata:
	 * not '', and not a sentinel (`avatar`, `header`, `COPY_STREAMED`).
	 */
	public static function isStoredName(string $name): bool {
		return $name !== '' && $name !== self::COPY_LOCAL_AVATAR && $name !== 'header'
			&& $name !== self::COPY_STREAMED;
	}

	public function setActorIcon(bool $actorIcon): self {
		$this->actorIcon = $actorIcon;

		return $this;
	}

	/** Whether this is the icon of an actor; see `$actorIcon`. */
	public function isActorIcon(): bool {
		return $this->actorIcon || (!$this->isRoot() && $this->getParent() instanceof Person);
	}

	/**
	 * Whether the file was uploaded here by a local account rather than cached
	 * from another server; only upload paths write `account`.
	 */
	public function isLocalUpload(): bool {
		return $this->account !== '';
	}

	/**
	 * @return string
	 */
	public function getMediaType(): string {
		return $this->mediaType;
	}

	/**
	 * @param string $mediaType
	 *
	 * @return ACore
	 */
	/**
	 * Where the subject of the photo is, as a fraction from the centre to each
	 * edge: -1 is the left or the bottom, 1 the right or the top, 0 the middle.
	 * Mastodon's `focus` in its API and `focalPoint` on the wire.
	 */
	public function getFocusX(): float {
		return $this->focusX;
	}

	public function getFocusY(): float {
		return $this->focusY;
	}

	public function hasFocus(): bool {
		return $this->focusX !== 0.0 || $this->focusY !== 0.0;
	}

	/** Both values are clamped to -1..1. */
	public function setFocus(float $x, float $y): self {
		$this->focusX = max(-1.0, min(1.0, $x));
		$this->focusY = max(-1.0, min(1.0, $y));

		return $this;
	}

	/**
	 * The `x,y` pair a Mastodon client sends as one string, or null when it
	 * sent something that is not one.
	 *
	 * @return array{0: float, 1: float}|null
	 */
	public static function parseFocus(string $focus): ?array {
		$parts = explode(',', $focus);
		if (count($parts) !== 2) {
			return null;
		}

		if (!is_numeric(trim($parts[0])) || !is_numeric(trim($parts[1]))) {
			return null;
		}

		return [(float)trim($parts[0]), (float)trim($parts[1])];
	}

	public function setMediaType(string $mediaType): ACore {
		$this->mediaType = $mediaType;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getMimeType(): string {
		return $this->mimeType;
	}

	/**
	 * @param string $mimeType
	 *
	 * @return ACore
	 */
	public function setMimeType(string $mimeType): ACore {
		$this->mimeType = $mimeType;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getLocalCopy(): string {
		return $this->localCopy;
	}

	/**
	 * @param string $localCopy
	 *
	 * @return Document
	 */
	/**
	 * Whether this is a pointer at a file on another server (`COPY_STREAMED`);
	 * it has no bytes here, so it is never served from disk or queued for
	 * download.
	 */
	public function isStreamed(): bool {
		return $this->localCopy === self::COPY_STREAMED;
	}

	public function setLocalCopy(string $localCopy): self {
		$this->localCopy = $localCopy;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getResizedCopy(): string {
		return $this->resizedCopy;
	}

	/**
	 * @param string $resizedCopy
	 *
	 * @return Document
	 */
	public function setResizedCopy(string $resizedCopy): self {
		$this->resizedCopy = $resizedCopy;

		return $this;
	}

	/**
	 * How many bytes the stored file is, or 0 where nothing measured it (a
	 * streamed file, or one stored before the column existed). Recorded because
	 * PeerTube drops a video file link without `size`.
	 */
	public function getSizeBytes(): int {
		return $this->sizeBytes;
	}

	public function setSizeBytes(int $sizeBytes): self {
		$this->sizeBytes = max(0, $sizeBytes);

		return $this;
	}

	public function setLocalCopySize(int $width, int $height): self {
		$this->localCopySize = [$width, $height];

		return $this;
	}

	public function getLocalCopySize(): array {
		return $this->localCopySize;
	}

	public function setResizedCopySize(int $width, int $height): void {
		$this->resizedCopySize = [$width, $height];
	}

	public function getResizedCopySize(): array {
		return $this->resizedCopySize;
	}

	public function setBlurHash(string $blurHash): self {
		$this->blurHash = $blurHash;

		return $this;
	}

	public function getBlurHash(): string {
		return $this->blurHash;
	}

	/** Null clears it, so the next read rebuilds it from the document. */
	public function setMeta(?AttachmentMeta $meta): self {
		$this->meta = $meta;

		return $this;
	}

	public function getMeta(): ?AttachmentMeta {
		return $this->meta;
	}

	public function setDescription(string $description): self {
		$this->description = $description;

		return $this;
	}

	public function getDescription(): string {
		return $this->description;
	}

	/**
	 * @return bool
	 */
	#[\Override]
	public function isPublic(): bool {
		return $this->public;
	}

	/**
	 * @param bool $public
	 *
	 * @return Document
	 */
	public function setPublic(bool $public): self {
		$this->public = $public;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getParentId(): string {
		return $this->parentId;
	}

	/**
	 * @param string $parentId
	 *
	 * @return Document
	 */
	public function setParentId(string $parentId): self {
		$this->parentId = $parentId;

		return $this;
	}

	/**
	 * @return int
	 */
	public function getError(): int {
		return $this->error;
	}

	/**
	 * @param int $error
	 *
	 * @return Document
	 */
	public function setError(int $error): self {
		$this->error = $error;

		return $this;
	}

	/**
	 * @return int
	 */
	public function getCaching(): int {
		return $this->caching;
	}

	/**
	 * @param int $caching
	 *
	 * @return Document
	 */
	public function setCaching(int $caching): self {
		$this->caching = $caching;

		return $this;
	}

	/**
	 * @param array $data
	 *
	 * @throws UrlCloudException
	 * @throws InvalidOriginException
	 */
	#[\Override]
	public function import(array $data) {
		parent::import($data);

		$this->setMediaType($this->validate(ACore::AS_STRING, 'mediaType', $data, ''));

		// Mastodon and Pixelfed send `focalPoint: [x, y]`, a two-element array.
		$focalPoint = $this->getArray('focalPoint', $data);
		if (count($focalPoint) === 2 && is_numeric($focalPoint[0]) && is_numeric($focalPoint[1])) {
			$this->setFocus((float)$focalPoint[0], (float)$focalPoint[1]);
		}
		// On the wire an attachment's alt text is its `name`.
		if ($this->getDescription() === '') {
			$this->setDescription($this->validate(ACore::AS_STRING, 'name', $data, ''));
		}

		// An attachment without an id gets one, so it can be cached and addressed.
		// An existing id is not held to the activity's origin: it is never fetched,
		// only `url` is, and CurlService checks that.
		if ($this->getId() === '') {
			$this->generateUniqueId('/documents/g');
		}
	}

	/**
	 * @param array $data
	 */
	#[\Override]
	public function importFromDatabase(array $data) {
		parent::importFromDatabase($data);

		$this->setAccount($this->get('account', $data));
		$this->setPublic(($this->getInt('public', $data, 0) === 1));
		$this->setError($this->getInt('error', $data, 0));
		$this->setLocalCopy($this->get('local_copy', $data, ''));
		$this->setResizedCopy($this->get('resized_copy', $data, ''));
		$this->setBlurHash($this->get('blurhash', $data, ''));
		$this->setSizeBytes($this->getInt('size', $data, 0));
		$this->setLaddered($this->getInt('laddered', $data, 0));
		$this->setTranscoded($this->getInt('transcoded', $data, 0));
		$this->setAiSource($this->getInt('ai_source', $data, self::AI_SOURCE_NONE));
		$this->setDescription($this->get('description', $data, ''));
		$this->setMediaType($this->get('media_type', $data, ''));
		$this->setMimeType($this->get('mime_type', $data, ''));
		$this->setParentId($this->get('parent_id', $data, ''));

		if ($this->get('caching', $data, '') === '') {
			$this->setCaching(0);
		} else {
			try {
				$date = new DateTime($this->get('caching', $data, ''));
				$this->setCaching($date->getTimestamp());
			} catch (Exception $e) {
			}
		}

		// The focal point lives in the stored `meta` blob: nothing queries it.
		$meta = $this->getArray('meta', $data);
		if ($meta !== []) {
			$this->setFocus(
				(float)($meta['focus']['x'] ?? 0),
				(float)($meta['focus']['y'] ?? 0)
			);
		}

		if ($this->get('meta', $data) !== '') {
			$meta = new AttachmentMeta();
			$meta->import($this->getArray('meta', $data));
			$this->setMeta($meta);
		}
	}

	/**
	 * @return array
	 */
	#[\Override]
	public function jsonSerialize(): array {
		$result = array_merge(
			parent::jsonSerialize(),
			[
				'mediaType' => $this->getMediaType(),
				'mimeType' => $this->getMimeType(),
				'localCopy' => $this->getLocalCopy(),
				'resizedCopy' => $this->getResizedCopy()
			]
		);

		if ($this->isCompleteDetails()) {
			$result['parentId'] = $this->getParentId();
		}

		return $result;
	}

	/**
	 * Where this instance serves its copy of the document, or '' while it has
	 * none (still being fetched, or refused): the route cannot be built with an
	 * empty uuid.
	 */
	public function getMediaUrl(IURLGenerator $urlGenerator, string $mime = ''): string {
		if ($this->getLocalCopy() === '') {
			return '';
		}
		if ($this->getLocalCopy() === self::COPY_LOCAL_AVATAR) {
			return $this->getUrl();
		}

		$ext = '';
		if ($mime !== '') {
			$parts = explode('/', $mime, 2);
			$ext = '.' . end($parts);
		}

		return $urlGenerator->linkToRouteAbsolute(
			'social.MediaApi.mediaOpen',
			['uuid' => $this->getLocalCopy() . $ext]
		);
	}

	/**
	 * The still to show before the file is drawn or played: the resized copy of
	 * an image, or a video's JPEG poster frame (linked as `.jpeg`, since
	 * `nosniff` refuses an image served as `video/mp4`). A video without a poster
	 * falls back to the media itself.
	 */
	private function previewUrl(IURLGenerator $urlGenerator, string $mime): string {
		if ($this->getResizedCopy() === '') {
			return $this->getMediaUrl($urlGenerator, $mime);
		}

		if (str_starts_with($this->getMediaType(), 'image/')) {
			return $this->getResizedMediaUrl($urlGenerator, $mime);
		}

		return $this->getResizedMediaUrl($urlGenerator, 'image/jpeg');
	}

	/**
	 * Where a streamed document is played from: this instance, proxying the
	 * origin, addressed by the cache row's key so the proxy cannot be pointed
	 * anywhere else.
	 */
	public function streamUrl(IURLGenerator $urlGenerator): string {
		// A playlist is served with every address in it pointed back through here.
		return $urlGenerator->linkToRouteAbsolute(
			DocumentService::isPlaylist($this->getMediaType()) ? 'social.MediaApi.mediaPlaylist' : 'social.MediaApi.mediaStream',
			['nid' => (string)$this->getNid()]
		);
	}

	public function getAiSource(): int {
		return $this->aiSource;
	}

	public function setAiSource(int $aiSource): self {
		$this->aiSource = $aiSource;

		return $this;
	}

	/** Whether the photo said a model produced it, in whole or in part. */
	public function isAiGenerated(): bool {
		return $this->aiSource > self::AI_SOURCE_NONE;
	}

	/** The `ai_source` value for what `ImageMetadataService::digitalSourceType()` read. */
	public static function aiSourceFor(string $digitalSourceType): int {
		return match ($digitalSourceType) {
			ImageMetadataService::SOURCE_TRAINED => self::AI_SOURCE_TRAINED,
			ImageMetadataService::SOURCE_COMPOSITE => self::AI_SOURCE_COMPOSITE,
			default => self::AI_SOURCE_NONE,
		};
	}

	public function getLaddered(): int {
		return $this->laddered;
	}

	public function setLaddered(int $laddered): self {
		$this->laddered = $laddered;

		return $this;
	}

	public function getTranscoded(): int {
		return $this->transcoded;
	}

	public function setTranscoded(int $transcoded): self {
		$this->transcoded = $transcoded;

		return $this;
	}

	/** Whether there is a ladder to offer a player. */
	public function hasLadder(): bool {
		return $this->laddered === 1 && $this->getLocalCopy() !== '';
	}

	/**
	 * The master playlist of this video's ladder, by uuid like `getMediaUrl()`,
	 * so it is no easier to reach than the video.
	 */
	public function ladderUrl(IURLGenerator $urlGenerator): string {
		return $urlGenerator->linkToRouteAbsolute(
			'social.MediaApi.mediaLadder',
			['uuid' => $this->getLocalCopy()]
		);
	}

	public function getResizedMediaUrl(IURLGenerator $urlGenerator, string $mime = ''): string {
		$ext = '';
		if ($mime !== '') {
			$parts = explode('/', $mime, 2);
			$ext = '.' . end($parts);
		}

		return $urlGenerator->linkToRouteAbsolute(
			'social.MediaApi.mediaOpen',
			['uuid' => $this->getResizedCopy() . $ext]
		);
	}

	/**
	 * @param IURLGenerator|null $urlGenerator
	 *
	 * @return MediaAttachment
	 */
	public function convertToMediaAttachment(
		?IURLGenerator $urlGenerator = null,
		int $exportFormat = self::FORMAT_LOCAL,
	): MediaAttachment {
		$media = new MediaAttachment();
		$media->setId((string)$this->getNid())
			->setExportFormat($exportFormat);

		$mime = '';
		if (strpos($this->getMediaType(), '/')) {
			[$type, $mime] = explode('/', $this->getMediaType(), 2);
			// Mastodon's four kinds, and `unknown` for everything else. HLS playlists
			// are application/* too, but must go to the video player, not the file card.
			$media->setType(DocumentService::isPlaylist($this->getMediaType())
				? 'video'
				: (in_array($type, ['image', 'video', 'audio', 'gifv'], true) ? $type : 'unknown'));
		}

		// The full type: the ActivityPub Document has to state it.
		$media->setMediaType($this->getMediaType());
		// For the wire: PeerTube drops a file link without a size.
		$media->setSizeBytes($this->getSizeBytes());

		if (!is_null($urlGenerator)) {
			// A refused copy gets no url, as in Mastodon, preview included;
			// `remote_url` and `cache_error` say where it came from and why. The origin
			// is not offered as a fallback (see MediaAttachment::asLocal()).
			if (!$this->isStreamed() && $this->getLocalCopy() === '') {
				$media->setUrl('')
					->setPreviewUrl('');
			} elseif ($this->isStreamed()) {
				// No local copy: streamed from the origin by a route addressed by the row.
				// A video's still is a document of its own, set by the caller that knows
				// (see PeerTubeService).
				$streamUrl = $this->streamUrl($urlGenerator);
				$media->setUrl($streamUrl);
				if (str_starts_with($this->getMediaType(), 'image/')) {
					$media->setPreviewUrl($streamUrl);
				}
			} else {
				$media->setUrl($this->getMediaUrl($urlGenerator, $mime));
				if ($this->hasLadder()) {
					$media->setHlsUrl($this->ladderUrl($urlGenerator));
				}
				// A file is not its own preview: a client would try to draw a PDF as an image.
				if ($media->getType() !== 'unknown') {
					$media->setPreviewUrl($this->previewUrl($urlGenerator, $mime));
				}
			}
		}

		$media->setRemoteUrl($this->getUrl())
			->setCacheError($this->getError());

		// Each half is filled in only where missing: a video may already carry its
		// duration before its dimensions are known.
		$meta = $this->getMeta() ?? new AttachmentMeta();
		if ($meta->getOriginal() === null) {
			$meta->setOriginal(new AttachmentMetaDim($this->getLocalCopySize()));
		}
		if ($meta->getSmall() === null) {
			$meta->setSmall(new AttachmentMetaDim($this->getResizedCopySize()));
		}
		if ($meta->getFocus() === null) {
			$meta->setFocus(new AttachmentMetaFocus($this->getFocusX(), $this->getFocusY()));
		}

		$this->setMeta($meta);

		$media->setMeta($this->getMeta())
			->setDescription($this->getDescription())
			->setBlurHash($this->getBlurHash())
			->setAiGenerated($this->isAiGenerated());

		return $media;
	}
}
