<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use InvalidArgumentException;
use OCA\Social\Exceptions\InterestNotRemovableException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Filter;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\FilterService;
use OCA\Social\Service\InterestFeedService;
use OCA\Social\Service\InterestService;
use OCP\AppFramework\Http;
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
 * My interests: the reader's hashtag interests, what teaches them, and the
 * feed made of them.
 *
 * Everything here is the viewer's own and nobody else's, so every route
 * resolves the viewer first and acts on nothing but them. With the feature
 * switched off by the administrator every route is a 404 — the feed, the
 * settings and the signals alike — rather than something that answers and
 * learns nothing.
 *
 * `#[PublicPage]` with `#[NoCSRFRequired]` for the reason every client API
 * controller has them: a Mastodon app has a bearer token and no session. A
 * session caller still has to pass the CSRF check, in `initViewer()`.
 */
class InterestsController extends ClientApiController {
	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		ClientService $clientService,
		private InterestService $interestService,
		private InterestFeedService $interestFeedService,
		private FilterService $filterService,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
	}

	/** The switches, the listed interests in rank order, and the candidates. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/interests')]
	public function index(): DataResponse {
		return $this->answer(['read:accounts'], fn () => $this->interestService->state($this->viewer()));
	}

	/** Adds a hashtag the reader chose. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/interests')]
	public function add(string $tag = ''): DataResponse {
		return $this->answer(['write:accounts'], fn () => $this->interestService->add($this->viewer(), $tag));
	}

	/** Pins a hashtag at a rank. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/interests/{tag}/move')]
	public function move(string $tag, int $position = 0): DataResponse {
		return $this->answer(['write:accounts'], fn () => $this->interestService->move($this->viewer(), $tag, $position));
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/interests/{tag}/pin')]
	public function pin(string $tag): DataResponse {
		return $this->answer(['write:accounts'], fn () => $this->interestService->pin($this->viewer(), $tag));
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/interests/{tag}/unpin')]
	public function unpin(string $tag): DataResponse {
		return $this->answer(['write:accounts'], fn () => $this->interestService->unpin($this->viewer(), $tag));
	}

	/** Takes a hashtag off the list; a followed one answers 422. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/interests/{tag}')]
	public function remove(string $tag): DataResponse {
		return $this->answer(['write:accounts'], fn () => $this->interestService->remove($this->viewer(), $tag));
	}

	/** Forgets everything learned and chosen. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/interests/reset')]
	public function reset(): DataResponse {
		return $this->answer(['write:accounts'], fn () => $this->interestService->reset($this->viewer()));
	}

	/**
	 * Saves the reader's switches: learning, pause, languages, and that they
	 * have seen the notice. Only what the request names is changed.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/interests/settings')]
	public function settings(): DataResponse {
		$patch = [];
		foreach (['learning', 'paused', 'languages', 'noticeAcknowledged'] as $key) {
			$value = $this->request->getParam($key);
			if ($value !== null) {
				$patch[$key] = $value;
			}
		}

		return $this->answer(['write:accounts'], fn () => $this->interestService->saveSettings($this->viewer(), $patch));
	}

	/**
	 * What the web interface saw the reader do while they scrolled.
	 *
	 * 204 whether or not anything was learned: a reader who paused learning
	 * or looked only at untagged posts has done nothing wrong, and the page
	 * sends these from `pagehide`, where nobody reads the answer anyway.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[UserRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/interests/signals')]
	public function signals(array|string $events = []): DataResponse {
		try {
			$this->initViewer(['write:statuses']);
			$this->assertEnabled();
			$this->interestService->recordEvents($this->viewer(), is_array($events) ? $events : []);

			return new DataResponse(null, Http::STATUS_NO_CONTENT);
		} catch (Throwable $e) {
			return $this->error($this->translate($e));
		}
	}

	/** "Less like this": fewer posts like it, and this one out of the feed. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/interests/less/{nid}')]
	public function less(string $nid): DataResponse {
		return $this->answer(['write:statuses'], function () use ($nid): array {
			$this->interestService->lessLikeThis($this->viewer(), $nid);

			return [];
		});
	}

	/** Takes a "less like this" back. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/interests/less/{nid}')]
	public function undoLess(string $nid): DataResponse {
		return $this->answer(['write:statuses'], function () use ($nid): array {
			$this->interestService->undoLessLikeThis($this->viewer(), $nid);

			return [];
		});
	}

	/**
	 * The feed.
	 *
	 * A literal path rather than another name for
	 * `TimelineApiController::timelines()`: that route ends in a slash, and the
	 * router matches this path exactly before it tries adding one, so which
	 * controller the filesystem lists first does not decide it. Paged with
	 * `max_id` like every timeline, though what it names is a place in the
	 * ranking rather than an age; `offset` is there for a client that pages
	 * the way Mastodon's trends are paged.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[UserRateLimit(limit: 300, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/timelines/interests')]
	public function timeline(int $limit = 20, int|string $max_id = 0, int $offset = 0): DataResponse {
		try {
			$this->initViewer(['read:statuses']);
			$this->assertEnabled();

			return $this->feedPage($limit, (string)$max_id, $offset);
		} catch (Throwable $e) {
			return $this->error($this->translate($e));
		}
	}

	/**
	 * A page of the feed as a Mastodon client reads one: the statuses, and a
	 * `Link` header whose `next` names the last of them.
	 */
	private function feedPage(int $limit, string $maxId, int $offset = 0): DataResponse {
		$limit = max(1, min(ProbeOptions::MAX_LIMIT, $limit));
		$maxId = ctype_digit($maxId) ? $maxId : '0';
		$posts = $this->interestFeedService->page($this->viewer(), $limit, $maxId, max(0, $offset));
		foreach ($posts as $post) {
			$post->setExportFormat(ACore::FORMAT_LOCAL);
		}

		$response = new DataResponse(
			$this->filterService->apply($posts, Filter::CONTEXT_PUBLIC, $this->viewer()),
			Http::STATUS_OK
		);

		if (count($posts) >= $limit) {
			$last = end($posts);
			if ($last instanceof Stream) {
				$response->addHeader('Link', '<' . $this->nextUrl((string)$last->getNid()) . '>; rel="next"');
			}
		}

		return $response;
	}

	/**
	 * Resolves the viewer, checks the feature is on, and answers what the call
	 * returns — or the error it raised, as the client API answers errors.
	 *
	 * @param string[] $scopes
	 * @param callable(): mixed $call
	 */
	private function answer(array $scopes, callable $call): DataResponse {
		try {
			$this->initViewer($scopes);
			$this->assertEnabled();

			return new DataResponse($call(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($this->translate($e));
		}
	}

	/** @throws ItemNotFoundException the administrator switched it off */
	private function assertEnabled(): void {
		if (!$this->interestService->isEnabled()) {
			throw new ItemNotFoundException('Record not found');
		}
	}

	/** A refusal the service raised, as the 422 the client API answers it with. */
	private function translate(Throwable $e): Throwable {
		if ($e instanceof InvalidArgumentException || $e instanceof InterestNotRemovableException) {
			return new InvalidResourceException($e->getMessage());
		}

		return $e;
	}

	/** This request's URL with `max_id` moved on, every other parameter kept. */
	private function nextUrl(string $maxId): string {
		$uri = $this->request->getRequestUri();
		$path = $uri;
		$query = [];

		$pos = strpos($uri, '?');
		if ($pos !== false) {
			$path = substr($uri, 0, $pos);
			parse_str(substr($uri, $pos + 1), $query);
		}

		unset($query['max_id'], $query['min_id'], $query['since_id'], $query['offset'], $query['_route']);

		return $path . '?' . http_build_query(array_merge($query, ['max_id' => $maxId]));
	}
}
