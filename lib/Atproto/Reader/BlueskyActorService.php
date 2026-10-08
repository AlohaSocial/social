<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Moderation\Blocklist;
use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\AppViewNotFoundException;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Details;
use OCA\Social\Service\ActorService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Bluesky accounts as cached actors: resolved through the public AppView by
 * handle or DID on first need, refreshed the way any remote actor is, and
 * read from the cache like any other the rest of the time.
 */
class BlueskyActorService {
	public function __construct(
		private AtprotoConfig $config,
		private AppViewClient $appView,
		private PlcClient $plc,
		private ActorMapper $mapper,
		private CacheActorsRequest $cacheActorsRequest,
		private ActorService $actorService,
		private Blocklist $blocklist,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The cached actor of a Bluesky handle or DID, fetched from the AppView
	 * when it is not here yet or a refresh is asked for.
	 *
	 * @throws CacheActorDoesNotExistException when Bluesky is off or the AppView does not know the account
	 * @throws AtprotoException when the AppView cannot be reached
	 */
	public function resolve(string $handleOrDid, bool $refresh = false): Person {
		if (!$this->config->isEnabled()) {
			throw new CacheActorDoesNotExistException();
		}
		$handleOrDid = strtolower(trim($handleOrDid));
		if (Syntax::isDid($handleOrDid) && $this->blocklist->isBlockedDid($handleOrDid)) {
			throw new CacheActorDoesNotExistException('blocked here: ' . $handleOrDid);
		}
		if (!$refresh) {
			$cached = $this->cached($handleOrDid);
			if ($cached !== null) {
				if ($this->blocklist->isBlockedActor($cached)) {
					throw new CacheActorDoesNotExistException('blocked here: ' . $handleOrDid);
				}

				return $cached;
			}
		}
		try {
			$profile = $this->appView->query('app.bsky.actor.getProfile', ['actor' => $handleOrDid]);
		} catch (AppViewNotFoundException $e) {
			throw new CacheActorDoesNotExistException($e->getMessage());
		}
		$did = (string)($profile['did'] ?? '');
		if (!Syntax::isDid($did)) {
			throw new CacheActorDoesNotExistException('the AppView answered no DID for ' . $handleOrDid);
		}
		$pds = $this->pdsOf($did);
		if ($this->blocklist->isBlockedAccount($did, $pds)) {
			throw new CacheActorDoesNotExistException('blocked here: ' . $did);
		}
		$person = $this->mapper->person($profile, $pds);
		$this->store($person);

		return $person;
	}

	/**
	 * Only what is in the cache: the actor by handle or DID, or null.
	 */
	public function cached(string $handleOrDid): ?Person {
		try {
			if (Syntax::isDid($handleOrDid)) {
				return $this->cacheActorsRequest->getFromId(BlueskyIds::actorId($handleOrDid));
			}

			return $this->cacheActorsRequest->getFromAccount($handleOrDid);
		} catch (CacheActorDoesNotExistException) {
			return null;
		}
	}

	/**
	 * Whether a cached actor is a Bluesky account.
	 */
	public static function isBluesky(Person $actor): bool {
		return BlueskyIds::isActorId($actor->getId());
	}

	/**
	 * Whether the account's labels keep its posts out: `!hide` or a takedown.
	 */
	public static function isLimited(Person $actor): bool {
		return (bool)($actor->getDetails(ActorMapper::DETAIL)['limited'] ?? false);
	}

	private function pdsOf(string $did): string {
		if (!str_starts_with($did, 'did:plc:')) {
			return '';
		}
		try {
			$document = $this->plc->document($did);
		} catch (Throwable $e) {
			$this->logger->notice('DID document not read', ['did' => $did, 'exception' => $e]);

			return '';
		}
		foreach (($document['service'] ?? []) as $service) {
			if (is_array($service) && ($service['id'] ?? '') === '#atproto_pds') {
				return (string)($service['serviceEndpoint'] ?? '');
			}
		}

		return '';
	}

	/**
	 * Keeps a mapped account: saved when new, updated when known, counts set.
	 */
	public function store(Person $person): void {
		try {
			$this->cacheActorsRequest->getFromId($person->getId());
			$this->actorService->update($person);
		} catch (CacheActorDoesNotExistException) {
			$this->actorService->save($person);
		}
		$this->cacheActorsRequest->setCounts($person->getId(), $person->getDetails(Details::COUNT));
	}
}
