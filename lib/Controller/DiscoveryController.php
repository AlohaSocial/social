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
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Service\PeerTrendService;
use OCA\Social\Service\PlaceService;
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
 * and the hashtags an account pins to its own profile.
 *
 * Four features in one controller because they are one thing to a client — a
 * Mastodon "explore" screen calls `/directory`, `/suggestions`, `/trends/*`
 * and `/featured_tags` to draw a single page — and because they share the one
 * rule that matters here: none of them may show an account or a post that the
 * caller would not have been shown anywhere else. A discovery surface is the
 * place where that goes wrong quietly, because nobody asked for the thing it
 * put in front of them.
 *
 * `#[PublicPage]` with `#[NoCSRFRequired]`, like `ApiController`,
 * `ListController` and `HistoryController`: a Mastodon client authenticates
 * with a bearer token and has no Nextcloud session or CSRF token to present.
 *
 * Which routes then require a viewer follows Mastodon exactly, and the split
 * is not arbitrary. The directory, the trends and another account's featured
 * tags are things this instance publishes about itself and are answered to
 * anybody. The suggestions and an account's own featured tags are about the
 * asking account and require a viewer — there is no such thing as an anonymous
 * "accounts you might follow".
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
		private LinkPreviewService $linkPreviewService,
		private PlaceService $placeService,
		private StarterPackService $starterPackService,
		private ProfileHighlightsService $profileHighlightsService,
		private AccountRelationService $accountRelationService,
		private FediverseDirectoryService $fediverseDirectoryService,
		private PeerTrendService $peerTrendService,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
	}

	/**
	 * The local profile directory: the accounts that opted in to being listed.
	 *
	 * Opted in, and nothing else. `discoverable` has been stored on
	 * `social_actor` and federated on the actor since
	 * `Version1000Date20260911000002` and was read by nothing at all — a user
	 * could turn the flag off and it changed nothing, because there was no
	 * listing to be kept out of. It is honoured here as a predicate of the
	 * query, so an account that did not opt in is never a row that was read
	 * and then dropped.
	 *
	 * `local` is accepted and ignored, as documented: only accounts this
	 * instance holds the profile of have the flag, and publishing a directory
	 * of cached remote actors would be this instance listing somebody else's
	 * users.
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
	 * them — this instance first.
	 *
	 * Published rather than assumed by the client, because which servers get
	 * asked is an administrator's decision and a reader is entitled to see
	 * whose directory they are searching before they search it.
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
	 * Looks for people in those directories.
	 *
	 * A viewer is required, unlike the local directory beside it. This one
	 * makes requests to other servers on the caller's say-so, and an
	 * endpoint that does that for anonymous callers is an open proxy with
	 * extra steps — the rate limit is the second half of the same argument.
	 *
	 * The answer carries `sources` as well as `accounts`, and a client is
	 * expected to show it: "nobody by that name" and "that server did not
	 * answer" are different answers, and a reader deciding whether to try
	 * another spelling needs to know which one they got.
	 *
	 * @param string $q what to look for; empty asks each source who is active
	 * @param string $source one host to ask, or '' for all of them
	 */
	#[NoCSRFRequired]
	#[PublicPage]
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
	 * The hashtags other servers are busy with, and a search across them.
	 *
	 * The trending list this app has always had ranks the tags used *here*,
	 * which on a small instance is what the handful of people on it posted
	 * today and on a new one is nothing at all. This asks other servers the
	 * same question and answers with what they said, attributed to them.
	 *
	 * Nothing is fetched, ingested or stored -- what comes back is a list of
	 * strings. See `PeerTrendService` for which kinds of server can answer a
	 * search rather than only a trending page, which is a fact about their
	 * APIs rather than a choice made here.
	 *
	 * The answer carries `sources` beside `tags` for the reason
	 * `/api/v1/directories/search` does: "nobody is talking about that" and
	 * "the servers we asked did not answer" are different answers.
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
	 * entities.
	 *
	 * Never somebody they already follow, have a pending request to, have
	 * blocked or muted, who has blocked them, or themselves — see
	 * SuggestionService, where those exclusions are gathered before either
	 * half of the list is built.
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
	 * Accounts followed by the accounts the viewer follows — the Followgraph
	 * question, asked of the servers those accounts live on rather than of
	 * this database.
	 *
	 * Not part of Mastodon's API and deliberately its own route: what it
	 * answers with carries the count and the handles behind each suggestion,
	 * and `/api/v2/suggestions` has nowhere to put them. The suggestions there
	 * are cheap and local; these cost one request per account followed, so
	 * they are asked for rather than included by default.
	 *
	 * Rate-limited per user, because that is what a button that makes twenty
	 * outgoing requests needs.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[UserRateLimit(limit: 10, period: 300)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/follow_graph')]
	public function followGraph(int $limit = 20): DataResponse {
		try {
			$this->initViewer(['read']);

			$answer = $this->followGraphService->suggestions($this->viewer, $limit);

			return new DataResponse([
				'suggestions' => $answer['suggestions'],
				// what it asked and what it still needs, so the page can say
				// "follow one more account" rather than "nothing found"
				'asked' => $answer['asked'],
				'needs' => $answer['needs'],
			], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Whether the graph is worth walking, which the page asks when it opens.
	 *
	 * Its own route rather than a parameter on the one above, because the two
	 * cost such different things: this reads one row count and goes nowhere
	 * near the network, so it is not rate-limited and the walk is.
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
	 * The v1 shape of the same list: bare `Account` entities, without the
	 * source that explains them.
	 *
	 * Served from the same query rather than deprecated away, because clients
	 * that never moved to v2 would otherwise show an empty "who to follow"
	 * panel with no indication why.
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
	 * "Stop suggesting this account."
	 *
	 * Mastodon's `DELETE /api/v1/suggestions/{account_id}`, and the reason the
	 * panel is usable at all: without it the same handful of accounts comes
	 * back on every visit, including the ones the reader has already decided
	 * about. The dismissal is permanent — see
	 * `AccountRelationService::TYPE_SUGGESTION_DISMISSED`.
	 *
	 * Mastodon answers an empty object, and does so whether or not the account
	 * was ever suggested: the client asked for a state, and that state is what
	 * it gets.
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
	 * The public statuses interacted with most in the window.
	 *
	 * `period` is this app's own parameter and is the one
	 * `/api/v1/trends/tags` already takes, so a client that asks all three
	 * trend routes reports on the same stretch of time. Mastodon has no such
	 * parameter and sends none; the default is what it gets.
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
			$this->linkPreviewService->attachCards($statuses);
			$this->placeService->attachPlaces($statuses);

			return new DataResponse($statuses, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The pictures being looked at right now: `/api/v2/discover/posts`.
	 *
	 * Pixelfed's route, and the one its clients ask for the discover screen. It
	 * is the trending statuses narrowed to the ones with a picture, because a
	 * discover screen is a grid of squares and a text post is a poor thing to
	 * put in one -- not a different ranking, so a post cannot trend here and not
	 * there.
	 *
	 * `media` narrows it again to one kind, which is this app's own parameter
	 * and not Pixelfed's: its clients send none and get what they always got,
	 * every post with an attachment. The page this app draws asks for `image`
	 * and `video` separately, because a grid of squares and a grid of players
	 * are two screens. Anything else is ignored rather than refused -- an
	 * unknown kind is a client asking for something this instance does not
	 * sort by, not an error worth a 4xx on a shop window.
	 *
	 * Public statuses only, as the trends are: this is a shop window, and the
	 * one rule a discovery surface must not break is showing somebody something
	 * they would not have been shown anywhere else.
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
			$this->linkPreviewService->attachCards($statuses);
			$this->placeService->attachPlaces($statuses);

			return new DataResponse($statuses, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The starter packs: named handfuls of accounts worth following.
	 *
	 * The index resolves nobody. A handle becomes a profile through a WebFinger
	 * lookup and an actor fetch against somebody else's server, and doing that
	 * for every handle of every pack in order to draw a list of pack *names*
	 * would make this page wait on the internet for nothing.
	 *
	 * Public: what this instance suggests is something it publishes about
	 * itself, and a signed-out visitor deciding whether to join deserves to see
	 * it.
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
	 * One pack, with its handles resolved to profiles.
	 *
	 * This one does reach other servers, which is why it is a route of its own
	 * rather than a fatter index: the cost is paid when somebody opens a pack,
	 * not when they glance at the page.
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
	 * Follows everyone in a pack that can be reached.
	 *
	 * The whole point of the button is that nobody has to follow six accounts by
	 * hand, so one unreachable host skips that account rather than failing the
	 * lot. The answer says who was actually followed.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
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
	 * The public posts carrying one link, newest first.
	 *
	 * What a reader gets by tapping a trending link rather than following it
	 * off the instance. The links themselves were already served at
	 * `/api/v1/trends/links`, so the data was here and the timeline that reads
	 * it was not.
	 *
	 * A missing or unknown `url` is an empty timeline, not an error: the link
	 * a client holds may be one nobody here has posted since.
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
			$this->linkPreviewService->attachCards($statuses);

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
	 * Pins a hashtag to the viewer's profile.
	 *
	 * A name that is not a hashtag is a 422 rather than a row nobody can post
	 * with, and so is one tag past the ceiling this instance advertises: a
	 * client that pre-checked against `max_featured_tags` and got it wrong
	 * must be told, not quietly refused.
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
	// Not reachable as a featured tag with the id "suggestions": the `{id}` of
	// unfeatureTag() is a `\d+`, and it has to stay one.
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
	 * Somebody's featured tags.
	 *
	 * Public, as the profile it is drawn on is: a featured tag is a claim an
	 * account makes about itself, and the posts the counts are taken from are
	 * the public ones. A viewer is neither required nor used.
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
	 *
	 * Public for the same reason the featured tags above are: it is drawn on a
	 * profile page, which is public, and it is built from the account's public
	 * posts alone. A viewer is neither required nor used, so it cannot differ
	 * per viewer — a chart that did would say which posts the viewer is
	 * allowed to see.
	 *
	 * Rate limited by the anonymous limiter the other public reads use: it is
	 * two queries rather than a lookup.
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
	 * The account cache hands out relationships relative to the viewer, so it
	 * is told who that is.
	 */
	#[\Override]
	protected function prepareViewer(Person $viewer): Person {
		$this->cacheActorService->setViewer($viewer);

		return $viewer;
	}
}
