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
 * The feeds somebody follows, and the entries read out of them.
 *
 * Owned by a Nextcloud user rather than by an actor: reading a blog needs no
 * fediverse identity, and somebody who has not finished the setup screen can
 * still follow one.
 */
class FeedsRequest extends CoreRequestBuilder {
	/** @return array<array<string, mixed>> one row per feed, newest first */
	public function feedsOf(string $userId): array {
		$qb = $this->getQueryBuilder();
		$qb->select('f.id', 'f.url', 'f.site_url', 'f.title', 'f.error', 'f.fetched_at')
			->from(self::TABLE_FEEDS, 'f')
			->where($qb->expr()->eq('f.user_id', $qb->createNamedParameter($userId)))
			->orderBy('f.id', 'desc');

		$rows = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$rows[] = $row;
		}
		$cursor->closeCursor();

		return $rows;
	}

	/** How many entries each of those feeds holds, by feed id. */
	public function countsFor(array $feedIds): array {
		if ($feedIds === []) {
			return [];
		}

		$qb = $this->getQueryBuilder();
		$qb->select('i.feed_id')
			->selectAlias($qb->func()->count('i.id'), 'entries')
			->from(self::TABLE_FEED_ITEMS, 'i')
			->where($qb->expr()->in('i.feed_id', $qb->createNamedParameter($feedIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->groupBy('i.feed_id');

		$counts = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$counts[(int)$row['feed_id']] = (int)$row['entries'];
		}
		$cursor->closeCursor();

		return $counts;
	}

	/** One feed of one reader, or null: the ownership check every write makes. */
	public function feedOf(string $userId, int $id): ?array {
		$qb = $this->getQueryBuilder();
		$qb->select('id', 'url', 'site_url', 'title', 'etag', 'modified_at')
			->from(self::TABLE_FEEDS)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return ($row === false) ? null : $row;
	}

	/** The id this reader already follows that address under, or 0. */
	public function idOf(string $userId, string $url): int {
		$qb = $this->getQueryBuilder();
		$qb->select('id')
			->from(self::TABLE_FEEDS)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('url_prim', $qb->createNamedParameter(md5($url))));

		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return ($row === false) ? 0 : (int)$row['id'];
	}

	public function create(string $userId, string $url, string $title, string $siteUrl): int {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_FEEDS)
			->setValue('user_id', $qb->createNamedParameter($userId))
			->setValue('url', $qb->createNamedParameter($url))
			->setValue('url_prim', $qb->createNamedParameter(md5($url)))
			->setValue('site_url', $qb->createNamedParameter($siteUrl))
			->setValue('title', $qb->createNamedParameter(mb_substr($title, 0, 255)))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));
		$qb->executeStatement();

		return $qb->getLastInsertId();
	}

	/** What the last read produced, so a feed that has gone can say so. */
	public function recordRead(int $id, string $title, string $siteUrl, string $etag, string $modified, string $error): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_FEEDS)
			->set('error', $qb->createNamedParameter(mb_substr($error, 0, 255)))
			->set('etag', $qb->createNamedParameter(mb_substr($etag, 0, 255)))
			->set('modified_at', $qb->createNamedParameter(mb_substr($modified, 0, 64)))
			->set('fetched_at', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		if ($title !== '') {
			$qb->set('title', $qb->createNamedParameter(mb_substr($title, 0, 255)));
		}
		if ($siteUrl !== '') {
			$qb->set('site_url', $qb->createNamedParameter($siteUrl));
		}

		$qb->executeStatement();
	}

	/**
	 * Adds an entry, unless this feed already holds it.
	 *
	 * The unique index on (feed, guid) is what makes that true rather than
	 * this check: two runs of the cron can overlap, and the read below would
	 * be stale by the time the insert happened. The check is here so the
	 * common case does not rely on a caught exception.
	 *
	 * @return bool whether the entry was new
	 */
	public function addItem(int $feedId, array $item): bool {
		$guid = md5((string)$item['guid']);
		$qb = $this->getQueryBuilder();
		$qb->select('id')
			->from(self::TABLE_FEED_ITEMS)
			->where($qb->expr()->eq('feed_id', $qb->createNamedParameter($feedId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('guid_prim', $qb->createNamedParameter($guid)));
		$cursor = $qb->executeQuery();
		$seen = $cursor->fetch();
		$cursor->closeCursor();

		if ($seen !== false) {
			return false;
		}

		$published = ((string)$item['published'] !== '')
			? new DateTime((string)$item['published'])
			: new DateTime('now');

		$insert = $this->getQueryBuilder();
		$insert->insert(self::TABLE_FEED_ITEMS)
			->setValue('feed_id', $insert->createNamedParameter($feedId, IQueryBuilder::PARAM_INT))
			->setValue('guid_prim', $insert->createNamedParameter($guid))
			->setValue('link', $insert->createNamedParameter((string)$item['link']))
			->setValue('title', $insert->createNamedParameter(mb_substr((string)$item['title'], 0, 512)))
			->setValue('summary', $insert->createNamedParameter((string)$item['summary']))
			->setValue('thumbnail', $insert->createNamedParameter((string)$item['thumbnail']))
			->setValue('published', $insert->createNamedParameter($published, IQueryBuilder::PARAM_DATE));

		try {
			$insert->executeStatement();
		} catch (\Throwable) {
			// the unique index refused it: somebody else wrote it first, which
			// is the answer this was asking for
			return false;
		}

		return true;
	}

	/**
	 * A page of what the reader's feeds have published, newest first.
	 *
	 * Ordered and paged by publication time, with the row id as a stable
	 * tie-breaker when feeds publish at the same second.
	 */
	public function timelineOf(string $userId, int $limit, int $maxId, string $beforePublished = '', int $beforeId = 0): array {
		// `max_id` is the cursor existing clients already know. Resolve it to
		// the same publication-time cursor as the newer `before` parameters;
		// insertion order and publication order are not interchangeable when
		// a feed republishes old entries or adds several at once.
		if ($beforePublished === '' && $maxId > 0) {
			$lookup = $this->getQueryBuilder();
			$lookup->select('i.published')
				->from(self::TABLE_FEED_ITEMS, 'i')
				->innerJoin('i', self::TABLE_FEEDS, 'f', $lookup->expr()->eq('i.feed_id', 'f.id'))
				->where($lookup->expr()->eq('i.id', $lookup->createNamedParameter($maxId, IQueryBuilder::PARAM_INT)))
				->andWhere($lookup->expr()->eq('f.user_id', $lookup->createNamedParameter($userId)));
			$cursor = $lookup->executeQuery();
			$row = $cursor->fetch();
			$cursor->closeCursor();

			if ($row !== false && is_string($row['published'] ?? null) && $row['published'] !== '') {
				$beforePublished = $row['published'];
				$beforeId = $maxId;
			}
		}

		$qb = $this->getQueryBuilder();
		$qb->select('i.id', 'i.link', 'i.title', 'i.summary', 'i.thumbnail', 'i.published')
			->selectAlias('f.title', 'feed_title')
			->from(self::TABLE_FEED_ITEMS, 'i')
			->innerJoin('i', self::TABLE_FEEDS, 'f', $qb->expr()->eq('i.feed_id', 'f.id'))
			->where($qb->expr()->eq('f.user_id', $qb->createNamedParameter($userId)))
			->orderBy('i.published', 'desc')
			->addOrderBy('i.id', 'desc')
			->setMaxResults($limit);

		if ($beforePublished !== '' && $beforeId > 0) {
			$cursorDate = new DateTime($beforePublished);
			$publishedBefore = $qb->createNamedParameter($cursorDate, IQueryBuilder::PARAM_DATE);
			$publishedEqual = $qb->createNamedParameter($cursorDate, IQueryBuilder::PARAM_DATE);
			$entryBefore = $qb->createNamedParameter($beforeId, IQueryBuilder::PARAM_INT);
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->lt('i.published', $publishedBefore),
				$qb->expr()->andX(
					$qb->expr()->eq('i.published', $publishedEqual),
					$qb->expr()->lt('i.id', $entryBefore)
				)
			));
		} elseif ($maxId > 0) {
			// Keep the historical behavior if the cursor row has already been
			// removed, or does not belong to this reader's subscriptions.
			$qb->andWhere($qb->expr()->lt('i.id', $qb->createNamedParameter($maxId, IQueryBuilder::PARAM_INT)));
		}

		$rows = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$rows[] = $row;
		}
		$cursor->closeCursor();

		return $rows;
	}

	/** Unfollowing takes the entries with it: they were only ever that feed's. */
	public function delete(string $userId, int $id): bool {
		if ($this->feedOf($userId, $id) === null) {
			return false;
		}

		$items = $this->getQueryBuilder();
		$items->delete(self::TABLE_FEED_ITEMS)
			->where($items->expr()->eq('feed_id', $items->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$items->executeStatement();

		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_FEEDS)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();

		return true;
	}

	/**
	 * Drops what a feed holds beyond its newest `$keep` entries, and anything
	 * published before `$before`, whichever reaches further.
	 *
	 * **The newest `$keep` are kept whatever their age.** A blog that stopped
	 * posting years ago is still a blog somebody chose to follow, and the age
	 * cut-off on its own emptied it: every entry was older than `$before`, so
	 * the page showed nothing and said nothing about why. The cut-off is there
	 * to bound what one feed may store, and below `$keep` entries there is
	 * nothing to bound.
	 *
	 * Both halves read `social_feeditem_fp` (feed, published): the cut-off is
	 * the `$keep`-th newest date, found with an offset into that index, and
	 * the delete is a range on it.
	 *
	 * @return int how many entries were removed
	 */
	public function prune(int $feedId, int $keep, int $before): int {
		$cutoff = new DateTime('@' . $before);

		$nth = $this->getQueryBuilder();
		$nth->select('published')
			->from(self::TABLE_FEED_ITEMS)
			->where($nth->expr()->eq('feed_id', $nth->createNamedParameter($feedId, IQueryBuilder::PARAM_INT)))
			->orderBy('published', 'desc')
			->setFirstResult(max(0, $keep - 1))
			->setMaxResults(1);
		$cursor = $nth->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		if ($row === false) {
			// fewer than `$keep` entries: there is nothing beyond the newest
			// `$keep`, so there is nothing to drop
			return 0;
		}

		if (is_string($row['published'] ?? null) && $row['published'] !== '') {
			$oldestKept = new DateTime($row['published']);
			if ($oldestKept > $cutoff) {
				$cutoff = $oldestKept;
			}
		}

		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_FEED_ITEMS)
			->where($qb->expr()->eq('feed_id', $qb->createNamedParameter($feedId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('published', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_DATE)));

		return $qb->executeStatement();
	}

	/**
	 * The feeds due a re-read. What the cron walks.
	 *
	 * The ones never read come first — a feed somebody has just followed is
	 * stored unread and waits for this — then the rest, stalest first. Two
	 * queries rather than one `ORDER BY fetched_at`, because where NULL sorts
	 * is not portable: first on MySQL and SQLite, last on PostgreSQL, where a
	 * new subscription would wait behind every stale one.
	 */
	public function due(int $limit, int $olderThan): array {
		$fresh = $this->getQueryBuilder();
		$fresh->select('id', 'url', 'etag', 'modified_at')
			->from(self::TABLE_FEEDS)
			->where($fresh->expr()->isNull('fetched_at'))
			->orderBy('id', 'asc')
			->setMaxResults($limit);
		$rows = $this->rowsOf($fresh);

		if (count($rows) >= $limit) {
			return $rows;
		}

		$qb = $this->getQueryBuilder();
		$qb->select('id', 'url', 'etag', 'modified_at')
			->from(self::TABLE_FEEDS)
			->where($qb->expr()->lt(
				'fetched_at',
				$qb->createNamedParameter(new DateTime('@' . $olderThan), IQueryBuilder::PARAM_DATE)
			))
			->orderBy('fetched_at', 'asc')
			->setMaxResults($limit - count($rows));

		return array_merge($rows, $this->rowsOf($qb));
	}

	/** @return array<array<string, mixed>> */
	private function rowsOf(SocialQueryBuilder $qb): array {
		$rows = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$rows[] = $row;
		}
		$cursor->closeCursor();

		return $rows;
	}
}
