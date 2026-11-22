<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Service\DocumentService;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\IURLGenerator;
use OCP\Server;

class MediaAttachment implements JsonSerializable {
	use TArrayTools;

	/**
	 * The tail of a link this instance serves media on: the cached document's
	 * uuid, optionally carrying the extension the mime type implies.
	 */
	private const MEDIA_UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(\.[a-z0-9]+)?$/i';

	/**
	 * The tail of a streamed link, which names a cache row rather than a copy:
	 * `media/stream/{nid}`, or `media/playlist/{nid}` for an HLS one. A federated video's
	 * `url` is one of these.
	 */
	private const MEDIA_STREAM = '/(?:^|\/)media\/(?:stream|playlist)\/([0-9]+)$/';

	/** The values of Mastodon's `MediaAttachment.type`. */
	public const CLIENT_TYPES = ['unknown', 'image', 'gifv', 'video', 'audio'];

	private string $id = '';
	private string $type = '';
	private string $mediaType = '';
	/**
	 * How many bytes the stored file is; 0 where nothing measured it. Not in
	 * Mastodon's entity; carried for the wire form of a video, since PeerTube
	 * drops a video file link without `size`.
	 */
	private int $sizeBytes = 0;
	private ?string $url = null;
	private string $previewUrl = '';
	/**
	 * The master playlist of this video's ladder, where it has one. Not a
	 * Mastodon key; a client that does not know it plays `url`.
	 */
	private string $hlsUrl = '';
	private ?string $remoteUrl = null;
	private int $cacheError = 0;
	private string $textUrl = '';
	private ?AttachmentMeta $meta = null;
	private string $description = '';
	private string $blurHash = '';
	/** whether the picture's metadata stated machine-generation provenance when it was stored */
	private bool $aiGenerated = false;
	private int $exportFormat = ACore::FORMAT_LOCAL;

	public function setId(string $id): self {
		$this->id = $id;

		return $this;
	}

	public function getId(): string {
		return $this->id;
	}

	public function setType(string $type): self {
		$this->type = $type;

		return $this;
	}

	public function getType(): string {
		return $this->type;
	}

	/**
	 * The full media type (`image/jpeg`), as opposed to `type`, the half
	 * Mastodon's client entity carries (`image`). The ActivityPub `Document`
	 * must state it for peers that do not sniff the file.
	 */
	public function getMediaType(): string {
		return $this->mediaType;
	}

	public function setMediaType(string $mediaType): self {
		$this->mediaType = $mediaType;

		return $this;
	}

	public function setUrl(string $url): self {
		$this->url = $url;

		return $this;
	}

	public function getUrl(): ?string {
		return $this->url;
	}

	public function setPreviewUrl(string $previewUrl): self {
		$this->previewUrl = $previewUrl;

		return $this;
	}

	public function getPreviewUrl(): string {
		return $this->previewUrl;
	}

	public function setRemoteUrl(string $remoteUrl): self {
		$this->remoteUrl = $remoteUrl;

		return $this;
	}

	public function getRemoteUrl(): ?string {
		return $this->remoteUrl;
	}

	public function setCacheError(int $cacheError): self {
		$this->cacheError = $cacheError;

		return $this;
	}

	public function getCacheError(): int {
		return $this->cacheError;
	}

	public function setTextUrl(string $textUrl): self {
		$this->textUrl = $textUrl;

		return $this;
	}

	public function getTextUrl(): string {
		return $this->textUrl;
	}

	public function setMeta(AttachmentMeta $meta): self {
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

	public function setBlurHash(string $blurHash): self {
		$this->blurHash = $blurHash;

		return $this;
	}

	public function getBlurHash(): string {
		return $this->blurHash;
	}

	public function setAiGenerated(bool $aiGenerated): self {
		$this->aiGenerated = $aiGenerated;

		return $this;
	}

	public function isAiGenerated(): bool {
		return $this->aiGenerated;
	}

	public function setExportFormat(int $exportFormat): self {
		$this->exportFormat = $exportFormat;

		return $this;
	}

	public function getExportFormat(): int {
		return $this->exportFormat;
	}

	public function import(array $data): self {
		$this->setId($this->get('id', $data));
		$this->setType($this->get('type', $data));
		$this->setUrl($this->get('url', $data));
		// this app's own key on the stored row (see asLocal()); a row from
		// before it existed, or a Mastodon entity, has none and gets a guess
		$this->setMediaType($this->get('media_type', $data, $this->get('mediaType', $data, '')));
		if ($this->getMediaType() === '') {
			$this->setMediaType(self::guessMediaType($this->getType(), $this->getUrl()));
		}
		$this->setPreviewUrl($this->get('preview_url', $data));
		$this->setHlsUrl($this->get('hls_url', $data, ''));
		$this->setRemoteUrl($this->get('remote_url', $data));
		$this->setCacheError($this->getInt('cache_error', $data, 0));
		$this->setDescription($this->get('description', $data));
		$this->setBlurHash($this->get('blurhash', $data));
		$this->setAiGenerated($this->getBool('ai_generated', $data));

		$meta = new AttachmentMeta();
		$meta->import($this->getArray('meta', $data));
		$this->setMeta($meta);

		return $this;
	}

	#[\Override]
	public function jsonSerialize(): array {
		if ($this->getExportFormat() === ACore::FORMAT_LOCAL) {
			return $this->asLocal();
		}

		return $this->asDocument();
	}

	/**
	 * Mastodon's MediaAttachment entity. Every key is always present, since
	 * clients may treat them as required and fail to decode the status.
	 */
	public function asLocal(): array {
		$meta = $this->getMeta()?->jsonSerialize();
		$preview = $this->onThisInstance($this->getPreviewUrl());
		$remote = $this->getRemoteUrl();
		// no copy here means no url here, as Mastodon sends null;
		// `remote_url` and `cache_error` say where it came from and why
		$url = $this->onThisInstance($this->getUrl());

		return [
			'id' => $this->getId(),
			// one of Mastodon's five: a Document that named no media type
			// left this empty, which no client's enum has
			'type' => in_array($this->getType(), self::CLIENT_TYPES, true) ? $this->getType() : 'unknown',
			// not Mastodon's: the full mime, so the ActivityPub Document served
			// later can state it (Pixelfed refuses an attachment without one)
			'media_type' => $this->getMediaType(),
			'url' => ($url === null || $url === '') ? null : $url,
			'preview_url' => ($preview === null || $preview === '') ? null : $preview,
			// not Mastodon's: where the same video exists at several sizes, a
			// player that understands HLS gets the one that fits the
			// connection. Always present, null where there is no ladder.
			'hls_url' => ($this->hlsUrl === '') ? null : $this->hlsUrl,
			'remote_url' => ($remote === null || $remote === '') ? null : $remote,
			// the finite error code of a refused remote copy, so the reader can
			// be told why without exposing the remote response
			'cache_error' => $this->cacheError > 0 ? $this->cacheError : null,
			// `meta` is an object on the wire, never a list: an empty
			// AttachmentMeta json-encodes as `[]`, which a client decoding a
			// dictionary rejects
			'meta' => ($meta === null || $meta === []) ? null : (object)$meta,
			'description' => ($this->getDescription() === '') ? null : $this->getDescription(),
			'blurhash' => ($this->getBlurHash() === '') ? null : $this->getBlurHash(),
			// not Mastodon's: whether the picture's metadata stated that a
			// model produced it, read when it was stored. Always a bool
			'ai_generated' => $this->isAiGenerated(),
		];
	}

	/**
	 * The mime a row written before `media_type` was stored most likely has:
	 * from the file extension where there is one, else the commonest type of
	 * its kind. A guess, but a guess Pixelfed accepts; '' it refuses.
	 */
	public static function guessMediaType(string $type, string $url): string {
		$extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
		$byExtension = [
			'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
			'webp' => 'image/webp', 'avif' => 'image/avif', 'heic' => 'image/heic', 'heif' => 'image/heif',
			'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
			'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg',
			'opus' => 'audio/opus', 'wav' => 'audio/wav', 'flac' => 'audio/flac', 'aac' => 'audio/aac',
			'pdf' => 'application/pdf', 'txt' => 'text/plain', 'md' => 'text/markdown', 'csv' => 'text/csv',
			'zip' => 'application/zip', 'epub' => 'application/epub+zip',
			'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
			'odp' => 'application/vnd.oasis.opendocument.presentation',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		];
		if (isset($byExtension[$extension])) {
			return $byExtension[$extension];
		}

		return match ($type) {
			'image' => 'image/jpeg',
			'video', 'gifv' => 'video/mp4',
			'audio' => 'audio/mpeg',
			default => '',
		};
	}

	/**
	 * A media link pointed at the address this instance answers on now. The
	 * stored link is absolute and may name an old host or another
	 * `overwrite.cli.url`, so it is rebuilt from its last segment, the
	 * document uuid. A link whose tail is not one of our uuids is returned as is.
	 */
	private function onThisInstance(?string $stored): ?string {
		if ($stored === null || $stored === '') {
			return $stored;
		}

		// a streamed video names a row, not a copy, and is rebuilt the same
		// way; a playlist goes through the route that rewrites its addresses
		if (preg_match(self::MEDIA_STREAM, $stored, $matches) === 1) {
			return Server::get(IURLGenerator::class)->linkToRouteAbsolute(
				DocumentService::isPlaylist($this->mediaType) ? 'social.MediaApi.mediaPlaylist' : 'social.MediaApi.mediaStream',
				['nid' => $matches[1]]
			);
		}

		$uuid = substr($stored, (int)strrpos($stored, '/') + 1);
		if (preg_match(self::MEDIA_UUID, $uuid) !== 1) {
			return $stored;
		}

		return Server::get(IURLGenerator::class)
			->linkToRouteAbsolute('social.MediaApi.mediaOpen', ['uuid' => $uuid]);
	}

	/**
	 * quick implementation of converting MediaAttachment to Document. Can be improved.
	 *
	 * @return array
	 */
	public function asDocument(): array {
		$original = $this->getMeta()?->getOriginal();
		$focus = $this->getMeta()?->getFocus();

		$document
			= [
				'type' => Document::TYPE,
				'mediaType' => $this->getMediaType(),
				// the origin where this instance holds no copy, since a peer
				// needs a host that serves the bytes
				'url' => ($this->getUrl() === '') ? (string)$this->getRemoteUrl() : $this->getUrl(),
				// the wire carries the alt text as `name`
				'name' => ($this->getDescription() === '') ? null : $this->getDescription(),
			];

		// stated only when known, never as zero or empty: Pixelfed validates
		// these when present and drops the whole post if one fails
		$blurhash = $this->getBlurHash();
		if ($blurhash !== '') {
			$document['blurhash'] = $blurhash;
		}

		$width = ($original === null) ? 0 : ($original->getWidth() ?? 0);
		$height = ($original === null) ? 0 : ($original->getHeight() ?? 0);
		if ($width > 0 && $height > 0) {
			$document['width'] = $width;
			$document['height'] = $height;
		}

		// Added only when it points somewhere. `[0, 0]` is the centre, which is
		// what a peer assumes when the field is absent, so sending it says
		// nothing and costs a field on every attachment of every post.
		if ($focus !== null && ($focus->getX() !== 0.0 || $focus->getY() !== 0.0)) {
			$document['focalPoint'] = [$focus->getX(), $focus->getY()];
		}

		return $document;
	}

	public function getHlsUrl(): string {
		return $this->hlsUrl;
	}

	public function setHlsUrl(string $hlsUrl): self {
		$this->hlsUrl = $hlsUrl;

		return $this;
	}

	public function getSizeBytes(): int {
		return $this->sizeBytes;
	}

	public function setSizeBytes(int $sizeBytes): self {
		$this->sizeBytes = max(0, $sizeBytes);

		return $this;
	}
}
