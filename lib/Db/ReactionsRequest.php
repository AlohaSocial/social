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
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Who reacted to what, and with which emoji.
 */
class ReactionsRequest extends ReactionsRequestBuilder {
	use TArrayTools;

	/**
	 * Records a reaction.
	 *
	 * A redelivery of one already stored is the ordinary case rather than an
	 * error — the Fediverse delivers the same activity more than once often
	 * enough — so the unique violation the database raises is swallowed and
	 * reported as `false`. It is caught around `executeStatement()` alone: a
	 * swallowed constraint violation aborts the surrounding transaction on
	 * PostgreSQL, so nothing else may be inside the `try`.
	 *
	 * @return bool whether this was new
	 */
	public function save(EmojiReact $reaction): bool {
		$qb = $this->getReactionsInsertSql();
		$qb->setValue('id', $qb->createNamedParameter($reaction->getId()))
			->setValue('id_prim', $qb->createNamedParameter($qb->prim($reaction->getId())))
			->setValue('actor_id', $qb->createNamedParameter($reaction->getActorId()))
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($reaction->getActorId())))
			->setValue('object_id', $qb->createNamedParameter($reaction->getObjectId()))
			->setValue('object_id_prim', $qb->createNamedParameter($qb->prim($reaction->getObjectId())))
			->setValue('emoji', $qb->createNamedParameter($reaction->getContent()))
			->setValue(
				'creation',
				$qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
			);

		try {
			$qb->executeStatement();
		} catch (DBException $e) {
			if ($e->getReason() === DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return false;
			}

			throw $e;
		}

		return true;
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
