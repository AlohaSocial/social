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
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Service\PlaceService;
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
 * A list is the account's own to read and change, and nobody else may reach
 * it through these routes. Its owner may make it public (`public`, this
 * app's own key): anyone may then see it and who is on it, as a Bluesky list
 * (`BlueskyLists`); a private one is theirs alone. Every route here resolves
 * its list through `ListsRequest::getOwnedById()`, which carries the owner in
 * the statement, so a list belonging to somebody else is a 404 and not a row
 * that was read and then rejected.
 *
 * `#[PublicPage]` with `#[NoCSRFRequired]`, like `ApiController` and
 * `TagController`, and for the same reason: a Mastodon client authenticates
 * with a bearer token and has no Nextcloud session or CSRF token to present,
 * so `#[NoAdminRequired]` would refuse every real caller before the handler
 * ran. Every route here then requires a viewer itself — no token, no session,
 * 401 — so nothing is public in fact.
 */
class ListController extends ClientApiController {
	/** What Mastodon defaults a page of list members at. */
	private const MEMBERS_LIMIT = 40;

	/**
	 * The most members one request may ask for.
	 *
	 * 500 rather than Mastodon's 80, and **not** 0.
	 *
	 * Mastodon documents `limit=0` as "all accounts without pagination", and
	 * this controller used to honour it. It never ran: Nextcloud's dispatcher
	 * applies a default range of 1–500 to any parameter named `limit`
	 * (`Dispatcher::ensureParameterValueSatisfiesRange`) and throws before the
	 * method is entered, so `limit=0` was a 500 and an HTML error page rather
	 * than a list of members. 500 is the largest the platform will pass
	 * through, so it is what "as many as you can" has to mean here.
	 *
	 * The page is built in memory, one cached actor per row, which is why
	 * there is a ceiling at all; a longer list is read with the cursor.
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
		private LinkPreviewService $linkPreviewService,
		private ListsRequest $listsRequest,
		private PlaceService $placeService,
		private GroupListService $groupListService,
		private ?ContainerInterface $container = null,
		private ?CountService $counts = null,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
	}

	/**
	 * Every list the viewer owns.
	 *
	 * Unpaged, as Mastodon's is: the route takes no cursor there either, and a
	 * client draws the whole sidebar from one call.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/lists')]
	public function index(): Response {
		try {
			$this->initViewer(['read:lists']);
			// the lists the viewer's groups give them, made when they would
			// notice they were missing; a failure here must not cost the sidebar
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
	 * Mastodon requires `title` on an update as it does on a create, so an
	 * absent one is a 422 and not "keep what is there": a client that meant to
	 * change only `exclusive` still sends the title back. `public` left out
	 * keeps what is there; a group list cannot be made public, as who is in a
	 * group is not the owner's to show.
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
	 * A page of the list's members as Account entities, newest addition
	 * first, with the `Link` header masto.js reads its cursor from.
	 *
	 * The cursor is the membership row id, not the account: an account can be
	 * removed from a list and added again, so its own id does not move in one
	 * direction and cannot page.
	 *
	 * A member whose actor is no longer in the cache is left out of the page
	 * rather than sent as a half-filled account — but its row is still what
	 * decides the cursor, so paging does not stall on it.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	// `/api/v1/lists/{id}` cannot read this as a list named "4/accounts"
	// because `{id}` matches one segment, and it has to stay that way.
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

			// the dispatcher has already refused anything outside 1–500; this
			// is the app's own ceiling on top of that
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
	 * Adds accounts to the list.
	 *
	 * A list is a view of what the owner already follows, so an account they
	 * do not follow may not be put in one — Mastodon refuses that with a 404,
	 * because the follow it would have to attach the membership to is what is
	 * missing. The owner may be in their own list without following
	 * themselves, which is also Mastodon's rule.
	 *
	 * `$account_ids` carries its own default because a request-bound array
	 * parameter is filled in by the dispatcher, before this method's try
	 * block: a client that asks with no `account_ids[]` at all would otherwise
	 * raise a TypeError there and get a Nextcloud error page instead of
	 * `{"error": …}`.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/lists/{id}/accounts')]
	public function addAccounts(int $id, array|string $account_ids = []): DataResponse {
		try {
			$this->initViewer(['write:lists']);
			$list = $this->notAGroupList($this->ownedList($id));

			// resolved before anything is written, so a request naming one
			// account that may not be added adds none of them — a partly
			// applied write is one a client cannot retry safely
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
				// resolved, not trusted: the row is keyed by the actor id, and
				// what a client sends is a numeric id or a handle
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
	 * Which of the viewer's own lists an account is in.
	 *
	 * The viewer's own, and nobody else's: which lists a stranger put somebody
	 * in is not a thing either of them may read, and Mastodon scopes this to
	 * the token's account for the same reason.
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
	 * The list's timeline.
	 *
	 * A route of its own rather than another name in `ApiController::
	 * timelines()`: that method takes the timeline as a path segment and
	 * matches it against a fixed set of probe names, and a list timeline is
	 * not a name but a name *and an id*. Squeezing it in would mean either a
	 * second placeholder on a route that has one, or reading the id back off
	 * the query string — and either way the list's owner would have to be
	 * checked inside a method that has no business knowing what a list is.
	 * Mastodon serves it from its own controller for the same reason.
	 *
	 * `read:lists`, not `read:statuses`: it is the list that decides whether
	 * the caller may see this page at all.
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
	): DataResponse {
		try {
			$this->initViewer(['read:lists']);
			$list = $this->ownedList($id);

			$this->listsRequest->setViewer($this->viewer);

			$options = new ProbeOptions($this->request);
			$options->setFormat(ACore::FORMAT_LOCAL);
			$options->setLimit($limit)
				->setMaxId($max_id)
				->setMinId($min_id)
				->setSince($since_id)
				->setOnlyMedia($only_media)
				->setOnlyVideo($only_video)
				->setOnlyNews($only_news);

			$posts = $this->listsRequest->getTimeline($list, $options);
			// one query for the whole page, as the home timeline does it
			$this->linkPreviewService->attachCards($posts);
			$this->placeService->attachPlaces($posts);
			$this->counts?->seen($posts);

			return $this->paged($posts, $options->getLimit());
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Where a list is a Bluesky list too (it is public, or a reply rule
	 * names it), its name and members are kept in step there; resolved
	 * lazily, as the Bluesky side needs services that need this one.
	 */
	private function blueskyLists(): ?BlueskyLists {
		$lists = $this->container?->get(BlueskyLists::class);

		return $lists instanceof BlueskyLists ? $lists : null;
	}

	/**
	 * The list a request names, or a 404.
	 *
	 * @throws ItemNotFoundException it is not there, or it is not the
	 *                               viewer's — which are one answer
	 */
	private function ownedList(int $id): MastodonList {
		return $this->listsRequest->getOwnedById($this->viewer->getId(), $id);
	}

	/**
	 * A group list's members are the group's and its existence is the
	 * membership's: neither is the owner's to edit here. Leaving the group
	 * is what removes the list, in the place the group is managed.
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
	 *                                  than quietly stored as the default: a
	 *                                  client that asked for one thing must
	 *                                  not be shown another
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
	 * The `account_ids[]` of a request, as strings and without blanks.
	 *
	 * Takes a bare string as well as an array: a client that sends one id
	 * without the `[]` hands PHP a scalar, and the dispatcher passes it
	 * through — a TypeError there would be a Nextcloud error page rather than
	 * `{"error": …}`.
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
		// a follow that has not been accepted yet counts, as it does on
		// Mastodon: the list is made now and fills in when the follow is
		// answered, rather than failing on a locked account
		if (!$relationship->isFollowing() && !$relationship->isRequested()) {
			throw new ItemNotFoundException('Record not found');
		}

		return $actor;
	}

	/**
	 * A page of entities with the `Link` header masto.js reads its cursor
	 * from — without it Elk and Phanpy show the first page of a list and stop.
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
