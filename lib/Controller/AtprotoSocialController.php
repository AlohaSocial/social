<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Atproto\AppViewClient;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\RecordMapper\InteractionPublisher;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

class AtprotoSocialController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $session,
		private readonly IdentityService $identities,
		private readonly AppViewClient $appview,
		private readonly InteractionPublisher $publisher,
	) {
		parent::__construct($appName, $request);
	}
	private function read(string $method, array $params): DataResponse {
		if (!$this->identities->isEnabled()) {
			return new DataResponse(['error' => 'Unavailable'], 503);
		}
		try {
			return new DataResponse($this->appview->get($method, $params));
		} catch (\Throwable) {
			return new DataResponse(['error' => 'UpstreamUnavailable'], 502);
		}
	}
	#[NoAdminRequired] #[FrontpageRoute(verb: 'GET', url: '/api/atproto/search/actors')]
	public function searchActors(string $q): DataResponse {
		return $this->read('app.bsky.actor.searchActors', ['q' => mb_substr($q, 0, 100), 'limit' => 25]);
	}
	#[NoAdminRequired] #[FrontpageRoute(verb: 'GET', url: '/api/atproto/profile')]
	public function profile(string $actor): DataResponse {
		return $this->read('app.bsky.actor.getProfile', ['actor' => mb_substr($actor, 0, 255)]);
	}
	#[NoAdminRequired] #[FrontpageRoute(verb: 'GET', url: '/api/atproto/thread')]
	public function thread(string $uri): DataResponse {
		return $this->read('app.bsky.feed.getPostThread', ['uri' => mb_substr($uri, 0, 1024), 'depth' => 6, 'parentHeight' => 6]);
	}
	#[NoAdminRequired] #[FrontpageRoute(verb: 'GET', url: '/api/atproto/author-feed')]
	public function authorFeed(string $actor): DataResponse {
		return $this->read('app.bsky.feed.getAuthorFeed', ['actor' => mb_substr($actor, 0, 255), 'limit' => 25]);
	}
	private function write(string $kind, string $target, ?string $cid = null, bool $remove = false): DataResponse {
		$user = $this->session->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'AuthenticationRequired'], 401);
		}
		try {
			$this->publisher->publish($user->getUID(), $kind, $target, $cid, $remove);
			return new DataResponse(['success' => true]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => 'InvalidRequest', 'message' => $e->getMessage()], 400);
		} catch (\Throwable) {
			return new DataResponse(['error' => 'Unavailable', 'message' => 'Interaction could not be committed. Registration may still be pending.'], 503);
		}
	}
	#[NoAdminRequired] #[FrontpageRoute(verb: 'POST', url: '/api/atproto/follow')]
	public function follow(string $targetDid): DataResponse {
		return $this->write('follow', $targetDid);
	}
	#[NoAdminRequired] #[FrontpageRoute(verb: 'POST', url: '/api/atproto/unfollow')]
	public function unfollow(string $targetDid): DataResponse {
		return $this->write('follow', $targetDid, null, true);
	}
	#[NoAdminRequired] #[FrontpageRoute(verb: 'POST', url: '/api/atproto/like')]
	public function like(string $uri, string $cid): DataResponse {
		return $this->write('like', $uri, $cid);
	}
	#[NoAdminRequired] #[FrontpageRoute(verb: 'POST', url: '/api/atproto/unlike')]
	public function unlike(string $uri): DataResponse {
		return $this->write('like', $uri, null, true);
	}
	#[NoAdminRequired] #[FrontpageRoute(verb: 'POST', url: '/api/atproto/repost')]
	public function repost(string $uri, string $cid): DataResponse {
		return $this->write('repost', $uri, $cid);
	}
	#[NoAdminRequired] #[FrontpageRoute(verb: 'POST', url: '/api/atproto/undorepost')]
	public function undoRepost(string $uri): DataResponse {
		return $this->write('repost', $uri, null, true);
	}
}
