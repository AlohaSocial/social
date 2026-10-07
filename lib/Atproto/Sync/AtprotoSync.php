<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Sync;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\NativeFeedService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/** Bounded polling imports into the ordinary Social timeline, using the newest page each time. */
class AtprotoSync {
	public const DEFAULT_BATCH = 50;
	public const DEFAULT_CEILING = 200;
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly IConfig $config,
		private readonly IdentityService $identities,
		private readonly NativeFeedService $feed,
	) {
	}
	public function run(int $batch = self::DEFAULT_BATCH, bool $dryRun = false): array {
		$stats = ['authors_checked' => 0, 'new_posts' => 0, 'updated_posts' => 0, 'errors' => 0, 'duration_ms' => 0];
		$start = microtime(true);
		if (!$this->identities->isEnabled()) {
			return $stats;
		}
		$ceiling = max(1, min(200, (int)$this->config->getAppValue('social', 'atproto_sync_ceiling', '200')));
		$batch = max(1, min($batch, $ceiling));
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('social_atpds_watch')->where($qb->expr()->lte('next_sync', $qb->createNamedParameter(gmdate('Y-m-d H:i:s'))))->orderBy('next_sync', 'ASC')->setMaxResults($batch);
		foreach ($qb->executeQuery()->fetchAllAssociative() as $watch) {
			$stats['authors_checked']++;
			if ($dryRun) {
				continue;
			}
			$failures = 0;
			$error = '';
			try {
				$stats['new_posts'] += $this->feed->syncAuthor($watch['did']);
			} catch (\Throwable $e) {
				$failures = (int)$watch['failures'] + 1;
				$stats['errors']++;
				$error = 'AppView sync failed';
				$this->logger->warning($error, ['did' => $watch['did'], 'exception' => $e]);
			}
			$qb = $this->db->getQueryBuilder();
			$qb->update('social_atpds_watch')->set('last_sync', $qb->createNamedParameter(gmdate('Y-m-d H:i:s')))
				->set('next_sync', $qb->createNamedParameter(gmdate('Y-m-d H:i:s', time() + min(21600, (120 << min($failures, 8))))))
				->set('failures', $qb->createNamedParameter($failures, IQueryBuilder::PARAM_INT))->set('last_error', $qb->createNamedParameter($error))->where($qb->expr()->eq('did', $qb->createNamedParameter($watch['did'])))->executeStatement();
		}
		$stats['duration_ms'] = (int)((microtime(true) - $start) * 1000.0);
		return $stats;
	}
}
