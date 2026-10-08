<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Moderation;

use InvalidArgumentException;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Db\AtprotoBlocklistRequest;
use OCA\Social\Db\AtprotoWatchRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\ModerationService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Changes the Bluesky block list, for the admin page and `occ`: a block
 * purges the blocked accounts that are followed here the way a domain
 * block purges a Fediverse account — their posts, the follows, the watch.
 */
class BlocklistManager {
	public function __construct(
		private Blocklist $blocklist,
		private AtprotoBlocklistRequest $request,
		private AtprotoWatchRequest $watches,
		private CacheActorsRequest $cacheActors,
		private ModerationService $moderation,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return int how many accounts were purged
	 * @throws InvalidArgumentException when the value is neither a DID nor a host
	 */
	public function block(string $value, string $reason = ''): int {
		[$kind, $value] = Blocklist::classify($value);
		$this->request->add($kind, $value, mb_substr(trim($reason), 0, 500));
		$this->blocklist->reset();
		$purged = 0;
		$seen = false;
		foreach ($this->watches->getAll() as $watch) {
			if ($kind === AtprotoBlocklistRequest::KIND_DID) {
				$hit = $watch->did === $value;
				$seen = $seen || $hit;
			} else {
				$actor = $this->cached($watch->did);
				$hit = $actor !== null && $this->blocklist->isBlockedActor($actor);
			}
			if ($hit) {
				$purged += $this->purge($watch->did) ? 1 : 0;
			}
		}
		if ($kind === AtprotoBlocklistRequest::KIND_DID && !$seen && $this->cached($value) !== null) {
			$purged += $this->purge($value) ? 1 : 0;
		}

		return $purged;
	}

	/**
	 * @return bool whether it was on the list
	 * @throws InvalidArgumentException
	 */
	public function unblock(string $value): bool {
		[$kind, $value] = Blocklist::classify($value);
		$removed = $this->request->remove($kind, $value);
		$this->blocklist->reset();

		return $removed;
	}

	private function purge(string $did): bool {
		try {
			$this->watches->remove($did);
			$this->moderation->purgeActor(BlueskyIds::actorId($did));
		} catch (Throwable $e) {
			$this->logger->warning('Blocked Bluesky account not purged', ['did' => $did, 'exception' => $e]);

			return false;
		}

		return true;
	}

	private function cached(string $did): ?Person {
		try {
			return $this->cacheActors->getFromId(BlueskyIds::actorId($did));
		} catch (CacheActorDoesNotExistException) {
			return null;
		}
	}
}
