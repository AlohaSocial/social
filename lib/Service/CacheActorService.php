<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\AP;
use OCA\Social\AppInfo\Application;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\AtprotoIdentityRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
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
	 * Actors already resolved during this request, as a page asks for the same
	 * author several times. Not a cache: the same answer inside one response.
	 * Held to MEMO_LIMIT entries, least recently used first out, because
	 * cron.php and `social:worker` are one long "request".
	 *
	 * @var array<string, Person>
	 */
	private array $memo = [];

	/** The most actors `$memo` holds; far more than one page of posts names. */
	public const MEMO_LIMIT = 500;

	/** The `DurableCache` namespace of URLs that serve an actor under another id of their host. */
	public const ALIAS_NAMESPACE = 'social.actoralias';
	public const ALIAS_TTL = 2592000; // 30 days

	/**
	 * Where an actor id that could not be fetched is remembered, and for how
	 * long: a post whose author is gone is liked, boosted and replied to, and
	 * each of those asked the author's server again inside the request.
	 */
	public const MISS_NAMESPACE = Application::APP_ID . '/actor-miss';
	public const MISS_SECONDS = 600;

	use TArrayTools;

	public function __construct(
		private ActorsRequest $actorsRequest,
		private CacheActorsRequest $cacheActorsRequest,
		private CurlService $curlService,
		private ConfigService $configService,
		private LoggerInterface $logger,
		private ?ContainerInterface $container = null,
		private ?ITimeFactory $timeFactory = null,
		private ?BlueskyActorService $blueskyActors = null,
		private ?AtprotoIdentityRequest $atprotoIdentities = null,
		private ?AtprotoConfig $atprotoConfig = null,
		private ?DurableCache $durableCache = null,
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
		return $this->getFromIdWithinTimeout($id, $refresh, null);
	}

	/** Resolve a Fediverse actor with a maximum time for remote document fetches. */
	public function getActivityPubActorWithTimeout(string $id, int $timeout): Person {
		if (BlueskyIds::isActorId($id)) {
			throw new \InvalidArgumentException('A Bluesky actor cannot be resolved through ActivityPub');
		}

		return $this->getFromIdWithinTimeout($id, false, max(1, $timeout));
	}

	private function getFromIdWithinTimeout(string $id, bool $refresh, ?int $timeout): Person {
		$deadline = $timeout === null ? null : hrtime(true) + (max(1, $timeout) * 1_000_000_000);
		$posAnchor = strpos($id, '#');
		if ($posAnchor !== false) {
			$id = substr($id, 0, $posAnchor);
		}

		// memoised per request (see $memo): a mention, a boost, a quoted post
		// and a tagged person each ask for the same author again by id
		if (!$refresh && array_key_exists($id, $this->memo)) {
			$actor = $this->memo[$id];
			// moved to the end, so the eviction below takes the stalest
			unset($this->memo[$id]);
			$this->memo[$id] = $actor;

			return $actor;
		}
		if (BlueskyIds::isActorId($id)) {
			$did = BlueskyIds::didOf($id);
			// as below: an account the AppView did not give a moment ago is
			// not asked for again, unless it has been stored since
			if (!$refresh && $this->durableCache?->get(self::MISS_NAMESPACE, md5($id)) !== null
				&& $this->blueskyActors?->cached($did) === null) {
				throw new CacheActorDoesNotExistException('not fetched again yet: ' . $id);
			}

			try {
				$actor = $this->blueskyActor($did, $refresh);
			} catch (Exception $e) {
				$this->durableCache?->set(self::MISS_NAMESPACE, md5($id), 1, self::MISS_SECONDS);

				throw $e;
			}
			$this->memo[$id] = $actor;

			return $actor;
		}

		try {
			if ($refresh) {
				throw new CacheActorDoesNotExistException();
			}

			$actor = $this->cached($id);
		} catch (CacheActorDoesNotExistException $e) {
			// a fetch of this id failed a moment ago: asked again, it is the
			// same connect attempt to the same server, inside somebody's request
			if (!$refresh && $this->durableCache?->get(self::MISS_NAMESPACE, md5($id)) !== null) {
				throw new CacheActorDoesNotExistException('not fetched again yet: ' . $id);
			}

			try {
				$actor = $this->fetchActor($id, $refresh, $deadline);
			} catch (Exception $e) {
				$this->durableCache?->set(self::MISS_NAMESPACE, md5($id), 1, self::MISS_SECONDS);

				throw $e;
			}
		}

		unset($this->memo[$id]);
		$this->memo[$id] = $actor;
		if (count($this->memo) > self::MEMO_LIMIT) {
			unset($this->memo[array_key_first($this->memo)]);
		}

		return $actor;
	}

	/**
	 * Fetches an actor this instance does not hold, checks it is the actor at
	 * that id, and stores it.
	 */
	private function fetchActor(string $id, bool $refresh, ?int $deadline = null): Person {
		try {
			$timeout = $this->remainingFetchTimeout($deadline);
			$object = $timeout === null
				? $this->curlService->retrieveObject($id)
				: $this->curlService->retrieveObject($id, true, $timeout);
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

		$actor = $this->theActorOf($id, $actor, $this->get('_contentType', $object), $deadline);

		$actor->setAccount($actor->getPreferredUsername() . '@' . $this->get('_host', $object));
		try {
			$this->save($actor);
		} catch (Exception $e) {
			throw new InvalidResourceException($e->getMessage());
		}

		return $actor;
	}

	/**
	 * The actor a key belongs to, for a keyId that is not the actor's id with
	 * the key in its fragment, such as GoToSocial's `…/users/alice/main-key`
	 * stub or a bare key document. The stub only says where to look, so the
	 * owner must be on the keyId's host, its document must be served at that
	 * id, and it must list this exact keyId among its keys (`publicKey`,
	 * `assertionMethod`, `verificationMethod`).
	 *
	 * @throws InvalidOriginException the key names no owner, or one that does not claim it
	 * @throws Exception anything `getFromId()` or the fetch raises
	 */
	public function getFromKeyId(string $keyId, bool $refresh = false): Person {
		$document = $this->activityPubDocument($keyId);

		$key = is_array($document['publicKey'] ?? null) && !array_is_list($document['publicKey']) ? $document['publicKey'] : $document;
		$owner = '';
		if (($key['id'] ?? '') === $keyId) {
			$owner = self::ownerOfKey($key);
		}
		// a keyId that is the actor's own id, fragment and all left off
		if ($owner === '' && ($document['id'] ?? '') === $keyId && !isset($document['owner']) && !isset($document['controller'])) {
			$owner = $keyId;
		}

		$keyHost = strtolower((string)parse_url($keyId, PHP_URL_HOST));
		if ($owner === '' || $keyHost === '' || strtolower((string)parse_url($owner, PHP_URL_HOST)) !== $keyHost) {
			throw new InvalidOriginException(
				'CacheActorService::getFromKeyId - keyId: ' . $keyId . ' - owner: ' . $owner
			);
		}

		if ($owner !== $keyId) {
			$actorDocument = $this->activityPubDocument($owner);
			if (($actorDocument['id'] ?? '') !== $owner) {
				throw new InvalidOriginException(
					'CacheActorService::getFromKeyId - keyId: ' . $keyId . ' - owner: ' . $owner
					. ' - serves: ' . (is_string($actorDocument['id'] ?? null) ? $actorDocument['id'] : '')
				);
			}
			if (!in_array($keyId, self::keyIdsOf($actorDocument, $owner), true)) {
				throw new InvalidOriginException(
					'CacheActorService::getFromKeyId - keyId: ' . $keyId . ' - not a key of ' . $owner
				);
			}
		}

		return $this->getFromId($owner, $refresh);
	}

	/**
	 * A document fetched as ActivityPub, or a refusal when it was not served
	 * as one.
	 *
	 * @return array<string, mixed>
	 * @throws InvalidOriginException
	 * @throws Exception anything the fetch raises
	 */
	private function activityPubDocument(string $id): array {
		$document = $this->curlService->retrieveObject($id);
		if (!CurlService::isActivityPubMediaType($this->get('_contentType', $document))) {
			throw new InvalidOriginException(
				'CacheActorService::getFromKeyId - id: ' . $id . ' - contentType: ' . $this->get('_contentType', $document)
			);
		}

		return $document;
	}

	/** Whom a key document says it belongs to: `owner`, or FEP-521a's `controller`. */
	private static function ownerOfKey(array $key): string {
		foreach (['owner', 'controller'] as $field) {
			if (is_string($key[$field] ?? null) && $key[$field] !== '') {
				return $key[$field];
			}
		}

		return '';
	}

	/**
	 * The ids of the keys an actor document lists as its own — one `publicKey`
	 * or several, and the FEP-521a `assertionMethod` and `verificationMethod`
	 * entries — leaving out any entry that names another owner.
	 *
	 * @param array<string, mixed> $actor
	 * @return list<string>
	 */
	private static function keyIdsOf(array $actor, string $owner): array {
		$ids = [];
		foreach (['publicKey', 'assertionMethod', 'verificationMethod'] as $field) {
			$entries = $actor[$field] ?? [];
			if (!is_array($entries) || !array_is_list($entries)) {
				$entries = [$entries];
			}
			foreach ($entries as $entry) {
				if (is_string($entry)) {
					$ids[] = $entry;
					continue;
				}
				if (!is_array($entry) || !is_string($entry['id'] ?? null)) {
					continue;
				}
				$claimed = self::ownerOfKey($entry);
				if ($claimed === '' || $claimed === $owner) {
					$ids[] = $entry['id'];
				}
			}
		}

		return $ids;
	}

	/**
	 * The cached copy of an actor: by its id, or by a URL remembered as
	 * another address of it (see `theActorOf()`).
	 *
	 * @throws CacheActorDoesNotExistException
	 */
	private function cached(string $id): Person {
		try {
			return $this->cacheActorsRequest->getFromId($id);
		} catch (CacheActorDoesNotExistException $e) {
			$actorId = $this->durableCache?->get(self::ALIAS_NAMESPACE, $id);
			if (!is_string($actorId) || $actorId === '') {
				throw $e;
			}

			return $this->cacheActorsRequest->getFromId($actorId);
		}
	}

	/**
	 * The actor a document fetched from `$id` stands for, or a refusal. The
	 * row is keyed by the actor's own `id` and holds its public key, so the
	 * document must be served as ActivityPub at the id it claims.
	 *
	 * A document whose `id` is another URL of the same host (Mastodon 4.5
	 * answers `/users/<name>` with an actor whose id is `/ap/users/<number>`)
	 * is fetched again from that id, and the URL asked is remembered as
	 * another address of the actor. An id on another host is refused.
	 *
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException the claimed id serves no actor
	 * @throws Exception anything the fetch raises
	 */
	private function theActorOf(string $id, Person $actor, string $contentType, ?int $deadline = null): Person {
		if (!CurlService::isActivityPubMediaType($contentType)) {
			throw new InvalidOriginException(
				'CacheActorService::getFromId - id: ' . $id . ' - contentType: ' . $contentType
			);
		}

		$actorId = $actor->getId();
		if (strtolower($actorId) === strtolower($id)) {
			return $actor;
		}

		$host = strtolower((string)parse_url($id, PHP_URL_HOST));
		if ($host === '' || strtolower((string)parse_url($actorId, PHP_URL_HOST)) !== $host) {
			throw new InvalidOriginException(
				'CacheActorService::getFromId - id: ' . $id . ' - actorId: ' . $actorId
			);
		}

		$timeout = $this->remainingFetchTimeout($deadline);
		$object = $timeout === null
			? $this->curlService->retrieveObject($actorId)
			: $this->curlService->retrieveObject($actorId, true, $timeout);
		/** @var Person $served */
		$served = AP::instance()->getItemFromData($object);
		if (!AP::instance()->isActor($served)) {
			throw new InvalidResourceException();
		}
		if (!CurlService::isActivityPubMediaType($this->get('_contentType', $object))
			|| strtolower($served->getId()) !== strtolower($actorId)) {
			throw new InvalidOriginException(
				'CacheActorService::getFromId - id: ' . $id . ' - actorId: ' . $actorId
				. ' - serves: ' . $served->getId()
			);
		}

		$this->durableCache?->set(self::ALIAS_NAMESPACE, $id, $actorId, self::ALIAS_TTL);

		return $served;
	}

	/** Return the remaining whole seconds for a bounded inline actor fetch. */
	private function remainingFetchTimeout(?int $deadline): ?int {
		if ($deadline === null) {
			return null;
		}

		$remaining = (int)floor(($deadline - hrtime(true)) / 1_000_000_000);
		if ($remaining < 1) {
			throw new \RuntimeException('Actor fetch exceeded its visible request budget');
		}

		return $remaining;
	}

	/**
	 * Forgets the memoised actors, for the long-running processes
	 * (`social:worker`, `Cron\Queue`, `Cron\Cache`) between batches, so a key
	 * rotation or profile change is seen.
	 */
	public function forgetMemoised(): void {
		$this->memo = [];
	}

	/**
	 * Forgets one memoised actor, once its cached copy was written: the next
	 * read in the same request is the copy as it is now.
	 */
	public function forget(string $id): void {
		unset($this->memo[$id]);
	}

	/**
	 * What is already known about a set of actors, in one query and without
	 * the network: a listing must not make a federated request per row.
	 *
	 * @param string[] $ids
	 *
	 * @return Person[] keyed by actor id; ids that are not cached are absent
	 */
	/**
	 * The actor at `$id` as this instance holds it — its row, or the row an
	 * address remembered as another of the actor's names points at — and
	 * never a fetch.
	 *
	 * @throws CacheActorDoesNotExistException
	 */
	public function getCachedFromId(string $id): Person {
		$anchor = strpos($id, '#');

		return $this->cached(($anchor === false) ? $id : substr($id, 0, $anchor));
	}

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
	 * `name@host` (or a bare local name), or its actor URL. Nothing is
	 * fetched from elsewhere unless `$mayFetch` says so, as a signed-in viewer
	 * naming a stranger does.
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
		if (BlueskyIds::isHandle($account)) {
			return $this->blueskyAccount(strtolower($account), $retrieve);
		}
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

			// as in getFromId(): a handle whose lookup failed a moment ago is
			// the same host-meta and WebFinger round, up to four requests to a
			// host that did not answer, inside somebody's request
			$miss = md5('acct:' . $account);
			if ($this->durableCache?->get(self::MISS_NAMESPACE, $miss) !== null) {
				throw new CacheActorDoesNotExistException('not fetched again yet: ' . $account);
			}

			try {
				$actor = $this->curlService->retrieveAccount($account);
				$actor->setAccount($account);
				try {
					$this->logger->debug('[CacheActorService] saving an actor', ['actor' => $actor->getId()]);

					$this->save($actor);
				} catch (Exception $e) {
					throw new InvalidResourceException($e->getMessage());
				}
			} catch (Exception $e) {
				$this->durableCache?->set(self::MISS_NAMESPACE, $miss, 1, self::MISS_SECONDS);

				throw $e;
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
		// a Bluesky account its labels limit, or one Bluesky switched off,
		// is kept and not offered
		return array_values(array_filter(
			$this->cacheActorsRequest->searchAccounts($search, $limit, $followedBy),
			static fn (Person $actor): bool => !BlueskyActorService::isLimited($actor)
		));
	}

	/**
	 * Refreshes the cached remote actors that are due: without a deadline one
	 * batch of `CacheActorsRequest::SYNC_BATCH`, or every due actor when forced
	 * (`occ social:cache:refresh`); with one, batch after batch until the
	 * deadline, as one batch a pass cannot keep up with a large cache.
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
	 * Fetches one cached remote actor again and records that it was tried, so
	 * a failing row waits `CacheActorsRequest::syncWait()` and is left alone
	 * after `SYNC_MAX_FAILURES` instead of being selected every pass; the row
	 * itself stays.
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
	 * Resolved when first needed, not injected: FeaturedCollection needs
	 * StreamRequest, which needs StreamDestRequest, which needs this service.
	 * Null without a container, as in a unit test.
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
		if (BlueskyIds::isActorId($item->getId())) {
			$this->refreshBlueskyDetails($item);

			return;
		}
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
		if (BlueskyIds::isActorId($actor->getId())) {
			// a Bluesky account's counts come with its profile; it has no collections
			return;
		}
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
	 * A Bluesky account by DID: from the cache, or resolved through the
	 * AppView when it is not here yet or a refresh is asked for.
	 *
	 * @throws CacheActorDoesNotExistException
	 * @throws AtprotoException
	 */
	private function blueskyActor(string $did, bool $refresh): Person {
		if ($this->blueskyActors === null) {
			throw new CacheActorDoesNotExistException();
		}

		return $this->blueskyActors->resolve($did, $refresh);
	}

	/**
	 * A handle with no `@`: one of this instance's own Bluesky handles leads
	 * to the local account that owns it, any other is a Bluesky account.
	 *
	 * @throws CacheActorDoesNotExistException
	 * @throws AtprotoException
	 */
	private function blueskyAccount(string $handle, bool $retrieve): Person {
		if ($this->atprotoIdentities !== null && $this->atprotoConfig !== null
			&& str_ends_with($handle, '.' . $this->atprotoConfig->handleHost())) {
			try {
				return $this->getFromId($this->atprotoIdentities->getByHandle($handle)->actorId);
			} catch (AtprotoIdentityNotFoundException) {
				throw new CacheActorDoesNotExistException();
			}
		}
		if ($this->blueskyActors === null) {
			throw new CacheActorDoesNotExistException();
		}
		if (!$retrieve) {
			$cached = $this->blueskyActors->cached($handle);
			if ($cached === null) {
				throw new CacheActorDoesNotExistException();
			}

			return $cached;
		}

		// as in getFromId(): a handle the AppView did not give a moment ago is
		// not asked for again, unless it has been stored since
		$miss = md5('acct:' . $handle);
		if ($this->durableCache?->get(self::MISS_NAMESPACE, $miss) !== null
			&& $this->blueskyActors->cached($handle) === null) {
			throw new CacheActorDoesNotExistException('not fetched again yet: ' . $handle);
		}

		try {
			return $this->blueskyActors->resolve($handle);
		} catch (Exception $e) {
			$this->durableCache?->set(self::MISS_NAMESPACE, $miss, 1, self::MISS_SECONDS);

			throw $e;
		}
	}

	/**
	 * The counts and labels of a Bluesky account come with its profile; a
	 * refresh re-reads it, and nothing is fetched from collections it has none of.
	 */
	private function refreshBlueskyDetails(Person $item): void {
		try {
			$this->blueskyActor(BlueskyIds::didOf($item->getId()), true);
			$this->cacheActorsRequest->recordSyncAttempt($item->getId(), true, $this->now());
		} catch (Exception $e) {
			$this->logger->info('could not refresh the Bluesky account ' . $item->getId() . ': ' . $e->getMessage(), ['actor' => $item->getId(), 'exception' => $e]);
			$this->cacheActorsRequest->recordSyncAttempt($item->getId(), false, $this->now());
		}
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
