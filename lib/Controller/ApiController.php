<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\Client\Status;
use OCA\Social\Model\Post;
use OCA\Social\Model\Report;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\ReportService;
use OCA\Social\Service\SearchService;
use OCA\Social\Service\StreamService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The client API routes that belong to no resource of their own: what an app's
 * credentials say about it, search, saved searches, trending hashtags and
 * reports.
 */
class ApiController extends MastodonApiController {

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
		private HashtagService $hashtagService,
		private ReportService $reportService,
		private SearchService $searchService,
	) {
		parent::__construct($request, $urlGenerator, $userSession, $logger, $clientService, $accountService, $cacheActorService, $streamService, $followService);
	}

	/**
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/apps/verify_credentials')]
	public function appsCredentials() {
		try {
			$this->initViewer(true);

			// `vapid_key` is always present, because a client reads it out of
			// this response before it decides whether to offer push at all; it
			// is empty because this app has no Web Push endpoint, which is the
			// answer that makes a client stop asking.
			if ($this->client === null) {
				return new DataResponse(
					[
						'name' => 'Aloha Social',
						'website' => 'https://github.com/alohasocial/social/',
						'vapid_key' => ''
					], Http::STATUS_OK
				);
			} else {
				return new DataResponse(
					[
						'name' => $this->client->getAppName(),
						// null rather than '' for the same reason the
						// registration route sends null: a client that decodes
						// this as a URL fails on the empty string
						'website' => $this->client->getAppWebsite() === ''
							? null : $this->client->getAppWebsite(),
						'vapid_key' => ''
					], Http::STATUS_OK
				);
			}
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Files a moderation report about an account (and optionally some of its
	 * statuses) for the instance admins.
	 *
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/reports')]
	public function reportNew(): DataResponse {
		try {
			$this->initViewer(true);

			$input = $this->convertInput(file_get_contents('php://input'));
			$accountId = (string)($input['account_id'] ?? '');
			if ($accountId === '') {
				return new DataResponse(['error' => 'account_id is required'], Http::STATUS_UNPROCESSABLE_ENTITY);
			}

			$target = $this->cacheActorService->resolve($accountId, $this->viewer !== null);
			if ($target->getId() === $this->viewer->getId()) {
				return new DataResponse(['error' => 'you cannot report yourself'], Http::STATUS_UNPROCESSABLE_ENTITY);
			}

			$statusIds = $input['status_ids'] ?? [];
			if (!is_array($statusIds)) {
				$statusIds = [$statusIds];
			}
			$statusIds = array_map('strval', $statusIds);

			$report = $this->reportService->reportFromLocal(
				$this->viewer,
				$target,
				$statusIds,
				(string)($input['comment'] ?? ''),
				(string)($input['category'] ?? Report::CATEGORY_OTHER),
				// Mastodon's `forward`: the report also goes to the instance
				// that hosts the account, which is the only one that can act
				// on it. Ignored for a local account -- ReportForwardService
				// decides, so no entry point can forward what must not be
				$this->formBool($input['forward'] ?? false)
			);
			$target->setExportFormat(ACore::FORMAT_LOCAL);

			return new DataResponse($report, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 *
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/saved_searches/list.json')]
	public function savedSearches(): DataResponse {
		try {
			$this->initViewer(true);

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Mastodon's v1 search, which is the v2 one without `statuses` being
	 * optional. `/api/v1/search` used to be the app's own web-UI search — a
	 * Nextcloud envelope, `content` where a client looks for `statuses`, `search=`
	 * where a client sends `q=`, and no bearer token accepted — so a client got
	 * a 200 it could make no sense of, which is worse than a 404. The web UI's
	 * own search goes through `/api/v2/search` like any client's.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	#[UserRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/search')]
	public function search(string $q = '', string $type = '', int $limit = 20, bool $resolve = false): DataResponse {
		return $this->searchV2($q, $type, $limit, $resolve);
	}

	/**
	 * Mastodon's search endpoint: accounts, statuses (the viewer-bounded
	 * full-text search) and hashtags, optionally narrowed with `type`.
	 *
	 * `resolve` is accepted and ignored on purpose: it asks the server to go
	 * and fetch an account or status it has never seen, and every account
	 * search here is already `LIKE '%term%'` over the actor cache. Following an
	 * unknown handle up remotely on an anonymous request would make this route
	 * an outbound-fetch amplifier.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	#[UserRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v2/search')]
	public function searchV2(string $q = '', string $type = '', int $limit = 20, bool $resolve = false): DataResponse {
		try {
			$this->initViewer(true);
			$q = trim($q);
			$limit = min(max($limit, 1), 40);

			$accounts = [];
			if ($type === '' || $type === 'accounts') {
				$found = array_merge(
					$this->searchService->searchUri($q),
					$this->searchService->searchAccounts($q)
				);
				$unique = [];
				foreach ($found as $account) {
					$unique[$account->getId()] = $account->setExportFormat(ACore::FORMAT_LOCAL);
				}
				$accounts = array_slice(array_values($unique), 0, $limit);
			}

			$statuses = [];
			if ($type === '' || $type === 'statuses') {
				$statuses = array_slice($this->searchService->searchStreamContent($q), 0, $limit);

				// `resolve` is the reader saying "I have a link, go and get
				// it". Without it a post found in a browser cannot be replied
				// to or boosted here, because nothing has ever had a reason to
				// ask its server for it. Only on the reader's say-so: this
				// fetches an address they chose.
				if ($resolve && $statuses === []) {
					// as the reader: the content search above is viewer-scoped,
					// so a post they may not see finds nothing there and falls
					// through to here — which used to hand it over in full
					$resolved = $this->searchService->resolveStatus($q, true);
					if ($resolved !== null) {
						$resolved->setExportFormat(ACore::FORMAT_LOCAL);
						$statuses = [$resolved];
					}
				}
			}

			$hashtags = [];
			if ($type === '' || $type === 'hashtags') {
				foreach (array_slice($this->searchService->searchHashtags($q), 0, $limit) as $hashtag) {
					$hashtags[] = [
						'name' => $hashtag['hashtag'],
						'url' => $this->urlGenerator->linkToRouteAbsolute(
							'social.Navigation.timeline', ['path' => 'tags/' . $hashtag['hashtag']]
						),
						'history' => [],
					];
				}
			}

			return new DataResponse(
				['accounts' => $accounts, 'statuses' => $statuses, 'hashtags' => $hashtags],
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The hashtags used most on this instance lately, as Mastodon's Tag
	 * entities. The counts come from the trend the cron already keeps for
	 * every hashtag; this instance counts uses rather than distinct accounts,
	 * so `accounts` is always 0.
	 *
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/trends/tags')]
	public function trendTags(int $limit = 10, string $period = HashtagService::PERIOD_DEFAULT): Response {
		try {
			$this->initViewer(false);
			$limit = max(1, min(20, $limit));

			// the same builder the tag lookup and the follow answers use, so a
			// Tag entity cannot mean one thing here and another there;
			// `following` is left out, as it must be on a public route
			$tags = [];
			foreach ($this->hashtagService->getTrending($limit, $period) as $hashtag) {
				$tags[] = $this->hashtagService->tagEntity($hashtag['hashtag'], null, $period);
			}

			return Revalidation::byContent($this->request, new DataResponse($tags, Http::STATUS_OK));
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

}
