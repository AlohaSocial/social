<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ConfigService;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Refreshes the cached copy of every local actor once per VERSION, for a
 * change in what the cache carries. Bump VERSION when the cache must be
 * rebuilt again; rebuilding every actor on each upgrade would be costly in
 * maintenance mode.
 */
class CacheFeaturedCollections implements IRepairStep {
	/** Bump this when a change means local actor caches must be rebuilt again. */
	private const VERSION = 2;

	/** Above this many local actors, hand the job to the admin rather than to `occ upgrade`. */
	private const INLINE_LIMIT = 500;

	private const MARKER = 'migration_local_actor_cache';

	public function __construct(
		private ActorsRequest $actorsRequest,
		private AccountService $accountService,
		private ConfigService $configService,
		private IDBConnection $connection,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Refresh the cached copy of local actors';
	}

	#[\Override]
	public function run(IOutput $output): void {
		if ($this->configService->getAppValueInt(self::MARKER) >= self::VERSION) {
			return;
		}

		// Counted, not loaded: `getAll()` builds every Person and can hit the
		// memory limit.
		$total = $this->countLocalActors();
		if ($total > self::INLINE_LIMIT) {
			// Too long for the upgrade; `occ social:cache:refresh` does the same later.
			$output->warning(
				sprintf(
					'%d local actors need their cache refreshed; skipping it here to keep the '
					. 'upgrade short. Run "occ social:cache:refresh" to publish their display '
					. 'names and featured collections.',
					$total
				)
			);
			$this->configService->setAppValue(self::MARKER, (string)self::VERSION);

			return;
		}

		// Walked, not loaded: each refresh reads its row back anyway.
		$refreshed = 0;
		$output->startProgress($total);
		foreach ($this->actorsRequest->walk() as $actor) {
			try {
				$this->accountService->cacheLocalActorByUsername($actor->getPreferredUsername());
				$refreshed++;
			} catch (\Exception $e) {
				$output->warning(
					'could not refresh the actor cache of ' . $actor->getPreferredUsername()
					. ': ' . $e->getMessage()
				);
			}
			$output->advance();
		}
		$output->finishProgress();

		if ($refreshed > 0) {
			$output->info(sprintf('refreshed the cache of %d local actor(s)', $refreshed));
		}

		$this->configService->setAppValue(self::MARKER, (string)self::VERSION);
	}

	/**
	 * The number of rows `ActorsRequest::getAll()` returns, without building
	 * models.
	 */
	private function countLocalActors(): int {
		$qb = $this->connection->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))
			->from(CoreRequestBuilder::TABLE_ACTORS);

		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($row['total'] ?? 0);
	}
}
