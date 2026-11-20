<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Db\DiscoveryRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountRelationService;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\DirectoryService;
use OCA\Social\Service\FeaturedTagService;
use OCA\Social\Service\FediverseDirectoryService;
use OCA\Social\Service\FollowGraphService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\PeerTrendService;
use OCA\Social\Service\PostDecorationService;
use OCA\Social\Service\ProfileHighlightsService;
use OCA\Social\Service\StarterPackService;
use OCA\Social\Service\SuggestionService;
use OCA\Social\Service\TrendService;
use OCP\AppFramework\Controller;
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
use stdClass;
use Throwable;

/**
 * Finding things: the profile directory, who to follow, what is being read,
 * and the hashtags an account pins to its own profile, in one controller
 * because together they make a Mastodon client's explore screen. None of
 * them may show an account or post the caller would not be shown anywhere
 * else.
 *
 * `#[PublicPage]` with `#[NoCSRFRequired]`, as Mastodon clients use a bearer
 * token. As in Mastodon, the directory, trends and another account's featured
 * tags are answered to anybody; suggestions and one's own featured tags
 * require a viewer.
 */
class DiscoveryController extends ClientApiController {

	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		private CacheActorService $cacheActorService,
		ClientService $clientService,
		private DirectoryService $directoryService,
		private SuggestionService $suggestionService,
		private FollowGraphService $followGraphService,
		private TrendService $trendService,
		private FeaturedTagService $featuredTagService,
		private PostDecorationService $postDecorationService,
		private StarterPackService $starterPackService,
		private ProfileHighlightsService $profileHighlightsService,
		private AccountRelationService $accountRelationService,
		private FediverseDirectoryService $fediverseDirectoryService,
		private PeerTrendService $peerTrendService,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
	}

	/**
	 * The local profile directory: only accounts that opted in with
	 * `discoverable`, filtered in the query. `local` is accepted and ignored:
	 * listing cached remote actors would publish somebody else's users.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/directory')]
	public function directory(
		int $offset = 0,
		int $limit = DirectoryService::LIMIT,
		string $order = DiscoveryRequest::ORDER_ACTIVE,
		bool $local = true,
	): DataResponse {
		try {
			$this->initViewer(['read'], false);

			return new DataResponse(
				$this->directoryService->page($order, $limit, $offset), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The directories this instance will ask about people, in the order it asks
	 * them, this instance first, so a reader sees whose directory they search.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/directories')]
	public function directories(): DataResponse {
		try {
			$this->initViewer(['read'], true);

			return new DataResponse($this->fediverseDirectoryService->sources(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Looks for people in those directories. Requires a viewer and is rate
	 * limited, since it makes requests to other servers on the caller's behalf.
	 * `sources` beside `accounts` tells "nobody by that name" apart from "that
	 * server did not answer".
	 *
	 * @param string $q what to look for; empty asks each source who is active
	 * @param string $source one host to ask, or '' for all of them
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[UserRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/directories/search')]
	public function searchDirectories(
		string $q = '',
		string $source = '',
		int $limit = FediverseDirectoryService::LIMIT,
	): DataResponse {
		try {
			$this->initViewer(['read'], true);

			return new DataResponse(
				$this->fediverseDirectoryService->search($q, $source, $limit), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The hashtags other servers are busy with, and a search across them,
	 * attributed to the servers that answered; nothing is stored. See
	 * `PeerTrendService` for which servers can search. `sources` beside `tags`
	 * tells "nobody is talking about that" apart from "no answer".
	 *
	 * @param string $q a tag to look for; empty asks each server what is trending there
	 * @param string $source one host to ask, or '' for all of them
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 10, period: 300)]
	#[UserRateLimit(limit: 30, period: 300)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/directories/hashtags')]
	public function searchHashtagDirectories(
		string $q = '',
		string $source = '',
		int $limit = PeerTrendService::LIMIT,
	): DataResponse {
		try {
			$this->initViewer(['read'], false);

			return new DataResponse($this->peerTrendService->tags($q, $source, $limit), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Accounts the viewer might want to follow, as Mastodon v2 `Suggestion`
	 * entities. SuggestionService excludes accounts already followed, requested,
	 * blocked or muted, those who blocked the viewer, and the viewer.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v2/suggestions')]
	public function suggestions(int $limit = SuggestionService::LIMIT): DataResponse {
		try {
			$this->initViewer(['read']);

			return new DataResponse(
				$this->suggestionService->suggestions($this->viewer->getId(), $limit),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Accounts followed by the accounts the viewer follows (the Followgraph
	 * question), asked of the servers those accounts live on. Not Mastodon's API:
	 * it carries the count and handles behind each suggestion and costs a request
	 * per account followed, so it is on request and rate limited.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 10, period: 300)]
	#[UserRateLimit(limit: 10, period: 300)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/follow_graph')]
	public function followGraph(int $limit = 20): DataResponse {
		try {
			$this->initViewer(['read']);

			$answer = $this->followGraphService->suggestions($this->viewer, $limit);

			return new DataResponse([
				'suggestions' => $answer['suggestions'],
				// What it asked and still needs, so the page can say "follow one more account".
				'asked' => $answer['asked'],
				'needs' => $answer['needs'],
			], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Whether the graph is worth walking, asked when the page opens. One row
	 * count and no network, so unlike the walk it is not rate limited.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/follow_graph/status')]
	public function followGraphStatus(): DataResponse {
		try {
			$this->initViewer(['read']);

			return new DataResponse($this->followGraphService->probe($this->viewer), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The v1 shape of the same list: bare `Account` entities, for clients that
	 * never moved to v2.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/suggestions')]
	public function suggestionsV1(int $limit = SuggestionService::LIMIT): DataResponse {
		try {
			$this->initViewer(['read']);

			$accounts = [];
			foreach ($this->suggestionService->suggestions($this->viewer->getId(), $limit) as $suggestion) {
				$account = $suggestion->getAccount();
				$account->setExportFormat(ACore::FORMAT_LOCAL);
				$accounts[] = $account;
			}

			return new DataResponse($accounts, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * "Stop suggesting this account": Mastodon's
	 * `DELETE /api/v1/suggestions/{account_id}`, permanent (see
	 * `AccountRelationService::TYPE_SUGGESTION_DISMISSED`). Answers an empty
	 * object whether or not the account was ever suggested, as Mastodon does.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/suggestions/{id}')]
	public function suggestionDismiss(string $id): DataResponse {
		try {
			$this->initViewer(['write']);
			$target = $this->cacheActorService->resolve($id, true);

			$this->accountRelationService->dismissSuggestion($this->viewer, $target);

			return new DataResponse(new stdClass(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The public statuses interacted with most in the window. `period` is this
	 * app's own parameter, shared with `/api/v1/trends/tags`; Mastodon clients
	 * send none and get the default.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/trends/statuses')]
	public function trendStatuses(
		int $limit = TrendService::LIMIT,
		int $offset = 0,
		string $period = HashtagService::PERIOD_DEFAULT,
	): DataResponse {
		try {
			$this->initViewer(['read'], false);

			$statuses = $this->trendService->trendingStatuses($period, $limit, $offset);
			// one query for the whole page, as the timelines do it
			$this->postDecorationService->decorate($statuses, $this->viewer?->getId() ?? '');

			return new DataResponse($statuses, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The photos being looked at right now: Pixelfed's `/api/v2/discover/posts`.
	 *
	 * The trending public statuses narrowed to those with media, not a separate
	 * ranking. `media` (this app's own parameter) narrows to one kind, `image`
	 * or `video`; anything else is ignored.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v2/discover/posts')]
	public function discoverPosts(
		int $limit = TrendService::LIMIT,
		int $offset = 0,
		string $period = HashtagService::PERIOD_DEFAULT,
		string $media = '',
	): DataResponse {
		try {
			$this->initViewer(['read'], false);

			$media = in_array($media, ['image', 'video', 'audio'], true) ? $media : '';
			$statuses = $this->trendService->trendingStatuses($period, $limit, $offset, true, $media);
			$this->postDecorationService->decorate($statuses, $this->viewer?->getId() ?? '');

			return new DataResponse($statuses, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The starter packs: named handfuls of accounts worth following. The index
	 * resolves nobody, so drawing the list never waits on other servers. Public,
	 * so a signed-out visitor can see it.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/starter_packs')]
	public function starterPacks(): DataResponse {
		try {
			return new DataResponse($this->starterPackService->packs(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * One pack, with its handles resolved to the profiles held here; those not
	 * held yet are listed as unresolved and fetched in the background.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/starter_packs/{slug}', requirements: ['slug' => '[a-z0-9-]+'])]
	public function starterPack(string $slug): DataResponse {
		try {
			return new DataResponse($this->starterPackService->pack($slug), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Follows everyone in a pack that can be reached: an unreachable host skips
	 * that account rather than failing the lot, and a member not held here is
	 * fetched now. The answer says who was followed.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 10, period: 60)]
	#[UserRateLimit(limit: 10, period: 60)]
	#[FrontpageRoute(
		verb: 'POST',
		url: '/api/v1/starter_packs/{slug}/follow',
		requirements: ['slug' => '[a-z0-9-]+']
	)]
	public function followStarterPack(string $slug): DataResponse {
		try {
			$this->initViewer(['write:follows'], true);

			return new DataResponse(
				['followed' => $this->starterPackService->followAll($this->viewer, $slug)],
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** The links most often attached to a public status in the window. */
	/**
	 * The public posts carrying one link, newest first, for a tapped trending
	 * link (`/api/v1/trends/links`). A missing or unknown `url` is an empty
	 * timeline, not an error.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/timelines/link')]
	public function linkTimeline(
		string $url = '',
		int $limit = 20,
		int|string $max_id = 0,
		int|string $min_id = 0,
	): DataResponse {
		try {
			$this->initViewer(['read'], false);

			$statuses = $this->trendService->linkTimeline($url, $limit, $max_id, $min_id);
			// one query for the whole page, as the timelines do it
			$this->postDecorationService->decorate($statuses, $this->viewer?->getId() ?? '');

			return new DataResponse($statuses, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/trends/links')]
	public function trendLinks(
		int $limit = TrendService::LIMIT,
		int $offset = 0,
		string $period = HashtagService::PERIOD_DEFAULT,
	): DataResponse {
		try {
			$this->initViewer(['read'], false);

			return new DataResponse(
				$this->trendService->trendingLinks($period, $limit, $offset), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** The viewer's own featured tags. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/featured_tags')]
	public function featuredTags(): DataResponse {
		try {
			$this->initViewer(['read:accounts']);

			return new DataResponse(
				$this->featuredTagService->featured($this->viewer->getId()), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Pins a hashtag to the viewer's profile. A name that is not a hashtag, or
	 * one past the advertised `max_featured_tags`, is a 422.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/featured_tags')]
	public function featureTag(string $name = ''): DataResponse {
		try {
			$this->initViewer(['write:accounts']);

			return new DataResponse(
				$this->featuredTagService->feature($this->viewer->getId(), $name), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Answers `{}`, as Mastodon does. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/featured_tags/{id}', requirements: ['id' => '\\d+'])]
	public function unfeatureTag(int $id): DataResponse {
		try {
			$this->initViewer(['write:accounts']);
			$this->featuredTagService->unfeature($this->viewer->getId(), $id);

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The hashtags the viewer posts with most and has not featured, as `Tag`
	 * entities.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	// The `{id}` of unfeatureTag() must stay `\d+` so this path is not taken
	// for a featured tag with the id "suggestions".
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/featured_tags/suggestions')]
	public function featuredTagSuggestions(): DataResponse {
		try {
			$this->initViewer(['read:accounts']);

			return new DataResponse(
				$this->featuredTagService->suggestions($this->viewer->getId()), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Somebody's featured tags. Public, as the profile is; counts come from
	 * public posts and no viewer is used.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/accounts/{account}/featured_tags', requirements: ['account' => '.+'])]
	public function accountFeaturedTags(string $account): DataResponse {
		try {
			$this->initViewer(['read'], false);
			$actor = $this->cacheActorService->resolve($account, $this->viewer !== null);

			return new DataResponse(
				$this->featuredTagService->featured($actor->getId()), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The shape of somebody's posting history, for the top of their profile.
	 * Built from public posts only and never per viewer, so it reveals nothing
	 * about what the viewer may see. Rate limited like the other public reads.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 60, period: 3600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/accounts/{account}/highlights', requirements: ['account' => '.+'])]
	public function accountHighlights(string $account): DataResponse {
		try {
			$this->initViewer(['read'], false);
			$actor = $this->cacheActorService->resolve($account, $this->viewer !== null);

			return new DataResponse(
				$this->profileHighlightsService->forActor($actor), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The account cache hands out relationships relative to the viewer.
	 */
	#[\Override]
	protected function prepareViewer(Person $viewer): Person {
		$this->cacheActorService->setViewer($viewer);

		return $viewer;
	}
}
