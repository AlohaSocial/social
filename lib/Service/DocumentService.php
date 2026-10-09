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
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Image;
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
	 * too large to decode. Recorded on the row so the caching run stops
	 * offering it again on every pass.
	 */
	public const ERROR_CONTENT = 4;

	/** A playlist is text and is read whole; this is the ceiling on that. */
	private const MAX_PLAYLIST = 2 * 1024 * 1024;

	/**
	 * Seconds one attachment download may take when nobody is waiting for it.
	 *
	 * A federation request is capped at ConfigService::DEFAULT_REQUEST_TIMEOUT
	 * for the *whole* transfer, not per read: the server's HTTP client is
	 * curl, and curl's timeout ends a download that is still arriving. Ten
	 * seconds is right inside an inbox request, and is also the end of any
	 * picture larger than ten seconds of the origin's upstream -- a phone
	 * photo from a Nextcloud on a home connection is several megabytes, and
	 * Social stores and serves the original. The inbox leaves such a picture
	 * for the caching run, so the caching run has to be able to finish it.
	 *
	 * Below `CacheDocumentsRequest::CACHING_TIMEOUT`, so that a download
	 * still running is never started a second time by the next pass.
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

		$mime = '';
		$this->cacheDocumentsRequest->initCaching($document);

		try {
			$this->cacheService->saveRemoteFileToCache($document, $mime);
			// The mime type is sniffed from the bytes at this point and nowhere
			// else; unpersisted, the copy is later served with no Content-Type.
			// It replaces the type the peer declared rather than filling in for
			// it: `/media/{uuid}` serves these bytes from this instance's own
			// origin, so what it says they are has to be what they are.
			if ($mime !== '') {
				$document->setMimeType($mime);
				$document->setMediaType($mime);
			}
			$this->cacheDocumentsRequest->endCaching($document);

			$this->streamRequest->updateAttachments($document);

			return $document;
		} catch (CacheContentDecodeException $e) {
			// A remote peer can hand us a PNG header followed by garbage, which
			// passes the mime sniff and then fails to decode. Left unrecorded the
			// row comes back on every caching run, so mark it and move on.
			$this->miscService->log(
				'Cannot decode document ' . json_encode($document) . ' ' . json_encode($e), 1
			);
			$document->setError(self::ERROR_CONTENT);
			$this->cacheDocumentsRequest->endCaching($document);
		} catch (CacheContentMimeTypeException $e) {
			$this->miscService->log(
				'Not allowed mime type ' . json_encode($document) . ' ' . json_encode($e), 1
			);
			$document->setMimeType($mime);
			$document->setError(self::ERROR_MIMETYPE);
			$this->cacheDocumentsRequest->endCaching($document);
		} catch (NotFoundException $e) {
			$this->miscService->log(
				'Cannot save cache file ' . json_encode($document) . ' ' . json_encode($e), 1
			);
			$document->setError(self::ERROR_PERMISSION);
			$this->cacheDocumentsRequest->endCaching($document);
		} catch (NotPermittedException $e) {
			$this->miscService->log(
				'Cannot save cache file ' . json_encode($document) . ' ' . json_encode($e), 1
			);
			$document->setError(self::ERROR_PERMISSION);
			$this->cacheDocumentsRequest->endCaching($document);
		} catch (RequestResultSizeException|CacheContentSizeException $e) {
			// either the download was cut off at the ceiling, or what arrived
			// turned out to be larger than this instance stores of that kind
			$this->miscService->log(
				'Downloaded file is too big ' . json_encode($document) . ' ' . json_encode($e), 1
			);
			$document->setError(self::ERROR_SIZE);
			$this->cacheDocumentsRequest->endCaching($document);
		} catch (RequestContentException $e) {
			$this->cacheDocumentsRequest->deleteById($document->getId());
		} catch (UnauthorizedFediverseException $e) {
			$this->cacheDocumentsRequest->deleteById($document->getId());
		} catch (RequestNetworkException $e) {
			$this->cacheDocumentsRequest->endCaching($document);
		} catch (RequestServerException $e) {
			$this->cacheDocumentsRequest->endCaching($document);
		}

		throw new CacheDocumentDoesNotExistException();
	}

	/**
	 * `cacheRemoteDocument()` for a caller nobody is waiting on -- the caching
	 * run and the retry command -- with the download given
	 * `BACKGROUND_FETCH_TIMEOUT` rather than the federation default. Reaching
	 * the origin keeps the default, so a host that is down still fails fast.
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
		$mime = $document->getMimeType();

		return $this->cacheService->getContentFromCache($document->getResizedCopy());
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
		$mimeType = $document->getMimeType();

		return $this->cacheService->getContentFromCache($document->getLocalCopy());
	}

	/**
	 * The document behind an id, as the viewer is allowed to see it.
	 *
	 * `/document/get` and `/document/get/resized` take a bare document id from
	 * whoever is asking, so a session alone must not be enough: without this
	 * check any account could name any id and read another account's
	 * attachments, direct messages included. Same rule as `/media/{uuid}`,
	 * widened by what the viewer's own timeline already shows them.
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
			// deliberately indistinguishable from an id that does not exist:
			// probing must not tell the caller which ids are real
			throw new CacheDocumentDoesNotExistException('unknown document');
		}
	}

	/**
	 * A public copy is readable by anyone, as `/media/{uuid}` already decided.
	 * Beyond that a viewer may read their own uploads, their own actor's avatar
	 * and header, and whatever hangs off a post their timeline would show them —
	 * which is the check that keeps a direct message's attachment private.
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
	 * it. Lets a route hand over bytes this instance holds rather than pointing
	 * the browser at the remote host.
	 *
	 * @throws CacheContentException
	 * @throws CacheDocumentDoesNotExistException
	 */
	public function getCachedFromUrl(string $url, string &$mimeType = ''): ISimpleFile {
		$document = $this->cacheDocumentsRequest->getByUrl($url);
		if ($document->getError() > 0 || $document->getLocalCopy() === '') {
			throw new CacheDocumentDoesNotExistException('not cached');
		}

		$mimeType = $document->getMimeType();

		return $this->cacheService->getContentFromCache($document->getLocalCopy());
	}

	/**
	 * The copy behind `/media/{uuid}`, with the row that describes it.
	 *
	 * The uuid *is* the access control. Every copy is named by a version-4
	 * uuid drawn from `random_bytes()` (`TStringTools::uuid()`), 122 bits
	 * nobody can enumerate, and the link is only ever handed to the people a
	 * post was addressed to. This is Mastodon's own model — media is a
	 * capability URL, reachable by whoever holds it, whatever the visibility
	 * of the post it hangs off — and it is the model the rest of the fediverse
	 * relies on: a remote server fetches an attachment unsigned, on behalf of
	 * a reader it has already checked. This method used to refuse any row not
	 * flagged `public`, which turned every followers-only or direct post with
	 * a picture into a broken image on every Mastodon that received it, while
	 * protecting nothing the uuid did not already protect.
	 *
	 * The row is still required — no row, no file — so a copy this app has
	 * forgotten about is not served, and so the caller learns the visibility
	 * (`Document::isPublic()`) to decide how shared caches may treat the bytes.
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

		return [$this->cacheService->getFromUuid($uuid), $document];
	}

	/**
	 * The remote file behind `/media/stream/{nid}`, open and ready to be
	 * passed on -- see `StreamedRemoteResponse` for why this instance stands
	 * in the middle at all.
	 *
	 * The row is the allowlist. A proxy that took a url would be an open one,
	 * so what the route accepts is a key into `social_cache_doc`, and only a
	 * row this app itself wrote as streamable answers: anything with a local
	 * copy is served from disk by `/media/{uuid}` and has no business here.
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

		$opened = $this->cacheService->openRemoteFile($document, $range);

		return array_merge(['document' => $document], $opened);
	}

	/**
	 * An HLS playlist, with every URI in it pointed back through this server.
	 *
	 * A PeerTube transcoding to HLS — the default, and what a public instance
	 * federates — publishes a `.m3u8` and nothing a browser other than Safari
	 * can open. hls.js fixes the browser half; this fixes the privacy half.
	 * A playlist names its segments **relative to itself**, so handing one to a
	 * player verbatim would have every segment fetched straight from the
	 * origin — which is exactly the thing the byte proxy exists to prevent, and
	 * worse, because it is one request per few seconds of video.
	 *
	 * So the playlist is read whole (they are kilobytes), every URI in it is
	 * rewritten to `/media/hls/{nid}?u=…`, and the segments come back through
	 * the same proxy. A nested playlist — the master listing one per
	 * resolution — is rewritten the same way and its children are playlists
	 * again, which is why the rewrite is on URIs rather than on file
	 * extensions.
	 *
	 * @return array{document: Document, playlist: string}
	 * @throws NotFoundException
	 */
	public function openPlaylist(int|string $nid, callable $proxyUrl): array {
		$document = $this->openStreamed($nid)['document'];
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
	 * One file out of a playlist, fetched from the origin and streamed on.
	 *
	 * The url is **not** trusted from the caller: it has to be on the same host
	 * as the playlist the nid names, which is what keeps this from being a
	 * proxy for the whole internet with a server behind it. That is the same
	 * property `openStreamed()` has by taking a row id rather than a url, one
	 * level further in.
	 *
	 * @return array{type: string, stream: resource, status: int, headers: array}
	 * @throws NotFoundException
	 */
	public function openPlaylistFile(int|string $nid, string $url, string $range = ''): array {
		$document = $this->openStreamed($nid)['document'];
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
			$this->cacheService->openRemoteFile($segment, $range)
		);
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
	 * Every URI in a playlist, pointed back through this server.
	 *
	 * A line that is not a comment is a URI; a comment that carries one does so
	 * in a `URI="…"` attribute (the keys and the encryption keys among them).
	 * Both are rewritten, because a player follows both.
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
	 *
	 * Addressed by uuid rather than by row id, exactly as `/media/{uuid}` is:
	 * a ladder is the same bytes as the video, so it must be no easier to
	 * reach than the video. A row id is a small integer and would be.
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
			// the row says there is a rung and the store disagrees: a 404 for
			// this rung, and the player falls back to another one
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
	 * What a file inside a playlist is, by its name.
	 *
	 * Stated rather than sniffed, and narrow: these are the only four things a
	 * playlist ever points at, and a player is handed a type it can act on
	 * rather than one it has to guess.
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
	 * edited: the description and the focal point of each one it names.
	 *
	 * Only the post's own attachments, and only documents `$account` uploaded:
	 * an id the post does not carry is ignored, as Mastodon ignores it. Each
	 * document is updated first, so a later rebuild of the post's copies (a
	 * poster frame, a conversion) does not bring the old words back, and the
	 * copy is then rebuilt from it the way `StreamRequest` rebuilds one.
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
	 * Whether one of `$actorId`'s posts carries this upload.
	 *
	 * An upload is not tied to its post by `parent_id` (it exists before the
	 * post does), so the post is found by its stored copy, which is keyed by
	 * the document's nid.
	 */
	public function isAttachedToAPostBy(Document $document, string $actorId): bool {
		return $this->streamRequest->carriesUpload($actorId, (string)$document->getNid());
	}

	/**
	 * Stores the focal point a client just set.
	 *
	 * The document's cached `meta` is dropped first so that
	 * `convertToMediaAttachment()` rebuilds it from the new focus rather than
	 * handing back the blob it was loaded with.
	 */
	public function updateFocus(Document $document, float $x, float $y): void {
		$document->setFocus($x, $y);
		$document->setMeta(null);
		$document->convertToMediaAttachment($this->urlGenerator);

		$this->cacheDocumentsRequest->updateFocus($document);
	}

	/**
	 * @return int
	 * @throws Exception
	 */
	public function manageCacheDocuments(): int {
		$update = $this->cacheDocumentsRequest->getNotCachedDocuments();

		$count = 0;
		foreach ($update as $item) {
			// None of these three should reach here at all: the query asks for
			// an *empty* `local_copy` and all three name theirs before the row
			// is written. They are checked anyway because the cost of the
			// invariant breaking is not symmetric -- a missed avatar is a
			// wasted request, while a streamed file is a PeerTube video, and
			// fetching one would spend hundreds of megabytes of disk to hand
			// back exactly the bytes the publishing instance already streams.
			if ($item->getLocalCopy() === Document::COPY_LOCAL_AVATAR
				|| $item->getLocalCopy() === 'header'
				|| $item->isStreamed()) {
				continue;
			}

			try {
				$this->cacheRemoteDocumentInBackground($item->getId());
			} catch (Throwable $e) {
				// One unusable row must never end the run: everything queued
				// behind it would silently stop being cached, on every pass.
				$this->miscService->log(
					'Could not cache document ' . $item->getId() . ' - ' . $e->getMessage(), 1
				);
				continue;
			}
			$count++;
		}

		return $count;
	}

	/**
	 * Fills in the type of documents that were stored without one.
	 *
	 * A row written with an empty `media_type` never gained one: the caching
	 * run only looks at rows with *no* local copy, so a document that was
	 * fetched, stored, and whose type was never written stayed that way for
	 * good -- `convertToMediaAttachment()` leaves `type` unset, which is how a
	 * client decides to show nothing at all, and the copy went out with no
	 * Content-Type. The answer is read back from the stored bytes, which is
	 * the only place it still exists.
	 *
	 * Bounded per run like every other sweep here, and it converges: a row
	 * that is filled in is not a candidate again.
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
	 * The icon document of a local account: Nextcloud's avatar route with
	 * the avatar's version in the address. Each version is a document of its
	 * own, so the address changes with the picture — a peer fetches the
	 * picture again only when the address changes, and the avatar route is
	 * served unchanged for a day. The document is local and points at the
	 * avatar itself; nothing is downloaded from this server's own address.
	 *
	 * @return string the document's id
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 * @throws ItemUnknownException
	 * @throws ItemAlreadyExistsException
	 */
	public function cacheLocalAvatarByUsername(Person $actor): string {
		$version = $this->configService->getUserValueInt('version', $actor->getUserId(), 'avatar');
		$url = $this->urlGenerator->linkToRouteAbsolute(
			'core.avatar.getAvatar', ['userId' => $actor->getUserId(), 'size' => 128, 'v' => $version]
		);

		try {
			$icon = $this->cacheDocumentsRequest->getByUrl($url);
			if ($icon->getMediaType() === '') {
				// cached before the mime was recorded: fill it in on the way
				// past rather than wait for the avatar to change
				$icon->setMediaType($this->localAvatarMimeType($actor->getUserId()));
				$this->cacheDocumentsRequest->update($icon);
			}
		} catch (CacheDocumentDoesNotExistException $e) {
			/** @var Image $icon */
			$icon = AP::instance()->getItemFromType(Image::TYPE);
			$icon->generateUniqueId('/documents/avatar');
			$icon->setUrl($url);
			// what the avatar route will actually serve. It used to be left
			// empty, and an empty `mediaType` is a field peers validate
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
	 * The mime of the file Nextcloud's avatar route serves for a user.
	 *
	 * PNG where nothing better is known: it is what a generated avatar is,
	 * and an uploaded one is stored in its own format, which the avatar
	 * manager knows and this method asks.
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
	 * Stores a file that is already on this server as one of an account's own
	 * attachments, on the post it belongs to.
	 *
	 * The bytes go through `saveFromTempToCache()`, which is the path a file
	 * picked in the composer takes: the type is sniffed from the content,
	 * anything this app does not store is refused, the camera's metadata comes
	 * off and a format no browser can draw becomes one it can. So a picture put
	 * back from an export archive is stored exactly as one posted today, and an
	 * archive -- a file a user hands the server -- cannot carry in something the
	 * upload route would have turned away.
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
	 *
	 * @throws CacheContentMimeTypeException
	 * @throws CacheContentSizeException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 */
	public function storeAsIs(Person $actor, string $tmpPath): Document {
		$document = new Document();
		$document->setLocal(true);
		$document->setAccount($actor->getPreferredUsername());
		$document->setUrlCloud($this->configService->getCloudUrl());
		$document->generateUniqueId('/documents/local');
		$document->setPublic(true);

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
