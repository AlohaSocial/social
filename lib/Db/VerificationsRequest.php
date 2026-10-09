<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The accounts this instance verified (`VerificationService`): one row per
 * account, with the DID, handle and display name its published record
 * names.
 *
 * @psalm-type VerificationRow = array{actorId: string, did: string, handle: string, displayName: string, verifiedBy: string, creation: int}
 */
class VerificationsRequest extends CoreRequestBuilder {
	private const FIELDS = ['actor_id', 'did', 'handle', 'display_name', 'verified_by', 'creation'];

	public function add(string $actorId, string $did, string $handle, string $displayName, string $verifiedBy, int $time): void {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_VERIFICATIONS)
			->setValue('actor_id', $qb->createNamedParameter($actorId))
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
			->setValue('did', $qb->createNamedParameter($did))
			->setValue('handle', $qb->createNamedParameter($handle))
			->setValue('display_name', $qb->createNamedParameter($displayName))
			->setValue('verified_by', $qb->createNamedParameter($verifiedBy))
			->setValue('creation', $qb->createNamedParameter(new DateTime('@' . $time), IQueryBuilder::PARAM_DATE));
		$qb->executeStatement();
	}

	/**
	 * What the account's record names now, after its handle or name changed.
	 */
	public function updateSubject(string $actorId, string $did, string $handle, string $displayName): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_VERIFICATIONS)
			->set('did', $qb->createNamedParameter($did))
			->set('handle', $qb->createNamedParameter($handle))
			->set('display_name', $qb->createNamedParameter($displayName))
			->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));
		$qb->executeStatement();
	}

	/**
	 * @return bool whether a row was removed
	 */
	public function remove(string $actorId): bool {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_VERIFICATIONS)
			->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		return $qb->executeStatement() > 0;
	}

	/**
	 * @return VerificationRow|null
	 */
	public function get(string $actorId): ?array {
		$qb = $this->getQueryBuilder();
		$qb->select(...self::FIELDS)->from(self::TABLE_VERIFICATIONS)
			->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return is_array($row) ? self::row($row) : null;
	}

	/**
	 * Every verification, newest first.
	 *
	 * @return list<VerificationRow>
	 */
	public function getAll(int $limit = 1000, int $offset = 0): array {
		$qb = $this->getQueryBuilder();
		$qb->select(...self::FIELDS)->from(self::TABLE_VERIFICATIONS)
			->orderBy('id', 'desc')
			->setMaxResults($limit)
			->setFirstResult($offset);
		$rows = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$rows[] = self::row($row);
		}
		$result->closeCursor();

		return $rows;
	}

	/**
	 * When each verified account was verified, by actor id: what an account
	 * entity is told, read once for a whole response.
	 *
	 * @return array<string, int>
	 */
	public function getIndex(): array {
		$qb = $this->getQueryBuilder();
		$qb->select('actor_id', 'creation')->from(self::TABLE_VERIFICATIONS);
		$index = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$index[(string)$row['actor_id']] = AtprotoIdentityRequest::time($row['creation']);
		}
		$result->closeCursor();

		return $index;
	}

	/**
	 * @return VerificationRow
	 */
	private static function row(array $row): array {
		return [
			'actorId' => (string)$row['actor_id'],
			'did' => (string)$row['did'],
			'handle' => (string)$row['handle'],
			'displayName' => (string)$row['display_name'],
			'verifiedBy' => (string)$row['verified_by'],
			'creation' => AtprotoIdentityRequest::time($row['creation']),
		];
	}
}
