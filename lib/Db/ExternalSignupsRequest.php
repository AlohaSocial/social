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
 * Registrations of external users that are not accounts yet.
 *
 * A row waits either for its email to be confirmed (`verified` 0) or, once it
 * is, for an administrator (`approval` 1). A row reserves its handle and its
 * email address for as long as it exists. The verification token is stored
 * as a SHA-256 hash, never as sent.
 *
 * @psalm-type ExternalSignupRow = array{id: int, handle: string, email: string, password: string, token: string, verified: bool, approval: bool, inviteId: int, ipHash: string, creation: int}
 */
class ExternalSignupsRequest {
	private const TABLE = CoreRequestBuilder::TABLE_EXTERNAL_SIGNUPS;

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	public function create(
		string $handle,
		string $email,
		string $passwordHash,
		string $tokenHash,
		bool $verified,
		bool $approval,
		int $inviteId,
		string $ipHash,
		int $creation,
	): int {
		$qb = $this->connection->getQueryBuilder();
		$qb->insert(self::TABLE)
			->setValue('handle', $qb->createNamedParameter($handle))
			->setValue('email', $qb->createNamedParameter($email))
			->setValue('password', $qb->createNamedParameter($passwordHash))
			->setValue('token', $qb->createNamedParameter($tokenHash))
			->setValue('verified', $qb->createNamedParameter($verified ? 1 : 0, IQueryBuilder::PARAM_INT))
			->setValue('approval', $qb->createNamedParameter($approval ? 1 : 0, IQueryBuilder::PARAM_INT))
			->setValue('invite_id', $qb->createNamedParameter($inviteId, IQueryBuilder::PARAM_INT))
			->setValue('ip_hash', $qb->createNamedParameter($ipHash))
			->setValue('creation', $qb->createNamedParameter($creation, IQueryBuilder::PARAM_INT));
		$qb->executeStatement();

		return $qb->getLastInsertId();
	}

	/** @return ExternalSignupRow|null */
	public function get(int $id): ?array {
		return $this->one('id', $id, IQueryBuilder::PARAM_INT);
	}

	/** @return ExternalSignupRow|null */
	public function getByTokenHash(string $tokenHash): ?array {
		return ($tokenHash === '') ? null : $this->one('token', $tokenHash, IQueryBuilder::PARAM_STR);
	}

	/** @return ExternalSignupRow|null */
	public function getByHandle(string $handle): ?array {
		return $this->one('handle', mb_strtolower($handle), IQueryBuilder::PARAM_STR);
	}

	/** @return ExternalSignupRow|null */
	public function getByEmail(string $email): ?array {
		return $this->one('email', mb_strtolower($email), IQueryBuilder::PARAM_STR);
	}

	public function markVerified(int $id): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('verified', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
			->set('token', $qb->createNamedParameter(''))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function delete(int $id): bool {
		$qb = $this->connection->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement() > 0;
	}

	/**
	 * Confirmed registrations an administrator has to decide on, oldest first.
	 *
	 * @return list<ExternalSignupRow>
	 */
	public function awaitingApproval(int $limit = 100): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('approval', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('verified', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->orderBy('creation', 'ASC')
			->setMaxResults(max(1, $limit));

		return $this->all($qb);
	}

	public function countAwaitingApproval(): int {
		$qb = $this->connection->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))
			->from(self::TABLE)
			->where($qb->expr()->eq('approval', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('verified', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));

		return $this->scalar($qb);
	}

	/** Every row, confirmed or not. */
	public function count(): int {
		$qb = $this->connection->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))
			->from(self::TABLE);

		return $this->scalar($qb);
	}

	/** Registrations from one address hash since a moment. */
	public function countFromIpSince(string $ipHash, int $since): int {
		$qb = $this->connection->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))
			->from(self::TABLE)
			->where($qb->expr()->eq('ip_hash', $qb->createNamedParameter($ipHash)))
			->andWhere($qb->expr()->gte('creation', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)));

		return $this->scalar($qb);
	}

	/**
	 * Forgets the registrations whose email was never confirmed in time.
	 *
	 * A row waiting for an administrator is kept however old it is: it is
	 * somebody's request, and only a decision ends it.
	 */
	public function purgeUnverifiedBefore(int $before): int {
		$qb = $this->connection->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('verified', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('creation', $qb->createNamedParameter($before, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}

	/** @return ExternalSignupRow|null */
	private function one(string $column, string|int $value, int $type): ?array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq($column, $qb->createNamedParameter($value, $type)))
			->setMaxResults(1);

		return $this->all($qb)[0] ?? null;
	}

	/** @return list<ExternalSignupRow> */
	private function all(IQueryBuilder $qb): array {
		$rows = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$rows[] = [
				'id' => (int)$row['id'],
				'handle' => (string)$row['handle'],
				'email' => (string)$row['email'],
				'password' => (string)$row['password'],
				'token' => (string)$row['token'],
				'verified' => (int)$row['verified'] === 1,
				'approval' => (int)$row['approval'] === 1,
				'inviteId' => (int)$row['invite_id'],
				'ipHash' => (string)$row['ip_hash'],
				'creation' => (int)$row['creation'],
			];
		}
		$cursor->closeCursor();

		return $rows;
	}

	private function scalar(IQueryBuilder $qb): int {
		$cursor = $qb->executeQuery();
		$value = (int)$cursor->fetchOne();
		$cursor->closeCursor();

		return $value;
	}
}
