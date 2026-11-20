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
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\Status;
use OCA\Social\Model\Post;
use OCA\Social\Response\RangedFileResponse;
use OCA\Social\Response\StreamedRemoteResponse;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\FileCommentsService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\GifService;
use OCA\Social\Service\InstanceService;
use OCA\Social\Service\StreamService;
use OCA\Social\Service\VideoLadderService;
use OCA\Social\Service\VideoThumbnailService;
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
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IRequest;
use OCP\ITempManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The client API's media routes: uploading files and serving bytes back
 * (images, video ranges, HLS playlists, transcode rungs), with care for the
 * declared media type under `nosniff`, sizes, and which copy a uuid names.
 *
 * Several routes are written into published ActivityPub documents as
 * `social.MediaApi.*` URLs; `RouteNamesTest` checks they still resolve.
 */
class MediaApiController extends MastodonApiController {
	/**
	 * How long `/media/{uuid}` may be cached: a year, the conventional
	 * "forever" for content whose URL names its bytes and never changes.
	 */
	private const MEDIA_CACHE_SECONDS = 31536000;

	public function __construct(
		IRequest $request,
		IURLGenerator $urlGenerator,
		IUserSession $userSession,
		LoggerInterface $logger,
		ClientService $clientService,
		AccountService $accountService,
		CacheActorService $cacheActorService,
		StreamService $streamService,
		FollowService $followService,
		private InstanceService $instanceService,
		private CacheDocumentService $cacheDocumentService,
		private DocumentService $documentService,
		private ConfigService $configService,
		private IRootFolder $rootFolder,
		private ITempManager $tempManager,
		private GifService $gifService,
		private FileCommentsService $fileCommentsService,
	) {
		parent::__construct($request, $urlGenerator, $userSession, $logger, $clientService, $accountService, $cacheActorService, $streamService, $followService);
	}

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
				// PHP drops a body over post_max_size whole, which leaves
				// $_FILES as empty as a request that sent no file at all
				$sent = (int)$this->request->getHeader('Content-Length');
				if ($sent > $this->instanceService->postMaxSize()) {
					throw $this->uploadTooLarge(
						'file', $this->instanceService->phpUploadLimit(), 'the request body is over post_max_size'
					);
				}

				throw new InvalidActionException('no media found');
			}

			$limit = str_starts_with(strtolower($file['type'] ?? ''), 'video/')
				? $this->instanceService->videoSizeLimit()
				: $this->instanceService->imageSizeLimit();
			if (!$this->uploadArrived($file, 'file', $limit)) {
				throw new InvalidActionException('no media found');
			}

			$name = $file['tmp_name'] ?? '';
			$size = $file['size'] ?? -1;
			$type = $file['type'] ?? '';

			if ($name === '' || $size === -1 || $type === '') {
				throw new InvalidActionException('missing details');
			}

			// The app's download ceiling, applied to uploads too: the file is read into
			// memory to be hashed, sniffed and (for an image) decoded.
			$this->refuseOversized($size, $type);

			$this->logger->debug('[' . static::class . '] mediaNew: ' . json_encode($file));

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
	 * Attaches a file the viewer already has in Nextcloud (not a Mastodon route).
	 *
	 * The file is copied, not referenced, so moving or deleting the original
	 * cannot empty a federated post, and the attachment is scoped like an
	 * upload. The path is resolved inside the viewer's user folder only.
	 *
	 * Web app only (session and CSRF token): a bearer token is a 403 whatever its
	 * scopes, since reading the user's files is more than uploading media.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[UserRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/media/from-file')]
	public function mediaFromFile(): DataResponse {
		try {
			$this->requireWebSession();
			$this->initViewer(true);

			$input = $this->convertInput(file_get_contents('php://input'));
			$path = trim((string)($input['path'] ?? $this->request->getParam('path', '')));
			if ($path === '') {
				throw new InvalidActionException('no file named');
			}

			$file = $this->ownFile($this->currentSession(), $path);
			$this->refuseOversized((int)$file->getSize(), $file->getMimeType());

			$description = (string)($input['description'] ?? $this->request->getParam('description', ''));

			// through a temp file, so sniffing, the size guard and resizing are the
			// upload's own code
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

			$attachment = $this->storeAttachment($tmpPath, $description, '', $file->getName());
			// the file the post is made from, so its replies can be shown on it
			$this->fileCommentsService->rememberAttachment($this->currentSession(), (int)$file->getId(), $attachment->getId());

			return new DataResponse($attachment, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * One file out of the viewer's own storage. A path is resolved relative to
	 * `getUserFolder()`, so a traversal is not found; the containment check after
	 * the lookup makes sure of it, since this route returns file contents.
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
		// Not public until a post says so: `public` decides whether the
		// unauthenticated /media/{uuid} route serves the file, and the visibility is
		// applied when the status is created (DocumentService::mediaAttachmentsForEdit()).
		$document->setPublic(false);
		$document->setDescription($description);

		// Where the subject is, so a crop keeps it in frame. A malformed focus is
		// ignored, not refused, so the upload is not lost over it.
		$parsed = ($focus === '') ? null : Document::parseFocus($focus);
		if ($parsed !== null) {
			$document->setFocus($parsed[0], $parsed[1]);
		}

		$this->cacheDocumentService->saveFromTempToCache($document, $tmpPath);
		if ($thumbnailPath !== '') {
			$this->cacheDocumentService->applyCustomPoster($document, $thumbnailPath);
		}
		if ($description === '' && $filename !== '' && CacheDocumentService::isDocumentMime($document->getMediaType())) {
			// a file's name is no description: a picture without alt text stays
			// undescribed
			$document->setDescription(mb_substr($filename, 0, 255));
		}
		$service = AP::instance()->getInterfaceForItem($document);
		$service->save($document);

		$mediaAttachment = $document->convertToMediaAttachment($this->urlGenerator);

		$this->logger->debug('generated attachment: ' . json_encode($mediaAttachment));

		return $mediaAttachment;
	}

	/**
	 * Same upload as mediaNew: Mastodon clients POST /api/v2/media and fall back
	 * to v1 on a 404.
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
	// Both limits, like its POST sibling: Nextcloud applies `UserRateLimit` only
	// to a session, and OAuth clients have none.
	#[AnonRateLimit(limit: 30, period: 60)]
	#[UserRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/media/{nid}')]
	public function mediaUpdate(int|string $nid): Response {
		try {
			$this->initViewer(true);

			$document = $this->ownDocument($nid);
			// a post keeps its own copy of what it carries, so changing an attached
			// upload needs an edit of the post; Mastodon refuses this too
			if ($this->documentService->isAttachedToAPostBy($document, $this->viewer->getId())) {
				throw new InvalidActionException(
					'this media is attached to a post; edit the post with media_attributes to change it'
				);
			}
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
	 * @return Response
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	// The most generous limits in this file, as for mediaLadderFile() and
	// mediaPlaylistFile(): a timeline loads forty to sixty of these a screen, and
	// other fediverse servers fetch media through it unsigned, so a busy peer
	// arrives as one anonymous address. This caps abuse; it is not a bandwidth
	// control, which belongs in front of the server.
	#[AnonRateLimit(limit: 600, period: 60)]
	#[UserRateLimit(limit: 3000, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/media/{uuid}')]
	public function mediaOpen(string $uuid): Response {
		if (strpos($uuid, '.') > 0) {
			[$uuid] = explode('.', $uuid, 2);
		}

		try {
			// Any local copy is served to whoever holds its uuid: other fediverse
			// servers fetch media unsigned for readers they have checked, so limiting
			// this to `public` rows would break the pictures of followers-only and direct
			// posts. The uuid is a random v4 handed only to the post's audience; see
			// DocumentService::getFromUuid().
			[$file, $document] = $this->documentService->getFromUuid($uuid);

			// range-capable, so a video can be seeked and its duration read without
			// fetching the whole file
			$response = new RangedFileResponse(
				$file,
				self::servedMediaType($document, $uuid, $file),
				$this->request->getHeader('Range'),
				// a timeline is forty to sixty of these a screen, and the bytes
				// behind a uuid never change
				$this->request->getHeader('If-None-Match')
			);

			// The bytes behind a uuid never change, but only a browser's own cache may
			// keep a non-public one: a shared proxy would serve it to anyone.
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
	 * A federated video, passed through chunk by chunk from the instance that
	 * holds it and stored nowhere (`StreamedRemoteResponse`): the CSP allows only
	 * `media-src 'self'`, and readers should not contact hosts they did not
	 * choose.
	 *
	 * Unauthenticated like `mediaOpen()`, being a media URL handed out with its
	 * post. It takes a row id this app wrote as streamed, never a URL, so it is
	 * no open proxy.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	// generous: a player asks for the first megabyte, the moov atom at the end
	// and a range per seek, and a tight limit stops playback midway
	#[AnonRateLimit(limit: 120, period: 60)]
	#[UserRateLimit(limit: 600, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/media/stream/{nid}')]
	public function mediaStream(int|string $nid): Response {
		try {
			$opened = $this->documentService->openStreamed(
				$nid, $this->request->getHeader('Range')
			);
			$document = $opened['document'];
			$downloadOnly = self::isDownloadOnly($document);

			$headers = [
				'Content-Type' => $downloadOnly ? 'application/octet-stream' : $document->getMediaType(),
				// what makes a player offer a seek bar at all
				'Accept-Ranges' => 'bytes',
				// the bytes behind a row never change, and the row is only
				// named by the post it hangs off
				'Cache-Control' => 'private, max-age=' . self::MEDIA_CACHE_SECONDS,
				// a file to play, never a document to interpret: the origin's type is not
				// passed on as a licence to sniff
				'X-Content-Type-Options' => 'nosniff',
			];
			if ($downloadOnly) {
				$headers['Content-Disposition'] = "attachment; filename*=UTF-8''" . rawurlencode($document->getDescription() !== '' ? $document->getDescription() : 'download');
				$headers['Content-Security-Policy'] = "default-src 'none'; sandbox";
			}

			// only the headers a player needs to make sense of a partial answer
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

	/** Whether a streamed file must be offered as a download instead of inline media. */
	private static function isDownloadOnly(Document $document): bool {
		$mediaType = strtolower($document->getMediaType());
		$inlineTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'application/x-mpegurl', 'application/vnd.apple.mpegurl'];

		return $document->isStreamed()
			&& !in_array($mediaType, $inlineTypes, true)
			&& !str_starts_with($mediaType, 'audio/')
			&& !str_starts_with($mediaType, 'video/');
	}

	/**
	 * An HLS playlist, with every URI in it pointed back through this server, so
	 * hls.js does not fetch segments from the origin (see `mediaStream()`).
	 * PeerTube publishes HLS by default, which only Safari plays natively.
	 *
	 * Unauthenticated like the other media routes: a media URL handed out with
	 * its post, taking a row id rather than a URL.
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
					'social.MediaApi.mediaPlaylistFile', ['nid' => $nid, 'u' => $url]
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

	/**
	 * One of a video's subtitle tracks, passed through as `text/vtt`
	 * (`DocumentService::caption()`). Keyed on the post and the track's index,
	 * never a URL, and the post is read as the viewer may see it. 404 for a post
	 * the viewer may not see, a missing track, or one that is not WebVTT.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[UserRateLimit(limit: 300, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/media/captions/{nid}/{index}.vtt')]
	public function mediaCaption(int|string $nid, int $index): Response {
		try {
			$this->initViewer(false);
			$post = $this->streamService->getStreamByNid($nid);
			$track = $this->documentService->caption($post, $index);

			return new DataDisplayResponse($track, Http::STATUS_OK, [
				'Content-Type' => 'text/vtt; charset=utf-8',
				'Cache-Control' => 'private, max-age=3600',
				'X-Content-Type-Options' => 'nosniff',
			]);
		} catch (NotFoundException|StreamNotFoundException $e) {
			return new DataResponse(['error' => 'no such caption'], Http::STATUS_NOT_FOUND);
		} catch (Exception $e) {
			$this->logger->warning('issues while mediaCaption', ['exception' => $e]);

			return new DataResponse(['error' => 'no such caption'], Http::STATUS_NOT_FOUND);
		}
	}

	// --- a local video's own ladder ---------------------------------------

	/**
	 * The master playlist of a stored video: which sizes it exists at.
	 *
	 * Addressed by uuid like `/media/{uuid}`, so a ladder is no easier to find
	 * than its video. 404 when there is no ladder, so a player falls back to the
	 * plain file instead of reporting an empty master as broken.
	 *
	 * Every address in this group ends in its content's extension, at different
	 * depths: ffmpeg's HLS demuxer refuses segment URIs whose extension it does
	 * not recognise.
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
					'social.MediaApi.mediaLadderRung', ['uuid' => $uuid, 'height' => $height]
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
	 * One rung's playlist: where each segment is inside that rung's file. The
	 * stored playlist has a placeholder for the media URI, filled in here since
	 * the route's address is not known when ffmpeg writes it.
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
					'social.MediaApi.mediaLadderFile', ['uuid' => $uuid, 'height' => $rung]
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
	 * One rung's file: the whole fragmented MP4, served with ranges. Every
	 * segment is a byte range into it, so a player asks this route hundreds of
	 * times, hence the generous limits.
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
			// cached as the video itself: for ever in the reader's own cache, and in a
			// shared one only when the post is public
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
	 * One file out of such a playlist: a segment, a key, or a nested playlist.
	 * The URL must be on the host of the playlist the nid names, so this is no
	 * open proxy.
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
	 * The ceiling an upload is held to: `max_size` for a picture, which is read
	 * whole into memory, and `max_video_size` for a video, which is copied in
	 * chunks.
	 *
	 * The mime is the one the client stated; the stored type is sniffed later and
	 * `filterMimeTypes()` refuses a file that lied, so a lie buys only a larger
	 * upload that is thrown away.
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
	 * The media type of the copy a uuid names. A video's resized copy is its
	 * JPEG poster frame, which a browser with `nosniff` refuses as `video/mp4`.
	 * The stored type was sniffed when the bytes were written; the URL's
	 * extension is not consulted.
	 */
	private static function servedMediaType(Document $document, string $uuid, ISimpleFile $file): string {
		$mediaType = $document->getMediaType();
		if ($uuid !== $document->getResizedCopy()) {
			return $mediaType;
		}

		if (!str_starts_with($mediaType, 'image/')) {
			return VideoThumbnailService::MEDIA_TYPE;
		}

		// the thumbnail of a cached actor icon is WebP whatever the original
		// was (`CacheDocumentService::AVATAR_THUMBNAIL`); the first twelve
		// bytes say which, and only a remote picture's resized copy can be one
		if ($mediaType !== 'image/webp' && !$document->isLocalUpload() && self::isWebp($file)) {
			return 'image/webp';
		}

		return $mediaType;
	}

	/** Whether a stored file starts with WebP's RIFF header. */
	private static function isWebp(ISimpleFile $file): bool {
		try {
			$stream = $file->read();
		} catch (\Throwable $e) {
			return false;
		}
		if (!is_resource($stream)) {
			return false;
		}

		try {
			$head = (string)fread($stream, 12);
		} finally {
			fclose($stream);
		}

		return strlen($head) === 12 && str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP';
	}

	/**
	 * Attaches a picture from the instance's library to a post being written.
	 * It is copied through `storeAttachment()`, like an upload, so removing it
	 * from the library cannot empty a federated post.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[UserRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/media/from-gif')]
	public function mediaFromGif(): DataResponse {
		try {
			$this->initViewer(true);

			$input = $this->convertInput(file_get_contents('php://input'));
			$slug = trim((string)($input['slug'] ?? $this->request->getParam('slug', '')));
			if ($slug === '') {
				throw new InvalidActionException('no picture named');
			}

			$gif = $this->gifService->bySlug($slug);
			if ($gif === null) {
				throw new InvalidActionException('no such picture');
			}

			$file = $this->gifService->file($slug);
			$this->refuseOversized($file->getSize(), $gif->getMediaType());

			$tmpPath = $this->tempManager->getTemporaryFile();
			if ($tmpPath === false) {
				throw new InvalidActionException('no temporary file to copy into');
			}

			if (file_put_contents($tmpPath, $file->getContent()) === false) {
				throw new InvalidActionException('the picture could not be copied');
			}

			// the title is the description unless the writer gave one, so a library
			// picture does not arrive without alt text
			$description = trim((string)($input['description'] ?? $this->request->getParam('description', '')));
			if ($description === '') {
				$description = $gif->getTitle();
			}

			return new DataResponse(
				$this->storeAttachment($tmpPath, $description, '', $gif->getFilename()),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}
}
