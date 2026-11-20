<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\Atproto\Publisher\BlueskyLists;
use OCA\Social\Db\ListsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\MastodonList;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\Counts\CountService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\GroupListService;
use OCA\Social\Service\PostDecorationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mastodon's lists: a user-made group of accounts they follow, with a timeline
 * of its own.
 *
 * Only the owner may read or change a list through this controller's
 * routes; a public one (`public`, this app's own key) is also published as a
 * Bluesky list (`BlueskyLists`). Every route resolves its list with
 * `ListsRequest::getOwnedById()`, so somebody else's list is a 404.
 *
 * `#[PublicPage]` with `#[NoCSRFRequired]`, as Mastodon clients use a bearer
 * token; every route then requires a viewer itself (401 without one).
 */
class ListController extends ClientApiController {
	/** What Mastodon defaults a page of list members at. */
	private const MEMBERS_LIMIT = 40;

	/**
	 * The most members one request may ask for. Mastodon's `limit=0` ("all")
	 * cannot be honoured: Nextcloud's dispatcher limits any `limit` parameter to
	 * 1–500 (`Dispatcher::ensureParameterValueSatisfiesRange`). The page is built
	 * in memory, so longer lists are read with the cursor.
	 */
	private const MAX_MEMBERS_LIMIT = 500;

	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		private CacheActorService $cacheActorService,
		ClientService $clientService,
		private FollowService $followService,
		private PostDecorationService $postDecorationService,
		private ListsRequest $listsRequest,
		private GroupListService $groupListService,
		private ?ContainerInterface $container = null,
		private ?CountService $counts = null,
		private ?StreamRequest $streamRequest = null,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
	}

	/**
	 * Every list the viewer owns, unpaged as in Mastodon.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/lists')]
	public function index(): Response {
		try {
			$this->initViewer(['read:lists']);
			// Create the lists the viewer's groups give them; a failure must not cost
			// the whole answer.
			try {
				$this->groupListService->ensureForViewer($this->viewer);
			} catch (Throwable $e) {
				$this->logger->warning('[ListController] could not make the group lists', ['exception' => $e]);
			}

			return Revalidation::byContent($this->request, new DataResponse(
				$this->listsRequest->getByActor($this->viewer->getId()), Http::STATUS_OK
			));
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/lists')]
	public function create(
		string $title = '',
		string $replies_policy = MastodonList::DEFAULT_REPLIES_POLICY,
		bool $exclusive = false,
		bool $public = false,
	): DataResponse {
		try {
			$this->initViewer(['write:lists']);

			$list = new MastodonList();
			$list->setOwnerId($this->viewer->getId())
				->setTitle($this->title($title))
				->setRepliesPolicy($this->repliesPolicy($replies_policy))
				->setExclusive($exclusive)
				->setPublic($public);

			$list = $this->listsRequest->create($list);
			if ($public) {
				$this->blueskyLists()?->sync($this->viewer, $list);
			}

			return new DataResponse($list, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/lists/{id}')]
	public function get(int $id): DataResponse {
		try {
			$this->initViewer(['read:lists']);

			return new DataResponse($this->ownedList($id), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Mastodon requires `title` on an update as on a create, so an absent one is
	 * a 422. `public` left out keeps what is there; a group list cannot be made
	 * public, as who is in a group is not the owner's to show.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/lists/{id}')]
	public function update(
		int $id,
		string $title = '',
		string $replies_policy = '',
		?bool $exclusive = null,
		?bool $public = null,
	): DataResponse {
		try {
			$this->initViewer(['write:lists']);
			$list = $this->ownedList($id);
			if ($public === true) {
				$this->notAGroupList($list);
			}

			if (!GroupListService::isGroupList($list)) {
				// a group list is called what the group is called
				$list->setTitle($this->title($title));
			}
			if ($replies_policy !== '') {
				$list->setRepliesPolicy($this->repliesPolicy($replies_policy));
			}
			if ($exclusive !== null) {
				$list->setExclusive($exclusive);
			}
			if ($public !== null) {
				$list->setPublic($public);
			}

			$this->listsRequest->update($list);
			$this->blueskyLists()?->sync($this->viewer, $list);

			return new DataResponse($list, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Answers `{}`, as Mastodon does, and takes the memberships with it. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/lists/{id}')]
	public function delete(int $id): DataResponse {
		try {
			$this->initViewer(['write:lists']);
			$list = $this->notAGroupList($this->ownedList($id));
			$members = $this->listsRequest->getMemberIds($list);
			$this->listsRequest->delete($list);
			$this->blueskyLists()?->deleted($list->getId(), $members);

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * A page of the list's members as Account entities, newest addition first.
	 * The cursor is the membership row id, since an account can be removed and
	 * re-added. A member no longer in the actor cache is left out but still
	 * moves the cursor.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	// `{id}` of `/api/v1/lists/{id}` must stay one segment so it cannot match
	// this path.
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/lists/{id}/accounts')]
	public function accounts(
		int $id,
		int $limit = self::MEMBERS_LIMIT,
		int|string $max_id = 0,
		int|string $min_id = 0,
	): DataResponse {
		try {
			$this->initViewer(['read:lists']);
			$list = $this->ownedList($id);

			// The dispatcher already refused anything outside 1–500.
			$limit = max(1, min(self::MAX_MEMBERS_LIMIT, $limit));

			$rows = $this->listsRequest->getMembers($list, $limit, $max_id, $min_id);
			$actors = $this->cacheActorService->getCachedFromIds(array_column($rows, 'actorId'));

			$accounts = [];
			foreach ($rows as $row) {
				$actor = $actors[$row['actorId']] ?? null;
				if ($actor === null) {
					continue;
				}

				$actor->setExportFormat(ACore::FORMAT_LOCAL);
				$accounts[] = $actor;
			}

			return $this->paged($accounts, $limit, array_column($rows, 'id'));
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Adds accounts to the list. Only accounts the owner follows (or the owner),
	 * else a 404 as in Mastodon.
	 *
	 * `$account_ids` has a default because the dispatcher binds it before the
	 * try block, where a missing `account_ids[]` would give a Nextcloud error
	 * page instead of `{"error": …}`.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/lists/{id}/accounts')]
	public function addAccounts(int $id, array|string $account_ids = []): DataResponse {
		try {
			$this->initViewer(['write:lists']);
			$list = $this->notAGroupList($this->ownedList($id));

			// All resolved before anything is written, so a refused account adds none.
			$members = [];
			foreach ($this->accountIds($account_ids) as $accountId) {
				$members[] = $this->followed($accountId);
			}

			foreach ($members as $member) {
				$this->listsRequest->addMember($list, $member->getId());
				$this->blueskyLists()?->memberAdded($this->viewer, $list->getId(), $member->getId());
			}

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Removes accounts from the list. Removing one that is not in it is not an
	 * error: Mastodon answers `{}` either way.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/lists/{id}/accounts')]
	public function removeAccounts(int $id, array|string $account_ids = []): DataResponse {
		try {
			$this->initViewer(['write:lists']);
			$list = $this->notAGroupList($this->ownedList($id));

			foreach ($this->accountIds($account_ids) as $accountId) {
				// The row is keyed by actor id; clients send a numeric id or a handle.
				$memberId = $this->cacheActorService->resolve($accountId, true)->getId();
				$this->listsRequest->removeMember($list, $memberId);
				$this->blueskyLists()?->memberRemoved($list->getId(), $memberId);
			}

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Which of the viewer's own lists an account is in, as Mastodon scopes it.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/accounts/{account}/lists', requirements: ['account' => '.+'])]
	public function accountLists(string $account): DataResponse {
		try {
			$this->initViewer(['read:lists']);
			$actor = $this->cacheActorService->resolve($account, true);

			return new DataResponse(
				$this->listsRequest->getByMember($this->viewer->getId(), $actor->getId()),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The list's timeline, a route of its own because it needs an id and the
	 * owner check, which `TimelineApiController::timelines()` has no place for.
	 * `read:lists`, not `read:statuses`: the list decides access.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/timelines/list/{id}')]
	public function timeline(
		int $id,
		int $limit = 20,
		int|string $max_id = 0,
		int|string $min_id = 0,
		int|string $since_id = 0,
		bool $only_media = false,
		bool $only_video = false,
		bool $only_news = false,
		bool $only_short = false,
	): DataResponse {
		try {
			$this->initViewer(['read:lists']);
			$list = $this->ownedList($id);

			$this->listsRequest->setViewer($this->viewer);
			// Exporting a status reads its quote, parent and interaction policy through
			// the stream request, as this reader.
			$this->streamRequest?->setViewer($this->viewer);

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setLimit($limit)
				->setMaxId($max_id)
				->setMinId($min_id)
				->setSince($since_id)
				->setOnlyMedia($only_media)
				->setOnlyVideo($only_video)
				->setOnlyShort($only_short)
				->setOnlyNews($only_news);

			$posts = $this->listsRequest->getTimeline($list, $options);
			// one query for the whole page, as the home timeline does it
			$this->postDecorationService->decorate($posts, $this->viewer()->getId());
			$filling = $this->counts?->seen($posts) ?? false;
			$response = $this->paged($posts, $options->getLimit());
			if ($filling) {
				$response->addHeader('X-Social-Filling', '1');
			}

			return $response;
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Keeps a public list, or one a reply rule names, in step with its Bluesky
	 * list; resolved lazily because of a service cycle.
	 */
	private function blueskyLists(): ?BlueskyLists {
		$lists = $this->container?->get(BlueskyLists::class);

		return $lists instanceof BlueskyLists ? $lists : null;
	}

	/**
	 * The list a request names, or a 404.
	 *
	 * @throws ItemNotFoundException it is not there, or it is not the
	 *                               viewer's
	 */
	private function ownedList(int $id): MastodonList {
		return $this->listsRequest->getOwnedById($this->viewer->getId(), $id);
	}

	/**
	 * A group list's members and existence follow the group, so it cannot be
	 * edited here.
	 *
	 * @throws InvalidResourceException a 422, with the reason
	 */
	private function notAGroupList(MastodonList $list): MastodonList {
		if (GroupListService::isGroupList($list)) {
			throw new InvalidResourceException(
				'This list follows the Nextcloud group "' . $list->getTitle() . '": its members are the group\'s, and it goes when you leave the group'
			);
		}

		return $list;
	}

	/**
	 * @throws InvalidResourceException Mastodon's "Title can't be blank"
	 */
	private function title(string $title): string {
		$title = ListsRequest::normaliseTitle($title);
		if ($title === '') {
			throw new InvalidResourceException("Title can't be blank");
		}

		return $title;
	}

	/**
	 * @throws InvalidResourceException an unknown policy is refused rather
	 *                                  than stored as the default
	 */
	private function repliesPolicy(string $repliesPolicy): string {
		if (!MastodonList::isRepliesPolicy($repliesPolicy)) {
			throw new InvalidResourceException(
				"'" . $repliesPolicy . "' is not a valid replies_policy"
			);
		}

		return $repliesPolicy;
	}

	/**
	 * The `account_ids[]` of a request, as strings and without blanks. Accepts
	 * a bare string too (one id sent without `[]`).
	 *
	 * @return string[]
	 *
	 * @throws InvalidResourceException
	 */
	private function accountIds(array|string $accountIds): array {
		$ids = [];
		foreach ((array)$accountIds as $accountId) {
			if (!is_scalar($accountId)) {
				continue;
			}

			$accountId = trim((string)$accountId);
			if ($accountId !== '') {
				$ids[$accountId] = $accountId;
			}
		}

		if ($ids === []) {
			throw new InvalidResourceException('account_ids is required');
		}

		return array_values($ids);
	}

	/**
	 * The account, once it is established that the viewer follows it.
	 *
	 * @throws Exception the account does not exist, or is not followed
	 */
	private function followed(string $accountId): Person {
		$actor = $this->cacheActorService->resolve($accountId, true);
		if ($actor->getId() === $this->viewer->getId()) {
			// your own account needs no follow to belong to your own list
			return $actor;
		}

		$this->followService->setViewer($this->viewer);
		$relationship = $this->followService->getRelationshipWith($actor);
		// A pending follow counts, as on Mastodon.
		if (!$relationship->isFollowing() && !$relationship->isRequested()) {
			throw new ItemNotFoundException('Record not found');
		}

		return $actor;
	}

	/**
	 * A page of entities with the `Link` header masto.js reads its cursor from.
	 *
	 * @param int[]|null $ids the cursor ids of the page, in its order; the
	 *                        entities' own ids when they are what pages
	 */
	private function paged(array $items, int $limit, ?array $ids = null): DataResponse {
		$response = new DataResponse($items, Http::STATUS_OK);

		$ids ??= $this->pageIds($items);
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
	 * The paging ids of a page of statuses.
	 *
	 * @return string[]
	 */
	private function pageIds(array $items): array {
		$ids = [];
		foreach ($items as $item) {
			$nid = (is_object($item) && method_exists($item, 'getNid')) ? (string)$item->getNid() : '0';
			if (\OCA\Social\Tools\Nid::compare($nid, '0') > 0) {
				$ids[] = $nid;
			}
		}

		return $ids;
	}
}
