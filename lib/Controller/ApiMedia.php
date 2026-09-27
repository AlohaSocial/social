<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\AP;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\Status;
use OCA\Social\Model\Post;
use OCA\Social\Response\RangedFileResponse;
use OCA\Social\Response\StreamedRemoteResponse;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\VideoLadderService;
use OCA\Social\Service\VideoThumbnailService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use Throwable;

/**
 * The client API's media half: putting a file on this server, and serving it
 * back.
 *
 * It is almost the only part of the client API that does not answer JSON.
 * These routes hand over bytes — an image, a range of a video, an HLS playlist
 * built on the spot, one rung of a ladder of transcodes — which is a different
 * job from the rest of ApiController with a different set of things to get
 * right: what media type to declare, what a browser with `nosniff` will do
 * with it, how large a file may be, and which copy of a document a uuid names.
 *
 * A trait and not a controller of its own, deliberately. A separate controller
 * would change every one of these route names, and `social.Api.mediaOpen` is
 * written into the ActivityPub documents this server publishes and into the
 * URLs its own pages generate — it is in eighteen places, and one missed is a
 * broken picture on a page nobody thought to open. What this separates is the
 * file; the controller is the same controller, with the same routes, the same
 * session handling and the same injected services.
 */
trait ApiMedia {
	/**
	 *
	 * @return DataResponse
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[UserRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/media')]
	public function mediaNew(): DataResponse {
		try {
			$this->initViewer(true);

			$file = $_FILES['file'] ?? [];
			if (empty($file)) {
				throw new InvalidActionException('no media found');
			}

			if ($file['error'] !== UPLOAD_ERR_OK) {
				throw new InvalidActionException('error during upload');
			}

			$name = $file['tmp_name'] ?? '';
			$size = $file['size'] ?? -1;
			$type = $file['type'] ?? '';

			if ($name === '' || $size === -1 || $type === '') {
				throw new InvalidActionException('missing details');
			}

			// The same ceiling the app puts on anything it downloads, applied
			// to what it is handed: without it an upload was bounded only by
			// PHP's own limits, and the file is read into memory to be hashed,
			// sniffed and (for an image) decoded.
			$this->refuseOversized($size, $type);

			$this->logger->debug('[ApiController] mediaNew: ' . json_encode($file));

			// a cover for a video, as Mastodon's API names it; anything
			// wrong with it costs the cover and not the upload
			$thumbnail = $_FILES['thumbnail'] ?? [];
			$thumbnailPath = (($thumbnail['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK)
				? ($thumbnail['tmp_name'] ?? '')
				: '';

			return new DataResponse(
				$this->storeAttachment(
					$name,
					(string)$this->request->getParam('description', ''),
					(string)$this->request->getParam('focus', ''),
					basename($file['name'] ?? ''),
					$thumbnailPath
				),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Attaches a file the viewer already has in Nextcloud.
	 *
	 * Not a Mastodon route: the point of running this app inside a Nextcloud is
	 * that the pictures are already here, and making somebody download their
	 * own photo and upload it back is the one thing no other Fediverse server
	 * has an excuse for. The file is copied, not referenced — a post keeps the
	 * picture it was published with, so moving or deleting the original later
	 * cannot empty a post that is already federated, and the attachment is
	 * scoped to the post's visibility the same way an upload is.
	 *
	 * The path is resolved inside the viewer's own user folder and nowhere
	 * else, so a share they can read is fair game and everything else is a 404.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[UserRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/media/from-file')]
	public function mediaFromFile(): DataResponse {
		try {
			$this->initViewer(true);

			$input = $this->convertInput(file_get_contents('php://input'));
			$path = trim((string)($input['path'] ?? $this->request->getParam('path', '')));
			if ($path === '') {
				throw new InvalidActionException('no file named');
			}

			$file = $this->ownFile($this->currentSession(), $path);
			$this->refuseOversized((int)$file->getSize(), $file->getMimeType());

			$description = (string)($input['description'] ?? $this->request->getParam('description', ''));

			// through a temp file, so the mime sniffing, the size guard and the
			// resizing are the same code an upload goes through rather than a
			// second path that could drift from it
			$tmpPath = $this->tempManager->getTemporaryFile();
			if ($tmpPath === false) {
				throw new InvalidActionException('no temporary file to copy into');
			}

			$handle = $file->fopen('r');
			if ($handle === false) {
				throw new InvalidActionException('the file could not be read');
			}

			try {
				if (file_put_contents($tmpPath, $handle) === false) {
					throw new InvalidActionException('the file could not be copied');
				}
			} finally {
				fclose($handle);
			}

			return new DataResponse($this->storeAttachment($tmpPath, $description, '', $file->getName()), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * One file out of the viewer's own storage.
	 *
	 * `getUserFolder()` is the boundary: a path is resolved relative to it, so
	 * a traversal leaves the folder and is not found. The containment check
	 * after the lookup says so a second time rather than trusting that — this
	 * route names a file and returns its contents, which is exactly the shape
	 * a mistake here would be exploited in.
	 *
	 * @throws InvalidActionException
	 */
	private function ownFile(string $userId, string $path): File {
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$node = $userFolder->get($path);
		} catch (Throwable $e) {
			throw new InvalidActionException('no such file');
		}

		if (!$node instanceof File) {
			throw new InvalidActionException('that is not a file');
		}

		if ($userFolder->getRelativePath($node->getPath()) === null) {
			throw new InvalidActionException('no such file');
		}

		return $node;
	}

	/**
	 * Stores a local file as one of the viewer's attachments.
	 *
	 * @return MediaAttachment the entity a client is answered with
	 */
	private function storeAttachment(string $tmpPath, string $description, string $focus = '', string $filename = '', string $thumbnailPath = ''): MediaAttachment {
		$document = new Document();
		$document->setLocal(true);
		$document->setAccount($this->viewer->getPreferredUsername());
		$document->setUrlCloud($this->configService->getCloudUrl());
		$document->generateUniqueId('/documents/local');
		// Not public until a post says so. `public` decides whether the
		// unauthenticated /media/{uuid} route serves the file, and this used
		// to be set on every upload — so an attachment to a direct message
		// was, by the row's own account, readable by anybody. The visibility
		// is applied when the status is created; see scopeMediaToVisibility().
		$document->setPublic(false);
		$document->setDescription($description);

		// Where the subject is, so a crop keeps it in frame. Nonsense is
		// ignored rather than refused: a client that sends a malformed focus
		// has still sent a picture, and losing the upload over it would be a
		// worse answer than centring it.
		$parsed = ($focus === '') ? null : Document::parseFocus($focus);
		if ($parsed !== null) {
			$document->setFocus($parsed[0], $parsed[1]);
		}

		$this->cacheDocumentService->saveFromTempToCache($document, $tmpPath);
		if ($thumbnailPath !== '') {
			$this->cacheDocumentService->applyCustomPoster($document, $thumbnailPath);
		}
		if ($description === '' && $filename !== '' && CacheDocumentService::isDocumentMime($document->getMediaType())) {
			// a file is known by its name, and the description is the one
			// field the entity has for saying what it is; a picture with no
			// alt text stays undescribed, which is a fact worth keeping
			$document->setDescription(mb_substr($filename, 0, 255));
		}
		$service = AP::instance()->getInterfaceForItem($document);
		$service->save($document);

		$mediaAttachment = $document->convertToMediaAttachment($this->urlGenerator);

		$this->logger->debug('generated attachment: ' . json_encode($mediaAttachment));

		return $mediaAttachment;
	}

	/**
	 * Same upload as mediaNew — modern Mastodon clients POST /api/v2/media and
	 * only fall back to v1 on a 404.
	 *
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[UserRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v2/media')]
	public function mediaNewV2(): DataResponse {
		return $this->mediaNew();
	}

	/**
	 * One of the viewer's own attachments, by the id mediaNew returned.
	 *
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/media/{nid}')]
	public function mediaGet(int|string $nid, string $preview = ''): Response {
		try {
			$this->initViewer(true);

			return new DataResponse($this->ownAttachment($nid), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Updates the alt text or the focal point of the viewer's own attachment.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	// A write, and the only one in this file that had no ceiling: the same
	// pair its POST sibling carries. Both attributes rather than just the user
	// one, because Nextcloud applies `UserRateLimit` only to a caller with a
	// session — and a client holding an OAuth token has none, which is every
	// client this API is written for.
	#[AnonRateLimit(limit: 30, period: 60)]
	#[UserRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/media/{nid}')]
	public function mediaUpdate(int|string $nid): Response {
		try {
			$this->initViewer(true);

			$document = $this->ownDocument($nid);
			$input = $this->convertInput(file_get_contents('php://input'));
			if (array_key_exists('description', $input)) {
				$document->setDescription((string)$input['description']);
				$this->documentService->updateDescription($document);
			}
			if (array_key_exists('focus', $input)) {
				$parsed = Document::parseFocus((string)$input['focus']);
				if ($parsed !== null) {
					$this->documentService->updateFocus($document, $parsed[0], $parsed[1]);
				}
			}

			return new DataResponse(
				$document->convertToMediaAttachment($this->urlGenerator), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * @throws NotFoundException when the id is unknown or belongs to someone else
	 */
	private function ownDocument(int|string $nid): Document {
		$documents = $this->documentService->getMediaFromArray(
			[$nid], $this->viewer->getPreferredUsername()
		);
		if (count($documents) !== 1) {
			throw new NotFoundException('unknown media');
		}

		return $documents[0];
	}

	/**
	 * @throws NotFoundException
	 */
	private function ownAttachment(int|string $nid): MediaAttachment {
		return $this->ownDocument($nid)->convertToMediaAttachment($this->urlGenerator);
	}

	/**
	 *
	 * @param string $id
	 *
	 * @return Response
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	// The one byte route here that carried no ceiling at all, while its six
	// siblings below all do. A uuid harvested from a public timeline could be
	// asked for in a loop, with a `Range` each time, for as long as anybody
	// cared to.
	//
	// The numbers are the most generous pair already in this file — the ones
	// `mediaLadderFile()` and `mediaPlaylistFile()` use — and deliberately so.
	// This is the highest-volume route the app has: a timeline is forty to
	// sixty of these a screen (see below), a portfolio is a gridful, and
	// **every other fediverse server fetches media through it unsigned**, so
	// one busy instance pulling a popular post's pictures arrives as a single
	// anonymous address. A limit tight enough to matter against a determined
	// scraper would show up first as broken images on a public page and as
	// media that never arrives on a peer.
	//
	// So this caps the unbounded case and closes the gap against the siblings;
	// it is not a bandwidth control. That belongs in front of the server,
	// where the bytes actually are.
	#[AnonRateLimit(limit: 600, period: 60)]
	#[UserRateLimit(limit: 3000, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/media/{uuid}')]
	public function mediaOpen(string $uuid): Response {
		if (strpos($uuid, '.') > 0) {
			[$uuid] = explode('.', $uuid, 2);
		}

		try {
			// Any local copy is served to whoever holds its uuid. The route is
			// unauthenticated on purpose: this is how Mastodon and every other
			// fediverse server fetch media — unsigned, on behalf of a reader they
			// have already checked — so restricting it to rows flagged `public`
			// (as this used to) only meant a broken image under every
			// followers-only or direct post with a picture. The uuid is a v4 from
			// random_bytes(), unguessable, handed only to the post's audience;
			// see DocumentService::getFromUuid() for the model.
			[$file, $document] = $this->documentService->getFromUuid($uuid);

			// Range-capable, because a picture is not the only thing served
			// here: a video a reader cannot seek is a video with a scrub bar
			// that does nothing, and asking for its duration alone used to cost
			// the whole file.
			$response = new RangedFileResponse(
				$file,
				$this->servedMediaType($document, $uuid),
				$this->request->getHeader('Range'),
				// a timeline is forty to sixty of these a screen, and the bytes
				// behind a uuid never change
				$this->request->getHeader('If-None-Match')
			);

			// The bytes behind a uuid never change, so they may be kept for good —
			// but only a browser's own cache may keep a non-public one: a shared
			// proxy in front of this instance would otherwise answer the same URL
			// to anyone, which is a wider audience than "whoever was sent it".
			$response->cacheFor(self::MEDIA_CACHE_SECONDS, $document->isPublic(), true);

			return $response;
		} catch (NotFoundException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (Exception $e) {
			$this->logger->warning('issues while mediaOpen', ['exception' => $e]);

			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * A federated video, passed through from the instance that holds it.
	 *
	 * The page may not point a `<video>` at another server -- Nextcloud's
	 * content security policy says `media-src 'self'` -- and even where it
	 * could, every reader who pressed play would be introducing themselves to
	 * a host they had never chosen to talk to. So the bytes come through here
	 * instead, a chunk at a time, stored nowhere; see `StreamedRemoteResponse`.
	 *
	 * Unauthenticated, like `mediaOpen()` and for the same reason: this is a
	 * media url, and it is handed out with the post it belongs to. What keeps
	 * it from being a proxy for the whole internet is that it takes a row id
	 * rather than a url, and the row has to be one this app wrote as streamed.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	// generous, because one video is many requests: a player asks for the
	// first megabyte, then the moov atom at the other end of the file, then a
	// range per seek. A limit sized for an API call would stop playback in the
	// middle, which is indistinguishable from a broken video
	#[AnonRateLimit(limit: 120, period: 60)]
	#[UserRateLimit(limit: 600, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/media/stream/{nid}')]
	public function mediaStream(int|string $nid): Response {
		try {
			$opened = $this->documentService->openStreamed(
				$nid, $this->request->getHeader('Range')
			);
			/** @var Document $document */
			$document = $opened['document'];

			$headers = [
				'Content-Type' => $document->getMediaType(),
				// what makes a player offer a seek bar at all
				'Accept-Ranges' => 'bytes',
				// the bytes behind a row never change, and the row is only
				// named by the post it hangs off
				'Cache-Control' => 'private, max-age=' . self::MEDIA_CACHE_SECONDS,
				// this is a file to play, never a document to interpret: the
				// origin's own type is not repeated to the browser as a
				// licence to sniff
				'X-Content-Type-Options' => 'nosniff',
			];

			// the two the origin answered that a player needs to make sense of
			// a partial answer, and nothing else it happened to send
			foreach (['Content-Length', 'Content-Range'] as $header) {
				$value = $opened['headers'][$header] ?? $opened['headers'][strtolower($header)] ?? [];
				if ($value !== []) {
					$headers[$header] = (string)$value[0];
				}
			}

			return new StreamedRemoteResponse($opened['stream'], $opened['status'], $headers);
		} catch (NotFoundException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (Exception $e) {
			$this->logger->warning('issues while mediaStream', ['exception' => $e]);

			return new DataResponse(['error' => 'could not reach the origin'], Http::STATUS_BAD_GATEWAY);
		}
	}

	// --- where somebody stopped watching ----------------------------------

	/**
	 * The videos the reader was in the middle of, newest first.
	 *
	 * Neither the ones they barely started nor the ones they finished: a row
	 * that offers back a video somebody watched to the end is a row nobody
	 * presses twice.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/videos/continue')]
	public function videosContinue(int $limit = 20): DataResponse {
		try {
			$this->initViewer(true);

			return new DataResponse(
				$this->watchService->unfinished($this->viewer, $limit), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * An HLS playlist, with every URI in it pointed back through this server.
	 *
	 * A PeerTube transcoding to HLS — the default, and what a public instance
	 * federates — publishes a `.m3u8` and nothing but Safari can open one. So
	 * the client loads hls.js and asks for this; without the rewrite it would
	 * then fetch every segment straight from the origin, which is the very
	 * thing `mediaStream()` exists to prevent, and worse, because it is one
	 * request per few seconds of video.
	 *
	 * Unauthenticated for the same reason the other two media routes are: it is
	 * a media url handed out with the post it belongs to, and it takes a row id
	 * rather than a url.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[UserRateLimit(limit: 300, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/media/playlist/{nid}')]
	public function mediaPlaylist(int|string $nid): Response {
		try {
			$opened = $this->documentService->openPlaylist(
				$nid,
				fn (string $url): string => $this->urlGenerator->linkToRouteAbsolute(
					'social.Api.mediaPlaylistFile', ['nid' => $nid, 'u' => $url]
				)
			);

			$response = new DataDisplayResponse($opened['playlist'], Http::STATUS_OK, [
				'Content-Type' => 'application/vnd.apple.mpegurl',
				'Cache-Control' => 'private, max-age=' . self::MEDIA_CACHE_SECONDS,
				'X-Content-Type-Options' => 'nosniff',
			]);

			return $response;
		} catch (NotFoundException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (Exception $e) {
			$this->logger->warning('issues while mediaPlaylist', ['exception' => $e]);

			return new DataResponse(['error' => 'could not reach the origin'], Http::STATUS_BAD_GATEWAY);
		}
	}

	// --- a local video's own ladder ---------------------------------------

	/**
	 * The master playlist of a stored video: which sizes it exists at.
	 *
	 * Addressed by uuid, the same handle `/media/{uuid}` takes, because it
	 * leads to the same video. A route keyed on a row id would make a ladder
	 * easier to find than the file it was built from, which would be a way of
	 * reading a followers-only post's video by counting.
	 *
	 * 404 rather than an empty playlist when there is no ladder: a player that
	 * is handed a master with no rungs in it reports a broken video, where one
	 * that gets a 404 falls back to the plain file, which is what should
	 * happen.
	 *
	 * Every address in this group ends in the extension its content actually
	 * has, and the three of them are at different depths so none can be read
	 * as another. Browsers and hls.js go by the `Content-Type`, but ffmpeg's
	 * HLS demuxer checks the *extension* of every segment URI it is given and
	 * refuses one it does not recognise — found on devel, where ffprobe would
	 * not open a playlist this server had written correctly.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[UserRateLimit(limit: 300, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/media/hls/{uuid}/master.m3u8')]
	public function mediaLadder(string $uuid): Response {
		try {
			$master = $this->documentService->masterPlaylist(
				$uuid,
				fn (int $height): string => $this->urlGenerator->linkToRouteAbsolute(
					'social.Api.mediaLadderRung', ['uuid' => $uuid, 'height' => $height]
				)
			);

			if ($master === null) {
				return new DataResponse(['error' => 'no ladder'], Http::STATUS_NOT_FOUND);
			}

			return new DataDisplayResponse($master, Http::STATUS_OK, [
				'Content-Type' => VideoLadderService::PLAYLIST_TYPE,
				'Cache-Control' => 'private, max-age=' . self::MEDIA_CACHE_SECONDS,
				'X-Content-Type-Options' => 'nosniff',
			]);
		} catch (NotFoundException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (Exception $e) {
			$this->logger->warning('issues while mediaLadder', ['exception' => $e]);

			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * One rung's playlist: where each segment is inside that rung's file.
	 *
	 * The stored playlist keeps a placeholder where the media URI goes, and it
	 * is filled in here — the address is a route on this server, which is not
	 * known when ffmpeg writes the file and changes if the instance moves.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	#[UserRateLimit(limit: 600, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/media/hls/{uuid}/{height}/index.m3u8')]
	public function mediaLadderRung(string $uuid, int $height): Response {
		try {
			$playlist = $this->documentService->rungPlaylist(
				$uuid,
				$height,
				fn (int $rung): string => $this->urlGenerator->linkToRouteAbsolute(
					'social.Api.mediaLadderFile', ['uuid' => $uuid, 'height' => $rung]
				)
			);

			if ($playlist === null) {
				return new DataResponse(['error' => 'no such rung'], Http::STATUS_NOT_FOUND);
			}

			return new DataDisplayResponse($playlist, Http::STATUS_OK, [
				'Content-Type' => VideoLadderService::PLAYLIST_TYPE,
				'Cache-Control' => 'private, max-age=' . self::MEDIA_CACHE_SECONDS,
				'X-Content-Type-Options' => 'nosniff',
			]);
		} catch (NotFoundException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (Exception $e) {
			$this->logger->warning('issues while mediaLadderRung', ['exception' => $e]);

			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * One rung's file: the whole fragmented MP4, served with ranges.
	 *
	 * Every segment of a rung is a byte range into this one file, so a player
	 * watching a ten-minute video asks this route a few hundred times with a
	 * different `Range` each time. That is what `RangedFileResponse` is for,
	 * and why the limits here are the generous ones.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	#[UserRateLimit(limit: 3000, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/media/hls/{uuid}/{height}/video.mp4')]
	public function mediaLadderFile(string $uuid, int $height): Response {
		try {
			$rung = $this->documentService->rungFile($uuid, $height);
			if ($rung === null) {
				return new DataResponse(['error' => 'no such rung'], Http::STATUS_NOT_FOUND);
			}

			[$file, $document] = $rung;
			$response = new RangedFileResponse(
				$file,
				VideoLadderService::RENDITION_TYPE,
				$this->request->getHeader('Range'),
				$this->request->getHeader('If-None-Match')
			);
			// the same terms the video itself is served on: for ever in the
			// reader's own cache, and in a shared one only when the post it
			// hangs off is public
			$response->cacheFor(self::MEDIA_CACHE_SECONDS, $document->isPublic(), true);

			return $response;
		} catch (NotFoundException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (Exception $e) {
			$this->logger->warning('issues while mediaLadderFile', ['exception' => $e]);

			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * One file out of such a playlist — a segment, a key, or a nested playlist.
	 *
	 * The url is **not** trusted from the caller: it has to be on the same host
	 * as the playlist the nid names. That is the same property `mediaStream()`
	 * has by taking a row id rather than a url, one level further in, and it is
	 * what keeps this from being a proxy for the whole internet.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	// a segment is a few seconds of video, so a film is hundreds of them
	#[AnonRateLimit(limit: 600, period: 60)]
	#[UserRateLimit(limit: 3000, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/media/playlist/{nid}/file')]
	public function mediaPlaylistFile(int|string $nid, string $u = ''): Response {
		try {
			$opened = $this->documentService->openPlaylistFile(
				$nid, $u, $this->request->getHeader('Range')
			);

			$headers = [
				'Content-Type' => (string)($opened['type'] ?? 'application/octet-stream'),
				'Accept-Ranges' => 'bytes',
				'Cache-Control' => 'private, max-age=' . self::MEDIA_CACHE_SECONDS,
				'X-Content-Type-Options' => 'nosniff',
			];

			foreach (['Content-Length', 'Content-Range'] as $header) {
				$value = $opened['headers'][$header] ?? $opened['headers'][strtolower($header)] ?? [];
				if ($value !== []) {
					$headers[$header] = (string)$value[0];
				}
			}

			return new StreamedRemoteResponse($opened['stream'], $opened['status'], $headers);
		} catch (NotFoundException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (Exception $e) {
			$this->logger->warning('issues while mediaPlaylistFile', ['exception' => $e]);

			return new DataResponse(['error' => 'could not reach the origin'], Http::STATUS_BAD_GATEWAY);
		}
	}

	/**
	 * The ceiling an upload is held to, which is not one number.
	 *
	 * `max_size` was written for a picture: one is read whole into memory to
	 * have its metadata stripped and a preview made of it, so a low ceiling is
	 * what keeps that honest. A video goes nowhere near memory — it is copied
	 * to storage a chunk at a time — and 10 MB of video is about forty seconds,
	 * which is not a video anybody meant to post. So video has a ceiling of its
	 * own, `max_video_size`.
	 *
	 * The mime is the one the *client* stated, which is not yet the one the
	 * file will be stored under: the real type is sniffed from the content
	 * afterwards, and a file that lied about being a video is still refused
	 * then by `filterMimeTypes()`. What a lie buys here is a larger upload of
	 * something that is then thrown away, which is why the sniffed type is what
	 * decides whether it is *kept*.
	 *
	 * @throws InvalidActionException
	 */
	private function refuseOversized(int $size, string $declaredMime): void {
		$max = str_starts_with(strtolower($declaredMime), 'video/')
			? $this->instanceService->maxVideoUploadSize()
			: $this->instanceService->maxUploadSize();

		if ($size > $max) {
			throw new InvalidActionException(
				'file is larger than the ' . (int)($max / 1048576) . 'MB limit'
			);
		}
	}

	/**
	 * What the bytes behind a uuid actually are.
	 *
	 * A document's media type describes the *file*, and for every image that is
	 * also what both of its copies are. A video's resized copy is not: it is
	 * the poster frame, a JPEG, and served as `video/mp4` a browser with
	 * `nosniff` on — which is every Nextcloud — refuses to draw it. So the
	 * answer depends on which copy the uuid named.
	 *
	 * The stored media type is what the bytes sniffed as when they were
	 * written, whether they arrived as an upload or were fetched from a peer —
	 * never what their sender called them. The extension in the URL is
	 * whatever the requester chose to write there and is not consulted.
	 */
	private function servedMediaType(Document $document, string $uuid): string {
		$mediaType = $document->getMediaType();

		if ($uuid === $document->getResizedCopy() && !str_starts_with($mediaType, 'image/')) {
			return VideoThumbnailService::MEDIA_TYPE;
		}

		return $mediaType;
	}
}
