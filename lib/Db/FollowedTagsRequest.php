<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The hashtags an account follows.
 *
 * Reads and writes are keyed by the prim hash of the actor id, matching the
 * rest of the schema, and by the normalised tag — see normalise(), which is
 * the only place that decides what a tag *is* here.
 */
class FollowedTagsRequest extends FollowedTagsRequestBuilder {
	/**
	 * The width of `social_stream_tag.hashtag`, which is what a followed tag
	 * has to be comparable with.
	 */
	public const MAX_LENGTH = 127;

	/**
	 * A tag as this app stores one, from whatever the client sent.
	 *
	 * `social_stream_tag` holds what `Note::fillHashtags()` produced: the tag
	 * with its leading `#` removed and trimmed, at most
	 * `social_stream_tag.hashtag`'s 127 characters. It does *not* hold a
	 * lowercased tag — which is why `StreamRequest::getTimelineHashtag()`
	 * compares `LOWER()` to `LOWER()` — so a followed tag is stored lowercased
	 * and the stored side is lowered in the query. That also makes the unique
	 * index mean what a reader expects: following `#NextCloud` and
	 * `#nextcloud` is following one tag, as it is on Mastodon.
	 *
	 * Returns '' for anything that is not a tag; the caller answers 422 rather
	 * than storing a row no post can match.
	 */
	public static function normalise(string $hashtag): string {
		$tag = trim($hashtag);
		// a client may send the tag with or without its '#'; Mastodon accepts
		// both on these routes
		$tag = trim(ltrim($tag, '#'));
		$tag = mb_strtolower($tag, 'UTF-8');

		// characters, not bytes: the column is VARCHAR(127), and cutting a
		// multi-byte tag with substr() would store half a character
		return mb_substr($tag, 0, self::MAX_LENGTH, 'UTF-8');
	}

	/**
	 * Idempotent: following a tag that is already followed changes nothing and
	 * is not an error — the unique index on (actor, tag) is what says so.
	 */
	public function save(string $actorId, string $hashtag): void {
		$qb = $this->getFollowedTagsInsertSql();
		$qb->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
			->setValue('hashtag', $qb->createNamedParameter($hashtag))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		try {
			$qb->executeStatement();
		} catch (DBException $e) {
			if ($e->getReason() !== DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
		}
	}

	/** Unfollowing a tag that is not followed is not an error either. */
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
	 * How many tags the account follows.
	 *
	 * The home timeline asks this before it builds its second query: it is
	 * answered out of the `(actor_id_prim, hashtag)` index without reading the
	 * table, and for the account that follows no tag — which is most of them —
	 * it is the only thing the feature costs.
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
	 * One page of the account's followed tags, newest follow first.
	 *
	 * Paged on the row id, which is what the `Link` header of
	 * `/api/v1/followed_tags` hands back as its cursor.
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
	 * The hashtags somebody here follows, each once, the one whose posts were
	 * read from beyond this server longest ago first — never read first of
	 * all — for `Service\Discovery\FollowedTagsFill`.
	 *
	 * A tag followed by several accounts is several rows, stamped together by
	 * markFilled(); a row added since carries 0, so the latest stamp is when
	 * the tag was read.
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
