<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use InvalidArgumentException;
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
 * For you: the reader's hashtag interests, what teaches them, and the feed
 * made of them.
 *
 * Every route acts on the viewer alone, and is a 404 when the administrator
 * switched the feature off. `#[PublicPage]` with `#[NoCSRFRequired]` as in
 * every client API controller, since a Mastodon app has a bearer token and no
 * session; a session caller still passes the CSRF check in `initViewer()`.
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

	/** Forgets the automatic profile and reading pace. */
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
	 * What a client saw the reader do while they scrolled.
	 *
	 * 204 once the events are taken, whether or not any of them taught
	 * anything. 403 with a `reason` when nothing this reader does is learned
	 * from, so a client stops collecting: `learning_off`, `learning_paused`,
	 * or `notice_pending` while learning waits for the reader to see what it
	 * learns from.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[UserRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/interests/signals')]
	public function signals(array|string $events = []): DataResponse {
		try {
			$this->initViewer(['write:statuses']);
			$this->assertEnabled();
			$settings = $this->interestService->settingsFor($this->viewer()->getUserId());
			if (!$settings['active']) {
				return new DataResponse([
					'error' => 'Nothing is learned from this account\'s reading',
					'reason' => self::notLearningReason($settings),
				], Http::STATUS_FORBIDDEN);
			}
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
	 * `TimelineApiController::timelines()`, whose route ends in a slash; the
	 * router matches this path exactly first. Paged with `max_id` (a place in
	 * the ranking) or `offset`.
	 *
	 * `media` narrows it to `photos` or `videos`, each a ranking of its own;
	 * `only_media` is the two together; `only_short` (`Stream::shortKindOf()`)
	 * wins over both. Anything else is ignored, as for the other media
	 * parameters.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 300, period: 60)]
	#[UserRateLimit(limit: 300, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/timelines/interests')]
	public function timeline(
		int $limit = 20,
		int|string $max_id = 0,
		int $offset = 0,
		string $media = '',
		bool $only_media = false,
		bool $only_short = false,
	): DataResponse {
		try {
			$this->initViewer(['read:statuses']);
			$this->assertEnabled();

			$media = in_array($media, ['photos', 'videos'], true) ? $media : '';
			if ($only_short) {
				$media = 'shorts';
			} elseif ($media === '' && $only_media) {
				$media = 'media';
			}

			return $this->feedPage($limit, (string)$max_id, $offset, $media);
		} catch (Throwable $e) {
			return $this->error($this->translate($e));
		}
	}

	/**
	 * A page of the feed as a Mastodon client reads one: the statuses, and a
	 * `Link` header whose `next` names the last of them.
	 */
	private function feedPage(int $limit, string $maxId, int $offset = 0, string $media = ''): DataResponse {
		$limit = max(1, min(ProbeOptions::MAX_LIMIT, $limit));
		$maxId = ctype_digit($maxId) ? $maxId : '0';
		$posts = $this->interestFeedService->page($this->viewer(), $limit, $maxId, max(0, $offset), $media);
		foreach ($posts as $post) {
			$post->setExportFormat(ACore::FORMAT_LOCAL);
		}

		$response = new DataResponse(
			$this->filterService->apply($posts, Filter::CONTEXT_PUBLIC, $this->viewer()),
			Http::STATUS_OK
		);
		if ($this->interestFeedService->lastPageCountsFilling()) {
			$response->addHeader('X-Social-Filling', '1');
		}

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

	/**
	 * Why a reader whose settings are not `active` is not learned from.
	 *
	 * @param array{learning: bool, paused: bool, instancePaused: bool, noticeAcknowledged: bool, ...} $settings
	 */
	public static function notLearningReason(array $settings): string {
		if (!$settings['learning']) {
			return 'learning_off';
		}
		if ($settings['paused'] || $settings['instancePaused']) {
			return 'learning_paused';
		}

		return 'notice_pending';
	}

	/** @throws ItemNotFoundException the administrator switched it off */
	private function assertEnabled(): void {
		if (!$this->interestService->isEnabled()) {
			throw new ItemNotFoundException('Record not found');
		}
	}

	/** A refusal the service raised, as the 422 the client API answers it with. */
	private function translate(Throwable $e): Throwable {
		if ($e instanceof InvalidArgumentException) {
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
