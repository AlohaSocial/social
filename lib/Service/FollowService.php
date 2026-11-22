<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\AP;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Reader\BlueskyGraphService;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Exceptions\BlockedByException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\FollowLimitException;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\FollowSameAccountException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\RetrieveAccountFormatException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Interfaces\Object\FollowInterface;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\OrderedCollection;
use OCA\Social\Model\ActivityPub\OrderedCollectionPage;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\Details;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\Relationship;
use OCA\Social\Service\BlockedBy\BlockedByService;
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

class FollowService {
	use TArrayTools;

	/**
	 * The most followers one uncursored read (`getFollowers()`) answers with, so
	 * an account with fifty thousand followers is not loaded whole into one
	 * request; `/api/v1/accounts/{account}/followers` is the route with a cursor.
	 */
	public const FOLLOWERS_PAGE = 500;

	/** Accounts one familiar-followers answer carries; Mastodon shows a handful. */
	public const FAMILIAR_MAX = 10;

	/** Accounts one relationships answer covers; the rest of a longer list is ignored. */
	public const RELATIONSHIPS_LIMIT = 40;

	private ?Person $viewer = null;

	public function __construct(
		private IURLGenerator $urlGenerator,
		private FollowsRequest $followsRequest,
		private ActorRelationRequest $actorRelationRequest,
		private ActivityService $activityService,
		private CacheActorService $cacheActorService,
		private ConfigService $configService,
		private FollowInterface $followInterface,
		private ModerationService $moderationService,
		private AccountRelationService $accountRelationService,
		private AccountService $accountService,
		private TimelineRevisionService $timelineRevisionService,
		private LoggerInterface $logger,
		private ?BlueskyGraphService $blueskyGraph = null,
		private ?BlockedByService $blockedBy = null,
		private ?HiddenCollectionsService $hiddenCollections = null,
	) {
	}

	/**
	 * Refuses an account that is following faster than anybody follows, as a
	 * compromised or scripted account fanning out signed follows would. Counted
	 * per hour from the rows, high enough for a follow-list import's bursts and
	 * working without memcache, and checked before the row is created so no
	 * follow is left unsent.
	 *
	 * @throws FollowLimitException
	 */
	private function assertWithinFollowLimit(Person $actor): void {
		$limit = (int)$this->configService->getAppValue(ConfigService::SOCIAL_FOLLOW_LIMIT);
		if ($limit < 1) {
			return;
		}

		$sent = $this->followsRequest->countFollowsSince($actor->getId(), time() - 3600);
		if ($sent < $limit) {
			return;
		}

		$this->logger->warning('an account reached its follow limit', [
			'actor' => $actor->getId(), 'limit' => $limit, 'sent' => $sent,
		]);

		throw new FollowLimitException(
			'this account has sent as many follows in the last hour as this server allows'
		);
	}

	/**
	 * Refuses a new follow of an account that has blocked this one, wherever
	 * the account is: it would reach nobody. A follow that is there already
	 * is left to be changed.
	 *
	 * @throws BlockedByException
	 */
	private function assertNotBlockedBy(Person $actor, Person $target): void {
		if ($this->blockedBy === null) {
			return;
		}
		try {
			$this->followsRequest->getByPersons($actor->getId(), $target->getId());

			return;
		} catch (FollowNotFoundException) {
		}
		$this->blockedBy->assertNotBlocked($actor, [$target->getId()]);
	}

	/**
	 * The accounts whose follows towards the viewer wait for approval, newest
	 * first.
	 *
	 * @param int $limit 0 for all of them
	 *
	 * @return Person[]
	 */
	public function getPendingRequests(int $limit = 0): array {
		return $this->getPendingRequestPage($limit)['accounts'];
	}

	/**
	 * A page of the viewer's pending follow requests, and where it sits. Cursors
	 * and the row count are the follow rows', not the accounts': a request whose
	 * account cannot be resolved is left off but still moves the cursor. `first`
	 * and `last` are the newest and oldest rows' cursors, '' on an empty page.
	 *
	 * @param string $maxId a cursor to read older requests from
	 * @param string $minId a cursor to read newer requests from
	 *
	 * @return array{accounts: Person[], rows: int, first: string, last: string}
	 */
	public function getPendingRequestPage(int $limit, string $maxId = '', string $minId = ''): array {
		$follows = $this->followsRequest->getPendingByObjectId($this->viewer->getId(), $limit, $maxId, $minId);

		$accounts = [];
		foreach ($follows as $follow) {
			try {
				$accounts[] = $this->cacheActorService->getFromId($follow->getActorId());
			} catch (Exception $e) {
			}
		}

		$first = reset($follows);
		$last = end($follows);

		return [
			'accounts' => $accounts,
			'rows' => count($follows),
			'first' => $first === false ? '' : FollowsRequest::pendingCursor($first),
			'last' => $last === false ? '' : FollowsRequest::pendingCursor($last),
		];
	}

	/**
	 * Approves a pending follow request: federates the Accept and marks the row.
	 *
	 * @throws FollowNotFoundException when there is no pending follow from that account
	 */
	public function authorizeFollowRequest(Person $follower): void {
		$follow = $this->followsRequest->getByPersons($follower->getId(), $this->viewer->getId());
		if ($follow->isAccepted()) {
			return; // already following: authorize is idempotent
		}

		$this->followInterface->confirmFollowRequest($follow);
	}

	/**
	 * Rejects a pending follow request: federates the Reject and drops the row.
	 *
	 * @throws FollowNotFoundException when there is no pending follow from that account
	 */
	public function rejectFollowRequest(Person $follower): void {
		$follow = $this->followsRequest->getByPersons($follower->getId(), $this->viewer->getId());

		$this->followInterface->rejectFollowRequest($follow);
	}

	/**
	 * @param Person $viewer
	 */
	public function setViewer(Person $viewer) {
		$this->viewer = $viewer;
		$this->followsRequest->setViewer($viewer);
	}

	/**
	 * @param Person $actor
	 * @param string $account
	 *
	 * @return bool whether a follow was made: false when one already existed
	 *
	 * @throws CacheActorDoesNotExistException
	 * @throws FollowSameAccountException
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws RetrieveAccountFormatException
	 * @throws SocialAppConfigException
	 * @throws ItemUnknownException
	 * @throws UrlCloudException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws RequestResultNotJsonException
	 * @throws UnauthorizedFediverseException
	 */
	public function followAccount(Person $actor, string $account): bool {
		$this->moderationService->assertNotSuspended($actor->getId());
		$this->logger->debug('FollowService::followAccount called', [
			'actor' => $actor->getId(),
			'account' => $account,
		]);

		$remoteActor = $this->cacheActorService->getFromAccount($account);
		$this->logger->debug('FollowService::followAccount - remote actor resolved', [
			'remoteId' => $remoteActor->getId(),
			'remoteNid' => $remoteActor->getNid(),
		]);

		return $this->followActor($actor, $remoteActor);
	}

	/**
	 * Follows an account that has already been resolved: `followAccount()` after
	 * the WebFinger lookup, for callers holding the actor already.
	 *
	 * @return bool whether a follow was made: false when one already existed,
	 *              which is what a client re-following to change a setting
	 *              sends
	 *
	 * @throws FollowSameAccountException
	 * @throws SocialAppConfigException
	 * @throws Throwable
	 */
	public function followActor(Person $actor, Person $remoteActor): bool {
		$this->moderationService->assertNotSuspended($actor->getId());
		$this->moderationService->assertNotMoved($actor);
		$this->assertWithinFollowLimit($actor);

		if ($remoteActor->getId() === $actor->getId()) {
			$this->logger->warning('FollowService::followAccount - same account');
			throw new FollowSameAccountException("Don't follow yourself, be your own lead");
		}
		$this->assertNotBlockedBy($actor, $remoteActor);

		if (BlueskyActorService::isBluesky($remoteActor)) {
			return $this->followBluesky($actor, $remoteActor);
		}
		// nothing can be delivered to an actor that publishes no inbox, and the
		// follow would stay pending for ever
		if (!$remoteActor->isLocal() && $remoteActor->getInbox() === '') {
			throw new InvalidResourceException(
				$remoteActor->getId() . ' publishes no inbox, so a Follow cannot be delivered'
			);
		}

		/** @var Follow $follow */
		$follow = AP::instance()->getItemFromType(Follow::TYPE);
		$follow->generateUniqueId();
		$follow->setActorId($actor->getId());
		$follow->setObjectId($remoteActor->getId());
		$follow->setFollowId($remoteActor->getOwnFollowers());

		try {
			$this->followsRequest->getByPersons($actor->getId(), $remoteActor->getId());
			$this->logger->info('FollowService::followAccount - already following', [
				'actor' => $actor->getId(),
				'target' => $remoteActor->getId(),
			]);

			return false;
		} catch (FollowNotFoundException $e) {
			$this->followsRequest->save($follow);
			// their home timeline holds different posts from now on, which its
			// ETag has no other way of knowing — see TimelineRevisionService
			$this->timelineRevisionService->bumpForActor($actor->getId());
			$this->logger->info('FollowService::followAccount - saved new follow', [
				'followId' => $follow->getId(),
				'actor' => $actor->getId(),
				'object' => $remoteActor->getId(),
			]);

			if ($remoteActor->isLocal()) {
				// Both sides live here, and a delivery to this instance is dropped before
				// it is sent (ActivityService::isOurs()), so run what the inbox would have
				// run. The origin is set first, as the inbox handler expects a verified
				// one.
				$follow->setOrigin(
					$this->configService->getCloudAuthority(),
					SignatureService::ORIGIN_REQUEST,
					time()
				);
				$this->followInterface->processIncomingRequest($follow);
				$this->logger->info('FollowService::followAccount - local follow handled in process');

				return true;
			}

			$follow->addInstancePath(
				new InstancePath(
					$remoteActor->getInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_TOP
				)
			);
			try {
				$this->activityService->request($follow);
				$this->logger->info('FollowService::followAccount - activity queued');
			} catch (Throwable $e) {
				$this->logger->error('FollowService::followAccount - failed to queue activity', [
					'error' => $e->getMessage(),
				]);
			}

			return true;
		}
	}

	/**
	 * A follow of a Bluesky account that moved here with the person's
	 * repository (§13.1): saved here, linked to the record that is there
	 * already, nothing written to Bluesky.
	 *
	 * @return bool whether the follow was made now
	 */
	public function adoptBlueskyFollow(Person $actor, Person $remoteActor, string $did, string $rkey): bool {
		return $this->followBluesky($actor, $remoteActor, $did, $rkey);
	}

	/**
	 * A follow of a Bluesky account: accepted at once, since Bluesky has no
	 * follow requests, written as a record in the local account's repository
	 * and watched from then on. Nothing is delivered anywhere.
	 *
	 * @throws FollowSameAccountException
	 * @throws InvalidResourceException when Bluesky is switched off
	 */
	private function followBluesky(Person $actor, Person $remoteActor, string $adoptDid = '', string $adoptRkey = ''): bool {
		if ($this->blueskyGraph === null) {
			throw new InvalidResourceException('Bluesky is not available here');
		}
		try {
			$this->followsRequest->getByPersons($actor->getId(), $remoteActor->getId());

			return false;
		} catch (FollowNotFoundException) {
		}
		/** @var Follow $follow */
		$follow = AP::instance()->getItemFromType(Follow::TYPE);
		$follow->generateUniqueId();
		$follow->setActorId($actor->getId());
		$follow->setObjectId($remoteActor->getId());
		$follow->setFollowId($remoteActor->getOwnFollowers());
		$follow->setAccepted(true);
		$this->followsRequest->save($follow);
		$this->followsRequest->accepted($follow);
		$this->accountService->bumpActorCount($remoteActor->getId(), 'count_followers', 1);
		$this->timelineRevisionService->bumpForActor($actor->getId());
		if ($adoptRkey !== '') {
			$this->blueskyGraph->adopt($adoptDid, $adoptRkey, $remoteActor, $follow);
		} else {
			$this->blueskyGraph->follow($actor, $remoteActor, $follow);
		}
		$this->logger->info('FollowService::followAccount - following a Bluesky account', ['actor' => $actor->getId(), 'object' => $remoteActor->getId()]);

		return true;
	}

	/**
	 * @param Person $actor
	 * @param string $account
	 *
	 * @return bool whether a follow was removed: false when there was none
	 *
	 * @throws CacheActorDoesNotExistException
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws RequestContentException
	 * @throws RetrieveAccountFormatException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws ItemUnknownException
	 * @throws UrlCloudException
	 * @throws RequestResultNotJsonException
	 * @throws UnauthorizedFediverseException
	 */
	public function unfollowAccount(Person $actor, string $account): bool {
		$remoteActor = $this->cacheActorService->getFromAccount($account);

		try {
			$follow = $this->followsRequest->getByPersons($actor->getId(), $remoteActor->getId());
			$this->followsRequest->delete($follow);
			$this->timelineRevisionService->bumpForActor($actor->getId());
			if ($follow->isAccepted()) {
				// the account they left has one follower fewer, but only if the follow
				// was accepted, which is what counted it up
				$this->accountService->bumpActorCount(
					$remoteActor->getId(), 'count_followers', -1
				);
			}

			if (BlueskyActorService::isBluesky($remoteActor)) {
				$this->blueskyGraph?->unfollow($actor, $remoteActor, $follow);

				return true;
			}
			$undo = AP::instance()->getItemFromType(Undo::TYPE);
			$follow->setParent($undo);
			// hung off the local actor, not the cloud root: see
			// ACore::generateUniqueIdFromActor()
			$undo->generateUniqueIdFromActor($actor->getId(), 'undo/follows');
			$undo->setObject($follow);
			$undo->setActorId($actor->getId());

			if (!$remoteActor->isLocal() && $remoteActor->getInbox() === '') {
				// the row is gone here either way; the other server keeps a
				// follower it will hear nothing more from
				$this->logger->error('cannot federate an Undo of a Follow: the actor publishes no inbox', [
					'actor' => $actor->getId(),
					'target' => $remoteActor->getId(),
				]);

				return true;
			}

			$undo->addInstancePath(
				new InstancePath(
					$remoteActor->getInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_TOP
				)
			);
			$this->activityService->request($undo);

			return true;
		} catch (FollowNotFoundException $e) {
			return false;
		}
	}

	/** The follow of one actor by another, or null when there is none. */
	public function getFollow(Person $actor, Person $target): ?Follow {
		try {
			return $this->followsRequest->getByPersons($actor->getId(), $target->getId());
		} catch (FollowNotFoundException) {
			return null;
		}
	}

	/**
	 * @param Person $local
	 * @param Person $actor
	 *
	 * @return array
	 */
	public function getLinksBetweenPersons(Person $local, Person $actor): array {
		$links = [
			'follower' => false,
			'following' => false
		];

		try {
			$this->followsRequest->getByPersons($local->getId(), $actor->getId());
			$links['following'] = true;
		} catch (FollowNotFoundException $e) {
		}

		try {
			$this->followsRequest->getByPersons($actor->getId(), $local->getId());
			$links['follower'] = true;
		} catch (FollowNotFoundException $e) {
		}

		return $links;
	}

	/**
	 * @param Person $actor
	 *
	 * @return Follow[]
	 *
	 * @psalm-return array<Follow>
	 */
	public function getFollowers(Person $actor, int $limit = self::FOLLOWERS_PAGE): array {
		return $this->followsRequest->getFollowersByActorId($actor->getId(), $limit);
	}

	/**
	 * The followers collection: its count and its first and last page, or,
	 * for an account that hides its lists, the count alone, as Mastodon
	 * answers for one.
	 */
	public function getFollowersCollection(Person $actor): OrderedCollection {
		$total = $this->getInt('followers', $actor->getDetails(Details::COUNT));
		if ($this->hiddenCollections?->hidesFor($actor) === true) {
			return OrderedCollection::counted($actor->getFollowers(), $total);
		}

		return OrderedCollection::paged(
			$actor->getFollowers(),
			$total,
			$this->collectionRoute('social.ActivityPub.followers', $actor)
		);
	}

	/**
	 * One page of the followers collection, which the collection's `first`
	 * (`?page=1`) points at.
	 */
	public function getFollowersPage(Person $actor, int $page, string $before = ''): OrderedCollectionPage {
		return $this->followPage(
			$actor->getFollowers(), $this->collectionRoute('social.ActivityPub.followers', $actor), $page, $before,
			fn (int $offset, string $cursor): array => $this->followsRequest->getFollowersByActorId(
				$actor->getId(), OrderedCollection::PAGE_SIZE, $offset, $cursor
			),
			static fn (Follow $follow): string => $follow->getActorId()
		);
	}

	/**
	 * A page of the followers or following collection: by number when a
	 * `page` was asked for, after a cursor when a `max_id` was, and with its
	 * `next` a cursor either way; see OrderedCollectionPage::of().
	 *
	 * @param callable(int, string): Follow[] $read the follows at an offset or after a cursor
	 * @param callable(Follow): string $item what the collection lists of each
	 */
	private function followPage(
		string $collection, string $route, int $page, string $before, callable $read, callable $item,
	): OrderedCollectionPage {
		$follows = ($before === '') ? $read(($page - 1) * OrderedCollection::PAGE_SIZE, '') : $read(0, $before);
		$last = end($follows);
		$next = ($last === false) ? '' : FollowsRequest::cursorAfter($last);
		$items = array_values(array_map($item, $follows));

		return ($before === '')
			? OrderedCollectionPage::of($collection, $route, $page, $items, $next)
			: OrderedCollectionPage::after($collection, $route, 'max_id', $before, $items, $next);
	}

	/**
	 * @param Person $actor
	 *
	 * @return Follow[]
	 *
	 * @psalm-return array<Follow>
	 */
	public function getFollowing(Person $actor, int $limit = 0, int $offset = 0): array {
		return $this->followsRequest->getFollowingByActorId($actor->getId(), $limit, $offset);
	}

	/**
	 * Read one keyset-paginated page of the accounts an actor follows.
	 *
	 * @return Follow[]
	 */
	public function getFollowingAfter(Person $actor, int $limit, string $cursor = ''): array {
		return $this->followsRequest->getFollowingByActorId($actor->getId(), $limit, 0, $cursor);
	}

	/**
	 * Which authors from one page the reader follows, in one bounded query.
	 *
	 * @param string[] $actorIds
	 * @return string[]
	 */
	public function followingAmong(Person $actor, array $actorIds): array {
		$actorIds = array_values(array_unique(array_filter($actorIds, static fn (string $id): bool => $id !== '' && $id !== $actor->getId())));
		if ($actorIds === []) {
			return [];
		}

		$targets = array_fill_keys($actorIds, true);
		$following = [];
		foreach ($this->followsRequest->getFollows(array_merge([$actor->getId()], $actorIds)) as $follow) {
			$target = $follow->getObjectId();
			if ($follow->getActorId() === $actor->getId() && $follow->isAccepted() && isset($targets[$target])) {
				$following[$target] = true;
			}
		}

		return array_keys($following);
	}

	/** The following collection. See getFollowersCollection(). */
	public function getFollowingCollection(Person $actor): OrderedCollection {
		$total = $this->getInt('following', $actor->getDetails(Details::COUNT));
		if ($this->hiddenCollections?->hidesFor($actor) === true) {
			return OrderedCollection::counted($actor->getFollowing(), $total);
		}

		return OrderedCollection::paged(
			$actor->getFollowing(),
			$total,
			$this->collectionRoute('social.ActivityPub.following', $actor)
		);
	}

	/** One page of the following collection. See getFollowersPage(). */
	public function getFollowingPage(Person $actor, int $page, string $before = ''): OrderedCollectionPage {
		return $this->followPage(
			$actor->getFollowing(), $this->collectionRoute('social.ActivityPub.following', $actor), $page, $before,
			fn (int $offset, string $cursor): array => $this->followsRequest->getFollowingByActorId(
				$actor->getId(), OrderedCollection::PAGE_SIZE, $offset, $cursor
			),
			static fn (Follow $follow): string => $follow->getObjectId()
		);
	}

	private function collectionRoute(string $route, Person $actor): string {
		return $this->urlGenerator->linkToRouteAbsolute(
			$route, ['username' => $actor->getPreferredUsername()]
		);
	}

	/**
	 * @param string $recipient
	 *
	 * @return Follow[]
	 */
	public function getFollowersFromFollowId(string $recipient): array {
		return $this->followsRequest->getFollowersByFollowId($recipient);
	}

	/**
	 * The people the viewer follows who also follow this account, for the
	 * "followed by X and 3 others you know" line. Nothing for the viewer's own
	 * profile, as on Mastodon, nor for an account that hides its followers; those
	 * who hide whom they follow are not named.
	 *
	 * @return Person[]
	 */
	public function familiarFollowers(Person $viewer, Person $target, int $limit = self::FAMILIAR_MAX): array {
		if ($viewer->getId() === $target->getId()) {
			return [];
		}
		if ($this->hiddenCollections?->hidesFor($target) === true) {
			return [];
		}

		$ids = $this->followsRequest->getFamiliarFollowers($viewer->getId(), $target->getId(), $limit);
		if ($ids === []) {
			return [];
		}

		return array_values(array_filter(
			$this->cacheActorService->getCachedFromIds($ids),
			fn (Person $follower): bool => $this->hiddenCollections?->hidesFor($follower) !== true
		));
	}

	/**
	 * The viewer's relationship with each of the accounts named, by nid or by
	 * actor id, for at most `RELATIONSHIPS_LIMIT` of them. Answered from the
	 * accounts held here, never fetched, so a request cannot make this server
	 * send hundreds of signed fetches; ids naming nothing held are left out.
	 *
	 * @param array<mixed> $ids
	 *
	 * @return Relationship[]
	 */
	public function getRelationships(array $ids): array {
		$wanted = [];
		foreach ($ids as $id) {
			$id = is_scalar($id) ? trim((string)$id) : '';
			if ($id !== '') {
				$wanted[$id] = $id;
			}
		}
		$wanted = array_slice(array_values($wanted), 0, self::RELATIONSHIPS_LIMIT);

		$nids = [];
		$actorIds = [];
		foreach ($wanted as $id) {
			if (ctype_digit($id)) {
				if ((int)$id > 0) {
					$nids[] = (int)$id;
				}
			} else {
				$actorIds[] = $id;
			}
		}

		$actorNids = [];
		foreach ($this->cacheActorService->getFromNids($nids) as $actor) {
			$actorNids[$actor->getNid()] = $actor->getId();
		}
		foreach ($this->cacheActorService->getCachedFromIds($actorIds) as $actor) {
			$actorNids[$actor->getNid()] = $actor->getId();
		}

		unset($actorNids[$this->viewer->getNid()]); // ignore current session

		if (count($actorNids) === 1) {
			// one account is a profile being looked at: whether it has blocked
			// the viewer is asked where its network can be asked
			$this->blockedBy?->ask($this->viewer->getId(), array_values($actorNids));
		}

		return $this->generateRelationships($this->viewer->getId(), $actorNids);
	}

	/**
	 * Relationships for a whole page of accounts, in a fixed number of queries:
	 * every lookup is on (viewer, account), so `IN` answers them all.
	 *
	 * @param array<int|string, string> $actorNids actor id keyed by nid
	 *
	 * @return Relationship[]
	 */
	private function generateRelationships(string $viewerId, array $actorNids): array {
		if ($actorNids === []) {
			return [];
		}

		$actorIds = array_values($actorNids);
		$follows = $this->followsRequest->getBetweenMany($viewerId, $actorIds);
		$relations = $this->actorRelationRequest->getBetweenMany($viewerId, $actorIds);

		$relationships = [];
		foreach ($actorNids as $actorNid => $actorId) {
			$relationship = new Relationship($actorNid);

			$following = $follows['following'][$actorId] ?? null;
			if ($following !== null) {
				$following->isAccepted()
					? $relationship->setFollowing(true)
					: $relationship->setRequested(true);
			}

			$followedBy = $follows['followedBy'][$actorId] ?? null;
			if ($followedBy !== null) {
				// a pending row the other way is what /api/v1/follow_requests
				// lists, and what a client shows approve/reject for
				$followedBy->isAccepted()
					? $relationship->setFollowedBy(true)
					: $relationship->setRequestedBy(true);
			}

			foreach ($relations[$actorId] ?? [] as $relation) {
				$this->applyRelation($relationship, $relation);
			}

			$relationships[$actorId] = $relationship;
		}

		// the note, the domain block and the expiry of a mute, for the whole
		// page at once — see AccountRelationService::decorateMany()
		$this->accountRelationService->decorateMany($relationships, $viewerId);

		return array_values($relationships);
	}

	private function applyRelation(Relationship $relationship, ActorRelation $relation): void {
		switch ($relation->getType()) {
			case ActorRelation::TYPE_BLOCK:
				$relationship->setBlocking(true);
				break;
			case ActorRelation::TYPE_BLOCKED_BY:
				$relationship->setBlockedBy(true);
				break;
			case ActorRelation::TYPE_MUTE:
				$relationship->setMuting(true);
				$relationship->setMutingNotifications($relation->isNotifications());
				break;
			case AccountRelationService::TYPE_ENDORSE:
				$relationship->setEndorsed(true);
				break;
			case ActorRelation::TYPE_HIDE_REBLOGS:
				$relationship->setShowingReblogs(false);
				break;
			case AccountRelationService::TYPE_NOTIFY:
				$relationship->setNotifying(true);
				break;
		}
	}

	/**
	 * The viewer's relationship with one resolved actor. Unlike getRelationships()
	 * this takes the Person directly, so it always returns an entry (the block/mute
	 * endpoints need the updated relationship back even right after the change).
	 */
	public function getRelationshipWith(Person $target): Relationship {
		return $this->generateRelationship($target->getNid(), $this->viewer->getId(), $target->getId());
	}

	/**
	 * One account's relationship with the viewer, built by the page version's
	 * code so the two cannot disagree.
	 *
	 * @param int|string $nid
	 * @param string $viewerId
	 * @param string $actorId
	 *
	 * @return Relationship
	 */
	private function generateRelationship(int|string $nid, string $viewerId, string $actorId): Relationship {
		return $this->generateRelationships($viewerId, [$nid => $actorId])[0] ?? new Relationship($nid);
	}
}
