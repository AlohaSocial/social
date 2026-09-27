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
 * Invitation links for external users.
 *
 * The token is stored as it is sent, like a share link's: the person who made
 * the invitation can copy the link again. `max_uses` 0 is any number of uses,
 * `expires` 0 is never.
 *
 * @psalm-type ExternalInviteRow = array{id: int, token: string, creator: string, note: string, maxUses: int, uses: int, expires: int, creation: int}
 */
class ExternalInvitesRequest {
	private const TABLE = CoreRequestBuilder::TABLE_EXTERNAL_INVITES;

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	public function create(string $token, string $creator, string $note, int $maxUses, int $expires, int $creation): int {
		$qb = $this->connection->getQueryBuilder();
		$qb->insert(self::TABLE)
			->setValue('token', $qb->createNamedParameter($token))
			->setValue('creator', $qb->createNamedParameter($creator))
			->setValue('note', $qb->createNamedParameter($note))
			->setValue('max_uses', $qb->createNamedParameter($maxUses, IQueryBuilder::PARAM_INT))
			->setValue('uses', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->setValue('expires', $qb->createNamedParameter($expires, IQueryBuilder::PARAM_INT))
			->setValue('creation', $qb->createNamedParameter($creation, IQueryBuilder::PARAM_INT));
		$qb->executeStatement();

		return $qb->getLastInsertId();
	}

	/** @return ExternalInviteRow|null */
	public function get(int $id): ?array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->all($qb)[0] ?? null;
	}

	/** @return ExternalInviteRow|null */
	public function getByToken(string $token): ?array {
		if ($token === '') {
			return null;
		}

		$qb = $this->connection->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));

		return $this->all($qb)[0] ?? null;
	}

	/**
	 * Every invitation, or those one person made, newest first.
	 *
	 * @return list<ExternalInviteRow>
	 */
	public function list(?string $creator = null, int $limit = 200): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->orderBy('creation', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(max(1, $limit));
		if ($creator !== null) {
			$qb->where($qb->expr()->eq('creator', $qb->createNamedParameter($creator)));
		}

		return $this->all($qb);
	}

	/**
	 * Counts one use, unless the invitation has none left.
	 *
	 * One statement, so two registrations racing for the last use of an
	 * invitation cannot both have it.
	 */
	public function consume(int $id): bool {
		$qb = $this->connection->getQueryBuilder();
		$expr = $qb->expr();
		$qb->update(self::TABLE)
			->set('uses', $qb->func()->add('uses', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->where($expr->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($expr->orX(
				$expr->eq('max_uses', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)),
				$expr->lt('uses', 'max_uses'),
			));

		return $qb->executeStatement() > 0;
	}

	public function delete(int $id): bool {
		$qb = $this->connection->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement() > 0;
	}

	/** Forgets the invitations that ran out: expired, or used up. */
	public function purgeSpent(int $now): int {
		$qb = $this->connection->getQueryBuilder();
		$expr = $qb->expr();
		$qb->delete(self::TABLE)
			->where($expr->orX(
				$expr->andX(
					$expr->gt('expires', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)),
					$expr->lt('expires', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)),
				),
				$expr->andX(
					$expr->gt('max_uses', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)),
					$expr->gte('uses', 'max_uses'),
				),
			));

		return $qb->executeStatement();
	}

	/** @return list<ExternalInviteRow> */
	private function all(IQueryBuilder $qb): array {
		$rows = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$rows[] = [
				'id' => (int)$row['id'],
				'token' => (string)$row['token'],
				'creator' => (string)$row['creator'],
				'note' => (string)$row['note'],
				'maxUses' => (int)$row['max_uses'],
				'uses' => (int)$row['uses'],
				'expires' => (int)$row['expires'],
				'creation' => (int)$row['creation'],
			];
		}
		$cursor->closeCursor();

		return $rows;
	}
}
