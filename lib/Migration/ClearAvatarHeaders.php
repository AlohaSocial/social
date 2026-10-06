<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\ConfigService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Takes the header off every cached local actor whose header is its own
 * avatar.
 *
 * The header used to fall back to the avatar, and the cached copy of a local
 * actor stored that fallback in its source document as if it were a banner;
 * rebuilding the cache reads the header back from there, so it never went
 * away by itself. A header that is the account's icon, or Nextcloud's avatar
 * route of its handle, is removed from the stored document. A banner the
 * account uploaded is a media URL of this app and is left alone.
 *
 * Only local rows are read, paged on the primary key, and the marker keeps a
 * later upgrade from reading them again.
 */
class ClearAvatarHeaders implements IRepairStep {
	/** Rows read per round trip. */
	private const CHUNK = 500;

	private const MARKER = 'migration_avatar_headers_cleared';

	public function __construct(
		private IDBConnection $connection,
		private ConfigService $configService,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Remove the avatar stored as the header of local accounts';
	}

	#[\Override]
	public function run(IOutput $output): void {
		if ($this->configService->getAppValueInt(self::MARKER) === 1) {
			return;
		}

		$cleared = 0;
		$after = 0;
		while (true) {
			$rows = $this->chunkAfter($after);
			if ($rows === []) {
				break;
			}

			foreach ($rows as $row) {
				$after = (int)$row['nid'];
				if ($this->clear($row)) {
					$cleared++;
				}
			}

			if (count($rows) < self::CHUNK) {
				break;
			}
		}

		$this->configService->setAppValue(self::MARKER, '1');

		if ($cleared > 0) {
			$output->info(sprintf('removed the avatar stored as the header of %d local account(s)', $cleared));
		}
	}

	/**
	 * @param array<string, mixed> $row
	 *
	 * @return bool whether the row needed writing
	 */
	private function clear(array $row): bool {
		$source = json_decode((string)($row['source'] ?? ''), true);
		if (!is_array($source) || !is_array($source['image'] ?? null)) {
			return false;
		}

		$header = $source['image']['url'] ?? '';
		if (!is_string($header) || $header === '') {
			return false;
		}

		$icon = is_array($source['icon'] ?? null) ? ($source['icon']['url'] ?? '') : '';
		if ($header !== $icon
			&& !Person::isAvatarRouteOf($header, [(string)($row['preferred_username'] ?? '')])) {
			return false;
		}

		unset($source['image']);

		$qb = $this->connection->getQueryBuilder();
		$qb->update(CoreRequestBuilder::TABLE_CACHE_ACTORS)
			->set('source', $qb->createNamedParameter(json_encode($source, JSON_UNESCAPED_SLASHES)))
			->where($qb->expr()->eq('nid', $qb->createNamedParameter((int)$row['nid'], IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		return true;
	}

	/**
	 * @return array<array<string, mixed>>
	 */
	private function chunkAfter(int $after): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('nid', 'preferred_username', 'source')
			->from(CoreRequestBuilder::TABLE_CACHE_ACTORS)
			->where($qb->expr()->eq('local', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('nid', $qb->createNamedParameter($after, IQueryBuilder::PARAM_INT)))
			->orderBy('nid', 'asc')
			->setMaxResults(self::CHUNK);

		$cursor = $qb->executeQuery();
		$rows = $cursor->fetchAll();
		$cursor->closeCursor();

		return $rows;
	}
}
