<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Db\FollowedTagsRequest;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\InsufficientScopeException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\TimelineRevisionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Following a hashtag, as Mastodon's client API does it.
 *
 * A followed hashtag is not a relationship with anybody: it puts the *public*
 * posts carrying it into the follower's home timeline, and that is the whole
 * of it. The timeline half lives in `StreamRequest::followedTagNids()`; these
 * four routes are what a client uses to say which tags, and to draw the
 * follow button.
 *
 * `#[PublicPage]` with `#[NoCSRFRequired]`, like `ApiController`, and for the
 * same reason: a Mastodon client authenticates with a bearer token and has no
 * Nextcloud session or CSRF token to present, so `#[NoAdminRequired]` would
 * refuse every real caller before the handler ran. Every route here then
 * requires a viewer itself — no token, no session, 401 — so nothing is public
 * in fact.
 */
class TagController extends Controller {
	/** What Mastodon caps a page of this list at. */
	private const MAX_LIMIT = 50;

	private string $bearer = '';
	private ?SocialClient $client = null;
	private ?Person $viewer = null;

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private AccountService $accountService,
		private ClientService $clientService,
		private HashtagService $hashtagService,
		private FollowedTagsRequest $followedTagsRequest,
		private TimelineRevisionService $timelineRevisionService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$authHeader = trim($this->request->getHeader('Authorization'));
		if (strpos($authHeader, ' ')) {
			[$authType, $authToken] = explode(' ', $authHeader);
			if (strtolower($authType) === 'bearer') {
				$this->bearer = $authToken;
			}
		}
	}

	/**
	 * The hashtags the viewer follows, newest follow first, with the `Link`
	 * header Mastodon pages with.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/followed_tags')]
	public function followedTags(int $limit = 20, int $max_id = 0, int $min_id = 0): Response {
		try {
			$this->initViewer(['read:follows']);
			$limit = max(1, min(self::MAX_LIMIT, $limit));

			$rows = $this->followedTagsRequest->getByActor(
				$this->viewer->getId(), $limit, $max_id, $min_id
			);

			$tags = [];
			foreach ($rows as $row) {
				$tags[] = $this->hashtagService->tagEntity($row['hashtag'], true);
			}

			return Revalidation::byContent($this->request, $this->paged($tags, $limit, array_column($rows, 'id')));
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** One hashtag, and whether the viewer follows it. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/tags/{hashtag}')]
	public function get(string $hashtag): DataResponse {
		try {
			$this->initViewer(['read:follows', 'read:statuses']);
			$tag = $this->tag($hashtag);

			return new DataResponse(
				$this->hashtagService->tagEntity(
					$tag, $this->followedTagsRequest->isFollowing($this->viewer->getId(), $tag)
				),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Follows the hashtag. Following one that is already followed is not an
	 * error — a client that lost the answer and retried gets the same tag back.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	// `/api/v1/tags/{hashtag}` cannot read this as a tag named "foo/follow"
	// because `{hashtag}` matches one segment. It has to stay that way: a `.+`
	// requirement on the lookup would swallow both action routes.
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/tags/{hashtag}/follow')]
	public function follow(string $hashtag): DataResponse {
		try {
			$this->initViewer(['write:follows', 'follow']);
			$tag = $this->tag($hashtag);
			$this->followedTagsRequest->save($this->viewer->getId(), $tag);
			// posts carrying it are part of their home timeline from now on,
			// which no id it is keyed on says — see TimelineRevisionService
			$this->timelineRevisionService->bumpForActor($this->viewer->getId());

			return new DataResponse($this->hashtagService->tagEntity($tag, true), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Unfollows it; unfollowing what was never followed is not an error either. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/tags/{hashtag}/unfollow')]
	public function unfollow(string $hashtag): DataResponse {
		try {
			$this->initViewer(['write:follows', 'follow']);
			$tag = $this->tag($hashtag);
			$this->followedTagsRequest->delete($this->viewer->getId(), $tag);
			$this->timelineRevisionService->bumpForActor($this->viewer->getId());

			return new DataResponse($this->hashtagService->tagEntity($tag, false), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The tag a request names, in the one form this app stores and compares.
	 *
	 * @throws Exception when what was named is not a tag at all
	 */
	private function tag(string $hashtag): string {
		$tag = FollowedTagsRequest::normalise($hashtag);
		if ($tag === '') {
			throw new InvalidResourceException('not a hashtag');
		}

		return $tag;
	}

	/**
	 * Resolves the viewer from the bearer token, or from the Nextcloud session
	 * when there is none — the same order `ApiController` uses, because the
	 * same clients call both.
	 *
	 * @param string[] $scopes any one of which satisfies a bearer token
	 *
	 * @throws ClientNotFoundException there is nobody to answer for
	 * @throws InsufficientScopeException the token is fine, its grant is not
	 */
	private function initViewer(array $scopes): void {
		try {
			$userId = $this->currentSession($scopes);
			$this->viewer = $this->accountService->getActorFromUserId($userId);
		} catch (InsufficientScopeException $e) {
			throw $e;
		} catch (Exception $e) {
			// a missing, stale or made-up token is ordinary internet noise and
			// is answered with a 401, not logged as a fault
			$this->logger->debug('[TagController] no usable credentials', [
				'exception' => $e->getMessage(),
			]);

			throw new ClientNotFoundException('the access_token was revoked');
		}
	}

	/**
	 * @param string[] $scopes
	 *
	 * @throws ClientNotFoundException
	 * @throws InsufficientScopeException
	 */
	private function currentSession(array $scopes): string {
		if ($this->bearer !== '') {
			$this->client = $this->clientService->getFromToken($this->bearer);
			$this->checkTokenScope($scopes);

			return $this->client->getAuthUserId();
		}

		$user = $this->userSession->getUser();
		if ($user !== null && $this->request->passesCSRFCheck()) {
			return $user->getUID();
		}

		throw new ClientNotFoundException('userId not defined');
	}

	/**
	 * A scope is satisfied by itself or by any of its granular variants
	 * ('write' by 'write:follows').
	 *
	 * @param string[] $accepted
	 *
	 * @throws InsufficientScopeException
	 */
	private function checkTokenScope(array $accepted): void {
		foreach ($accepted as $scope) {
			$broad = strstr($scope, ':', true);
			$broad = ($broad === false) ? $scope : $broad;

			foreach ($this->client?->getAuthScopes() ?? [] as $granted) {
				if ($granted === $scope || $granted === $broad) {
					return;
				}
			}
		}

		throw new InsufficientScopeException(
			'token scope does not allow this request (needs ' . implode(' or ', $accepted) . ')'
		);
	}

	/**
	 * A page of Tag entities with the `Link` header masto.js reads its cursor
	 * from — without it Elk and Phanpy show the first page of a list and stop.
	 *
	 * The cursor is the `social_followed_tag` row id, not the tag: a tag can be
	 * unfollowed and followed again, so its name does not move in one
	 * direction and cannot page.
	 *
	 * @param int[] $ids the row ids of the page, in its order
	 */
	private function paged(array $tags, int $limit, array $ids): DataResponse {
		$response = new DataResponse($tags, Http::STATUS_OK);
		if ($ids === []) {
			return $response;
		}

		$links = [];
		if (count($ids) >= $limit) {
			// a page shorter than the limit is the last one
			$links[] = '<' . $this->pageUrl(['max_id' => (string)min($ids)]) . '>; rel="next"';
		}
		$links[] = '<' . $this->pageUrl(['min_id' => (string)max($ids)]) . '>; rel="prev"';

		$response->addHeader('Link', implode(', ', $links));

		return $response;
	}

	/**
	 * This request's own URL with the cursor replaced, so every other filter
	 * the client sent survives into the next page.
	 */
	private function pageUrl(array $cursor): string {
		$uri = $this->request->getRequestUri();
		$path = $uri;
		$query = [];

		$pos = strpos($uri, '?');
		if ($pos !== false) {
			$path = substr($uri, 0, $pos);
			parse_str(substr($uri, $pos + 1), $query);
		}

		unset($query['max_id'], $query['min_id'], $query['since_id'], $query['_route']);

		return $path . '?' . http_build_query(array_merge($query, $cursor));
	}

	/**
	 * A failure as a Mastodon client can act on it: `{"error": "..."}` with a
	 * status that says what to do about it. An unrecognised failure is a bug
	 * on this side, so it answers 500 and its message is not sent on — these
	 * are `#[PublicPage]` routes, and echoing getMessage() publishes whatever
	 * the failure happened to name.
	 */
	private function error(Throwable $e): DataResponse {
		if ($e instanceof InsufficientScopeException) {
			return new DataResponse(
				['error' => $e->getMessage()],
				Http::STATUS_FORBIDDEN,
				['WWW-Authenticate' => 'Bearer error="insufficient_scope"']
			);
		}

		if ($e instanceof ClientNotFoundException) {
			$message = trim($e->getMessage());

			return new DataResponse(
				['error' => ($message === '') ? 'the access_token is invalid' : $message],
				Http::STATUS_UNAUTHORIZED,
				['WWW-Authenticate' => 'Bearer error="invalid_token"']
			);
		}

		if ($e instanceof InvalidResourceException) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$this->logger->error('[TagController] unexpected failure answering the client API', [
			'exception' => $e,
			'route' => (string)$this->request->getParam('_route', ''),
		]);

		return new DataResponse(
			['error' => 'internal server error'], Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}
}
