<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\AP;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\RetrieveAccountFormatException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Interfaces\Activity\FeaturedCollection;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\OrderedCollection;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\Details;
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Class CacheActorService
 *
 * @package OCA\Social\Service
 */
class CacheActorService {
	/**
	 * The most batches one timed pass takes — a guard, not the limit: the
	 * deadline is what ends a pass, and this keeps one that the clock does not
	 * stop (a frozen test clock, a stamp that is never written) finite.
	 */
	public const MAX_BATCHES = 40;

	/**
	 * Actors already resolved during this request.
	 *
	 * A page of twenty posts asks for the same author several times over, and
	 * each ask was a query. Not a cache — there is no lifetime and no
	 * invalidation — but the same answer to the same question inside one
	 * response, which is what makes it safe: nothing can change underneath it
	 * that this request would have seen anyway.
	 *
	 * @var array<string, Person>
	 */
	private array $memo = [];

	use TArrayTools;

	public function __construct(
		private IUrlGenerator $urlGenerator,
		private ActorsRequest $actorsRequest,
		private CacheActorsRequest $cacheActorsRequest,
		private CurlService $curlService,
		private FediverseService $fediverseService,
		private ConfigService $configService,
		private LoggerInterface $logger,
		private ?ContainerInterface $container = null,
		private ?ITimeFactory $timeFactory = null,
	) {
	}

	/** The clock the refresh stamps attempts with; injectable so a test can move it. */
	private function now(): int {
		return $this->timeFactory?->getTime() ?? time();
	}

	/**
	 * @param Person $viewer
	 */
	public function setViewer(Person $viewer) {
		$this->cacheActorsRequest->setViewer($viewer);
	}

	/**
	 * @param string $id
	 *
	 * @param bool $refresh
	 *
	 * @return Person
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws ItemUnknownException
	 * @throws RequestResultNotJsonException
	 * @throws UnauthorizedFediverseException
	 */
	public function getFromId(string $id, bool $refresh = false): Person {
		$posAnchor = strpos($id, '#');
		if ($posAnchor !== false) {
			$id = substr($id, 0, $posAnchor);
		}

		// A page of twenty posts asks for the same author several times over —
		// the author is joined for the row, but a mention, a boost, a quoted
		// post and a tagged person each ask again by id, and every one of those
		// was a query. Memoised per request, which is where the repetition is:
		// this is not a cache with a lifetime, it is the same answer to the
		// same question inside one response.
		if (!$refresh && array_key_exists($id, $this->memo)) {
			return $this->memo[$id];
		}

		try {
			if ($refresh) {
				throw new CacheActorDoesNotExistException();
			}

			$actor = $this->cacheActorsRequest->getFromId($id);
		} catch (CacheActorDoesNotExistException $e) {
			try {
				$object = $this->curlService->retrieveObject($id);
			} catch (RequestContentException $e) {
				// in case of refresh but remote tells us that actor is gone, we delete it.
				if ($refresh && $e->getCode() === Http::STATUS_GONE) {
					$this->delete($this->cacheActorsRequest->getFromId($id));
				}

				throw $e;
			}

			$this->logger->debug('object retrieved', ['id' => $id, 'object' => $object]);

			/** @var Person $actor */
			$actor = AP::instance()->getItemFromData($object);
			if (!AP::instance()->isActor($actor)) {
				throw new InvalidResourceException();
			}

			$this->assertIsTheActorOf($id, $actor, $this->get('_contentType', $object));

			$actor->setAccount($actor->getPreferredUsername() . '@' . $this->get('_host', $object));
			try {
				$this->save($actor);
			} catch (Exception $e) {
				throw new InvalidResourceException($e->getMessage());
			}
		}

		$this->memo[$id] = $actor;

		return $actor;
	}

	/**
	 * Holds a fetched document to being the actor of the URL it came from.
	 *
	 * The cached row is keyed by the actor's own `id`, so a document that
	 * answers with somebody else's id rewrites somebody else's row — public
	 * key included, which is the key `SignatureService` then verifies that
	 * account's requests with. Matching only the host was enough for any URL
	 * on a host that serves JSON somebody else wrote (on a Nextcloud, a public
	 * share) to claim that host's admin account, and an inbox POST naming such
	 * a URL as its `keyId` is enough to make this instance go and fetch it.
	 *
	 * Two things are therefore required, the same two `CurlService::retrieveAccount()`
	 * and `StreamQueueService` require: the document has to be served as
	 * ActivityPub, and it has to be the actor that was asked for. An actor
	 * served at some other URL of its host is no longer taken as the actor of
	 * this one — it is exactly what a document claiming a stranger's id looks
	 * like from here.
	 *
	 * @throws InvalidOriginException
	 */
	private function assertIsTheActorOf(string $id, Person $actor, string $contentType): void {
		if (!CurlService::isActivityPubMediaType($contentType)) {
			throw new InvalidOriginException(
				'CacheActorService::getFromId - id: ' . $id . ' - contentType: ' . $contentType
			);
		}

		if (strtolower($actor->getId()) !== strtolower($id)) {
			throw new InvalidOriginException(
				'CacheActorService::getFromId - id: ' . $id . ' - actorId: ' . $actor->getId()
			);
		}
	}

	/**
	 * Forgets the memoised actors.
	 *
	 * For the long-running processes — `social:worker`, the cron — where "one
	 * request" is not a bound at all and an unbounded map is a leak. Every
	 * short-lived request throws the whole object away instead.
	 */
	public function forgetMemoised(): void {
		$this->memo = [];
	}

	/**
	 * What is already known about a set of actors, in one query and without
	 * touching the network.
	 *
	 * getFromId() resolves a miss by fetching the actor from its instance,
	 * which is right for one actor on a path that can wait, and wrong for a
	 * listing: a page of reports or of blocks would make one synchronous
	 * federated request per row, and one unreachable instance would hang the
	 * whole page for the curl timeout.
	 *
	 * @param string[] $ids
	 *
	 * @return Person[] keyed by actor id; ids that are not cached are absent
	 */
	public function getCachedFromIds(array $ids): array {
		$wanted = [];
		foreach ($ids as $id) {
			$posAnchor = strpos($id, '#');
			if ($posAnchor !== false) {
				$id = substr($id, 0, $posAnchor);
			}

			if ($id !== '') {
				$wanted[$id] = $id;
			}
		}

		if ($wanted === []) {
			return [];
		}

		return $this->cacheActorsRequest->getFromIds(array_values($wanted));
	}

	/**
	 * @param string $account
	 *
	 * @return Person
	 * @throws CacheActorDoesNotExistException
	 * @throws SocialAppConfigException
	 * @throws ActorDoesNotExistException
	 */
	public function getFromLocalAccount(string $account): Person {
		$instance = '';
		$account = ltrim($account, '@');
		if (strrpos($account, '@')) {
			[$account, $instance] = explode('@', $account, 2);
		}

		// a host name is case-insensitive, as the account part already is
		if ($instance !== ''
			&& strcasecmp($this->configService->getCloudHost(), $instance) !== 0
			&& strcasecmp($this->configService->getSocialAddress(), $instance) !== 0) {
			throw new CacheActorDoesNotExistException('Address is not local');
		}

		$actor = $this->actorsRequest->getFromUsername($account);
		if ($actor->getDeleted() > 0) {
			throw new CacheActorDoesNotExistException('Account is deleted');
		}

		return $this->cacheActorsRequest->getFromLocalAccount($account);
	}

	/**
	 * An account by whatever a client names it with: its numeric id, its
	 * `name@host` (or a bare local name), or its actor URL.
	 *
	 * Mastodon's own routes take the numeric id, this app's `{account_id}`
	 * paths were written for an actor URL, and a client that has an account
	 * entity in hand has the numeric one. Rather than each route deciding,
	 * they ask here.
	 *
	 * Nothing is fetched from elsewhere unless `$mayFetch` says so: a route
	 * about accounts this server already holds, answered to anybody, is not a
	 * reason to go and webfinger a name or fetch a URL. A signed-in viewer
	 * naming a stranger is, and passes `true`.
	 *
	 * @throws CacheActorDoesNotExistException
	 */
	public function resolve(string $reference, bool $mayFetch = false): Person {
		$reference = ltrim(trim($reference), '@');
		if ($reference === '') {
			throw new CacheActorDoesNotExistException('unknown account');
		}

		if (is_numeric($reference)) {
			if ((int)$reference < 1) {
				throw new CacheActorDoesNotExistException('unknown account');
			}

			$actors = $this->getFromNids([(int)$reference]);
			if ($actors === []) {
				throw new CacheActorDoesNotExistException('unknown account');
			}

			return $actors[0];
		}

		if (str_starts_with($reference, 'http://') || str_starts_with($reference, 'https://')) {
			if ($mayFetch) {
				return $this->getFromId($reference);
			}

			$cached = $this->getCachedFromIds([$reference]);
			if ($cached === []) {
				throw new CacheActorDoesNotExistException('unknown account');
			}

			return array_values($cached)[0];
		}

		return $this->getFromAccount($reference, $mayFetch);
	}

	/**
	 * @param string $account
	 *
	 * @param bool $retrieve
	 *
	 * @return Person
	 * @throws CacheActorDoesNotExistException
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws ItemUnknownException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws RetrieveAccountFormatException
	 * @throws SocialAppConfigException
	 * @throws ItemUnknownException
	 * @throws RequestResultNotJsonException
	 * @throws UnauthorizedFediverseException
	 */
	public function getFromAccount(string $account, bool $retrieve = true): Person {
		try {
			return $this->getFromLocalAccount($account);
		} catch (CacheActorDoesNotExistException $e) {
		}

		$this->logger->debug('[CacheActorService] getFromAccount', [
			'account' => $account, 'retrieve' => $retrieve,
		]);

		try {
			$actor = $this->cacheActorsRequest->getFromAccount($account);

			// the id, not the document: `getSource()` is the whole actor as its
			// server sent it, and a log line per lookup of that size is a log
			// nobody can read and a disk nobody budgeted for
			$this->logger->debug('[CacheActorService] found an actor', [
				'account' => $account, 'actor' => $actor->getId(),
			]);
		} catch (CacheActorDoesNotExistException $e) {
			$this->logger->debug('[CacheActorService] no actor held here', ['account' => $account]);

			if (!$retrieve) {
				throw new CacheActorDoesNotExistException();
			}

			$actor = $this->curlService->retrieveAccount($account);
			$actor->setAccount($account);
			try {
				$this->logger->debug('[CacheActorService] saving an actor', ['actor' => $actor->getId()]);

				$this->save($actor);
			} catch (Exception $e) {
				throw new InvalidResourceException($e->getMessage());
			}
		}

		return $actor;
	}

	/**
	 * @param string $search
	 * @param string $followedBy when set, only the accounts this actor follows
	 *
	 * @return Person[]
	 */
	public function searchCachedAccounts(string $search, ?int $limit = null, string $followedBy = ''): array {
		return $this->cacheActorsRequest->searchAccounts($search, $limit, $followedBy);
	}

	/**
	 * Refreshes the cached remote actors that are due.
	 *
	 * Without a deadline, one batch of `CacheActorsRequest::SYNC_BATCH`, or
	 * every due actor when forced — what `occ social:cache:refresh` asks for.
	 * With one, batch after batch until the deadline: fifty a pass, 120
	 * passes a day, is 6,000 refreshes a day, and a ten-day lifetime over
	 * more than 60,000 cached actors was then never met — every actor was
	 * overdue, and stale keys and avatars stayed stale for months.
	 *
	 * @param int $deadline when to stop taking actors, or 0 for one batch
	 *
	 * @return int how many were due, whether or not their refresh worked
	 * @throws Exception
	 */
	public function manageCacheRemoteActors(bool $force = false, int $deadline = 0): int {
		return $this->walkDue(
			fn (): array => $this->cacheActorsRequest->getRemoteActorsToUpdate($force, $this->now()),
			function (Person $item): void {
				$this->refreshRemoteActor($item);
			},
			$force ? 0 : $deadline
		);
	}

	/**
	 * Hands every actor the selection returns to `$each`, a batch at a time,
	 * until the deadline, an empty batch, a batch of nothing but actors this
	 * pass already handled (a stamp that could not be written), or
	 * `MAX_BATCHES`. No deadline is one batch.
	 *
	 * @param callable(): Person[] $select
	 * @param callable(Person): void $each
	 *
	 * @return int how many actors were handled
	 */
	private function walkDue(callable $select, callable $each, int $deadline): int {
		$done = 0;
		$seen = [];

		for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
			$fresh = 0;
			foreach ($select() as $item) {
				if (isset($seen[$item->getId()])) {
					continue;
				}
				$seen[$item->getId()] = true;
				$fresh++;

				$each($item);
				$done++;

				if ($deadline > 0 && time() >= $deadline) {
					return $done;
				}
			}

			if ($deadline <= 0 || $fresh === 0) {
				break;
			}
		}

		return $done;
	}

	/**
	 * Fetches one cached remote actor again and records that it was tried.
	 *
	 * The record is the point. A failed refresh used to leave the row exactly
	 * as it was, so it was due again on the next pass, and fifty rows on a
	 * dead instance were the fifty the refresh selected every twelve minutes
	 * for as long as the instance stayed dead — which for a dead instance is
	 * for ever. Stamped, the row waits `CacheActorsRequest::syncWait()` and
	 * after `SYNC_MAX_FAILURES` is left alone; the row itself, and everything
	 * pointing at it, stays.
	 *
	 * @return bool whether the actor could be fetched
	 */
	public function refreshRemoteActor(Person $actor): bool {
		try {
			$this->getFromId($actor->getId(), true);
		} catch (Exception $e) {
			$this->logger->info(
				'could not refresh cached actor ' . $actor->getId() . ': ' . $e->getMessage(),
				['actor' => $actor->getId(), 'exception' => $e]
			);
			$this->cacheActorsRequest->recordSyncAttempt($actor->getId(), false, $this->now());

			return false;
		}

		$this->cacheActorsRequest->recordSyncAttempt($actor->getId(), true, $this->now());

		return true;
	}

	/**
	 * Resolved when first needed rather than injected: FeaturedCollection needs
	 * StreamRequest, which needs StreamDestRequest, which needs this service —
	 * a cycle the container refuses while constructing and is fine with once
	 * everything exists. Null without a container, as in a unit test.
	 */
	private function featuredCollection(): ?FeaturedCollection {
		$service = $this->container?->get(FeaturedCollection::class);

		return ($service instanceof FeaturedCollection) ? $service : null;
	}

	private function profileLinkVerifier(): ?ProfileLinkVerifier {
		$service = $this->container?->get(ProfileLinkVerifier::class);

		return ($service instanceof ProfileLinkVerifier) ? $service : null;
	}

	/**
	 * Refreshes the counts, pinned posts and verified links of the cached
	 * remote actors whose details are due — batch after batch until the
	 * deadline, as `manageCacheRemoteActors()` does.
	 *
	 * @param int $deadline when to stop taking actors, or 0 for one batch
	 *
	 * @return int
	 * @throws Exception
	 */
	public function manageDetailsRemoteActors(bool $force = false, int $deadline = 0): int {
		// WARNING: risk of race condition if something else update details on remote actor.
		// Any details update on remote cache-actor must be managed from here.
		return $this->walkDue(
			fn (): array => $this->cacheActorsRequest->getRemoteActorsToUpdateDetails($force, $this->now()),
			function (Person $item): void {
				$this->refreshRemoteDetails($item);
			},
			$force ? 0 : $deadline
		);
	}

	private function refreshRemoteDetails(Person $item): void {
		try {
			$this->addRemoteActorDetailCount($item);
			// what else a profile shows that only a fetch can answer: the
			// posts the account has pinned, and whether its links link back
			$this->featuredCollection()?->refresh($item);
			$this->profileLinkVerifier()?->verify($item);
			$this->cacheActorsRequest->updateDetails($item);
		} catch (Exception $e) {
			// the same book the refresh keeps: `details_update` only moves
			// on success, so without this the fifty oldest were the fifty
			// unreachable, on every pass
			$this->logger->info(
				'could not refresh the details of ' . $item->getId() . ': ' . $e->getMessage(),
				['actor' => $item->getId(), 'exception' => $e]
			);
			$this->cacheActorsRequest->recordSyncAttempt($item->getId(), false, $this->now());
		}
	}

	public function addRemoteActorDetailCount(Person $actor): void {
		try {
			$followers = $this->getCollectionFromId($actor->getFollowers());
			$following = $this->getCollectionFromId($actor->getFollowing());
			$outbox = $this->getCollectionFromId($actor->getOutbox());
		} catch (InvalidResourceException $e) {
			return;
		}

		$count = [
			'followers' => $followers->getTotalItems(),
			'following' => $following->getTotalItems(),
			'post' => $outbox->getTotalItems()
		];
		$actor->setDetailArray(Details::COUNT, $count);
	}

	/**
	 * @param string $id
	 *
	 * @return OrderedCollection
	 * @throws InvalidResourceException
	 */
	private function getCollectionFromId(string $id): OrderedCollection {
		try {
			$object = $this->curlService->retrieveObject($id);
			/** @var OrderedCollection $collection */
			$collection = AP::instance()->getItemFromData($object);
		} catch (Exception $e) {
			throw new InvalidResourceException();
		}

		if ($collection->getType() !== OrderedCollection::TYPE) {
			throw new InvalidResourceException();
		}

		return $collection;
	}

	/**
	 * @param Person $actor
	 *
	 * @throws ItemAlreadyExistsException
	 */
	private function save(Person $actor) {
		try {
			$interface = AP::instance()->getInterfaceFromType($actor->getType());
			$interface->save($actor);
		} catch (ItemUnknownException $e) {
		}
	}

	/**
	 * @param Person $actor
	 *
	 * @return void
	 */
	private function delete(Person $actor): void {
		try {
			$interface = AP::instance()->getInterfaceFromType($actor->getType());
			$interface->delete($actor);
		} catch (ItemUnknownException $e) {
		}
	}

	/**
	 * @param ProbeOptions $options
	 *
	 * @return Person[]
	 */
	public function probeActors(ProbeOptions $options): array {
		return $this->cacheActorsRequest->probeActors($options);
	}

	public function getFromNids(array $ids): array {
		return $this->cacheActorsRequest->getFromNids($ids);
	}
}
