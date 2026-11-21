<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\CacheActorsRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Evicts the cached remote actors nothing here refers to any more: nobody
 * here follows them or is followed by them, no post of theirs is stored, no
 * follow request, block, mute or endorsement names them, and they have been
 * neither seen nor tried for `cache_actor_days` (default 180; 0 disables).
 * Swept actors are fetched again when needed; local actors are never touched.
 *
 * Likes and boosts of local posts do not keep an actor, as with Mastodon's
 * `tootctl accounts prune`: the `social_action` row stays. Paged like
 * `StreamPruneService`, bounded per cron pass.
 */
class CacheActorSweepService {
	public const PAGE = 100;

	public function __construct(
		private ConfigService $configService,
		private CacheActorsRequest $cacheActorsRequest,
		private MediaPurgeService $mediaPurgeService,
		private LoggerInterface $logger,
		private ?ITimeFactory $timeFactory = null,
	) {
	}

	public function getSweepDays(): int {
		return max(0, $this->configService->getAppValueInt(ConfigService::SOCIAL_CACHE_ACTOR_DAYS));
	}

	/**
	 * @param int|null $days the age past which an unreferenced actor goes; the
	 *                       `cache_actor_days` setting when null
	 * @param bool $dryRun count what would go and remove nothing
	 * @param int $max stop after this many actors; 0 for all of them
	 *
	 * @return array{actors: int, documents: int} what was (or would be) removed
	 */
	public function sweep(?int $days = null, bool $dryRun = false, int $max = 0): array {
		$days ??= $this->getSweepDays();
		if ($days <= 0) {
			return ['actors' => 0, 'documents' => 0];
		}

		$cutoff = ($this->timeFactory?->getTime() ?? time()) - $days * 86400;

		if ($dryRun) {
			// one read, because the query answers "the next ones to go" and has
			// no cursor: with nothing deleted a second read returns the same
			// rows. So a dry run counts the ceiling it was given, or a page
			$ids = $this->cacheActorsRequest->getSweepableIds($cutoff, $max > 0 ? $max : self::PAGE);

			return ['actors' => count($ids), 'documents' => 0];
		}

		$actors = 0;
		$documents = 0;
		while (true) {
			$limit = self::PAGE;
			if ($max > 0) {
				$limit = min($limit, $max - $actors);
				if ($limit <= 0) {
					break;
				}
			}

			$ids = $this->cacheActorsRequest->getSweepableIds($cutoff, $limit);
			if ($ids === []) {
				break;
			}

			foreach ($ids as $id) {
				$documents += $this->evict($id);
				$actors++;
			}
		}

		if ($actors > 0) {
			$this->logger->info('swept cached remote actors nobody here refers to', [
				'days' => $days, 'actors' => $actors, 'documents' => $documents,
			]);
		}

		return ['actors' => $actors, 'documents' => $documents];
	}

	/**
	 * One actor and what hangs off it: the files first, because the rows are
	 * the only thing that remembers the files' names.
	 *
	 * @return int the document rows removed with it
	 */
	private function evict(string $id): int {
		$documents = $this->mediaPurgeService->purgeByParent($id);
		$this->cacheActorsRequest->deleteCacheById($id);

		return $documents;
	}
}
