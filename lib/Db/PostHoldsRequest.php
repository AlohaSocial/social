<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\HeldPost;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The posts waiting for a moderator.
 *
 * Two kinds of reader ask here and they are scoped differently on purpose.
 * An **author** may only ever reach their own rows, so every method they can
 * reach takes an actor id and compares it in SQL — a held post is unpublished
 * text nobody has seen, and the only person entitled to it is whoever wrote
 * it. A **moderator** reads the queue unscoped, because reading it is the
 * whole of their job; who is allowed to be that moderator is decided above,
 * in `AdminApiService`, not here.
 */
class PostHoldsRequest extends PostHoldsRequestBuilder {
	/**
	 * Holds one post.
	 *
	 * A repeat is not an error. A client that has been told its post is
	 * waiting will be pressed again by its user, and the Pixelfed app retries
	 * a 422 on its own; the unique index on the digest is what makes the
	 * second attempt find the first rather than give a moderator the same post
	 * twice.
	 *
	 * @return int the id it was stored under, or 0 when it was already waiting
	 */
	public function save(HeldPost $held): int {
		$qb = $this->getPostHoldInsertSql();
		$qb->setValue('actor_id', $qb->createNamedParameter($held->getActorId()))
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($held->getActorId())))
			->setValue('params', $qb->createNamedParameter($held->exportParams()))
			->setValue('reason', $qb->createNamedParameter($held->getReason()))
			->setValue('digest', $qb->createNamedParameter($held->digest()))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		if (!$this->insertUnlessDuplicate($qb)) {
			return 0;
		}

		$id = $qb->getLastInsertId();
		$held->setId($id);
		if ($held->getCreation() === 0) {
			$held->setCreation(time());
		}

		return $id;
	}

	/**
	 * One held post, whoever wrote it. For a moderator.
	 *
	 * @throws ItemNotFoundException
	 */
	public function getById(int $id): HeldPost {
		$qb = $this->getPostHoldSelectSql();
		$qb->andWhere($qb->expr()->eq('ph.id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->one($qb);
	}

	/**
	 * One held post of one account.
	 *
	 * @throws ItemNotFoundException there is no such row *of this account*,
	 *                               which is also the answer for one that
	 *                               belongs to somebody else
	 */
	public function getByIdForActor(int $id, string $actorId): HeldPost {
		$qb = $this->getPostHoldSelectSql();
		$qb->andWhere($qb->expr()->eq('ph.id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('ph.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		return $this->one($qb);
	}

	/**
	 * A page of the queue, oldest first: the order it should be drained in,
	 * and the order somebody who has waited longest is served in.
	 *
	 * @return HeldPost[]
	 */
	public function page(int $limit = 50, int $offset = 0): array {
		$qb = $this->getPostHoldSelectSql();
		$qb->orderBy('ph.id', 'asc')
			->setMaxResults($limit)
			->setFirstResult($offset);

		return $this->all($qb);
	}

	/**
	 * One account's own held posts, newest first — the order an author looks
	 * for the post they have just written in.
	 *
	 * @return HeldPost[]
	 */
	public function getByActor(string $actorId, int $limit = 50): array {
		$qb = $this->getPostHoldSelectSql();
		$qb->andWhere($qb->expr()->eq('ph.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->orderBy('ph.id', 'desc')
			->setMaxResults($limit);

		return $this->all($qb);
	}

	/** How many posts are waiting, all told. */
	public function countAll(): int {
		return $this->count(null);
	}

	/** How many of them are this account's, which is what the cap is on. */
	public function countForActor(string $actorId): int {
		return $this->count($actorId);
	}

	/** Lets one go, approved or refused; both are the same row leaving. */
	public function delete(int $id): bool {
		$qb = $this->getPostHoldDeleteSql();
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement() > 0;
	}

	/**
	 * Forgets what one account had waiting.
	 *
	 * Called when the account is purged: a suspended account's unpublished
	 * posts are not going to be approved by anybody, and leaving them would
	 * leave a moderator a queue of decisions that cannot be taken.
	 */
	public function deleteByActor(string $actorId): void {
		$qb = $this->getPostHoldDeleteSql();
		$qb->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$qb->executeStatement();
	}

	private function count(?string $actorId): int {
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('*'), 'total')
			->from(self::TABLE_POST_HOLD, 'ph');

		if ($actorId !== null) {
			$qb->where($qb->expr()->eq('ph.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));
		}

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($data['total'] ?? 0);
	}

	/** @throws ItemNotFoundException */
	private function one(SocialQueryBuilder $qb): HeldPost {
		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new ItemNotFoundException('no such held post');
		}

		return $this->parsePostHoldSelectSql($data);
	}

	/** @return HeldPost[] */
	private function all(SocialQueryBuilder $qb): array {
		$held = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$held[] = $this->parsePostHoldSelectSql($data);
		}
		$cursor->closeCursor();

		return $held;
	}
}
