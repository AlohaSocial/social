<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The profile directory and the suggestions of whom to follow, read from
 * data the app already keeps; nothing is scored. A suggestion is somebody
 * the people you follow follow, by how many do; the directory is everybody
 * who opted in, by how recently they posted.
 *
 * Activity is ordered by `MAX(s.nid)`, not `MAX(s.published_time)`: nid rises
 * with insertion, and `COALESCE(…, 0)` sorts never-posted accounts last on
 * every platform, where a NULL date sorts differently on PostgreSQL and MySQL.
 */
class DiscoveryRequest extends DiscoveryRequestBuilder {
	/** Newest account first. */
	public const ORDER_NEW = 'new';
	/** Most recently posted first, which is Mastodon's default. */
	public const ORDER_ACTIVE = 'active';

	/**
	 * The page of the directory, as `id_prim`s in display order; only accounts
	 * that opted in.
	 *
	 * @return string[]
	 */
	public function directoryPrims(string $order, int $limit, int $offset): array {
		$qb = $this->getDiscoverablePrimsSelectSql();

		if ($order === self::ORDER_NEW) {
			$qb->orderBy('a.creation', 'desc');
		} else {
			$this->orderByLastStatus($qb, 'a.id_prim');
			$qb->groupBy('a.id_prim');
		}

		$qb->setMaxResults($limit);
		$qb->setFirstResult($offset);

		return $this->primsFromRequest($qb, 'id_prim');
	}

	/**
	 * The accounts followed by the accounts the viewer follows, by how many of
	 * them do. The viewer and their own follows are excluded in the statement, so
	 * they take no slot in the page.
	 *
	 * @return string[]
	 */
	public function friendsOfFriendsPrims(string $viewerId, int $limit): array {
		$qb = $this->getFollowedPrimsSelectSql('f2');
		$expr = $qb->expr();
		$viewerPrim = $qb->prim($viewerId);

		$qb->innerJoin(
			'f2', self::TABLE_FOLLOWS, 'f1',
			$expr->andX(
				$expr->eq('f1.object_id_prim', 'f2.actor_id_prim'),
				$expr->eq('f1.actor_id_prim', $qb->createNamedParameter($viewerPrim)),
				$expr->eq('f1.accepted', $qb->createNamedParameter(1))
			)
		);

		// not yourself, and not the people you already follow
		$qb->andWhere($expr->neq('f2.object_id_prim', $qb->createNamedParameter($viewerPrim)));
		$qb->andWhere(
			$expr->notIn(
				'f2.object_id_prim',
				$qb->createNamedParameter(
					$this->followedPrims($viewerId) ?: [''],
					IQueryBuilder::PARAM_STR_ARRAY
				)
			)
		);

		$qb->selectAlias($qb->createFunction('COUNT(f2.object_id_prim)'), 'mutuals');
		$qb->groupBy('f2.object_id_prim');
		$qb->orderBy('mutuals', 'desc');
		$qb->setMaxResults($limit);

		return $this->primsFromRequest($qb, 'object_id_prim');
	}

	/**
	 * Local accounts that opted in to being listed, most recently active first:
	 * the fallback when the follow graph suggests nobody, as for a new account.
	 *
	 * @return string[]
	 */
	public function activeLocalPrims(int $limit): array {
		return $this->directoryPrims(self::ORDER_ACTIVE, $limit, 0);
	}

	/**
	 * The accounts the viewer follows, pending requests included, since this
	 * list excludes.
	 *
	 * @return string[]
	 */
	public function followedPrims(string $viewerId): array {
		$qb = $this->getQueryBuilder();
		$qb->select('f.object_id_prim')
			->from(self::TABLE_FOLLOWS, 'f');
		$qb->andWhere(
			$qb->expr()->eq('f.actor_id_prim', $qb->createNamedParameter($qb->prim($viewerId)))
		);

		return $this->primsFromRequest($qb, 'object_id_prim');
	}

	/**
	 * The accounts the viewer has blocked or muted, and the accounts that have
	 * blocked the viewer: none of them may be suggested.
	 *
	 * @param string[] $types ActorRelation::TYPE_* values
	 *
	 * @return string[]
	 */
	public function relatedPrims(string $viewerId, array $types): array {
		if ($types === []) {
			return [];
		}

		$qb = $this->getQueryBuilder();
		$qb->select('ar.object_id_prim')
			->from(self::TABLE_ACTOR_RELATION, 'ar');
		$qb->andWhere(
			$qb->expr()->eq('ar.actor_id_prim', $qb->createNamedParameter($qb->prim($viewerId)))
		);
		$qb->andWhere(
			$qb->expr()->in('ar.type', $qb->createNamedParameter($types, IQueryBuilder::PARAM_STR_ARRAY))
		);

		return $this->primsFromRequest($qb, 'object_id_prim');
	}

	/**
	 * The Account entities of accounts already decided on, in that order,
	 * restored here since `IN` keeps no order portably. An account without a
	 * cache row drops out.
	 *
	 * @param string[] $prims
	 *
	 * @return Person[]
	 */
	public function actorsByPrims(array $prims): array {
		if ($prims === []) {
			return [];
		}

		$byPrim = [];
		foreach ($this->fetchActors($prims) as $actor) {
			$byPrim[md5($actor->getId())] = $actor;
		}

		$actors = [];
		foreach ($prims as $prim) {
			if (isset($byPrim[$prim])) {
				$actor = $byPrim[$prim];
				$actor->setExportFormat(Stream::FORMAT_LOCAL);
				$actors[] = $actor;
			}
		}

		return $actors;
	}

	/**
	 * The cached profiles of a set of accounts, in whatever order the database
	 * returns them.
	 *
	 * @param string[] $prims
	 *
	 * @return Person[]
	 */
	protected function fetchActors(array $prims): array {
		$qb = $this->getDiscoveryActorsSelectSql();
		$qb->andWhere(
			$qb->expr()->in('ca.id_prim', $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY))
		);

		return $this->getCacheActorsFromRequest($qb);
	}

	/**
	 * Orders a statement by when the account last posted in public, newest
	 * first, accounts that never have at the end; a left join, so they stay.
	 */
	private function orderByLastStatus(SocialQueryBuilder $qb, string $actorColumn): void {
		$expr = $qb->expr();
		$qb->leftJoin(
			'a', self::TABLE_STREAM, 's',
			$expr->andX(
				$expr->eq('s.attributed_to_prim', $actorColumn),
				$expr->eq('s.visibility', $qb->createNamedParameter(Stream::TYPE_PUBLIC))
			)
		);

		$qb->selectAlias($qb->createFunction('COALESCE(MAX(s.nid), 0)'), 'last_status');
		$qb->orderBy('last_status', 'desc');
	}

	/**
	 * @return string[] the named column of every row, blanks dropped
	 */
	private function primsFromRequest(SocialQueryBuilder $qb, string $column): array {
		$prims = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$prim = (string)($data[$column] ?? '');
			if ($prim !== '') {
				$prims[] = $prim;
			}
		}
		$cursor->closeCursor();

		return $prims;
	}
}
