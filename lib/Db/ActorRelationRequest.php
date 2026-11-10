<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Model\ActivityPub\Activity\Block;
use OCA\Social\Model\ActorRelation;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Storage for block/mute relations. All reads and writes are keyed by the prim
 * hash of the two actor ids, matching the rest of the schema.
 */
class ActorRelationRequest extends ActorRelationRequestBuilder {
	/**
	 * Idempotent: saving an already-existing relation only refreshes its
	 * `notifications` flag (relevant when re-muting with a different setting)
	 * and the activity it came from.
	 *
	 * @param string $activityId the id of the activity that made the relation
	 *                           (an incoming `Block`), so an `Undo` naming
	 *                           only that id finds it; '' for none
	 */
	public function save(
		string $actorId, string $objectId, string $type, bool $notifications = true, string $activityId = '',
	): void {
		$qb = $this->getActorRelationInsertSql();
		$qb->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
			->setValue('object_id', $qb->createNamedParameter($objectId))
			->setValue('object_id_prim', $qb->createNamedParameter($qb->prim($objectId)))
			->setValue('type', $qb->createNamedParameter($type))
			->setValue('notifications', $qb->createNamedParameter($notifications, IQueryBuilder::PARAM_BOOL))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));
		if ($activityId !== '') {
			$qb->setValue('activity_id_prim', $qb->createNamedParameter($qb->prim($activityId)));
		}

		if (!$this->insertUnlessDuplicate($qb)) {
			$update = $this->getQueryBuilder();
			$update->update(self::TABLE_ACTOR_RELATION)
				->set('notifications', $update->createNamedParameter($notifications, IQueryBuilder::PARAM_BOOL));
			if ($activityId !== '') {
				$update->set('activity_id_prim', $update->createNamedParameter($update->prim($activityId)));
			}
			$update->where(
				$update->expr()->eq('actor_id_prim', $update->createNamedParameter($update->prim($actorId))),
				$update->expr()->eq('object_id_prim', $update->createNamedParameter($update->prim($objectId))),
				$update->expr()->eq('type', $update->createNamedParameter($type))
			);
			$update->executeStatement();
		}
	}

	public function delete(string $actorId, string $objectId, string $type): void {
		$qb = $this->getActorRelationDeleteSql();
		$qb->where(
			$qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))),
			$qb->expr()->eq('object_id_prim', $qb->createNamedParameter($qb->prim($objectId))),
			$qb->expr()->eq('type', $qb->createNamedParameter($type))
		);

		$qb->executeStatement();
	}

	/**
	 * The incoming `Block` a `blocked_by` relation was written from, found by
	 * the block's id: the blocker as its actor, the local account it blocked
	 * as its object. Null when no relation came from that activity.
	 */
	public function getBlockByActivityId(string $activityId): ?Block {
		$qb = $this->getQueryBuilder();
		$qb->select('ar.object_id', 'a.id')
			->from(self::TABLE_ACTOR_RELATION, 'ar')
			->innerJoin('ar', self::TABLE_ACTORS, 'a', $qb->expr()->eq('a.id_prim', 'ar.actor_id_prim'))
			->where($qb->expr()->eq('ar.activity_id_prim', $qb->createNamedParameter($qb->prim($activityId))))
			->andWhere($qb->expr()->eq('ar.type', $qb->createNamedParameter(ActorRelation::TYPE_BLOCKED_BY)))
			->setMaxResults(1);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			return null;
		}

		$block = new Block();
		$block->setId($activityId);
		$block->setActorId((string)$data['object_id']);
		$block->setObjectId((string)$data['id']);

		return $block;
	}

	public function exists(string $actorId, string $objectId, string $type): bool {
		return $this->getRelation($actorId, $objectId, $type) !== null;
	}

	public function getRelation(string $actorId, string $objectId, string $type): ?ActorRelation {
		$qb = $this->getActorRelationSelectSql();
		$qb->andWhere(
			$qb->expr()->eq('ar.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))),
			$qb->expr()->eq('ar.object_id_prim', $qb->createNamedParameter($qb->prim($objectId))),
			$qb->expr()->eq('ar.type', $qb->createNamedParameter($type))
		);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			return null;
		}

		return $this->parseActorRelationSelectSql($data);
	}

	/**
	 * Every relation two actors have with each other, in both directions —
	 * feeds the Mastodon relationship flags in one query.
	 *
	 * @return ActorRelation[]
	 */
	public function getBetween(string $actorId, string $objectId): array {
		$qb = $this->getActorRelationSelectSql();
		$expr = $qb->expr();
		$qb->andWhere($expr->eq('ar.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($expr->eq('ar.object_id_prim', $qb->createNamedParameter($qb->prim($objectId))));

		return $this->fetchAll($qb);
	}

	/**
	 * Every relation between one actor and a *set* of others, keyed by the
	 * other's id.
	 *
	 * The single-pair version answers one account; a client asking about a page
	 * of forty paid forty round trips for it, and the same again for the follow
	 * rows and the notes. Same query, one `IN`.
	 *
	 * @param string[] $objectIds
	 *
	 * @return array<string, ActorRelation[]>
	 */
	public function getBetweenMany(string $actorId, array $objectIds): array {
		if ($objectIds === []) {
			return [];
		}

		$qb = $this->getActorRelationSelectSql();
		$prims = array_map(static fn (string $id): string => $qb->prim($id), $objectIds);
		$qb->andWhere($qb->expr()->eq('ar.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($qb->expr()->in('ar.object_id_prim', $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)));

		$relations = [];
		foreach ($this->fetchAll($qb) as $relation) {
			$relations[$relation->getObjectId()][] = $relation;
		}

		return $relations;
	}

	/**
	 * @return ActorRelation[]
	 */
	public function getByActor(string $actorId, string $type, int $limit = 40): array {
		$qb = $this->getActorRelationSelectSql();
		$qb->andWhere($qb->expr()->eq('ar.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($qb->expr()->eq('ar.type', $qb->createNamedParameter($type)));
		$qb->orderBy('ar.id', 'desc');
		$qb->setMaxResults($limit);

		return $this->fetchAll($qb);
	}

	/**
	 * The **local** accounts on the other end of a relation of that type — the
	 * subscribers to one account rather than what one account subscribes to.
	 *
	 * Local only, and that is a join rather than a filter: the table stores the
	 * subscriber as an md5 and nothing else, so the actor id has to be read
	 * from somewhere that holds it, and `social_actor` holds exactly the
	 * accounts worth reading. A remote subscriber is told by their own server
	 * from the same post, so there is nothing here to do for one.
	 *
	 * Bounded: an account with more subscribers than this is one where a
	 * notification fan-out is the wrong shape anyway, and walking an unbounded
	 * set on every post is worse than walking a bounded one.
	 *
	 * @return string[] actor ids
	 */
	public function getLocalByObject(string $objectId, string $type, int $limit = 500): array {
		$qb = $this->getQueryBuilder();
		$qb->select('a.id')
			->from(self::TABLE_ACTOR_RELATION, 'ar')
			->innerJoin('ar', self::TABLE_ACTORS, 'a', $qb->expr()->eq('a.id_prim', 'ar.actor_id_prim'))
			->where($qb->expr()->eq('ar.object_id_prim', $qb->createNamedParameter($qb->prim($objectId))))
			->andWhere($qb->expr()->eq('ar.type', $qb->createNamedParameter($type)))
			->orderBy('ar.id', 'desc')
			->setMaxResults($limit);

		$actors = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$actors[] = (string)$data['id'];
		}
		$cursor->closeCursor();

		return $actors;
	}

	/**
	 * Removes only the relations the actor holds over other accounts.
	 *
	 * What other accounts hold over *it* is theirs and outlives it being taken
	 * out of the timelines: a suspension can be lifted, and an account that
	 * came back into the timelines, notifications and follow pipeline of
	 * everybody who had blocked it would be a block silently undone by a
	 * moderator's decision about somebody else.
	 */
	public function deleteByActor(string $actorId): void {
		$qb = $this->getActorRelationDeleteSql();
		$qb->where(
			$qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
		);

		$qb->executeStatement();
	}

	/** Removes every relation owned by or targeting the actor (account deletion). */
	public function deleteRelatedId(string $actorId): void {
		$qb = $this->getActorRelationDeleteSql();
		$prim = $qb->prim($actorId);
		$qb->where(
			$qb->expr()->orX(
				$qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($prim)),
				$qb->expr()->eq('object_id_prim', $qb->createNamedParameter($prim))
			)
		);

		$qb->executeStatement();
	}

	/**
	 * @return ActorRelation[]
	 */
	private function fetchAll(SocialQueryBuilder $qb): array {
		$relations = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$relations[] = $this->parseActorRelationSelectSql($data);
		}
		$cursor->closeCursor();

		return $relations;
	}
}
