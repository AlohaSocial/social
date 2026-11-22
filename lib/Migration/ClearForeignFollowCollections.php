<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\ConfigService;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Empties the followers collection of every follow whose collection is not on
 * the followed actor's server. A follow is how a post addressed to a
 * collection reaches a home timeline, so a follow of an actor whose document
 * named another account's followers showed that account's followers-only
 * posts. `Person::import()` no longer accepts such a collection; this clears
 * the follows stored before it. Paged on the primary key, gated on a marker.
 */
class ClearForeignFollowCollections implements IRepairStep {
	/** Rows read per round trip. */
	private const CHUNK = 500;

	private const MARKER = 'migration_foreign_follow_collections_cleared';

	public function __construct(
		private IDBConnection $connection,
		private ConfigService $configService,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Clear follows of a followers collection on another server than the followed account';
	}

	#[\Override]
	public function run(IOutput $output): void {
		if ($this->configService->getAppValueInt(self::MARKER) === 1) {
			return;
		}

		$cleared = 0;
		$after = '';
		while (true) {
			$rows = $this->chunkAfter($after);
			if ($rows === []) {
				break;
			}

			foreach ($rows as $row) {
				$after = (string)$row['id_prim'];
				if (!Person::isOnHostOf((string)$row['follow_id'], (string)$row['object_id'])) {
					$this->clear($after);
					$cleared++;
				}
			}

			if (count($rows) < self::CHUNK) {
				break;
			}
		}

		$this->configService->setAppValue(self::MARKER, '1');

		if ($cleared > 0) {
			$output->info(sprintf('cleared the followers collection of %d follow(s) of an account on another server', $cleared));
		}
	}

	private function clear(string $idPrim): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->update(CoreRequestBuilder::TABLE_FOLLOWS)
			->set('follow_id', $qb->createNamedParameter(''))
			->set('follow_id_prim', $qb->createNamedParameter(''))
			->where($qb->expr()->eq('id_prim', $qb->createNamedParameter($idPrim)));
		$qb->executeStatement();
	}

	/**
	 * @return array<array<string, mixed>>
	 */
	private function chunkAfter(string $after): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('id_prim', 'object_id', 'follow_id')
			->from(CoreRequestBuilder::TABLE_FOLLOWS)
			->where($qb->expr()->neq('follow_id_prim', $qb->createNamedParameter('')))
			->andWhere($qb->expr()->gt('id_prim', $qb->createNamedParameter($after)))
			->orderBy('id_prim', 'asc')
			->setMaxResults(self::CHUNK);

		$cursor = $qb->executeQuery();
		$rows = $cursor->fetchAll();
		$cursor->closeCursor();

		return $rows;
	}
}
