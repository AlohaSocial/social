<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Atproto\Model\Move;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Moves of Bluesky accounts between this server and another PDS.
 */
class AtprotoMoveRequest extends CoreRequestBuilder {
	public function add(Move $move): int {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_ATPROTO_MOVE)
			->setValue('did', $qb->createNamedParameter($move->did))
			->setValue('user_id', $qb->createNamedParameter($move->userId))
			->setValue('direction', $qb->createNamedParameter($move->direction))
			->setValue('pds', $qb->createNamedParameter($move->pds))
			->setValue('pds_did', $qb->createNamedParameter($move->pdsDid))
			->setValue('handle', $qb->createNamedParameter($move->handle))
			->setValue('step', $qb->createNamedParameter($move->step))
			->setValue('state', $qb->createNamedParameter($move->state))
			->setValue('session', $qb->createNamedParameter($move->session))
			->setValue('progress', $qb->createNamedParameter((string)json_encode($move->progress)))
			->setValue('error', $qb->createNamedParameter($move->error))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
			->setValue('updated', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));
		$qb->executeStatement();

		return $qb->getLastInsertId();
	}

	public function update(Move $move): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_ATPROTO_MOVE)
			->set('step', $qb->createNamedParameter($move->step))
			->set('state', $qb->createNamedParameter($move->state))
			->set('session', $qb->createNamedParameter($move->session))
			->set('progress', $qb->createNamedParameter((string)json_encode($move->progress)))
			->set('error', $qb->createNamedParameter(mb_substr($move->error, 0, 512)))
			->set('updated', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($move->id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function get(int $id): ?Move {
		$qb = $this->getQueryBuilder();
		$qb->select('*')->from(self::TABLE_ATPROTO_MOVE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->one($qb);
	}

	/** The person's latest move. */
	public function latestOfUser(string $userId): ?Move {
		$qb = $this->getQueryBuilder();
		$qb->select('*')->from(self::TABLE_ATPROTO_MOVE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('id', 'desc')
			->setMaxResults(1);

		return $this->one($qb);
	}

	public function deleteByUser(string $userId): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_ATPROTO_MOVE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	private function one(IQueryBuilder $qb): ?Move {
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if (!is_array($row)) {
			return null;
		}
		$progress = json_decode((string)$row['progress'], true);

		return new Move(
			(int)$row['id'],
			(string)$row['did'],
			(string)$row['user_id'],
			(string)$row['direction'],
			(string)$row['pds'],
			(string)$row['pds_did'],
			(string)$row['handle'],
			(string)$row['step'],
			(string)$row['state'],
			(string)$row['session'],
			is_array($progress) ? $progress : [],
			(string)$row['error'],
			AtprotoIdentityRequest::time($row['creation']),
			AtprotoIdentityRequest::time($row['updated']),
		);
	}
}
