<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Channel;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ChannelService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\PeerTubeApiService;
use OCA\Social\Service\SearchService;
use OCA\Social\Service\StreamService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The routes PeerTube's own clients ask for (the official app, Tubelab,
 * Fedilab), translated to PeerTube's API; the shapes live in
 * `PeerTubeApiService`.
 *
 * Read-only: PeerTube's resumable upload is not implemented, and posting
 * comments here would bypass the composer's review queue. The routes are
 * only reachable by real clients when this instance answers at its domain
 * root, since they build `https://<host>/api/v1/...` themselves.
 */
class PeerTubeApiController extends ClientApiController {
	/** What one page holds, and the most one request may ask for. */
	private const LIMIT = 20;
	private const MAX_LIMIT = 100;

	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		ClientService $clientService,
		private PeerTubeApiService $peerTubeApiService,
		private StreamService $streamService,
		private ChannelService $channelService,
		private SearchService $searchService,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
	}

	/**
	 * What the client reads once, on launch, before anybody signs in; public.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/config')]
	public function config(): DataResponse {
		return new DataResponse($this->peerTubeApiService->config(), Http::STATUS_OK);
	}

	/**
	 * The client ids PeerTube hands out before a login. PeerTube reuses one pair
	 * per instance, but this app stores only a hash of each secret, so a fresh
	 * registration is answered, as through `/api/v1/apps`.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 3600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/oauth-clients/local')]
	public function oauthClient(): DataResponse {
		try {
			$client = $this->clientService->registerPeerTubeClient();

			return new DataResponse([
				'client_id' => $client->getAppClientId(),
				'client_secret' => $client->getAppClientSecret(),
			], Http::STATUS_OK);
		} catch (Throwable $e) {
			$this->logger->warning('could not answer the PeerTube client credentials', [
				'exception' => $e,
			]);

			return new DataResponse(['error' => 'no client credentials'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * The videos this instance has, newest first: the public timeline narrowed
	 * with the same `only_video` as the Videos timeline.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[UserRateLimit(limit: 300, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/videos')]
	public function videos(int $start = 0, int $count = self::LIMIT): DataResponse {
		try {
			$this->softViewer();

			return new DataResponse($this->peerTubeApiService->page(array_map(
				fn (Stream $post): array => $this->peerTubeApiService->video($post),
				$this->videoPage($start, $count)
			)), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->failed($e, 'videos');
		}
	}

	/**
	 * One video, by the id this API gives it, not by its uuid: that is a one-way
	 * hash of the post's address with nothing to look it up by, so a uuid is a
	 * 404. The `\d+` requirement also keeps this from matching
	 * `/api/v1/videos/continue`.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	#[UserRateLimit(limit: 600, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/videos/{id}', requirements: ['id' => '\d+'])]
	public function video(string $id): DataResponse {
		try {
			$this->softViewer();
			$post = $this->videoOf($id);
			if ($post === null) {
				return new DataResponse(['error' => 'no such video'], Http::STATUS_NOT_FOUND);
			}

			return new DataResponse(
				$this->peerTubeApiService->video($post, true), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->failed($e, 'video');
		}
	}

	/**
	 * The replies to one video, as PeerTube's comment threads (read-only).
	 * `totalReplies` is 0, since a wrong count makes PeerTube draw branches
	 * leading nowhere.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[UserRateLimit(limit: 300, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/videos/{id}/comment-threads', requirements: ['id' => '\d+'])]
	public function comments(string $id, int $count = self::LIMIT): DataResponse {
		try {
			$this->softViewer();
			$post = $this->videoOf($id);
			if ($post === null) {
				return new DataResponse(['error' => 'no such video'], Http::STATUS_NOT_FOUND);
			}

			// The thread the web page's status context is drawn from.
			$context = $this->streamService->getContextByNid($post->getNid());
			$replies = array_slice(
				$context['descendants'] ?? [], 0, min(self::MAX_LIMIT, max(1, $count))
			);

			return new DataResponse($this->peerTubeApiService->page(array_map(
				fn (Stream $reply): array => $this->peerTubeApiService->comment($reply, $post->getNid()),
				$replies
			)), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->failed($e, 'comments');
		}
	}

	/** The channels of this instance. */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/video-channels')]
	public function channels(int $count = self::LIMIT): DataResponse {
		try {
			return new DataResponse($this->peerTubeApiService->page(array_map(
				fn (Channel $channel): array => $this->peerTubeApiService->channel($channel),
				$this->channelService->all(min(self::MAX_LIMIT, max(1, $count)))
			)), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->failed($e, 'channels');
		}
	}

	/** One channel, by its handle. */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/video-channels/{handle}')]
	public function channelByHandle(string $handle): DataResponse {
		try {
			$channel = $this->channelService->byHandle($handle);
			if ($channel === null) {
				return new DataResponse(['error' => 'no such channel'], Http::STATUS_NOT_FOUND);
			}

			return new DataResponse(
				$this->peerTubeApiService->channel($channel, true), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->failed($e, 'channel');
		}
	}

	/** The signed-in account, as PeerTube's `User`. */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	#[UserRateLimit(limit: 300, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/users/me')]
	public function me(): DataResponse {
		try {
			$this->initViewer(['read']);
			if ($this->viewer === null) {
				return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
			}

			return new DataResponse(
				$this->peerTubeApiService->user($this->viewer, $this->viewer->getUserId()),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}
	}

	/**
	 * Videos matching a word, or one fetched by its address, through the same
	 * `SearchService` as the rest of the app, narrowed to videos.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[UserRateLimit(limit: 120, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/search/videos')]
	public function searchVideos(string $search = '', int $count = self::LIMIT): DataResponse {
		try {
			$this->softViewer();
			$search = trim($search);
			if ($search === '') {
				return new DataResponse($this->peerTubeApiService->page([]), Http::STATUS_OK);
			}

			$found = $this->searchService->searchStreamContent(
				$search, min(self::MAX_LIMIT, max(1, $count))
			);

			$videos = [];
			foreach ($found as $post) {
				if ($this->isVideo($post)) {
					$videos[] = $this->peerTubeApiService->video($post);
				}
			}

			return new DataResponse($this->peerTubeApiService->page($videos), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->failed($e, 'search');
		}
	}

	/**
	 * A viewer where credentials are sent, and nobody otherwise: every route is
	 * public, since a PeerTube client browses before signing in.
	 */
	private function softViewer(): void {
		try {
			$this->initViewer(['read']);
		} catch (Throwable $e) {
			$this->viewer = null;
		}
	}

	/**
	 * One page of this instance's videos.
	 *
	 * @return Stream[]
	 */
	private function videoPage(int $start, int $count): array {
		$options = new ProbeOptions();
		$options->setFormat(ACore::FORMAT_LOCAL)
			->setProbe(ProbeOptions::PUBLIC)
			->setOnlyVideo(true)
			->setLimit(min(self::MAX_LIMIT, max(1, $count)));

		$posts = $this->streamService->getTimeline($options);

		// PeerTube pages by offset: it is applied to the page read, so a deep page
		// gets fewer rows rather than drifting ones.
		return ($start > 0) ? array_slice($posts, $start) : $posts;
	}

	/** One video by the id this API gives it, or null. */
	private function videoOf(string $id): ?Stream {
		try {
			$post = $this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($id));
		} catch (Throwable $e) {
			return null;
		}

		return $this->isVideo($post) ? $post : null;
	}

	private function isVideo(Stream $post): bool {
		foreach ($post->getAttachments() as $attachment) {
			$exported = is_array($attachment) ? $attachment : $attachment->asLocal();
			if (($exported['type'] ?? '') === 'video') {
				return true;
			}
		}

		return false;
	}

	private function failed(Throwable $e, string $what): DataResponse {
		$this->logger->warning('a PeerTube client route failed', [
			'route' => $what, 'exception' => $e,
		]);

		return new DataResponse(['error' => 'request failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
	}
}
