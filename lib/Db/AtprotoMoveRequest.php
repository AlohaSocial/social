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
		$this->write($move);
	}

	/**
	 * Writes a move only while it is stored as running, so a job does not
	 * write over what the person did meanwhile (called it off).
	 *
	 * @return bool whether it was written
	 */
	public function updateIfRunning(Move $move): bool {
		return $this->write($move, Move::RUNNING);
	}

	/**
	 * Writes a move only while it is stored as it was read — in $state, at
	 * $step — so what the person does is not written over what a job did
	 * meanwhile (moved it on to the handover).
	 *
	 * @return bool whether it was written
	 */
	public function updateIf(Move $move, string $state, string $step): bool {
		return $this->write($move, $state, $step);
	}

	private function write(Move $move, ?string $state = null, ?string $step = null): bool {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_ATPROTO_MOVE)
			->set('step', $qb->createNamedParameter($move->step))
			->set('state', $qb->createNamedParameter($move->state))
			->set('session', $qb->createNamedParameter($move->session))
			->set('progress', $qb->createNamedParameter((string)json_encode($move->progress)))
			->set('error', $qb->createNamedParameter(mb_substr($move->error, 0, 512)))
			->set('updated', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($move->id, IQueryBuilder::PARAM_INT)));
		if ($state !== null) {
			$qb->andWhere($qb->expr()->eq('state', $qb->createNamedParameter($state)));
		}
		if ($step !== null) {
			$qb->andWhere($qb->expr()->eq('step', $qb->createNamedParameter($step)));
		}

		return $qb->executeStatement() > 0;
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

	/** The latest move of a DID in one direction. */
	public function latestOfDid(string $did, string $direction): ?Move {
		$qb = $this->getQueryBuilder();
		$qb->select('*')->from(self::TABLE_ATPROTO_MOVE)
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))
			->andWhere($qb->expr()->eq('direction', $qb->createNamedParameter($direction)))
			->orderBy('id', 'desc')
			->setMaxResults(1);

		return $this->one($qb);
	}

	/**
	 * @return list<Move> the invitations of a DID to move here that are not
	 *                    taken up, each person's own, newest first
	 */
	public function invitedOfDid(string $did): array {
		$qb = $this->getQueryBuilder();
		$qb->select('*')->from(self::TABLE_ATPROTO_MOVE)
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))
			->andWhere($qb->expr()->eq('direction', $qb->createNamedParameter(Move::INBOUND)))
			->andWhere($qb->expr()->eq('step', $qb->createNamedParameter(Move::STEP_INVITED)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(Move::WAITING)))
			->orderBy('id', 'desc');

		return $this->all($qb);
	}

	/**
	 * @return list<Move> the running moves not written to since a time, the longest silent first
	 */
	public function getStale(int $before, int $limit): array {
		$qb = $this->getQueryBuilder();
		$qb->select('*')->from(self::TABLE_ATPROTO_MOVE)
			->where($qb->expr()->eq('state', $qb->createNamedParameter(Move::RUNNING)))
			->andWhere($qb->expr()->lt('updated', $qb->createNamedParameter(new DateTime('@' . $before), IQueryBuilder::PARAM_DATE)))
			->orderBy('updated', 'asc')
			->setMaxResults($limit);

		return $this->all($qb);
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

		return $this->move($row);
	}

	/** @return list<Move> */
	private function all(IQueryBuilder $qb): array {
		$moves = [];
		$result = $qb->executeQuery();
		while (is_array($row = $result->fetch())) {
			$moves[] = $this->move($row);
		}
		$result->closeCursor();

		return $moves;
	}

	private function move(array $row): Move {
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
