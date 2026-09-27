<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\Db\FollowedTagsRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\TimelineRevisionService;
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
class TagController extends ClientApiController {
	/** What Mastodon caps a page of this list at. */
	private const MAX_LIMIT = 50;

	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		ClientService $clientService,
		private HashtagService $hashtagService,
		private FollowedTagsRequest $followedTagsRequest,
		private TimelineRevisionService $timelineRevisionService,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
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
}
