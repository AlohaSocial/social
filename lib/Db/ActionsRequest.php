<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use Exception;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Class ActionsRequest
 *
 * @package OCA\Social\Db
 */
class ActionsRequest extends ActionsRequestBuilder {
	use TArrayTools;

	/**
	 * Insert a new Note in the database.
	 */
	public function save(ACore $like): void {
		$qb = $this->getActionsInsertSql();
		$qb->setValue('id', $qb->createNamedParameter($like->getId()))
			->setValue('id_prim', $qb->createNamedParameter($qb->prim($like->getId())))
			->setValue('actor_id', $qb->createNamedParameter($like->getActorId()))
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($like->getActorId())))
			->setValue('type', $qb->createNamedParameter($like->getType()))
			->setValue('object_id', $qb->createNamedParameter($like->getObjectId()))
			->setValue('object_id_prim', $qb->createNamedParameter($qb->prim($like->getObjectId())));

		try {
			$qb->setValue(
				'creation',
				$qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
			);
		} catch (Exception $e) {
			// 'now' is a constant, so this does not happen; were it ever to,
			// the row would go in with no date at all, and every list that
			// orders on it would place it arbitrarily
			$this->logger->warning('could not timestamp a row', ['exception' => $e]);
		}

		$qb->executeStatement();
	}

	/**
	 * The action a URI names — a Like, a Block, a pin.
	 *
	 * Only a peer that sends the object of an `Undo` as a bare link needs
	 * this: the id is then all there is to go on.
	 *
	 * @throws ActionDoesNotExistException
	 */
	public function getById(string $id): ACore {
		if ($id === '') {
			throw new ActionDoesNotExistException('empty action id');
		}

		$qb = $this->getActionsSelectSql();
		$this->limitToIdPrimString($qb, $id);

		return $this->getActionFromRequest($qb);
	}

	/**
	 * @param string $actorId
	 * @param string $objectId
	 *
	 * @param string $type
	 *
	 * @return ACore
	 * @throws ActionDoesNotExistException
	 */
	public function getAction(string $actorId, string $objectId, string $type): ACore {
		$qb = $this->getActionsSelectSql();
		$this->limitToPrim($qb, 'actor_id_prim', $actorId);
		$this->limitToPrim($qb, 'object_id_prim', $objectId);
		$qb->limitToType($type);

		return $this->getActionFromRequest($qb);
	}

	/**
	 * @param ACore $item
	 *
	 * @return ACore
	 * @throws ActionDoesNotExistException
	 */
	public function getActionFromItem(ACore $item): ACore {
		$qb = $this->getActionsSelectSql();

		$this->limitToPrim($qb, 'actor_id_prim', $item->getActorId());
		$this->limitToPrim($qb, 'object_id_prim', $item->getObjectId());
		$qb->limitToType($item->getType());

		return $this->getActionFromRequest($qb);
	}

	/**
	 * @param string $objectId
	 * @param string $type
	 *
	 * @return int
	 */
	public function countActions(string $objectId, string $type): int {
		$qb = $this->countActionsSelectSql();
		$this->limitToPrim($qb, 'object_id_prim', $objectId);
		$qb->limitToType($type);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $this->getInt('count', $data, 0);
	}

	/**
	 * Every action of one type by one actor, newest first.
	 *
	 * @return ACore[]
	 */
	public function getActionsByActor(string $actorId, string $type, int $limit = 0): array {
		$qb = $this->getActionsSelectSql();
		if ($limit > 0) {
			$qb->setMaxResults($limit);
		}
		$this->limitToPrim($qb, 'actor_id_prim', $actorId);
		$qb->limitToType($type);
		$qb->orderBy('a.creation', 'desc');
		// `creation` is a DATETIME: the actions taken within one second of each
		// other have no order of their own, and the cut above would keep a
		// different one of them each time. The primary key breaks the tie.
		$qb->addOrderBy('a.id_prim', 'desc');

		return $this->getActionsFromRequest($qb);
	}

	/**
	 * Who did one thing to one post, newest first: the rows behind Mastodon's
	 * `favourited_by` and `reblogged_by`.
	 *
	 * The actor of each row is joined in, because the answer is a page of
	 * accounts rather than of actions — a row whose actor this instance has
	 * never cached has nothing to show and is left out by the caller.
	 *
	 * @return ACore[]
	 */
	public function getActionsOnObject(string $objectId, string $type, int $limit = 0): array {
		$qb = $this->getActionsSelectSql();
		$this->limitToPrim($qb, 'object_id_prim', $objectId);
		$qb->limitToType($type);
		$this->leftJoinCacheActors($qb, 'actor_id');
		$qb->orderBy('a.creation', 'desc');
		// without this the likes made within one second of each other are in
		// no particular order, and the cut by the limit keeps a different one
		// of them each time
		$qb->addOrderBy('a.id_prim', 'desc');
		if ($limit > 0) {
			$qb->setMaxResults($limit);
		}

		return $this->getActionsFromRequest($qb);
	}

	/**
	 * Removes one action, addressed the way it is looked up.
	 */
	public function deleteAction(string $actorId, string $objectId, string $type): void {
		$qb = $this->getActionsDeleteSql();
		$qb->limitToDBField('actor_id_prim', $qb->prim($actorId));
		$qb->limitToDBField('object_id_prim', $qb->prim($objectId));
		$qb->limitToType($type);

		$qb->executeStatement();
	}

	/**
	 * @param string $objectId
	 *
	 * @return Like[]
	 */
	public function getByObjectId(string $objectId, int $limit = 0): array {
		$qb = $this->getActionsSelectSql();
		if ($limit > 0) {
			$qb->setMaxResults($limit);
		}
		$this->limitToPrim($qb, 'object_id_prim', $objectId);
		$this->leftJoinCacheActors($qb, 'actor_id');

		return $this->getActionsFromRequest($qb);
	}

	/**
	 * Who voted in a poll.
	 *
	 * A vote is a `Vote` action whose object is the poll's id with an option
	 * suffix, so the poll is matched by prefix — the one place in this app
	 * where a `LIKE` on an id is the right shape, because the suffix is this
	 * app's own and the prefix is an exact id rather than a host.
	 *
	 * @return string[] actor ids, each once however many options they chose
	 */
	public function votersOf(string $pollId, int $limit = 1000): array {
		$qb = $this->getQueryBuilder();
		$qb->selectDistinct('actor_id')
			->from(self::TABLE_ACTIONS)
			->where($qb->expr()->eq('type', $qb->createNamedParameter('Vote')))
			->andWhere($qb->expr()->like(
				'object_id',
				$qb->createNamedParameter(
					$this->escapeLikeValue($pollId) . '#option-%'
				)
			))
			->setMaxResults(max(1, $limit));

		$voters = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$voters[] = (string)$data['actor_id'];
		}
		$cursor->closeCursor();

		return $voters;
	}

	/** `%`, `_` and the escape itself are literals inside an id. */
	private function escapeLikeValue(string $value): string {
		return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
	}

	/**
	 * @param ACore $item
	 */
	public function delete(ACore $item) {
		$qb = $this->getActionsDeleteSql();
		$this->limitToIdPrimString($qb, $item->getId());
		$qb->limitToType($item->getType());

		$qb->executeStatement();
	}

	public function deleteByActor(string $actorId): void {
		$qb = $this->getActionsDeleteSql();
		$qb->limitToDBField('actor_id_prim', $qb->prim($actorId));

		$qb->executeStatement();
	}

	public function moveAccount(string $actorId, string $newId): void {
		$qb = $this->getActionsUpdateSql();
		$qb->set('actor_id', $qb->createNamedParameter($newId))
			->set('actor_id_prim', $qb->createNamedParameter($qb->prim($newId)));

		$qb->limitToDBField('actor_id_prim', $qb->prim($actorId));

		$qb->executeStatement();
	}
}
