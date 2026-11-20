<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use Psr\Log\LoggerInterface;

/**
 * Removes what a blocked instance already sent: its cached accounts, posts,
 * follows in both directions and notifications, as Mastodon's domain block
 * does. The block alone only stops new requests.
 *
 * Bounded: one call purges at most `$limit` accounts using the batched
 * per-account deletes a suspension uses, never one table-wide statement.
 * Idempotent: work is found by what is still stored, so a dead purge is
 * resumed by running it again. Exactly the domain named, not subdomains,
 * though `FediverseService::isListed()` blocks those too. Unblocking restores
 * nothing.
 */
class DomainPurgeService {
	/**
	 * Accounts detached per `purgeStep()`. Small, as each costs several
	 * batched deletes, so a run can stop between steps.
	 */
	public const BATCH = 50;

	/**
	 * How many relationships of one account a severed-relationships count
	 * looks at. A local account that follows more than this on one instance
	 * loses more than the notification says, and a number is what it is for.
	 */
	private const SEVERED_MAX = 500;

	public function __construct(
		private CacheActorsRequest $cacheActorsRequest,
		private StreamRequest $streamRequest,
		private FollowsRequest $followsRequest,
		private ModerationService $moderationService,
		private ConfigService $configService,
		private NotificationService $notificationService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Purges a domain completely.
	 *
	 * @param int $maxSteps how many batches to run before giving up on making
	 *                      progress, or 0 for no ceiling. A ceiling is what a
	 *                      cron run wants; an `occ` run wants to finish.
	 *
	 * @return int how many accounts were detached
	 *
	 * @throws InvalidResourceException the domain is not one, or is this
	 *                                  instance's own
	 */
	public function purge(string $domain, int $maxSteps = 0): int {
		$domain = $this->assertRemoteDomain($domain);

		$purged = 0;
		$previous = null;
		for ($step = 0; ($maxSteps === 0) || ($step < $maxSteps); $step++) {
			$batch = $this->remaining($domain, self::BATCH);
			if ($batch === []) {
				break;
			}

			if ($batch === $previous) {
				// the same accounts came back, so the last pass deleted nothing.
				// Detaches swallow per-table failures, so without this a
				// database refusing the delete would loop for ever.
				$this->logger->warning('a domain purge stopped making progress', [
					'domain' => $domain, 'accounts' => $batch,
				]);
				break;
			}

			$this->detach($batch);
			$previous = $batch;
			$purged += count($batch);
		}

		if ($purged > 0) {
			$this->logger->info('purged accounts of a blocked instance', [
				'domain' => $domain, 'accounts' => $purged,
			]);
		}

		return $purged;
	}

	/**
	 * One batch.
	 *
	 * @return int accounts detached; 0 means there is nothing of the domain
	 *             left here, which is how every caller knows to stop
	 *
	 * @throws InvalidResourceException
	 */
	public function purgeStep(string $domain): int {
		$domain = $this->assertRemoteDomain($domain);

		$actorIds = $this->remaining($domain, self::BATCH);
		$this->detach($actorIds);

		return count($actorIds);
	}

	/**
	 * @param string[] $actorIds
	 */
	private function detach(array $actorIds): void {
		foreach ($actorIds as $actorId) {
			// counted before the purge, because the purge is what removes them:
			// afterwards there is nothing left to count and nobody to tell
			$severed = $this->localRelationshipsWith($actorId);
			$this->moderationService->purgeActor($actorId);
			$this->tellThemTheirFollowsWereCut($severed, $actorId);
		}
	}

	/**
	 * The local accounts that follow, or are followed by, an account about to
	 * be purged, and how many relationships each of them loses.
	 *
	 * @return array<string, int> local actor id => how many
	 */
	private function localRelationshipsWith(string $actorId): array {
		$counts = [];

		foreach ([
			$this->followsRequest->getFollowersByActorId($actorId, self::SEVERED_MAX),
			$this->followsRequest->getFollowingByActorId($actorId, self::SEVERED_MAX),
		] as $follows) {
			foreach ($follows as $follow) {
				foreach ([$follow->getActorId(), $follow->getObjectId()] as $side) {
					if ($side !== $actorId && $this->isLocal($side)) {
						$counts[$side] = ($counts[$side] ?? 0) + 1;
					}
				}
			}
		}

		return $counts;
	}

	/**
	 * Tells the local accounts a block has cut off, as Mastodon's
	 * `severed_relationships` notification.
	 *
	 * @param array<string, int> $severed
	 */
	private function tellThemTheirFollowsWereCut(array $severed, string $actorId): void {
		$domain = strtolower((string)parse_url($actorId, PHP_URL_HOST));
		if ($domain === '') {
			return;
		}

		foreach ($severed as $local => $lost) {
			try {
				$this->notificationService->onRelationshipsSevered($local, $domain, $lost);
			} catch (\Exception $e) {
				// the purge is the point and has already happened; a
				// notification that could not be written must not stop it
				$this->logger->warning('could not tell an account its follows were cut', [
					'actor' => $local, 'exception' => $e,
				]);
			}
		}
	}

	/** Whether an actor id is one this instance serves. */
	private function isLocal(string $actorId): bool {
		return str_starts_with($actorId, $this->configService->getSocialUrl());
	}

	/**
	 * Whether anything of the domain is still stored here.
	 *
	 * @throws InvalidResourceException
	 */
	public function hasRemains(string $domain): bool {
		return $this->remaining($this->assertRemoteDomain($domain), 1) !== [];
	}

	/**
	 * The accounts of the domain that still have something here: a cached
	 * actor, a post or a follow row, as posts and follows can outlive the
	 * cached actor. The purge ends only when all three are empty.
	 *
	 * @return string[] actor ids
	 *
	 * @throws InvalidResourceException
	 */
	private function remaining(string $domain, int $limit): array {
		$found = [];
		foreach ([
			fn (): array => $this->cacheActorsRequest->getIdsFromDomain($domain, $limit),
			fn (): array => $this->streamRequest->getAuthorsFromDomain($domain, $limit),
			fn (): array => $this->followsRequest->getActorIdsFromDomain($domain, $limit),
		] as $source) {
			foreach ($source() as $actorId) {
				$found[$actorId] = true;
				if (count($found) >= $limit) {
					return array_keys($found);
				}
			}
		}

		return array_keys($found);
	}

	/**
	 * @throws InvalidResourceException purging this instance's own domain
	 *                                  would delete every local account's
	 *                                  posts, follows and notifications, and
	 *                                  no block could ever have asked for that
	 */
	private function assertRemoteDomain(string $domain): string {
		$domain = DomainBlockService::normalise($domain);

		$local = array_filter([
			strtolower($this->configService->getSocialAddress()),
			strtolower($this->configService->getCloudHost()),
		]);
		if (in_array($domain, $local, true)) {
			throw new InvalidResourceException('cannot purge this instance');
		}

		return $domain;
	}
}
