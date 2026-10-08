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
 * The routes PeerTube's own clients ask for.
 *
 * The same move as the Pixelfed routes and for the same reason: the official
 * PeerTube app, Tubelab and Fedilab all exist and are good, and a client
 * somebody already has is worth more than one nobody has written. PeerTube's
 * API is not Mastodon's — different names, different ids, a different idea of
 * what a video is — so this is a translation and not an alias. The shapes live
 * in `PeerTubeApiService`; what is here is which route answers what.
 *
 * **Two things a reader should know before judging this by what is missing.**
 *
 * It is **read-only**. PeerTube's upload is a resumable protocol with a
 * transcoding state machine behind it, and a half-built one that took somebody's
 * file and lost it would be worse than none. Comments are read and not posted,
 * because a client posting through this route would go round the review queue
 * the composer goes through — the same reason the Pixelfed routes do not post
 * either.
 *
 * And it is **gated on the domain root**. No PeerTube client will ask under
 * `/apps/social/`: they all build `https://<host>/api/v1/...` from the address
 * a person types. Every route here is correct and none of them is reachable by
 * a real client until this instance answers at its own root — which is item 1
 * of the Mastodon compatibility list and a decision for whoever runs the
 * server, not something this app can do to a Nextcloud. Until then these serve
 * `curl`, the interop harness, and anybody willing to configure a rewrite.
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
	 * What the client reads once, on launch.
	 *
	 * Public and answered without a viewer, like Pixelfed's: the app asks for
	 * it before anybody has signed in, to decide whether it can talk to this
	 * server at all.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/config')]
	public function config(): DataResponse {
		return new DataResponse($this->peerTubeApiService->config(), Http::STATUS_OK);
	}

	/**
	 * The client ids PeerTube hands out before a login.
	 *
	 * PeerTube mints one pair per instance and hands the same one to everybody
	 * for ever. This app mints a pair per client and **hashes the secret**,
	 * deliberately and as a fix to a real problem, so there is no stored
	 * plaintext to hand back a second time. A fresh registration is answered
	 * instead — which satisfies what the route is for, since a client fetches
	 * a pair immediately before logging in and uses it at once, and is exactly
	 * what a Mastodon client does through `/api/v1/apps`.
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
	 * The videos this instance has, newest first.
	 *
	 * The public timeline narrowed to videos — the same `only_video` the app's
	 * own Videos timeline uses, so the two cannot come to disagree about what
	 * a video is.
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
	 * One video, by the id this API gives it.
	 *
	 * **Not by its uuid**, which the listing does carry. That uuid is derived
	 * from the post's address by a one-way hash — it is the same one this app
	 * publishes over ActivityPub, which is the point of it — so there is
	 * nothing to look it up by without a column to store it in. A uuid here is
	 * a 404 saying which id to use; a client that lists and then fetches has
	 * the id already.
	 *
	 * The `\d+` requirement is also what keeps this from swallowing
	 * `/api/v1/videos/continue`, this app's own "continue watching" route,
	 * which lives at the same depth.
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
	 * The replies to one video, as PeerTube's comment threads.
	 *
	 * Read-only: see the class comment. `totalReplies` on each is 0 rather
	 * than a count — this app threads replies to replies and PeerTube draws a
	 * tree from that number, and a wrong one is a tree with branches that lead
	 * nowhere.
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

			// the same thread the app's own status context is drawn from, so a
			// reply a client sees here is one the web page shows too
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
	 * Videos matching a word, or one fetched by its address.
	 *
	 * The same `SearchService` the rest of this app searches with, narrowed to
	 * videos afterwards — so a video that is findable on one route cannot be
	 * missing from the other.
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
	 * A viewer where there is one, and nobody where there is not.
	 *
	 * Every route here is public: a PeerTube client browses before it signs in,
	 * and a 401 on the first screen is a client that decides this server does
	 * not work. Where credentials *are* sent they are used, so a signed-in
	 * reader sees what they are entitled to see.
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

		// PeerTube pages by offset and this app pages by cursor. Rather than
		// invent a cursor from an offset — which would drift as posts arrive —
		// the offset is applied to the page that was read, and a client asking
		// for a deep page gets fewer rows rather than wrong ones.
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
