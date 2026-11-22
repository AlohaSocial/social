<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Db\DiscoverCategoriesRequest;
use OCA\Social\Middleware\AnonymousRateLimit;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\Client\StoryInteraction;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ArchiveService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\MediaTagService;
use OCA\Social\Service\PixelfedConfigService;
use OCA\Social\Service\PixelfedService;
use OCA\Social\Service\PostDecorationService;
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
 * The Pixelfed-only routes: the config object its app reads on launch, and
 * the parts of the `v1.1` namespace it calls to start up and draw discover.
 * Everything else Pixelfed uses is the Mastodon client API.
 *
 * Discover reuses `TrendService` and `SuggestionService`, as the Mastodon
 * routes do, so the two cannot disagree.
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
		private PostDecorationService $postDecorationService,
		private PixelfedService $pixelfedService,
		private StoryService $storyService,
		private StoryInteractionService $storyInteractionService,
		private MediaTagService $mediaTagService,
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
	 * Accounts worth following: `/api/v1.1/discover/accounts/popular`. The
	 * suggestions `/api/v2/suggestions` answers, without Mastodon's
	 * `{source, account}` wrapper. Needs a viewer.
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
	 * Trending photos: `/api/v1.1/discover/posts`, the same answer as
	 * `/api/v2/discover/posts`.
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
			$viewer = $this->optionalViewer(['read']);

			$statuses = $this->trendService->trendingStatuses(
				$period, max(1, min($limit, self::LIMIT)), max(0, $offset), true
			);
			foreach ($statuses as $status) {
				$status->setExportFormat(ACore::FORMAT_LOCAL);
			}
			$this->postDecorationService->decorate($statuses, $viewer?->getId() ?? '');

			return new DataResponse($statuses, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Trending hashtags: `/api/v1.1/discover/posts/hashtags`, the same as
	 * `/api/v1/trends/tags`.
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
	 * Pixelfed's trend `range` (daily, monthly, yearly) as a window here; the
	 * counters run to ten days, where both longer ranges land.
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
	#[AnonymousRateLimit(limit: 300, period: 60)]
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
	 * An emoji sent back to whoever posted a story (a short, in the UI). A
	 * story the viewer may not see is the same 404 as a missing one.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonymousRateLimit(limit: 60, period: 60)]
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
	 * A text reply to a story. Pixelfed turns it into a direct message; here
	 * it stays with the story, which expires after a day.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonymousRateLimit(limit: 60, period: 60)]
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
	 * Names the people in one of the viewer's own photos. `accounts` is the
	 * full list, so anybody left out is untagged and resending it is a no-op.
	 * Only the post's author may tag.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonymousRateLimit(limit: 60, period: 60)]
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
	 * Takes the viewer's own name off a photo, without the author's say.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonymousRateLimit(limit: 60, period: 60)]
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
	 * The photos somebody is tagged in. Each post is read with the reader's
	 * visibility, so a page can be short without being the last; the `Link`
	 * header's `max_id` is the last tag row read.
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
	 * The team accounts the viewer may post as, asked live of the group
	 * manager so a member who left loses access at once.
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
	#[AnonymousRateLimit(limit: 30, period: 3600)]
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
	 * The app's own settings, kept on the server. GET and POST share one URL
	 * as the app uses them; a POST without `common` is a read. Storing needs
	 * a write scope, so a read-only token cannot change someone's settings.
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
	 * Archiving one of your own posts and restoring it. Nothing federates:
	 * other servers keep their copy, which only deletion withdraws.
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
			$this->postDecorationService->decorate($posts, $this->viewer()->getId());

			return new DataResponse($posts, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The instance's curated subjects, each a few hashtags an administrator
	 * chose. Public, for visitors.
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
	 * Turning push off or on. Nothing is stored, as there is no push here,
	 * but the scope a change needs is still required, as Mastodon does.
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
	#[AnonymousRateLimit(limit: 60, period: 60)]
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
