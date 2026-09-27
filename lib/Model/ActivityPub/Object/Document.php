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
use OCA\Social\Model\Client\AttachmentMeta;
use OCA\Social\Model\Client\AttachmentMetaDim;
use OCA\Social\Model\Client\AttachmentMetaFocus;
use OCA\Social\Model\Client\MediaAttachment;
use OCP\IURLGenerator;

/**
 * Class Document
 *
 * @package OCA\Social\Model\ActivityPub
 */
class Document extends ACore implements JsonSerializable {
	public const TYPE = 'Document';

	/**
	 * What stands in `localCopy` for a file this instance deliberately never
	 * mirrors and streams from its origin instead.
	 *
	 * A federated video is the case it exists for: a talk is gigabytes, an
	 * attachment is copied into the instance's own storage on the way in, and
	 * doing that for every video that crosses a timeline is not a trade
	 * anybody would make. The row still exists -- it is what `/media/stream`
	 * checks a request against, so the proxy can only be pointed at a url that
	 * actually arrived in an activity -- but it holds no bytes.
	 *
	 * It is a sentinel in the same column as `avatar` and `header` rather than
	 * a flag of its own because that column is already what decides whether
	 * the caching cron picks a row up: `getNotCachedDocuments()` looks only at
	 * rows whose `local_copy` is empty, so filling it in is what keeps cron
	 * off a file that must not be fetched.
	 */
	public const COPY_STREAMED = 'stream';

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
	 * Whether this video has been written at a ladder of smaller sizes: the
	 * four states of `social_cache_doc.laddered`. Carried so that serving an
	 * attachment can say there is an adaptive playlist without a second query
	 * per attachment.
	 */
	private int $laddered = 0;
	private array $localCopySize = [0, 0];
	private array $resizedCopySize = [0, 0];

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
	 * Where the subject of the picture is, as a fraction of the way from the
	 * centre to each edge: -1 is the left or the bottom, 1 the right or the
	 * top, 0 the middle.
	 *
	 * A client sends this so that a crop -- a thumbnail, the square a profile
	 * grid draws -- keeps the face in frame instead of cutting it off. Mastodon
	 * calls it `focus` in its API and `focalPoint` on the wire; both are this.
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

	/** Both values are clamped: outside -1..1 there is no picture to point at. */
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
	 * Whether this is a pointer at a file on another server rather than a copy
	 * of one -- see `COPY_STREAMED`. Such a document has no bytes here, so
	 * nothing may serve it from disk and nothing may queue it for download.
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
	 * How many bytes the stored file is, or 0 where nothing measured it.
	 *
	 * Recorded rather than measured on demand: PeerTube wants `size` on every
	 * video file link it is offered and drops a link without one, and a
	 * filesystem lookup inside a serialisation is not a thing to do per
	 * delivery. 0 for a streamed file, which has no bytes here at all, and for
	 * anything stored before the column existed.
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

		// Mastodon and Pixelfed both send `focalPoint: [x, y]`; the context
		// declares it as an ordered list, so it arrives as a two-element array.
		$focalPoint = $this->getArray('focalPoint', $data);
		if (count($focalPoint) === 2 && is_numeric($focalPoint[0]) && is_numeric($focalPoint[1])) {
			$this->setFocus((float)$focalPoint[0], (float)$focalPoint[1]);
		}
		// on the wire an attachment's alt text is its `name`; without this the
		// description a remote author wrote never reaches local clients
		if ($this->getDescription() === '') {
			$this->setDescription($this->validate(ACore::AS_STRING, 'name', $data, ''));
		}

		// An attachment that arrived without an id of its own gets one here, so
		// that it can be cached and addressed like any other. One that has an
		// id keeps it, and it is deliberately not held to the activity's own
		// origin: a server may name its media on a host it does not federate
		// from, and the id of an attachment is never fetched — only its `url`
		// is, and CurlService holds that to a scheme it can fetch.
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

		// The focal point rides in the stored `meta` blob rather than a column of
		// its own: nothing queries it, and it is only ever read back with the
		// rest of the attachment's metadata.
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

	public function getMediaUrl(IURLGenerator $urlGenerator, string $mime = ''): string {
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
	 * The still to show before the file itself is drawn or played.
	 *
	 * For an image, the resized copy of the same picture. For a **video**, the
	 * poster frame -- which is a JPEG rather than a video, so the link says
	 * `.jpeg` and `/media/{uuid}` answers with the matching type: a browser
	 * with `nosniff` on refuses to draw an image served as `video/mp4`, and
	 * every Nextcloud has `nosniff` on.
	 *
	 * A video with no poster (an older upload, or a server with no ffmpeg)
	 * falls back to the media itself, which is what this app told clients
	 * before posters existed and is still better than nothing: a player handed
	 * it will show its own first frame.
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
	 * origin. Addressed by the cache row's own key, which is the whole of what
	 * keeps the proxy from being pointed anywhere a caller likes.
	 */
	public function streamUrl(IURLGenerator $urlGenerator): string {
		return $urlGenerator->linkToRouteAbsolute(
			'social.MediaApi.mediaStream',
			['nid' => (string)$this->getNid()]
		);
	}

	public function getLaddered(): int {
		return $this->laddered;
	}

	public function setLaddered(int $laddered): self {
		$this->laddered = $laddered;

		return $this;
	}

	/** Whether there is a ladder to offer a player. */
	public function hasLadder(): bool {
		return $this->laddered === 1 && $this->getLocalCopy() !== '';
	}

	/**
	 * The master playlist of this video's ladder.
	 *
	 * By uuid, like `getMediaUrl()` and for the same reason: a ladder is the
	 * same video, so it must be no easier to reach than the video.
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
			// Mastodon's four kinds, and its word for everything else: a PDF
			// is not an "application", it is a file a client cannot render
			$media->setType(in_array($type, ['image', 'video', 'audio', 'gifv'], true) ? $type : 'unknown');
		}

		// the whole of it, not just the half the client entity shows: what
		// goes back out as an ActivityPub Document has to state it
		$media->setMediaType($this->getMediaType());
		// carried for the wire alone: a PeerTube `Video` states the size of
		// every file it offers, and drops a link that does not
		$media->setSizeBytes($this->getSizeBytes());

		if (!is_null($urlGenerator)) {
			// A refused copy names nothing. `getMediaUrl()` builds
			// `/media/{local_copy}{ext}` whatever `local_copy` says, so a
			// document this instance could not cache went out as
			// `/media/.jpeg` — a link to this server with no bytes behind it.
			// Every client fetched it, every fetch 404'd, and a third-party
			// one has no way to tell that from an attachment that is merely
			// slow. Mastodon leaves the url off such an attachment; so does
			// this. `remote_url` and `cache_error` below still say where it
			// came from and why it was refused, which is what the reader is
			// shown instead — the origin is deliberately not offered as a
			// fallback, see MediaAttachment::asLocal().
			if (!$this->isStreamed() && $this->getLocalCopy() === '') {
				$media->setUrl('')
					->setPreviewUrl('');
			} elseif ($this->isStreamed()) {
				// no local copy to name: the bytes are fetched from the origin
				// as they are played, and the route that does it is addressed
				// by the row rather than by a uuid there is none of. The
				// preview is left empty -- a streamed video's still is a
				// document of its own, and the caller that knows which one
				// sets it (see PeerTubeService).
				$media->setUrl($this->streamUrl($urlGenerator));
			} else {
				$media->setUrl($this->getMediaUrl($urlGenerator, $mime));
				if ($this->hasLadder()) {
					$media->setHlsUrl($this->ladderUrl($urlGenerator));
				}
				// a file has no preview of itself; handing the file back as
				// one would have a client try to draw a PDF as a picture
				if ($media->getType() !== 'unknown') {
					$media->setPreviewUrl($this->previewUrl($urlGenerator, $mime));
				}
			}
		}

		$media->setRemoteUrl($this->getUrl())
			->setCacheError($this->getError());

		// Filled in rather than built only when absent. A document may already
		// carry *part* of a meta -- a video is given its duration when it is
		// stored, before anything knows its dimensions -- and the whole block
		// used to be skipped whenever anything at all was there, so the video
		// went out with a running time and no size. Each half is only supplied
		// where the document has nothing.
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
			->setBlurHash($this->getBlurHash());

		return $media;
	}
}
