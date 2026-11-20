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
 * The hashtags an account follows, keyed by the actor's prim and the tag as
 * normalise() makes it.
 */
class FollowedTagsRequest extends FollowedTagsRequestBuilder {
	/**
	 * The width of `social_stream_tag.hashtag`, which is what a followed tag
	 * has to be comparable with.
	 */
	public const MAX_LENGTH = 127;

	/**
	 * A tag as this app stores one: without `#`, trimmed, lowercased and at most
	 * `social_stream_tag.hashtag`'s 127 characters. Stream tags are not
	 * lowercased, so the query lowers that side; following `#NextCloud` and
	 * `#nextcloud` is one tag, as on Mastodon. '' for anything that is not a tag.
	 */
	public static function normalise(string $hashtag): string {
		$tag = trim($hashtag);
		// Mastodon accepts the tag with or without its '#'.
		$tag = trim(ltrim($tag, '#'));
		$tag = mb_strtolower($tag, 'UTF-8');

		// Characters, not bytes: the column is VARCHAR(127).
		return mb_substr($tag, 0, self::MAX_LENGTH, 'UTF-8');
	}

	/**
	 * Idempotent through the unique index on (actor, tag).
	 */
	public function save(string $actorId, string $hashtag): void {
		$qb = $this->getFollowedTagsInsertSql();
		$qb->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
			->setValue('hashtag', $qb->createNamedParameter($hashtag))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		$this->insertUnlessDuplicate($qb);
	}

	/** Unfollowing a tag that is not followed is not an error either. */
	/** Every hashtag one account follows. */
	public function deleteByActor(string $actorId): void {
		$qb = $this->getFollowedTagsDeleteSql();
		$qb->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$qb->executeStatement();
	}

	public function delete(string $actorId, string $hashtag): void {
		$qb = $this->getFollowedTagsDeleteSql();
		$qb->where(
			$qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))),
			$qb->expr()->eq('hashtag', $qb->createNamedParameter($hashtag))
		);

		$qb->executeStatement();
	}

	public function isFollowing(string $actorId, string $hashtag): bool {
		if ($hashtag === '') {
			return false;
		}

		$qb = $this->getFollowedTagsSelectSql();
		$qb->andWhere($qb->expr()->eq('ft.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($qb->expr()->eq('ft.hashtag', $qb->createNamedParameter($hashtag)));
		$qb->setMaxResults(1);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $data !== false;
	}

	/**
	 * How many tags the account follows, answered from the
	 * `(actor_id_prim, hashtag)` index; the home timeline asks before building
	 * its second query.
	 */
	public function countByActor(string $actorId): int {
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('*'), 'count')
			->from(self::TABLE_FOLLOWED_TAGS, 'ft');
		$qb->where($qb->expr()->eq('ft.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return ($data === false) ? 0 : (int)$data['count'];
	}

	/**
	 * One page of the account's followed tags, newest follow first, paged on the
	 * row id (the `/api/v1/followed_tags` cursor).
	 *
	 * @return array<array{id: int, hashtag: string, creation: int}>
	 */
	public function getByActor(string $actorId, int $limit, int|string $maxId = '0', int|string $minId = 0): array {
		$qb = $this->getFollowedTagsSelectSql();
		$expr = $qb->expr();
		$qb->andWhere($expr->eq('ft.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		if ($maxId > 0) {
			$qb->andWhere($expr->lt('ft.id', $qb->createNamedParameter($maxId, IQueryBuilder::PARAM_INT)));
		}
		if ($minId > 0) {
			$qb->andWhere($expr->gt('ft.id', $qb->createNamedParameter($minId, IQueryBuilder::PARAM_INT)));
		}

		$qb->orderBy('ft.id', 'desc');
		$qb->setMaxResults(max(1, $limit));

		$tags = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$tags[] = $this->parseFollowedTagsSelectSql($data);
		}
		$cursor->closeCursor();

		return $tags;
	}

	/**
	 * The hashtags somebody here follows, each once, least recently read from
	 * beyond this server first (never read first of all), for
	 * `Service\Discovery\FollowedTagsFill`. A row added since the last
	 * markFilled() carries 0, so the latest stamp per tag counts.
	 *
	 * @return list<array{hashtag: string, filled: int}>
	 */
	public function dueForFill(int $limit): array {
		$qb = $this->getQueryBuilder();
		$qb->select('hashtag')->selectAlias($qb->func()->max('filled'), 'last_filled')
			->from(self::TABLE_FOLLOWED_TAGS)
			->groupBy('hashtag')
			->orderBy('last_filled', 'asc')
			->addOrderBy('hashtag', 'asc')
			->setMaxResults(max(1, $limit));

		$tags = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$tags[] = ['hashtag' => (string)$data['hashtag'], 'filled' => (int)$data['last_filled']];
		}
		$cursor->closeCursor();

		return $tags;
	}

	/** Records that the posts with a followed hashtag were read at `$time`. */
	public function markFilled(string $hashtag, int $time): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_FOLLOWED_TAGS)
			->set('filled', $qb->createNamedParameter($time, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('hashtag', $qb->createNamedParameter($hashtag)));

		$qb->executeStatement();
	}
}
