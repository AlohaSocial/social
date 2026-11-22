<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\DB\QueryBuilder\IQueryBuilder;

class ActionsRequest extends ActionsRequestBuilder {
	use TArrayTools;

	/** What a vote's object adds to the poll's id: `<poll>#option-<n>`. */
	public const VOTE_OPTION = '#option-';

	/**
	 * Inserts an action, unless a row with its id or its (actor, object, type)
	 * is already there. A redelivery can pass the inbox's lookup too, and the
	 * database skips the duplicate rather than failing, which would abort the
	 * transaction on PostgreSQL.
	 *
	 * @return bool whether the row was written: false when it was already there
	 */
	public function save(ACore $like): bool {
		$qb = $this->getQueryBuilder();

		return $this->dbConnection->insertIgnoreConflict(self::TABLE_ACTIONS, [
			'id' => $like->getId(),
			'id_prim' => $qb->prim($like->getId()),
			'actor_id' => $like->getActorId(),
			'actor_id_prim' => $qb->prim($like->getActorId()),
			'type' => $like->getType(),
			'object_id' => $like->getObjectId(),
			'object_id_prim' => $qb->prim($like->getObjectId()),
			'poll_prim' => $qb->prim(self::pollOfVote($like->getType(), $like->getObjectId())),
			'creation' => (new DateTime('now'))->format('Y-m-d H:i:s'),
		]) > 0;
	}

	/**
	 * The action a URI names (a Like, a Block, a pin), for a peer that sends the
	 * object of an `Undo` as a bare link.
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
		// `creation` is a DATETIME, so actions within one second have no order of
		// their own: the primary key breaks the tie, keeping the cut stable.
		$qb->addOrderBy('a.id_prim', 'desc');

		return $this->getActionsFromRequest($qb);
	}

	/**
	 * Who did one thing to one post, newest first: the rows behind Mastodon's
	 * `favourited_by` and `reblogged_by`. The actor of each row is joined in; the
	 * caller leaves out rows whose actor was never cached.
	 *
	 * @return ACore[]
	 */
	public function getActionsOnObject(string $objectId, string $type, int $limit = 0): array {
		$qb = $this->getActionsSelectSql();
		$this->limitToPrim($qb, 'object_id_prim', $objectId);
		$qb->limitToType($type);
		$this->leftJoinCacheActors($qb, 'actor_id');
		$qb->orderBy('a.creation', 'desc');
		// the primary key orders likes made within one second, keeping the cut
		// stable
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
	 * The poll a vote was cast in, or '' for any other action: a `Vote`'s object
	 * is the poll's id with this app's option suffix.
	 */
	public static function pollOfVote(string $type, string $objectId): string {
		if ($type !== 'Vote') {
			return '';
		}

		$at = strrpos($objectId, self::VOTE_OPTION);

		return ($at === false) ? '' : substr($objectId, 0, $at);
	}

	/**
	 * Who voted in each of several polls, in one query, matched on the indexed
	 * `poll_prim` since the closed-poll sweep asks this for every poll.
	 *
	 * @param string[] $pollIds
	 * @param int $limit the most voters read per poll, on average
	 *
	 * @return array<string, string[]> poll id => actor ids, each once however
	 *                                 many options they chose; a poll nobody
	 *                                 voted in has an empty list
	 */
	public function votersOfPolls(array $pollIds, int $limit = 1000): array {
		$byPrim = [];
		$voters = [];
		$qb = $this->getQueryBuilder();
		foreach ($pollIds as $pollId) {
			$voters[$pollId] = [];
			$prim = $qb->prim($pollId);
			if ($prim !== '') {
				$byPrim[$prim] = $pollId;
			}
		}

		if ($byPrim === []) {
			return $voters;
		}

		$qb->selectDistinct(['poll_prim', 'actor_id'])
			->from(self::TABLE_ACTIONS)
			->where($qb->expr()->in(
				'poll_prim',
				$qb->createNamedParameter(array_keys($byPrim), IQueryBuilder::PARAM_STR_ARRAY)
			))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter('Vote')))
			->setMaxResults(max(1, $limit) * count($byPrim));

		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$pollId = $byPrim[(string)$data['poll_prim']] ?? null;
			if ($pollId !== null) {
				$voters[$pollId][] = (string)$data['actor_id'];
			}
		}
		$cursor->closeCursor();

		return $voters;
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
}
