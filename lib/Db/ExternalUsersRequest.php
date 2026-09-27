<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The logins of self-registered external users.
 *
 * Not a `CoreRequestBuilder`: the user backend that reads this table is built
 * on every request, before anybody is logged in, and needs nothing but the
 * connection. The table is still registered in `CoreRequestBuilder::$tables`.
 *
 * @psalm-type ExternalUserRow = array{uid: string, password: string, displayname: string, origin: string, creation: int}
 */
class ExternalUsersRequest {
	private const TABLE = CoreRequestBuilder::TABLE_EXTERNAL_USERS;

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	/**
	 * One login by user id, compared case-insensitively.
	 *
	 * @return ExternalUserRow|null
	 */
	public function get(string $uid): ?array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('uid_lower', $qb->createNamedParameter(mb_strtolower($uid))))
			->setMaxResults(1);

		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return ($row === false) ? null : self::row($row);
	}

	public function create(string $uid, string $passwordHash, string $displayName, string $origin, int $creation): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->insert(self::TABLE)
			->setValue('uid', $qb->createNamedParameter($uid))
			->setValue('uid_lower', $qb->createNamedParameter(mb_strtolower($uid)))
			->setValue('password', $qb->createNamedParameter($passwordHash))
			->setValue('displayname', $qb->createNamedParameter($displayName))
			->setValue('origin', $qb->createNamedParameter($origin))
			->setValue('creation', $qb->createNamedParameter($creation, IQueryBuilder::PARAM_INT));
		$qb->executeStatement();
	}

	public function delete(string $uid): bool {
		$qb = $this->connection->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('uid_lower', $qb->createNamedParameter(mb_strtolower($uid))));

		return $qb->executeStatement() > 0;
	}

	public function setPasswordHash(string $uid, string $passwordHash): bool {
		$qb = $this->connection->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('password', $qb->createNamedParameter($passwordHash))
			->where($qb->expr()->eq('uid_lower', $qb->createNamedParameter(mb_strtolower($uid))));

		return $qb->executeStatement() > 0;
	}

	public function setDisplayName(string $uid, string $displayName): bool {
		$qb = $this->connection->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('displayname', $qb->createNamedParameter($displayName))
			->where($qb->expr()->eq('uid_lower', $qb->createNamedParameter(mb_strtolower($uid))));

		return $qb->executeStatement() > 0;
	}

	/**
	 * Logins whose user id or display name contains the search, by user id.
	 *
	 * @return list<ExternalUserRow>
	 */
	public function search(string $search = '', ?int $limit = null, ?int $offset = null): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->orderBy('uid_lower', 'ASC');
		if ($search !== '') {
			$like = '%' . $this->connection->escapeLikeParameter(mb_strtolower($search)) . '%';
			$qb->where($qb->expr()->orX(
				$qb->expr()->like('uid_lower', $qb->createNamedParameter($like)),
				$qb->expr()->iLike('displayname', $qb->createNamedParameter($like)),
			));
		}
		if ($limit !== null && $limit > 0) {
			$qb->setMaxResults($limit);
		}
		if ($offset !== null && $offset > 0) {
			$qb->setFirstResult($offset);
		}

		$rows = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$rows[] = self::row($row);
		}
		$cursor->closeCursor();

		return $rows;
	}

	public function count(): int {
		$qb = $this->connection->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))
			->from(self::TABLE);

		$cursor = $qb->executeQuery();
		$total = (int)$cursor->fetchOne();
		$cursor->closeCursor();

		return $total;
	}

	/**
	 * @return ExternalUserRow
	 */
	private static function row(array $row): array {
		return [
			'uid' => (string)$row['uid'],
			'password' => (string)$row['password'],
			'displayname' => (string)($row['displayname'] ?? ''),
			'origin' => (string)($row['origin'] ?? ''),
			'creation' => (int)($row['creation'] ?? 0),
		];
	}
}
