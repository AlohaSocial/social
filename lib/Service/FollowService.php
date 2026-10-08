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
	 * The most followers one uncursored read (`getFollowers()`) answers with.
	 * Mastodon's `/api/v1/accounts/{account}/followers` is the route with a
	 * cursor; the legacy `/api/v1/current/followers` that this bounded was
	 * retired with the rest of the superseded Custom Local API. The choice was
	 * a bound or an account with fifty thousand followers loading all of them,
	 * hydrated, into the memory of one request.
	 */
	public const FOLLOWERS_PAGE = 500;

	/** Accounts one familiar-followers answer carries; Mastodon shows a handful. */
	public const FAMILIAR_MAX = 10;

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
	) {
	}

	/**
	 * Refuses an account that is following faster than anybody follows.
	 *
	 * A compromised account, or one running a script, can fan out follows to
	 * thousands of servers from this instance's address in a few minutes —
	 * every one of them a signed request that this instance is answerable for,
	 * and the shape of it is indistinguishable from a botnet warming up.
	 * Nothing anywhere counted them.
	 *
	 * An hour rather than a day, because what this catches is a burst;
	 * somebody importing a follow list from another server does it in bursts
	 * too, which is why the number is high enough for one. Counted from the
	 * rows, so it holds on an instance with no memcache, and checked before
	 * the follow is created rather than before it is delivered — a row written
	 * and then not sent is a follow that never completes and never retries.
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
	 * A page of the viewer's pending follow requests, and where it sits.
	 *
	 * The cursors and the row count are the follow rows', not the accounts':
	 * a request whose account can no longer be resolved is left off the page,
	 * and the page after it still has to start behind it, and still has to
	 * be offered when the page read as many rows as it was allowed to.
	 * `first` and `last` are the cursors of the newest and the oldest row
	 * read, '' on an empty page.
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
	 * Follows an account that has already been resolved.
	 *
	 * The half of `followAccount()` after the WebFinger lookup, on its own so
	 * that a caller holding the actor already — an import that was handed
	 * actor URLs rather than handles — does not resolve it a second time.
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

		if (BlueskyActorService::isBluesky($remoteActor)) {
			return $this->followBluesky($actor, $remoteActor);
		}
		// Nothing can be delivered to an actor that publishes no inbox:
		// `addInstancePath()` drops the empty URI, `request()` answers
		// "<request token not needed>", and the follow row sat pending for
		// ever with a log line claiming the activity had been queued.
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
		$follow->setFollowId($remoteActor->getFollowers());

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
				// Both sides live in this database, and a delivery addressed to
				// this instance is dropped before it is sent (see
				// ActivityService::isOurs()) — the server would otherwise have
				// to reach its own public address, which behind a reverse proxy
				// or split-horizon DNS it often cannot. A post loses nothing by
				// that, because its recipients are written into
				// social_stream_dest when it is saved; a Follow has no such
				// path, so the row stayed `accepted = 0` for ever, nobody's
				// home timeline changed, and the follower saw a request that
				// was never answered. Run what the inbox would have run, here.
				//
				// The origin has to be set first: that handler is the inbox's,
				// and the inbox only ever sees activities that arrived over the
				// wire with an origin already verified. This one was made here
				// a moment ago, so say so — otherwise checkOrigin() compares
				// the actor's host against an empty origin and refuses it.
				$follow->setOrigin(
					$this->configService->getCloudHost(),
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
	 * A follow of a Bluesky account: accepted at once, since Bluesky has no
	 * follow requests, written as a record in the local account's repository
	 * and watched from then on. Nothing is delivered anywhere.
	 *
	 * @throws FollowSameAccountException
	 * @throws InvalidResourceException when Bluesky is switched off
	 */
	private function followBluesky(Person $actor, Person $remoteActor): bool {
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
		$follow->setFollowId($remoteActor->getFollowers());
		$follow->setAccepted(true);
		$this->followsRequest->save($follow);
		$this->followsRequest->accepted($follow);
		$this->accountService->bumpActorCount($remoteActor->getId(), 'count_followers', 1);
		$this->timelineRevisionService->bumpForActor($actor->getId());
		$this->blueskyGraph->follow($actor, $remoteActor, $follow);
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
				// the account they left has one follower fewer. The Accept is
				// what counted it up — locally handled or delivered, see
				// FollowInterface::confirmFollowRequest() — so a follow that
				// was never accepted must not count down.
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
	 * @param Person $actor
	 *
	 * @return OrderedCollection
	 */
	public function getFollowersCollection(Person $actor): OrderedCollection {
		return OrderedCollection::paged(
			$actor->getFollowers(),
			$this->getInt('followers', $actor->getDetails(Details::COUNT)),
			$this->collectionRoute('social.ActivityPub.followers', $actor)
		);
	}

	/**
	 * One page of the followers collection.
	 *
	 * The collection has always advertised `first` as `?page=1`, but nothing
	 * read the parameter: `?page=1` returned the identical collection, whose
	 * `first` pointed at itself. A consumer following `first` either looped or
	 * gave up, so nobody could enumerate a local actor's followers — which is
	 * how another instance discovers who to deliver to when its own record is
	 * incomplete, and how account migration tools rebuild a follower list.
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
	public function getFollowing(Person $actor): array {
		return $this->followsRequest->getFollowingByActorId($actor->getId());
	}

	/**
	 * @param Person $actor
	 *
	 * @return OrderedCollection
	 */
	public function getFollowingCollection(Person $actor): OrderedCollection {
		return OrderedCollection::paged(
			$actor->getFollowing(),
			$this->getInt('following', $actor->getDetails(Details::COUNT)),
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
	 * @return Relationship[]
	 */
	/**
	 * The people the viewer follows who also follow this account — the
	 * "followed by X and 3 others you know" line on a profile.
	 *
	 * Answers nothing for the viewer's own profile, which is what Mastodon
	 * does: everybody who follows you is somebody you know of.
	 *
	 * @return Person[]
	 */
	public function familiarFollowers(Person $viewer, Person $target, int $limit = self::FAMILIAR_MAX): array {
		if ($viewer->getId() === $target->getId()) {
			return [];
		}

		$ids = $this->followsRequest->getFamiliarFollowers($viewer->getId(), $target->getId(), $limit);
		if ($ids === []) {
			return [];
		}

		return array_values($this->cacheActorService->getCachedFromIds($ids));
	}

	public function getRelationships(array $ids): array {
		$actorNids = [];

		// try to resolve actors by their id (could be nid or url)
		$nids = [];
		foreach ($ids as $id) {
			if (is_numeric($id) && (int)$id > 0) {
				$nids[] = (int)$id;
			}
		}

		// retrieve actorIds from list of Nid
		foreach ($this->cacheActorService->getFromNids($nids) as $actor) {
			$actorNids[$actor->getNid()] = $actor->getId();
		}

		// if any ids weren't found by nid, try by url
		foreach ($ids as $id) {
			if (is_numeric($id) && (int)$id > 0 && isset($actorNids[(int)$id])) {
				continue;
			}
			try {
				$actor = $this->cacheActorService->getFromId((string)$id);
				$actorNids[$actor->getNid()] = $actor->getId();
			} catch (CacheActorDoesNotExistException $e) {
				$this->logger->debug('getRelationships - actor not found by id', ['id' => $id]);
			}
		}

		unset($actorNids[$this->viewer->getNid()]); // ignore current session

		return $this->generateRelationships($this->viewer->getId(), $actorNids);
	}

	/**
	 * Relationships for a whole page of accounts, in a fixed number of queries.
	 *
	 * Built one at a time, this was six round trips per account — the two
	 * follow rows, the blocks and mutes, the note, and the mute's expiry — so a
	 * client asking about a page of forty paid two hundred and forty. The same
	 * five queries answer any number of accounts, because every one of them was
	 * already a lookup on (viewer, account) and `IN` takes a list.
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
				// the row is already in hand; reading it again would be a query
				// for something this loop just read
				$relationship->setEndorsed(true);
				break;
			case ActorRelation::TYPE_HIDE_REBLOGS:
				// `showing_reblogs` was always true, so a client that had
				// turned the boosts off was told they were on
				$relationship->setShowingReblogs(false);
				break;
			case AccountRelationService::TYPE_NOTIFY:
				// `notifying` was always false, so a client that had turned the
				// bell on was told it was off and drew it that way
				$relationship->setNotifying(true);
				break;
		}
	}

	/**
	 * @param int|string $nid
	 * @param string $viewerId
	 * @param string $actorId
	 *
	 * @return Relationship
	 */
	/**
	 * The viewer's relationship with one resolved actor. Unlike getRelationships()
	 * this takes the Person directly, so it always returns an entry (the block/mute
	 * endpoints need the updated relationship back even right after the change).
	 */
	public function getRelationshipWith(Person $target): Relationship {
		return $this->generateRelationship($target->getNid(), $this->viewer->getId(), $target->getId());
	}

	/**
	 * One account's relationship with the viewer.
	 *
	 * Built by the same code the page version uses, deliberately: two
	 * implementations of "what is the relationship between these two" is two
	 * places for the answer to differ, and the route that draws a follow button
	 * and the route that draws a list of them have to agree.
	 */
	private function generateRelationship(int|string $nid, string $viewerId, string $actorId): Relationship {
		return $this->generateRelationships($viewerId, [$nid => $actorId])[0] ?? new Relationship($nid);
	}
}
