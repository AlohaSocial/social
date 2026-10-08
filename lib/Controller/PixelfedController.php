<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Db\DiscoverCategoriesRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\Client\StoryInteraction;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ArchiveService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Service\MediaTagService;
use OCA\Social\Service\PixelfedConfigService;
use OCA\Social\Service\PixelfedService;
use OCA\Social\Service\PlaceService;
use OCA\Social\Service\PortfolioService;
use OCA\Social\Service\StoryInteractionService;
use OCA\Social\Service\StoryService;
use OCA\Social\Service\SuggestionService;
use OCA\Social\Service\TeamService;
use OCA\Social\Service\TrendService;
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
 * The routes the Pixelfed app asks for that live nowhere else.
 *
 * Pixelfed speaks the Mastodon client API for almost everything, and everything
 * it shares is served by the Mastodon client API controllers. What is left is a small
 * surface of its own: a config object it reads on launch, and a `v1.1` namespace
 * its discover screen uses. They are gathered here rather than scattered,
 * because they have one thing in common that the rest of this API does not --
 * they exist to satisfy one client, and a reader should be able to see the whole
 * of that in one file.
 *
 * **This is not all of Pixelfed's `v1.1`.** It is the part its official app
 * actually calls to start up and to draw discover. Routes are added when
 * something asks for them, not to fill in a namespace.
 *
 * Nothing here ranks or selects anything of its own. Discover is the same
 * `TrendService` the Mastodon trend routes use and the same `SuggestionService`
 * behind `/api/v2/suggestions` -- so a post or an account cannot be popular on
 * one route and absent from the other, which is the kind of disagreement nobody
 * finds until they are looking at two screens side by side.
 */
class PixelfedController extends ClientApiController {
	/** What one page of discover holds. */
	private const LIMIT = 20;

	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		ClientService $clientService,
		private PixelfedConfigService $pixelfedConfigService,
		private TrendService $trendService,
		private SuggestionService $suggestionService,
		private HashtagService $hashtagService,
		private LinkPreviewService $linkPreviewService,
		private PlaceService $placeService,
		private PixelfedService $pixelfedService,
		private StoryService $storyService,
		private StoryInteractionService $storyInteractionService,
		private MediaTagService $mediaTagService,
		private PortfolioService $portfolioService,
		private TeamService $teamService,
		private CacheActorService $cacheActorService,
		private ArchiveService $archiveService,
		private DiscoverCategoriesRequest $discoverCategoriesRequest,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
	}

	/**
	 * The numbers and switches the app reads once, on launch.
	 *
	 * Public, and answered without a viewer: the app asks for it before anybody
	 * has signed in, to decide whether it can talk to this server at all.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v2/config')]
	public function config(): DataResponse {
		try {
			return new DataResponse($this->pixelfedConfigService->config(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Accounts worth following: `/api/v1.1/discover/accounts/popular`.
	 *
	 * The same suggestions `/api/v2/suggestions` answers, unwrapped -- Mastodon
	 * wraps each account in a `{source, account}` suggestion and Pixelfed sends
	 * the accounts themselves. One list, two shapes, rather than two lists that
	 * can disagree.
	 *
	 * Needs a viewer, as the Mastodon route does: there is no such thing as an
	 * anonymous "accounts you might follow".
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1.1/discover/accounts/popular')]
	#[FrontpageRoute(verb: 'GET', url: '/api/pixelfed/v1/discover/accounts/popular', postfix: 'pf')]
	public function popularAccounts(int $limit = self::LIMIT): DataResponse {
		try {
			$this->initViewer(['read']);

			$accounts = [];
			foreach (
				$this->suggestionService->suggestions(
					$this->viewer()->getId(), max(1, min($limit, self::LIMIT))
				) as $suggestion
			) {
				// Mastodon wraps each account in a {source, account}
				// suggestion; Pixelfed sends the accounts themselves
				$accounts[] = $suggestion->getAccount();
			}

			return new DataResponse($accounts, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The pictures being looked at: `/api/v1.1/discover/posts`.
	 *
	 * The same answer as `/api/v2/discover/posts`, at the path the app asks for.
	 * Deliberately a second route onto one implementation rather than a second
	 * implementation: a post cannot then trend on one and not the other.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1.1/discover/posts')]
	public function discoverPosts(
		int $limit = self::LIMIT,
		int $offset = 0,
		string $period = HashtagService::PERIOD_DEFAULT,
	): DataResponse {
		try {
			$this->optionalViewer(['read']);

			$statuses = $this->trendService->trendingStatuses(
				$period, max(1, min($limit, self::LIMIT)), max(0, $offset), true
			);
			foreach ($statuses as $status) {
				$status->setExportFormat(ACore::FORMAT_LOCAL);
			}
			$this->linkPreviewService->attachCards($statuses);
			$this->placeService->attachPlaces($statuses);

			return new DataResponse($statuses, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The hashtags being used: `/api/v1.1/discover/posts/hashtags`.
	 *
	 * Pixelfed's discover screen draws a row of tags above the grid. The same
	 * trending tags `/api/v1/trends/tags` answers.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1.1/discover/posts/hashtags')]
	#[FrontpageRoute(verb: 'GET', url: '/api/pixelfed/v1/discover/posts/hashtags', postfix: 'pf')]
	public function discoverHashtags(
		int $limit = self::LIMIT,
		string $period = HashtagService::PERIOD_DEFAULT,
	): DataResponse {
		try {
			$this->optionalViewer(['read']);

			return new DataResponse(
				$this->hashtagService->getTrending(max(1, min($limit, self::LIMIT)), $period),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}
	/**
	 * How the app names a trend window, as this app counts one.
	 *
	 * Pixelfed's `range` is daily, monthly or yearly; the trend counters here
	 * run to ten days, which is where both longer ranges land.
	 */
	private function periodFor(string $range): string {
		return match (strtolower(trim($range))) {
			'daily' => '1d',
			'monthly', 'yearly' => '10d',
			default => HashtagService::PERIOD_DEFAULT,
		};
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1.1/discover/posts/trending')]
	#[FrontpageRoute(verb: 'GET', url: '/api/pixelfed/v1/discover/posts/trending', postfix: 'pf')]
	public function discoverPostsTrending(string $range = '', int $limit = self::LIMIT, int $offset = 0): DataResponse {
		return $this->discoverPosts($limit, $offset, $this->periodFor($range));
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1.1/discover/posts/network/trending')]
	public function discoverNetworkTrending(string $range = '', int $limit = self::LIMIT, int $offset = 0): DataResponse {
		return $this->discoverPosts($limit, $offset, $this->periodFor($range));
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1.2/stories/carousel')]
	#[FrontpageRoute(verb: 'GET', url: '/api/pixelfed/v1/stories/carousel', postfix: 'pf')]
	public function storiesCarousel(): DataResponse {
		try {
			$this->initViewer(['read:stories']);

			return new DataResponse($this->pixelfedService->carousel($this->viewer()), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[UserRateLimit(limit: 300, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1.1/stories/seen')]
	#[FrontpageRoute(verb: 'POST', url: '/api/pixelfed/v1/stories/seen', postfix: 'pf')]
	public function storiesSeen(int $id = 0): DataResponse {
		try {
			$this->initViewer(['write:stories']);
			$this->storyService->markSeen($this->viewer(), $id);

			return new DataResponse(['code' => 200], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1.1/stories/self-expire/{id}', requirements: ['id' => '\\d+'])]
	#[FrontpageRoute(verb: 'POST', url: '/api/pixelfed/v1/stories/self-expire/{id}', requirements: ['id' => '\\d+'], postfix: 'pf')]
	public function storiesSelfExpire(int $id): DataResponse {
		try {
			$this->initViewer(['write:stories']);
			$this->storyService->delete($this->viewer(), $id);

			return new DataResponse(['code' => 200, 'msg' => 'Successfully deleted'], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1.2/stories/viewers')]
	#[FrontpageRoute(verb: 'GET', url: '/api/pixelfed/v1/stories/viewers', postfix: 'pf')]
	public function storiesViewers(int $sid = 0): DataResponse {
		try {
			$this->initViewer(['read:stories']);

			return new DataResponse($this->pixelfedService->storyViewers($this->viewer(), $sid), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * An emoji sent back to whoever posted a story.
	 *
	 * Pixelfed's own spelling of the route, down to `sid` for the story. A
	 * story the viewer may not see and one that does not exist are the same
	 * 404: whether an account has a story up is told to its followers alone.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[UserRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1.2/stories/react')]
	#[FrontpageRoute(verb: 'POST', url: '/api/pixelfed/v1/stories/react', postfix: 'pf')]
	public function storiesReact(int $sid = 0, string $reaction = ''): DataResponse {
		try {
			$this->initViewer(['write:stories']);

			return new DataResponse(
				$this->storyInteractionService->answer(
					$this->viewer(), $sid, StoryInteraction::TYPE_REACTION, $reaction
				),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * A sentence sent back to whoever posted a story.
	 *
	 * Pixelfed calls this a comment and turns it into a direct message. Here it
	 * stays beside the story: it is a private answer to something that is gone
	 * tomorrow, and a post would outlive what it was about.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[UserRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1.2/stories/comment')]
	#[FrontpageRoute(verb: 'POST', url: '/api/pixelfed/v1/stories/comment', postfix: 'pf')]
	public function storiesComment(int $sid = 0, string $caption = ''): DataResponse {
		try {
			$this->initViewer(['write:stories']);

			return new DataResponse(
				$this->storyInteractionService->answer(
					$this->viewer(), $sid, StoryInteraction::TYPE_REPLY, $caption
				),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Names the people in one of the viewer's own photographs.
	 *
	 * `accounts` is the whole list the post should end up naming, not what to
	 * add: anybody dropped from it is untagged, which is what makes a client
	 * that sends its list again on every edit a no-op rather than a growing
	 * pile. Only the post's author may name anybody in it.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[UserRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1.1/compose/tag')]
	#[FrontpageRoute(verb: 'POST', url: '/api/pixelfed/v1/compose/tag', postfix: 'pf')]
	public function composeTag(int|string $status_id = 0, array $accounts = []): DataResponse {
		try {
			$this->initViewer(['write:statuses']);

			return new DataResponse(
				['tagged_people' => $this->mediaTagService->tag($this->viewer(), $status_id, $accounts)],
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Takes the viewer's own name off a photograph.
	 *
	 * Pixelfed's route, and its whole remedy for being named in somebody
	 * else's picture: being in one is not something to need their permission
	 * to leave.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[UserRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1.1/compose/tag/untagme')]
	#[FrontpageRoute(verb: 'POST', url: '/api/pixelfed/v1/compose/tag/untagme', postfix: 'pf')]
	public function composeUntagMe(int|string $status_id = 0): DataResponse {
		try {
			$this->initViewer(['write:statuses']);

			return new DataResponse(
				['untagged' => $this->mediaTagService->untag($this->viewer(), $status_id)],
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The photographs somebody is named in — "photos of you", for anybody.
	 *
	 * Which of them the reader may see is not decided here: the ids come out
	 * of the tag table and each post is read the way any other post is read
	 * for this reader, so one they may not see is simply not among them —
	 * and a page can therefore be short without being the last. Whether there
	 * is another is said by the `Link` header, whose `max_id` is the last tag
	 * row read rather than the last post shown.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1.1/accounts/{account_id}/tagged')]
	#[FrontpageRoute(verb: 'GET', url: '/api/pixelfed/v1/accounts/{account_id}/tagged', postfix: 'pf')]
	public function accountTagged(string $account_id, int $limit = 20, int|string $max_id = 0): DataResponse {
		try {
			$this->initViewer(['read:statuses']);
			$subject = $this->cacheActorService->resolve($account_id);

			$page = $this->mediaTagService->photosOf($this->viewer(), $subject->getId(), $limit, $max_id);

			$response = new DataResponse($page['posts'], Http::STATUS_OK);
			if ($page['next'] !== null) {
				$response->addHeader('Link', '<' . $this->pageUrl(['max_id' => $page['next']]) . '>; rel="next"');
			}

			return $response;
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The viewer's own page of work, turned on or not.
	 *
	 * An account that has never opened the editor gets the defaults rather
	 * than a 404: there is nothing to find, and this is what lets the editor
	 * render without a "create it first" step nobody needs.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1.1/portfolio')]
	public function portfolioOwn(): DataResponse {
		try {
			$this->initViewer(['read:accounts']);

			return new DataResponse($this->portfolioService->own($this->viewer()), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Writes it.
	 *
	 * `active` is the moment its owner decides the internet may read it; a row
	 * that exists without it is a draft.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[UserRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1.1/portfolio')]
	public function portfolioSave(
		bool $active = false,
		string $title = '',
		string $intro = '',
		string $layout = 'grid',
		string $source = 'recent',
		int $collection_id = 0,
		bool $show_captions = true,
		bool $show_places = true,
		bool $show_dates = false,
		bool $show_avatar = true,
	): DataResponse {
		try {
			$this->initViewer(['write:accounts']);

			return new DataResponse(
				$this->portfolioService->save($this->viewer(), [
					'active' => $active,
					'title' => $title,
					'intro' => $intro,
					'layout' => $layout,
					'source' => $source,
					'collection_id' => $collection_id,
					'show_captions' => $show_captions,
					'show_places' => $show_places,
					'show_dates' => $show_dates,
					'show_avatar' => $show_avatar,
				]),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Somebody's published page, as the internet reads it.
	 *
	 * **No viewer is resolved at all**, deliberately: what a portfolio shows
	 * is what the whole internet may see, whoever happens to be reading it, so
	 * the posts on it are read as the anonymous internet reads them. A
	 * followers-only photograph cannot reach this page because nothing on the
	 * path to it has ever seen one. A page its owner has not turned on is a
	 * **404**, not an empty page with their name on it.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1.1/portfolio/{handle}')]
	public function portfolioOf(string $handle): DataResponse {
		try {
			return new DataResponse(
				$this->portfolioService->published($handle), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The team accounts the viewer may post as, right now.
	 *
	 * Asked of the group manager rather than of a stored membership: somebody
	 * who left the group this morning may not post as it this afternoon, and
	 * the only way to be sure of that is to ask. An empty list is the ordinary
	 * answer on an instance that has no team accounts, which is most of them.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1.1/teams')]
	public function teams(): DataResponse {
		try {
			$this->initViewer(['read:accounts']);

			return new DataResponse(
				['teams' => $this->teamService->forUser($this->viewer()->getUserId())],
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** What has been said about one of the viewer's own stories. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1.2/stories/reactions')]
	#[FrontpageRoute(verb: 'GET', url: '/api/pixelfed/v1/stories/reactions', postfix: 'pf')]
	public function storiesReactions(int $sid = 0): DataResponse {
		try {
			$this->initViewer(['read:stories']);

			return new DataResponse(
				['reactions' => $this->storyInteractionService->forStory($this->viewer(), $sid)],
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1.2/stories/mention-autocomplete')]
	public function storiesMentionAutocomplete(string $q = ''): DataResponse {
		try {
			$this->initViewer(['read']);

			return new DataResponse($this->pixelfedService->mentionAutocomplete($q), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1.1/collections/self')]
	#[FrontpageRoute(verb: 'GET', url: '/api/pixelfed/v1/collections/self', postfix: 'pf')]
	public function collectionsSelf(): DataResponse {
		try {
			$this->initViewer(['read:collections']);

			return new DataResponse($this->pixelfedService->collections($this->viewer()), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1.1/accounts/username/{username}')]
	public function accountByUsername(string $username): DataResponse {
		try {
			$this->optionalViewer(['read']);
			$account = $this->pixelfedService->resolveAccount($username);
			$account->setExportFormat(ACore::FORMAT_LOCAL);

			return new DataResponse($account, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1.1/accounts/mutuals/{id}')]
	public function accountMutuals(string $id): DataResponse {
		try {
			$this->initViewer(['read']);

			return new DataResponse($this->pixelfedService->mutuals($this->viewer(), $id), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1.1/accounts/avatar')]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/pixelfed/v1/accounts/avatar', postfix: 'pf')]
	public function accountAvatarDelete(): DataResponse {
		try {
			$this->initViewer(['write:accounts']);

			return new DataResponse($this->pixelfedService->removeAvatar($this->viewer()), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[UserRateLimit(limit: 30, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1.1/report')]
	#[FrontpageRoute(verb: 'POST', url: '/api/pixelfed/v1/report', postfix: 'pf')]
	public function report(
		string $report_type = '',
		string $object_id = '',
		string $object_type = '',
		string $message = '',
	): DataResponse {
		try {
			$this->initViewer(['write:reports']);
			$this->pixelfedService->report($this->viewer(), $report_type, $object_id, $object_type, $message);

			return new DataResponse(['msg' => 'Successfully sent report', 'code' => 200], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1.1/compose/settings')]
	#[FrontpageRoute(verb: 'GET', url: '/api/pixelfed/v1/compose/settings', postfix: 'pf')]
	public function composeSettings(): DataResponse {
		try {
			$this->initViewer(['read']);

			return new DataResponse($this->pixelfedService->composeSettings($this->viewer()), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The app's own switches, kept on the server so a reinstall finds the app
	 * as it was left.
	 *
	 * GET and POST on one method because the app uses one URL for both, and
	 * a POST with no `common` is a read — which is what the app sends when it
	 * wants to know what is stored without changing it. Storing is a write to
	 * the account, so it needs the scope that says so; a token that may only
	 * read could otherwise turn content warnings off in somebody's app.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/pixelfed/v1/app/settings')]
	#[FrontpageRoute(verb: 'POST', url: '/api/pixelfed/v1/app/settings', postfix: 'store')]
	public function appSettings(): DataResponse {
		try {
			$common = $this->request->getParam('common');
			$this->initViewer(is_array($common) ? ['write:accounts'] : ['read']);

			return new DataResponse(
				is_array($common)
					? $this->pixelfedService->saveAppSettings($this->viewer(), $common)
					: $this->pixelfedService->appSettings($this->viewer()),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Putting one of your own posts away, and getting it back.
	 *
	 * Pixelfed's three screens, at Pixelfed's own paths. Nothing federates: an
	 * archived post is still on every server that received it, and taking it
	 * back from them is what deleting is for.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/pixelfed/v1/archive/add/{id}', requirements: ['id' => '\\d+'])]
	public function archiveAdd(int $id): DataResponse {
		try {
			$this->initViewer(['write:statuses']);
			$this->archiveService->archive($this->viewer(), $id);

			return new DataResponse(['code' => 200], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/pixelfed/v1/archive/remove/{id}', requirements: ['id' => '\\d+'])]
	public function archiveRemove(string $id): DataResponse {
		try {
			$this->initViewer(['write:statuses']);
			$this->archiveService->restore($this->viewer(), $id);

			return new DataResponse(['code' => 200], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/pixelfed/v1/archive/list')]
	public function archiveList(int $limit = ArchiveService::PAGE, int|string $max_id = 0): DataResponse {
		try {
			$this->initViewer(['read:statuses']);
			$posts = $this->archiveService->forActor($this->viewer(), $limit, $max_id);
			foreach ($posts as $post) {
				$post->setExportFormat(ACore::FORMAT_LOCAL);
			}

			return new DataResponse($posts, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * What this instance says it is about: a few named subjects, each a
	 * handful of hashtags an administrator chose.
	 *
	 * Curated rather than computed, which is the point of it. Trending on a
	 * small instance is four hashtags and a wedding; what the instance would
	 * *like* to be known for is a decision, and no counter can work it out.
	 * Public, because it is the page a visitor lands on.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1.1/discover/categories')]
	public function discoverCategories(): DataResponse {
		try {
			return new DataResponse(
				['categories' => $this->discoverCategoriesRequest->getAll()], Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1.1/nag/state')]
	public function nagState(): DataResponse {
		try {
			$this->initViewer(['read']);

			return new DataResponse($this->pixelfedService->nagState(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1.1/push/state')]
	public function pushState(): DataResponse {
		try {
			$this->initViewer(['read']);

			return new DataResponse($this->pixelfedService->pushState($this->viewer()), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Turning push off or on. Nothing is stored, since there is no push here
	 * to switch, but these are the app's requests to change the account and
	 * are held to the scope a change needs, as Mastodon holds its own push
	 * subscription to `push`.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1.1/push/disable', postfix: 'disable')]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1.1/push/update', postfix: 'update')]
	public function pushChange(): DataResponse {
		try {
			$this->initViewer(['write:accounts', 'push']);

			return new DataResponse($this->pixelfedService->pushState($this->viewer()), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1.1/push/compare')]
	public function pushCompare(): DataResponse {
		try {
			$this->initViewer(['read']);

			return new DataResponse($this->pixelfedService->pushCompare($this->viewer()), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/tags/{hashtag}/related')]
	public function tagRelated(string $hashtag, int $limit = self::LIMIT): DataResponse {
		try {
			$this->optionalViewer(['read']);

			return new DataResponse($this->hashtagService->related($hashtag, $limit), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1.1/direct/thread')]
	#[FrontpageRoute(verb: 'GET', url: '/api/pixelfed/v1/direct/thread', postfix: 'pf')]
	public function directThread(string $pid = '', int|string $max_id = 0, int|string $min_id = 0): DataResponse {
		try {
			$this->initViewer(['read:statuses']);

			return new DataResponse(
				$this->pixelfedService->thread($this->viewer(), $pid, $max_id, $min_id),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[UserRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1.1/direct/thread/send')]
	#[FrontpageRoute(verb: 'POST', url: '/api/pixelfed/v1/direct/thread/send', postfix: 'pf')]
	public function directThreadSend(string $to_id = '', string $message = '', string $type = 'text'): DataResponse {
		try {
			$this->initViewer(['write:statuses']);

			return new DataResponse(
				$this->pixelfedService->sendMessage($this->viewer(), $to_id, $message, $type),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1.1/direct/thread/message')]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/pixelfed/v1/direct/thread/message', postfix: 'pf')]
	public function directThreadDelete(int $id = 0): DataResponse {
		try {
			$this->initViewer(['write:statuses']);
			$this->pixelfedService->deleteMessage($this->viewer(), $id);

			return new DataResponse([200], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1.1/direct/compose/mutuals')]
	public function directComposeMutuals(): DataResponse {
		try {
			$this->initViewer(['read']);

			return new DataResponse($this->pixelfedService->composeMutuals($this->viewer()), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}
}
