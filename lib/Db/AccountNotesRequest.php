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
 * The private notes one account keeps about others.
 *
 * A note is readable by the account that wrote it and by nobody else — not by
 * the account it is about, and never by another instance: nothing here is
 * federated, and no read in this class is written without the author's actor
 * id in the statement.
 */
class AccountNotesRequest extends AccountNotesRequestBuilder {
	/**
	 * One note per pair, so a second note about the same account replaces the
	 * first. Written as an insert that falls back to an update on the unique
	 * index rather than as a read followed by a write: two clients saving a
	 * note at the same moment would otherwise both insert.
	 */
	public function save(string $actorId, string $objectId, string $note): void {
		$qb = $this->getAccountNotesInsertSql();
		$qb->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
			->setValue('object_id', $qb->createNamedParameter($objectId))
			->setValue('object_id_prim', $qb->createNamedParameter($qb->prim($objectId)))
			->setValue('note', $qb->createNamedParameter($note))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		if (!$this->insertUnlessDuplicate($qb)) {
			$update = $this->getAccountNotesUpdateSql();
			$update->set('note', $update->createNamedParameter($note));
			$update->where(
				$update->expr()->eq('actor_id_prim', $update->createNamedParameter($update->prim($actorId))),
				$update->expr()->eq('object_id_prim', $update->createNamedParameter($update->prim($objectId)))
			);
			$update->executeStatement();
		}
	}

	/** Clearing a note that was never written is not an error. */
	public function delete(string $actorId, string $objectId): void {
		$qb = $this->getAccountNotesDeleteSql();
		$qb->where(
			$qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))),
			$qb->expr()->eq('object_id_prim', $qb->createNamedParameter($qb->prim($objectId)))
		);

		$qb->executeStatement();
	}

	/** '' when this account wrote no note about that one. */
	/**
	 * The viewer's notes about a *set* of accounts, keyed by account id.
	 *
	 * One query for a page of relationships rather than one per account: the
	 * single-pair version above is for the routes that answer about one.
	 *
	 * @param string[] $objectIds
	 *
	 * @return array<string, string>
	 */
	public function getNotes(string $actorId, array $objectIds): array {
		if ($objectIds === []) {
			return [];
		}

		$qb = $this->getAccountNotesSelectSql();
		$prims = array_map(static fn (string $id): string => $qb->prim($id), $objectIds);
		$qb->andWhere($qb->expr()->eq('an.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($qb->expr()->in('an.object_id_prim', $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)));

		$notes = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$notes[(string)($data['object_id'] ?? '')] = (string)($data['note'] ?? '');
		}
		$cursor->closeCursor();

		return $notes;
	}

	public function getNote(string $actorId, string $objectId): string {
		$qb = $this->getAccountNotesSelectSql();
		$qb->andWhere($qb->expr()->eq('an.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($qb->expr()->eq('an.object_id_prim', $qb->createNamedParameter($qb->prim($objectId))));
		$qb->setMaxResults(1);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			return '';
		}

		return $this->get('note', $data);
	}

	/**
	 * Everything an account leaves behind here when it is deleted: the notes it
	 * wrote, and the notes others wrote about it — the second half is not
	 * tidiness, it is a row keyed by an actor id that is about to be somebody
	 * else's if the name is ever reused.
	 */
	public function deleteRelatedId(string $actorId): void {
		$qb = $this->getAccountNotesDeleteSql();
		$prim = $qb->prim($actorId);
		$qb->where(
			$qb->expr()->orX(
				$qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($prim)),
				$qb->expr()->eq('object_id_prim', $qb->createNamedParameter($prim))
			)
		);

		$qb->executeStatement();
	}
}
