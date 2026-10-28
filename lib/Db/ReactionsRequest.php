<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Model\ActivityPub\Object\EmojiReact;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Who reacted to what, and with which emoji.
 */
class ReactionsRequest extends ReactionsRequestBuilder {
	use TArrayTools;

	/**
	 * Inserts a reaction unless its identity or actor/post/emoji already exists.
	 * Skipping conflicts keeps redelivery harmless inside PostgreSQL transactions.
	 */
	public function save(EmojiReact $reaction): bool {
		$qb = $this->getQueryBuilder();

		return $this->dbConnection->insertIgnoreConflict(self::TABLE_REACTIONS, [
			'id' => $reaction->getId(),
			'id_prim' => $qb->prim($reaction->getId()),
			'actor_id' => $reaction->getActorId(),
			'actor_id_prim' => $qb->prim($reaction->getActorId()),
			'object_id' => $reaction->getObjectId(),
			'object_id_prim' => $qb->prim($reaction->getObjectId()),
			'emoji' => $reaction->getContent(),
			'creation' => (new DateTime('now'))->format('Y-m-d H:i:s'),
		]) > 0;
	}

	/**
	 * The reaction a URI names, for an `Undo` that names it by its id alone.
	 *
	 * @throws ActionDoesNotExistException
	 */
	public function getById(string $id): EmojiReact {
		$qb = $this->getReactionsSelectSql();
		$qb->andWhere($qb->expr()->eq('r.id_prim', $qb->createNamedParameter($qb->prim($id))));

		return $this->getReactionFromRequest($qb);
	}

	/**
	 * One account's reaction to one post with one emoji.
	 *
	 * @throws ActionDoesNotExistException
	 */
	public function getReaction(string $actorId, string $objectId, string $emoji): EmojiReact {
		$qb = $this->getReactionsSelectSql();
		$expr = $qb->expr();

		$qb->andWhere($expr->eq('r.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));
		$qb->andWhere($expr->eq('r.object_id_prim', $qb->createNamedParameter($qb->prim($objectId))));
		$qb->andWhere($expr->eq('r.emoji', $qb->createNamedParameter($emoji)));

		return $this->getReactionFromRequest($qb);
	}

	/**
	 * Every reaction on a post, newest last.
	 *
	 * @return EmojiReact[]
	 */
	public function getByObjectId(string $objectId, int $limit = 500): array {
		$qb = $this->getReactionsSelectSql();
		$qb->andWhere(
			$qb->expr()->eq('r.object_id_prim', $qb->createNamedParameter($qb->prim($objectId)))
		);
		$qb->orderBy('r.creation', 'asc');
		$qb->setMaxResults($limit);

		return $this->getReactionsFromRequest($qb);
	}

	/**
	 * The reactions on several posts at once, keyed by the post they are on.
	 *
	 * A timeline page draws a reaction bar per card, and one query for the
	 * page rather than one per card is the difference between a constant and
	 * a multiple of the page size.
	 *
	 * @param string[] $objectIds
	 * @return array<string, EmojiReact[]> keyed by the *prim* of the post
	 */
	public function getByObjectIds(array $objectIds, int $limit = 2000): array {
		if ($objectIds === []) {
			return [];
		}

		$qb = $this->getReactionsSelectSql();
		$qb->addSelect('r.object_id_prim');

		$prims = array_values(array_unique(array_map(
			static fn (string $id): string => md5($id),
			array_filter($objectIds, static fn (string $id): bool => $id !== '')
		)));
		if ($prims === []) {
			return [];
		}

		$qb->andWhere($qb->expr()->in(
			'r.object_id_prim',
			$qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)
		));
		$qb->orderBy('r.creation', 'asc');
		$qb->setMaxResults($limit);

		$byPost = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$reaction = new EmojiReact();
			$reaction->importFromDatabase($data);
			$byPost[(string)$data['object_id_prim']][] = $reaction;
		}
		$cursor->closeCursor();

		return $byPost;
	}

	/** Removes only the named reaction, leaving a newer use of the same emoji intact. */
	public function delete(EmojiReact $reaction): void {
		$this->deleteReaction(
			$reaction->getActorId(), $reaction->getObjectId(), $reaction->getContent(), $reaction->getId()
		);
	}

	public function deleteReaction(string $actorId, string $objectId, string $emoji, ?string $reactionId = null): void {
		$qb = $this->getReactionsDeleteSql();
		$expr = $qb->expr();

		$qb->where($expr->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));
		$qb->andWhere($expr->eq('object_id_prim', $qb->createNamedParameter($qb->prim($objectId))));
		$qb->andWhere($expr->eq('emoji', $qb->createNamedParameter($emoji)));
		if ($reactionId !== null) {
			$qb->andWhere($expr->eq('id_prim', $qb->createNamedParameter($qb->prim($reactionId))));
		}

		$qb->executeStatement();
	}

	/**
	 * Everything on a post, for when the post itself goes.
	 *
	 * A deleted post used to leave its likes and its hashtags behind; this
	 * table is not going to repeat that.
	 */
	public function deleteByObjectId(string $objectId): void {
		$qb = $this->getReactionsDeleteSql();
		$qb->where(
			$qb->expr()->eq('object_id_prim', $qb->createNamedParameter($qb->prim($objectId)))
		);

		$qb->executeStatement();
	}

	/** Everything by an account, for when the account goes. */
	public function deleteByActor(string $actorId): void {
		$qb = $this->getReactionsDeleteSql();
		$qb->where(
			$qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
		);

		$qb->executeStatement();
	}
}
