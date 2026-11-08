<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use DateTimeZone;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Model\ActivityPub\Object\EmojiReact;
use OCA\Social\Model\ActorRelation;
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
	 * How many accounts reacted to each of these posts with each emoji,
	 * counted by the database rather than read row by row, so a post with
	 * thousands of reactions is counted in full.
	 *
	 * Left out: reactions by the accounts in `$hiddenActorIds`, by accounts on
	 * the instances in `$hiddenHosts` (subdomains included) and, for a viewer,
	 * by accounts the viewer blocks, is blocked by or mutes. Each of those is
	 * an anti-join probe on the relation's unique index, not a list in the
	 * statement, so a viewer with a long block list costs no more.
	 *
	 * @param string[] $objectIds
	 * @param string $viewerId the reader's actor id, or '' for anonymous
	 * @param string[] $hiddenActorIds
	 * @param string[] $hiddenHosts lower-case hostnames
	 * @return array<string, array<string, int>> keyed by the *prim* of the post, then by emoji in
	 *                                           the order each was first used
	 */
	public function countByObjectIds(array $objectIds, string $viewerId = '', array $hiddenActorIds = [], array $hiddenHosts = [], ?int $now = null): array {
		$prims = self::prims($objectIds);
		if ($prims === []) {
			return [];
		}

		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$qb->select('r.object_id_prim', 'r.emoji')
			->selectAlias($qb->func()->count('*'), 'reactions')
			->selectAlias($qb->func()->min('r.creation'), 'first_at')
			->from(self::TABLE_REACTIONS, 'r')
			->where($expr->in('r.object_id_prim', $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($expr->neq('r.emoji', $qb->createNamedParameter('')))
			->groupBy('r.object_id_prim', 'r.emoji')
			->orderBy($qb->func()->min('r.creation'), 'ASC');

		$hiddenPrims = self::prims($hiddenActorIds);
		if ($hiddenPrims !== []) {
			$qb->andWhere($expr->notIn('r.actor_id_prim', $qb->createNamedParameter($hiddenPrims, IQueryBuilder::PARAM_STR_ARRAY)));
		}
		foreach (array_unique($hiddenHosts) as $host) {
			if ($host === '') {
				continue;
			}
			$escaped = $this->dbConnection->escapeLikeParameter($host);
			$qb->andWhere($expr->notLike($qb->func()->lower('r.actor_id'), $qb->createNamedParameter('%://' . $escaped . '/%')));
			$qb->andWhere($expr->notLike($qb->func()->lower('r.actor_id'), $qb->createNamedParameter('%.' . $escaped . '/%')));
		}
		if ($viewerId !== '') {
			$this->hideRelatedTo($qb, $viewerId, $now ?? time());
		}

		$rows = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$rows[] = [(string)$data['object_id_prim'], (string)$data['emoji'], (int)$data['reactions'], (string)$data['first_at']];
		}
		$cursor->closeCursor();

		// first reacted first; emoji used first in the same second are ordered
		// by their bytes here, since collations disagree about emoji (MySQL's
		// general ones weigh them all alike)
		usort($rows, static fn (array $a, array $b): int => strcmp($a[3], $b[3]) ?: strcmp($a[1], $b[1]));
		$counts = [];
		foreach ($rows as [$prim, $emoji, $count]) {
			$counts[$prim][$emoji] = $count;
		}

		return $counts;
	}

	/**
	 * The emoji one account reacted with to each of these posts.
	 *
	 * @param string[] $objectIds
	 * @return array<string, array<string, true>> keyed by the *prim* of the post, then by emoji
	 */
	public function emojisOf(string $actorId, array $objectIds): array {
		$prims = self::prims($objectIds);
		if ($actorId === '' || $prims === []) {
			return [];
		}

		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$qb->select('r.object_id_prim', 'r.emoji')
			->from(self::TABLE_REACTIONS, 'r')
			->where($expr->eq('r.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($expr->in('r.object_id_prim', $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)));

		$emojis = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$emojis[(string)$data['object_id_prim']][(string)$data['emoji']] = true;
		}
		$cursor->closeCursor();

		return $emojis;
	}

	/**
	 * Leaves out the reactions of accounts the viewer blocks, is blocked by,
	 * or mutes with a mute that has not run out.
	 */
	private function hideRelatedTo(SocialQueryBuilder $qb, string $viewerId, int $now): void {
		$expired = $this->getQueryBuilder();
		$expired->select($expired->createFunction('1'))
			->from(self::TABLE_MUTE_EXPIRY, 'rx')
			->where($expired->expr()->eq('rx.actor_id_prim', $qb->createNamedParameter($qb->prim($viewerId))))
			->andWhere($expired->expr()->eq('rx.object_id_prim', 'r.actor_id_prim'))
			->andWhere($expired->expr()->lte('rx.expires_at', $qb->createNamedParameter(
				(new DateTime('@' . $now))->setTimezone(new DateTimeZone(date_default_timezone_get())),
				IQueryBuilder::PARAM_DATE
			)));

		$relation = $this->getQueryBuilder();
		$expr = $relation->expr();
		$relation->select($relation->createFunction('1'))
			->from(self::TABLE_ACTOR_RELATION, 'rr')
			->where($expr->eq('rr.actor_id_prim', $qb->createNamedParameter($qb->prim($viewerId))))
			->andWhere($expr->eq('rr.object_id_prim', 'r.actor_id_prim'))
			->andWhere($expr->orX(
				$expr->in('rr.type', $qb->createNamedParameter(
					[ActorRelation::TYPE_BLOCK, ActorRelation::TYPE_BLOCKED_BY], IQueryBuilder::PARAM_STR_ARRAY
				)),
				$expr->andX(
					$expr->eq('rr.type', $qb->createNamedParameter(ActorRelation::TYPE_MUTE)),
					'NOT EXISTS (' . $expired->getSQL() . ')'
				)
			));

		$qb->andWhere('NOT EXISTS (' . $relation->getSQL() . ')');
	}

	/**
	 * @param string[] $ids
	 * @return list<string>
	 */
	private static function prims(array $ids): array {
		return array_values(array_unique(array_map(
			static fn (string $id): string => md5($id),
			array_filter($ids, static fn (string $id): bool => $id !== '')
		)));
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
