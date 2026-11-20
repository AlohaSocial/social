<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\AP;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\RenditionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\CacheContentDecodeException;
use OCA\Social\Exceptions\CacheContentException;
use OCA\Social\Exceptions\CacheContentMimeTypeException;
use OCA\Social\Exceptions\CacheContentSizeException;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\RefusalException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\VideoRendition;
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCA\Social\Tools\Nid;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IAvatarManager;
use OCP\IURLGenerator;
use Throwable;

class DocumentService {
	public const ERROR_SIZE = 1;
	public const ERROR_MIMETYPE = 2;
	public const ERROR_PERMISSION = 3;

	/**
	 * The bytes could not be decoded as the image they claimed to be, or were
	 * too large to decode; recorded so the caching run stops retrying.
	 */
	public const ERROR_CONTENT = 4;

	/** What `storeRemoteDocument()` did: stored a copy. */
	public const STORED = 'stored';

	/** Recorded why there is no copy, or forgot the row: nothing to try again. */
	public const SETTLED = 'settled';

	/** Left the row as it was: the origin could not be reached, and may be later. */
	public const RETRY = 'retry';

	/** A playlist is text and is read whole; this is the ceiling on that. */
	private const MAX_PLAYLIST = 2 * 1024 * 1024;

	/** The largest subtitle track passed on: an hour of dense dialogue is a few hundred kilobytes. */
	public const MAX_CAPTION = 1024 * 1024;

	/**
	 * Seconds one attachment download may take when nobody is waiting for it.
	 *
	 * curl's timeout covers the whole transfer, and the federation default ends
	 * a large photo from a slow origin, which the inbox leaves to
	 * `RemoteMediaPrefetcher`. Below `CacheDocumentsRequest::CACHING_TIMEOUT`, so
	 * the next pass never restarts a download still running.
	 */
	public const BACKGROUND_FETCH_TIMEOUT = 120;

	public function __construct(
		private IUrlGenerator $urlGenerator,
		private CacheDocumentsRequest $cacheDocumentsRequest,
		private RenditionsRequest $renditionsRequest,
		private ActorsRequest $actorRequest,
		private StreamRequest $streamRequest,
		private CacheDocumentService $cacheService,
		private ConfigService $configService,
		private MiscService $miscService,
		private IAvatarManager $avatarManager,
	) {
	}

	/**
	 * @param string $id
	 * @param bool $public
	 *
	 * @return Document
	 * @throws CacheDocumentDoesNotExistException
	 * @throws MalformedArrayException
	 * @throws SocialAppConfigException
	 */
	public function cacheRemoteDocument(string $id, bool $public = false) {
		$document = $this->cacheDocumentsRequest->getById($id, $public);
		if ($document->getError() > 0) {
			throw new CacheDocumentDoesNotExistException();
		}

		if ($document->getLocalCopy() !== '') {
			return $document;
		}

		if ($document->getCaching() > (time() - (CacheDocumentsRequest::CACHING_TIMEOUT * 60))) {
			return $document;
		}

		$this->cacheDocumentsRequest->initCaching($document);
		$stored = $this->storeRemoteDocument(
			$document,
			function (Document $document): string {
				$mime = '';
				$this->cacheService->saveRemoteFileToCache($document, $mime);

				return $mime;
			}
		);
		if ($stored === self::STORED) {
			return $document;
		}

		throw new CacheDocumentDoesNotExistException();
	}

	/**
	 * Stores the file of a remote document whose row is marked as being
	 * cached, and records the outcome: the copy and its sniffed type, or why
	 * there is none. `$store` puts the file in place (see
	 * `CacheDocumentService::saveRemoteFileToCache()`) and returns the type
	 * the bytes turned out to be.
	 *
	 * A permanent failure is recorded on the row (or the row forgotten when the
	 * file is gone or the instance blocked); a network or server failure leaves
	 * the row for a later try.
	 *
	 * @param \Closure(Document): string $store
	 *
	 * @return self::STORED|self::SETTLED|self::RETRY
	 * @throws MalformedArrayException
	 * @throws SocialAppConfigException
	 */
	public function storeRemoteDocument(Document $document, \Closure $store): string {
		$mime = '';
		try {
			$document->setActorIcon($this->cacheDocumentsRequest->isActorIcon($document));
			$mime = $store($document);
			// The sniffed type replaces the declared one: `/media/{uuid}` serves these
			// bytes from this instance's own origin.
			if ($mime !== '') {
				$document->setMimeType($mime);
				$document->setMediaType($mime);
			}
			$this->cacheDocumentsRequest->endCaching($document);

			$this->streamRequest->updateAttachments($document);

			return self::STORED;
		} catch (CacheContentDecodeException $e) {
			// A valid header followed by garbage passes the sniff but fails to decode;
			// recorded so the row is not retried on every run.
			$this->miscService->log(
				'Cannot decode document ' . json_encode($document) . ' ' . json_encode($e), 1
			);
			$document->setError(self::ERROR_CONTENT);
		} catch (CacheContentMimeTypeException $e) {
			$this->miscService->log(
				'Not allowed mime type ' . json_encode($document) . ' ' . json_encode($e), 1
			);
			$document->setMimeType($mime);
			$document->setError(self::ERROR_MIMETYPE);
		} catch (NotFoundException|NotPermittedException $e) {
			$this->miscService->log(
				'Cannot save cache file ' . json_encode($document) . ' ' . json_encode($e), 1
			);
			$document->setError(self::ERROR_PERMISSION);
		} catch (RequestResultSizeException|CacheContentSizeException $e) {
			// Cut off at the ceiling, or larger than this instance stores of that kind.
			$this->miscService->log(
				'Downloaded file is too big ' . json_encode($document) . ' ' . json_encode($e), 1
			);
			$document->setError(self::ERROR_SIZE);
		} catch (RequestContentException|UnauthorizedFediverseException $e) {
			$this->cacheDocumentsRequest->deleteById($document->getId());

			return self::SETTLED;
		} catch (RequestNetworkException|RequestServerException $e) {
			$this->cacheDocumentsRequest->endCaching($document);

			return self::RETRY;
		}

		$this->cacheDocumentsRequest->endCaching($document);

		return self::SETTLED;
	}

	/**
	 * `cacheRemoteDocument()` for a caller nobody is waiting on (the retry
	 * command), with `BACKGROUND_FETCH_TIMEOUT` for the download; connecting
	 * keeps the default so a host that is down still fails fast.
	 *
	 * @throws CacheDocumentDoesNotExistException
	 * @throws MalformedArrayException
	 * @throws SocialAppConfigException
	 */
	public function cacheRemoteDocumentInBackground(string $id): Document {
		/** @var Document */
		return $this->configService->withRequestTimeout(
			self::BACKGROUND_FETCH_TIMEOUT,
			fn (): Document => $this->cacheRemoteDocument($id),
			ConfigService::DEFAULT_REQUEST_TIMEOUT
		);
	}

	/**
	 * @param string $id
	 * @param string $mime
	 * @param bool $public
	 *
	 * @return ISimpleFile
	 * @throws CacheContentException
	 * @throws CacheDocumentDoesNotExistException
	 * @throws MalformedArrayException
	 * @throws RequestResultNotJsonException
	 * @throws SocialAppConfigException
	 */
	public function getResizedFromCache(string $id, string &$mime = '', bool $public = false) {
		$document = $this->cacheRemoteDocument($id, $public);
		$file = $this->storedCopy($document, true);
		$mime = $document->getMimeType();

		return $file;
	}

	/**
	 * @param string $id
	 * @param bool $public
	 * @param string $mimeType
	 *
	 * @return ISimpleFile
	 * @throws CacheContentException
	 * @throws CacheDocumentDoesNotExistException
	 * @throws MalformedArrayException
	 * @throws SocialAppConfigException
	 */
	public function getFromCache(
		string $id, string &$mimeType = '', bool $public = false,
	): ISimpleFile {
		$document = $this->cacheRemoteDocument($id, $public);
		$file = $this->storedCopy($document, false);
		$mimeType = $document->getMimeType();

		return $file;
	}

	/**
	 * The document behind an id, as the viewer is allowed to see it.
	 * `/document/get` takes any id from the caller, so without this check any
	 * account could read another's attachments, direct messages included.
	 *
	 * @throws CacheContentException
	 * @throws CacheDocumentDoesNotExistException
	 * @throws MalformedArrayException
	 * @throws SocialAppConfigException
	 */
	public function getFromCacheAsViewer(
		string $id, ?Person $viewer, string &$mimeType = '',
	): ISimpleFile {
		$this->assertViewerMayRead($id, $viewer);

		return $this->getFromCache($id, $mimeType);
	}

	/**
	 * The preview copy behind an id, as the viewer is allowed to see it.
	 *
	 * @throws CacheContentException
	 * @throws CacheDocumentDoesNotExistException
	 * @throws MalformedArrayException
	 * @throws SocialAppConfigException
	 */
	public function getResizedFromCacheAsViewer(
		string $id, ?Person $viewer, string &$mimeType = '',
	): ISimpleFile {
		$this->assertViewerMayRead($id, $viewer);

		return $this->getResizedFromCache($id, $mimeType);
	}

	/**
	 * Caches (if needed) and returns a document the viewer is allowed to see.
	 *
	 * @throws CacheDocumentDoesNotExistException
	 * @throws MalformedArrayException
	 * @throws SocialAppConfigException
	 */
	public function cacheRemoteDocumentAsViewer(string $id, ?Person $viewer): Document {
		$this->assertViewerMayRead($id, $viewer);

		return $this->cacheRemoteDocument($id);
	}

	/**
	 * @throws CacheDocumentDoesNotExistException
	 */
	private function assertViewerMayRead(string $id, ?Person $viewer): void {
		$document = $this->cacheDocumentsRequest->getById($id);

		if (!$this->viewerMayRead($document, $viewer)) {
			// Indistinguishable from an unknown id, so probing reveals nothing.
			throw new CacheDocumentDoesNotExistException('unknown document');
		}
	}

	/**
	 * A public copy is readable by anyone. Otherwise a viewer may read their own
	 * uploads, their own avatar and header, and whatever hangs off a post their
	 * timeline would show them.
	 */
	private function viewerMayRead(Document $document, ?Person $viewer): bool {
		if ($document->isPublic()) {
			return true;
		}

		if ($viewer === null) {
			return false;
		}

		$account = $document->getAccount();
		if ($account !== '' && $account === $viewer->getPreferredUsername()) {
			return true;
		}

		$parentId = $document->getParentId();
		if ($parentId === '') {
			return false;
		}

		if ($parentId === $viewer->getId()) {
			return true;
		}

		try {
			$this->streamRequest->setViewer($viewer);

			// asViewer applies the same visibility the timelines do
			$this->streamRequest->getStreamById($parentId, true);

			return true;
		} catch (Exception $e) {
			return false;
		}
	}

	/**
	 * The cached copy of a remote url, when the caching run has already fetched
	 * it, so a route can serve the bytes instead of pointing at the remote host.
	 *
	 * @throws CacheContentException
	 * @throws CacheDocumentDoesNotExistException
	 */
	public function getCachedFromUrl(string $url, string &$mimeType = ''): ISimpleFile {
		$document = $this->cacheDocumentsRequest->getByUrl($url);
		if ($document->getError() > 0 || $document->getLocalCopy() === '') {
			throw new CacheDocumentDoesNotExistException('not cached');
		}

		$file = $this->storedCopy($document, false);
		$mimeType = $document->getMimeType();

		return $file;
	}

	/**
	 * One of a document's two copies, fetched again first where it was a
	 * cached remote file that is no longer on disk.
	 *
	 * @throws CacheContentException
	 * @throws CacheDocumentDoesNotExistException
	 */
	private function storedCopy(Document $document, bool $resized): ISimpleFile {
		try {
			return $this->cacheService->getContentFromCache(
				$resized ? $document->getResizedCopy() : $document->getLocalCopy()
			);
		} catch (CacheContentException $e) {
			if (!$this->restoreCopy($document)) {
				throw $e;
			}
		}

		return $this->cacheService->getContentFromCache(
			$resized ? $document->getResizedCopy() : $document->getLocalCopy()
		);
	}

	/**
	 * Fetches a cached remote file again when its row names it and the disk does
	 * not have it (e.g. `MediaPurgeService::purgeCache()` removed it), under the
	 * names the row already has.
	 *
	 * Only for what can be fetched again (not a local upload, not a refused
	 * row), and at most once per `CacheDocumentsRequest::CACHING_TIMEOUT`, so a
	 * busy page does not hammer an origin that is down.
	 *
	 * @return bool whether the file is back
	 */
	private function restoreCopy(Document $document): bool {
		if ($document->isLocalUpload() || $document->getError() > 0 || $document->getUrl() === ''
			|| !Document::isStoredName($document->getLocalCopy())
			|| $document->getCaching() > (time() - (CacheDocumentsRequest::CACHING_TIMEOUT * 60))) {
			return false;
		}

		try {
			if (MediaUsageService::isLocalDocumentId($document->getId(), $this->configService->getCloudUrl())) {
				return false;
			}

			$this->cacheDocumentsRequest->initCaching($document);
			$document->setActorIcon($this->cacheDocumentsRequest->isActorIcon($document));
			$resized = $document->getResizedCopy();
			// The type, size and names are set on the document as it is stored.
			$this->configService->withRequestTimeout(
				self::BACKGROUND_FETCH_TIMEOUT,
				fn () => $this->cacheService->restoreRemoteCopy($document),
				ConfigService::DEFAULT_REQUEST_TIMEOUT
			);
			$this->cacheDocumentsRequest->endCaching($document);

			// Record a newly named preview and refresh the post's attachments.
			if ($document->getResizedCopy() !== $resized) {
				$this->streamRequest->updateAttachments($document);
			}

			return true;
		} catch (Throwable $e) {
			$this->miscService->log(
				'Could not fetch the purged copy of ' . $document->getId() . ' again: ' . $e->getMessage(), 1
			);

			return false;
		}
	}

	/**
	 * The copy behind `/media/{uuid}`, with the row that describes it.
	 *
	 * The uuid is the access control: a random v4 uuid
	 * (`TStringTools::uuid()`) handed only to the post's audience, which is
	 * Mastodon's capability-URL model that remote servers rely on when fetching
	 * unsigned. A row is still required, and tells the caller the visibility
	 * (`Document::isPublic()`) for its cache headers.
	 *
	 * @return array{0: ISimpleFile, 1: Document}
	 * @throws NotFoundException
	 */
	public function getFromUuid(string $uuid): array {
		if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)
			!== 1) {
			throw new NotFoundException('invalid document');
		}

		try {
			// either copy: a preview link names the resized one
			$document = $this->cacheDocumentsRequest->getByCopy($uuid);
		} catch (CacheDocumentDoesNotExistException $e) {
			throw new NotFoundException('unknown document');
		}

		try {
			return [$this->cacheService->getFromUuid($uuid), $document];
		} catch (NotFoundException $e) {
			if (!$this->restoreCopy($document)) {
				throw $e;
			}
		}

		return [$this->cacheService->getFromUuid($uuid), $document];
	}

	/**
	 * The remote file behind `/media/stream/{nid}`, open and ready to be
	 * passed on (see `StreamedRemoteResponse`).
	 *
	 * Only a row this app wrote as streamable answers, so this cannot be used as
	 * an open proxy; anything with a local copy is served by `/media/{uuid}`.
	 *
	 * @param string $range the client's own `Range` header, forwarded verbatim
	 *
	 * @return array{document: Document, stream: resource, status: int, headers: array<string, string[]>}
	 *
	 * @throws NotFoundException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 */
	public function openStreamed(int|string $nid, string $range = ''): array {
		$document = $this->streamedDocument($nid);
		$opened = $this->cacheService->openRemoteFile($document, $range);

		return array_merge(['document' => $document], $opened);
	}

	/**
	 * The streamable row a nid names, without opening anything at its origin.
	 *
	 * @throws NotFoundException
	 */
	private function streamedDocument(int|string $nid): Document {
		if (!ctype_digit((string)$nid) || Nid::compare($nid, '0') < 1) {
			throw new NotFoundException('invalid document');
		}

		try {
			$document = $this->cacheDocumentsRequest->getByNid($nid);
		} catch (CacheDocumentDoesNotExistException $e) {
			throw new NotFoundException('unknown document');
		}

		if (!$document->isStreamed()) {
			throw new NotFoundException('document is not streamed');
		}

		return $document;
	}

	/**
	 * An HLS playlist (as PeerTube publishes), with every URI in it rewritten to
	 * `/media/hls/{nid}?u=…` so the player fetches no segment from the origin.
	 * Nested playlists are rewritten the same way, which is why the rewrite is
	 * on URIs rather than file extensions.
	 *
	 * @return array{document: Document, playlist: string}
	 * @throws NotFoundException
	 */
	public function openPlaylist(int|string $nid, callable $proxyUrl): array {
		$document = $this->streamedDocument($nid);
		if (!self::isPlaylist($document->getMediaType())) {
			throw new NotFoundException('document is not a playlist');
		}

		$body = $this->cacheService->readRemoteFile($document, self::MAX_PLAYLIST);

		return [
			'document' => $document,
			'playlist' => $this->rewritePlaylist($body, $document->getUrl(), $proxyUrl),
		];
	}

	/**
	 * One file out of a playlist, fetched from the origin and streamed on. The
	 * url must be on the playlist's host, so this is not a general proxy.
	 *
	 * @return array{type: string, stream: resource, status: int, headers: array}
	 * @throws NotFoundException
	 */
	public function openPlaylistFile(int|string $nid, string $url, string $range = ''): array {
		$document = $this->streamedDocument($nid);
		if (!self::isPlaylist($document->getMediaType())) {
			throw new NotFoundException('document is not a playlist');
		}

		if (!$this->sameOrigin($document->getUrl(), $url)) {
			throw new NotFoundException('that file is not part of this playlist');
		}

		$segment = new Document();
		$segment->setUrl($url);
		$segment->setMediaType($this->playlistPartType($url));
		$segment->setLocalCopy(Document::COPY_STREAMED);

		return array_merge(
			['type' => $segment->getMediaType()],
			$this->cacheService->openRemoteFile($segment, $range, null, true)
		);
	}

	/**
	 * One of a video's subtitle tracks, proxied because of `media-src 'self'`
	 * and the reader's privacy. The track is picked by position in the post's
	 * own `video.captions`, never by a url from the caller. WebVTT only, up to
	 * `MAX_CAPTION`.
	 *
	 * @param Stream $post the video, already read as the viewer may see it
	 *
	 * @return string the track, as WebVTT
	 * @throws NotFoundException when the post has no such track, or what is there is not one
	 */
	public function caption(Stream $post, int $index): string {
		$captions = $post->getVideoMeta()['captions'] ?? null;
		$caption = is_array($captions) ? (array_values($captions)[$index] ?? null) : null;
		$url = is_array($caption) && is_string($caption['url'] ?? null) ? $caption['url'] : '';
		if ($url === '') {
			throw new NotFoundException('no such caption');
		}

		$track = new Document();
		$track->setId($url);
		$track->setUrl($url);
		$track->setParentId($post->getId());
		$track->setMediaType('text/vtt');
		$track->setLocalCopy(Document::COPY_STREAMED);

		try {
			$body = $this->cacheService->readRemoteFile($track, self::MAX_CAPTION);
		} catch (RequestServerException|RequestContentException|RequestNetworkException|UnauthorizedFediverseException|SocialAppConfigException $e) {
			throw new NotFoundException('the caption could not be read');
		}

		// a byte-order mark is allowed before the signature, and nothing else
		$body = str_starts_with($body, "\u{FEFF}") ? substr($body, 3) : $body;
		if (preg_match('/^WEBVTT(?:[ \t\r\n]|$)/', $body) !== 1 || !mb_check_encoding($body, 'UTF-8')) {
			throw new NotFoundException('the caption is not WebVTT');
		}

		return $body;
	}

	/** Whether a media type is one of the two spellings of an HLS playlist. */
	public static function isPlaylist(string $mediaType): bool {
		return in_array(
			strtolower($mediaType),
			['application/x-mpegurl', 'application/vnd.apple.mpegurl'],
			true
		);
	}

	/**
	 * Every URI in a playlist, pointed back through this server: each
	 * non-comment line, and each `URI="…"` attribute in a comment line.
	 *
	 * @param callable(string): string $proxyUrl
	 */
	private function rewritePlaylist(string $body, string $base, callable $proxyUrl): string {
		$lines = [];
		foreach (preg_split('/\R/', $body) ?: [] as $line) {
			$trimmed = trim($line);

			if ($trimmed === '') {
				$lines[] = $line;
				continue;
			}

			if ($trimmed[0] === '#') {
				$lines[] = (string)preg_replace_callback(
					'/URI="([^"]+)"/',
					fn (array $m): string => 'URI="' . $proxyUrl($this->absolute($m[1], $base)) . '"',
					$line
				);
				continue;
			}

			$lines[] = $proxyUrl($this->absolute($trimmed, $base));
		}

		return implode("\n", $lines);
	}

	// --- a local video's own ladder ---------------------------------------

	/**
	 * The master playlist for a stored video, or null when it has no ladder.
	 * Addressed by uuid like `/media/{uuid}`, since a row id is guessable.
	 *
	 * @param callable(int): string $rungUrl what to call each rung in the
	 *                                       master playlist, given its height
	 *
	 * @throws NotFoundException
	 */
	public function masterPlaylist(string $uuid, callable $rungUrl): ?string {
		[, $document] = $this->getFromUuid($uuid);
		$renditions = $this->renditionsRequest->forDocument($document->getNid());
		if ($renditions === []) {
			return null;
		}

		$lines = ['#EXTM3U', '#EXT-X-VERSION:7'];
		foreach ($renditions as $rendition) {
			$lines[] = $rendition->masterEntry($rungUrl($rendition->getHeight()));
		}

		return implode("\n", $lines) . "\n";
	}

	/**
	 * One rung's playlist, with the URI of its media file filled in.
	 *
	 * @param callable(int): string $mediaUrl what to call the rung's own file
	 *
	 * @throws NotFoundException
	 */
	public function rungPlaylist(string $uuid, int $height, callable $mediaUrl): ?string {
		[, $document] = $this->getFromUuid($uuid);
		$rendition = $this->renditionsRequest->forHeight($document->getNid(), $height);

		return ($rendition === null) ? null : $rendition->playlistFor($mediaUrl($height));
	}

	/**
	 * One rung's fragmented MP4, open and ready to be served with a `Range`.
	 *
	 * @return array{0: ISimpleFile, 1: Document}|null
	 *
	 * @throws NotFoundException
	 */
	public function rungFile(string $uuid, int $height): ?array {
		[, $document] = $this->getFromUuid($uuid);
		$rendition = $this->renditionsRequest->forHeight($document->getNid(), $height);
		if ($rendition === null || $rendition->getLocalCopy() === '') {
			return null;
		}

		try {
			return [$this->cacheService->getContentFromCache($rendition->getLocalCopy()), $document];
		} catch (Exception $e) {
			// The row names a rung the store lacks: 404 and the player falls back.
			throw new NotFoundException('the rung is not there');
		}
	}

	/**
	 * The rungs of a stored video, for a client that wants to know there are
	 * any before it loads a player that can use them.
	 *
	 * @return VideoRendition[]
	 */
	public function renditionsOf(int|string $nid): array {
		return ($nid < 1) ? [] : $this->renditionsRequest->forDocument($nid);
	}

	/** A URI in a playlist, against the playlist's own address. */
	private function absolute(string $uri, string $base): string {
		if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
			return $uri;
		}

		$parts = parse_url($base);
		$root = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '')
			. (isset($parts['port']) ? ':' . $parts['port'] : '');

		if (str_starts_with($uri, '/')) {
			return $root . $uri;
		}

		$path = $parts['path'] ?? '/';

		return $root . substr($path, 0, (int)strrpos($path, '/') + 1) . $uri;
	}

	/** Whether two addresses are on the same host, scheme and port. */
	private function sameOrigin(string $one, string $other): bool {
		$a = parse_url($one);
		$b = parse_url($other);

		return $a !== false && $b !== false
			&& ($a['host'] ?? null) !== null
			&& strcasecmp($a['host'] ?? '', $b['host'] ?? '') === 0
			&& ($a['scheme'] ?? '') === ($b['scheme'] ?? '')
			&& ($a['port'] ?? null) === ($b['port'] ?? null);
	}

	/**
	 * What a file inside a playlist is, by its name: one of the four types a
	 * playlist points at.
	 */
	private function playlistPartType(string $url): string {
		$path = strtolower((string)parse_url($url, PHP_URL_PATH));

		return match (true) {
			str_ends_with($path, '.m3u8') => 'application/x-mpegURL',
			str_ends_with($path, '.m4s'), str_ends_with($path, '.mp4') => 'video/mp4',
			str_ends_with($path, '.ts') => 'video/mp2t',
			default => 'application/octet-stream',
		};
	}

	/**
	 * @param array $getMediaIds
	 * @param string $account
	 *
	 * @return Document[]
	 */
	public function getMediaFromArray(array $getMediaIds, string $account = ''): array {
		return $this->cacheDocumentsRequest->getFromArray($getMediaIds, $account);
	}

	/**
	 * Resolves an edited post's complete attachment list from the author's own
	 * uploads, in the requested order; an unknown or other account's id is refused.
	 *
	 * @param list<int> $mediaIds
	 * @return MediaAttachment[]
	 * @throws RefusalException
	 */
	public function replaceMediaAttachments(array $mediaIds, string $account, bool $public): array {
		$mediaIds = array_values(array_unique(array_filter(array_map('intval', $mediaIds), static fn (int $id): bool => $id > 0)));
		if ($mediaIds === []) {
			return [];
		}

		$documents = $this->getMediaFromArray($mediaIds, $account);
		$byId = [];
		foreach ($documents as $document) {
			$byId[(int)$document->getNid()] = $document;
		}
		if (count($byId) !== count($mediaIds)) {
			throw new RefusalException('One or more media attachments are unavailable', 'media-ids-invalid');
		}

		$attachments = [];
		foreach ($mediaIds as $id) {
			$document = $byId[$id];
			if ($document->isPublic() !== $public) {
				$document->setPublic($public);
				$this->cacheDocumentsRequest->update($document);
			}
			$attachments[] = $document->convertToMediaAttachment($this->urlGenerator);
		}

		return $attachments;
	}

	/**
	 * One cached document by its ActivityPub id.
	 *
	 * @throws CacheDocumentDoesNotExistException
	 */
	public function getDocumentById(string $id): Document {
		return $this->cacheDocumentsRequest->getById($id);
	}

	/**
	 * Stores a changed alt text.
	 */
	public function updateDescription(Document $document): void {
		$this->cacheDocumentsRequest->updateDescription($document);
	}

	/**
	 * Applies Mastodon's `media_attributes` to the attachments of a post being
	 * edited: the description and focal point of each one it names.
	 *
	 * Only the post's own attachments uploaded by `$account`; other ids are
	 * ignored, as in Mastodon. The document is updated first, so a later
	 * rebuild of the post's copies keeps the change.
	 *
	 * @param MediaAttachment[] $attachments the post's attachments
	 * @param list<array<string, mixed>> $attributes `id` and `description` and/or `focus` each
	 *
	 * @return MediaAttachment[] the same list, the changed ones rebuilt
	 */
	public function applyMediaAttributes(array $attachments, string $account, array $attributes): array {
		$carried = array_map(static fn (MediaAttachment $attachment): string => $attachment->getId(), $attachments);

		$wanted = [];
		foreach ($attributes as $attribute) {
			$id = (string)($attribute['id'] ?? '');
			if ($id !== '' && in_array($id, $carried, true)) {
				$wanted[$id] = $attribute;
			}
		}
		if ($wanted === []) {
			return $attachments;
		}

		$rebuilt = [];
		foreach ($this->getMediaFromArray(array_keys($wanted), $account) as $document) {
			$attribute = $wanted[(string)$document->getNid()] ?? null;
			if ($attribute === null) {
				continue;
			}

			if (array_key_exists('description', $attribute)) {
				$document->setDescription((string)$attribute['description']);
				$this->updateDescription($document);
			}
			$focus = array_key_exists('focus', $attribute) ? Document::parseFocus((string)$attribute['focus']) : null;
			if ($focus !== null) {
				$this->updateFocus($document, $focus[0], $focus[1]);
			}

			$rebuilt[(string)$document->getNid()] = $document->convertToMediaAttachment($this->urlGenerator);
		}

		return array_map(
			static fn (MediaAttachment $attachment): MediaAttachment => $rebuilt[$attachment->getId()] ?? $attachment,
			$attachments
		);
	}

	/**
	 * Resolves the full `media_ids` list for a post edit: only the author's own
	 * uploads, in the requested order.
	 *
	 * @param list<int|string> $mediaIds
	 * @return MediaAttachment[]
	 * @throws InvalidActionException
	 */
	public function mediaAttachmentsForEdit(array $mediaIds, string $account, string $visibility): array {
		if (count($mediaIds) > Stream::MAX_ATTACHMENTS) {
			throw new InvalidActionException('Too many media attachments');
		}

		$ids = [];
		foreach ($mediaIds as $id) {
			$id = (string)$id;
			if (!ctype_digit($id) || Nid::compare($id, '0') < 1) {
				throw new InvalidActionException('Invalid media attachment');
			}
			$id = Nid::normalize($id);
			if (isset($ids[$id])) {
				throw new InvalidActionException('Invalid media attachment');
			}
			$ids[$id] = true;
		}
		if ($ids === []) {
			return [];
		}

		$documents = $this->getMediaFromArray(array_keys($ids), $account);
		$byId = [];
		foreach ($documents as $document) {
			$id = (string)$document->getNid();
			if ($document->isLocalUpload() && $document->getAccount() === $account && isset($ids[$id])) {
				$byId[$id] = $document;
			}
		}
		if (count($byId) !== count($ids)) {
			throw new InvalidActionException('One or more media attachments are unavailable');
		}

		$public = in_array($visibility, [Stream::TYPE_PUBLIC, Stream::TYPE_UNLISTED], true);
		$attachments = [];
		foreach (array_keys($ids) as $id) {
			$document = $byId[$id];
			if ($document->isPublic() !== $public) {
				$document->setPublic($public);
				$this->cacheDocumentsRequest->update($document);
			}
			$attachments[] = $document->convertToMediaAttachment($this->urlGenerator, ACore::FORMAT_ACTIVITYPUB);
		}

		return $attachments;
	}

	/**
	 * Whether one of `$actorId`'s posts carries this upload, found by the post's
	 * stored copy (an upload has no `parent_id` until posted).
	 */
	public function isAttachedToAPostBy(Document $document, string $actorId): bool {
		return $this->streamRequest->carriesUpload($actorId, (string)$document->getNid());
	}

	/**
	 * Stores the focal point a client just set, dropping the cached `meta` so
	 * `convertToMediaAttachment()` rebuilds it.
	 */
	public function updateFocus(Document $document, float $x, float $y): void {
		$document->setFocus($x, $y);
		$document->setMeta(null);
		$document->convertToMediaAttachment($this->urlGenerator);

		$this->cacheDocumentsRequest->updateFocus($document);
	}

	/**
	 * Fills in the type of stored documents that have none, sniffed from the
	 * stored bytes; without one a client shows nothing. Bounded per run, and a
	 * filled row is not a candidate again.
	 *
	 * @return int how many rows were filled in
	 */
	public function fillMissingMediaTypes(int $limit = 500): int {
		$filled = 0;
		foreach ($this->cacheDocumentsRequest->getWithoutMediaType($limit) as $document) {
			try {
				$mime = $this->cacheService->sniffStored($document->getLocalCopy());
				if ($mime === '') {
					continue;
				}

				$document->setMediaType($mime);
				$document->setMimeType($mime);
				$this->cacheDocumentsRequest->updateMediaType($document);
				$filled++;
			} catch (Throwable $e) {
				// one unreadable file is not a reason to stop reading the rest
				$this->miscService->log(
					'Could not read the type of ' . $document->getId() . ' - ' . $e->getMessage(), 1
				);
			}
		}

		return $filled;
	}

	/**
	 * The icon document of a local account: this app's avatar route of the
	 * username (`Person::localAvatarUrl()`), not Nextcloud's, which names the
	 * login. The avatar version is in the address, so peers refetch only when
	 * the picture changes. Nothing is downloaded.
	 *
	 * @return string the document's id
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 * @throws ItemUnknownException
	 * @throws ItemAlreadyExistsException
	 */
	public function cacheLocalAvatarByUsername(Person $actor): string {
		$version = $this->configService->getUserValueInt('version', $actor->getUserId(), 'avatar');
		$url = Person::localAvatarUrl($this->urlGenerator, $actor->getPreferredUsername(), $version);

		try {
			$icon = $this->cacheDocumentsRequest->getByUrl($url);
			if ($icon->getMediaType() === '') {
				// Cached without a type: fill it in now.
				$icon->setMediaType($this->localAvatarMimeType($actor->getUserId()));
				$this->cacheDocumentsRequest->update($icon);
			}
		} catch (CacheDocumentDoesNotExistException $e) {
			/** @var Image $icon */
			$icon = AP::instance()->getItemFromType(Image::TYPE);
			$icon->generateUniqueId('/documents/avatar');
			$icon->setUrl($url);
			// Peers validate `mediaType`, so it must not be empty.
			$icon->setMediaType($this->localAvatarMimeType($actor->getUserId()));
			$icon->setLocalCopy(Document::COPY_LOCAL_AVATAR);
			$icon->setLocal(true);

			AP::instance()->getInterfaceFromType(Image::TYPE)->save($icon);
		}

		if ($actor->getAvatarVersion() !== $version) {
			$actor->setAvatarVersion($version);
			$this->actorRequest->update($actor);
		}

		return $icon->getId();
	}

	/**
	 * The mime type Nextcloud's avatar route serves for a user; PNG for a
	 * generated avatar.
	 */
	private function localAvatarMimeType(string $userId): string {
		try {
			$mime = $this->avatarManager->getAvatar($userId)->getFile(128)->getMimeType();

			return ($mime === '') ? 'image/png' : $mime;
		} catch (\Throwable $e) {
			return 'image/png';
		}
	}

	/**
	 * Stores a file already on this server as one of an account's own
	 * attachments, through the composer's upload path (`saveFromTempToCache()`):
	 * type sniffed, unsupported types refused, metadata stripped. So an archive
	 * import cannot carry in what an upload would refuse.
	 *
	 * @param string $parentId the post this hangs off, or '' for an upload that
	 *                         has not been posted yet
	 * @param bool $public whether the unauthenticated media route may serve it,
	 *                     which follows the visibility of that post
	 *
	 * @throws CacheContentMimeTypeException the file is not a type this app stores
	 * @throws CacheContentDecodeException the bytes are not the image they claim
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 */
	public function storeLocalAttachment(
		Person $actor,
		string $tmpPath,
		string $parentId = '',
		string $description = '',
		bool $public = false,
	): Document {
		$document = new Document();
		$document->setLocal(true);
		$document->setAccount($actor->getPreferredUsername());
		$document->setUrlCloud($this->configService->getCloudUrl());
		$document->generateUniqueId('/documents/local');
		$document->setParentId($parentId);
		$document->setPublic($public);
		$document->setDescription($description);

		$this->cacheService->saveFromTempToCache($document, $tmpPath);
		$this->cacheDocumentsRequest->save($document);

		return $document;
	}

	/**
	 * Stores a file of an account's byte for byte, as a blob that moved here
	 * with its repository is kept (see `CacheDocumentService::saveAsIsFromTemp()`).
	 * `$public` controls the ordinary Social media route; XRPC applies its own
	 * repository availability checks.
	 *
	 * @throws CacheContentMimeTypeException
	 * @throws CacheContentSizeException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 */
	public function storeAsIs(Person $actor, string $tmpPath, bool $public = true): Document {
		$document = new Document();
		$document->setLocal(true);
		$document->setAccount($actor->getPreferredUsername());
		$document->setUrlCloud($this->configService->getCloudUrl());
		$document->generateUniqueId('/documents/local');
		$document->setPublic($public);

		$this->cacheService->saveAsIsFromTemp($document, $tmpPath);
		$this->cacheDocumentsRequest->save($document);

		return $document;
	}

	/**
	 * How many files of its own an account has stored here, by media type.
	 *
	 * @return array<string, int> media type => how many
	 */
	public function countStoredCopies(string $account): array {
		return $this->cacheDocumentsRequest->countLocalCopiesByType($account);
	}
}
