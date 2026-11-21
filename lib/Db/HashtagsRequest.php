<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Exceptions\HashtagDoesNotExistException;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\DB\QueryBuilder\IQueryBuilder;

class HashtagsRequest extends HashtagsRequestBuilder {
	/** The most rows a hashtag search will read, whatever it was asked for. */
	public const SEARCH_LIMIT = 25;

	use TArrayTools;

	/**
	 * The trend window each sortable counter column holds. The same counts are
	 * in the `trend` JSON column the API returns; these let the database
	 * order and cut the list.
	 */
	public const TREND_COLUMNS = [
		'1h' => 'trend_1h',
		'12h' => 'trend_12h',
		'1d' => 'trend_1d',
		'3d' => 'trend_3d',
		'10d' => 'trend_10d',
	];

	/**
	 * How long a hashtag this table holds, as in `social_stream_tag`. A longer
	 * one cannot be stored: `hashtag` is the primary key, so truncating would
	 * merge tags.
	 */
	public const HASHTAG_MAX_LENGTH = 127;

	/**
	 * Writes one hashtag's trend, whether or not it already has a row. The
	 * UPDATE goes first, since most hashtags were seen before, then an insert
	 * the database skips on conflict, so an unknown row costs a statement
	 * rather than a failed transaction (fatal on PostgreSQL).
	 */
	public function upsert(string $hashtag, array $trend): void {
		if ($this->update($hashtag, $trend) > 0) {
			return;
		}

		$values = [
			'hashtag' => $hashtag,
			'trend' => json_encode($trend),
		];
		foreach (self::TREND_COLUMNS as $period => $column) {
			$values[$column] = (int)($trend[$period] ?? 0);
		}

		$this->dbConnection->insertIgnoreConflict(self::TABLE_HASHTAGS, $values);
	}

	/**
	 * Insert a new Hashtag.
	 *
	 * @param string $hashtag
	 * @param array $trend
	 */
	public function save(string $hashtag, array $trend) {
		$qb = $this->getHashtagsInsertSql();
		$qb->setValue('hashtag', $qb->createNamedParameter($hashtag))
			->setValue('trend', $qb->createNamedParameter(json_encode($trend)));
		foreach (self::TREND_COLUMNS as $period => $column) {
			$qb->setValue($column, $qb->createNamedParameter(
				(int)($trend[$period] ?? 0), IQueryBuilder::PARAM_INT
			));
		}

		$qb->executeStatement();
	}

	/**
	 * Rewrites one hashtag's trend, if it has a row.
	 *
	 * @return int how many rows were written — 0 when there is no such
	 *             hashtag, and on MySQL also when the row already held these
	 *             counts
	 */
	public function update(string $hashtag, array $trend): int {
		$qb = $this->getHashtagsUpdateSql();
		$qb->set('trend', $qb->createNamedParameter(json_encode($trend)));
		foreach (self::TREND_COLUMNS as $period => $column) {
			$qb->set($column, $qb->createNamedParameter(
				(int)($trend[$period] ?? 0), IQueryBuilder::PARAM_INT
			));
		}
		$qb->limitToHashtag($hashtag);

		return $qb->executeStatement();
	}

	/**
	 * Rows carry a `counters` entry next to the JSON `trend`: the sortable
	 * columns' numbers, so the trends cron can see the two disagree.
	 *
	 * @param int $limit 0 for every row — what the trends cron needs, since it
	 *                   has to know which hashtags already have a row
	 *
	 * @return array
	 */
	public function getAll(int $limit = 0): array {
		$qb = $this->getHashtagsSelectSql();
		foreach (self::TREND_COLUMNS as $column) {
			$qb->addSelect('h.' . $column);
		}
		if ($limit > 0) {
			$qb->setMaxResults($limit);
		}

		$hashtags = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$hashtags[] = array_merge(
				$this->parseHashtagsSelectSql($data),
				['counters' => self::countersFromRow($data)]
			);
		}
		$cursor->closeCursor();

		return $hashtags;
	}

	/**
	 * The hashtags that currently claim a trend, the only rows the trends cron
	 * can act on; a small set, the trending list.
	 *
	 * @return array
	 */
	public function getWithAnyTrend(int $limit = 0): array {
		$qb = $this->getHashtagsSelectSql();
		foreach (self::TREND_COLUMNS as $column) {
			$qb->addSelect('h.' . $column);
		}

		// built first and passed in one go: an empty orX() is deprecated and
		// will throw
		$windows = [];
		foreach (self::TREND_COLUMNS as $column) {
			$windows[] = $qb->expr()->gt('h.' . $column, $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT));
		}
		$qb->andWhere($qb->expr()->orX(...$windows));

		if ($limit > 0) {
			$qb->setMaxResults($limit);
		}

		$hashtags = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$hashtags[] = array_merge(
				$this->parseHashtagsSelectSql($data),
				['counters' => self::countersFromRow($data)]
			);
		}
		$cursor->closeCursor();

		return $hashtags;
	}

	/**
	 * The sortable counters of one row, keyed by window.
	 *
	 * @return array<string, int>
	 */
	public static function countersFromRow(array $data): array {
		$counters = [];
		foreach (self::TREND_COLUMNS as $period => $column) {
			$counters[$period] = (int)($data[$column] ?? 0);
		}

		return $counters;
	}

	/**
	 * The most used hashtags within one window, most used first, ordered and
	 * cut in the database on the sortable column.
	 *
	 * @param string $period one of the windows in self::TREND_COLUMNS
	 *
	 * @return array[] [['hashtag' => string, 'trend' => array], …]
	 */
	public function getTrending(string $period, int $limit): array {
		$column = self::TREND_COLUMNS[$period] ?? null;
		if ($column === null) {
			return [];
		}

		$qb = $this->getHashtagsSelectSql();
		$qb->andWhere($qb->expr()->gt(
			'h.' . $column, $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)
		));
		$qb->orderBy('h.' . $column, 'desc');
		$qb->addOrderBy('h.hashtag', 'asc');
		$qb->setMaxResults(max(1, $limit));

		$hashtags = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$hashtags[] = $this->parseHashtagsSelectSql($data);
		}
		$cursor->closeCursor();

		return $hashtags;
	}

	/**
	 * @param string $hashtag
	 *
	 * @return array
	 * @throws HashtagDoesNotExistException
	 */
	public function getHashtag(string $hashtag): array {
		$qb = $this->getHashtagsSelectSql();

		$qb->limitToHashtag($hashtag);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new HashtagDoesNotExistException();
		}

		return $this->parseHashtagsSelectSql($data);
	}

	/**
	 * The hashtags that travel with one: those on the same public posts, most
	 * often first. Tags on followers-only posts do not count.
	 *
	 * @return array<string, int> hashtag => how many public posts carry both
	 */
	public function related(string $hashtag, int $limit = 20): array {
		$hashtag = strtolower(trim(ltrim($hashtag, '#')));
		if ($hashtag === '' || $limit < 1) {
			return [];
		}

		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();

		$qb->select('other.hashtag')
			->selectAlias($qb->func()->count('*'), 'total')
			->from(self::TABLE_STREAM_TAGS, 'st')
			->innerJoin('st', self::TABLE_STREAM_TAGS, 'other', $expr->andX(
				$expr->eq('other.stream_id', 'st.stream_id'),
				$expr->neq('other.hashtag', 'st.hashtag')
			))
			->innerJoin('st', self::TABLE_STREAM, 's', $expr->eq('s.id_prim', 'st.stream_id'))
			->where($expr->eq('st.hashtag', $qb->createNamedParameter($hashtag)))
			->andWhere($expr->eq('s.visibility', $qb->createNamedParameter(Stream::TYPE_PUBLIC)))
			->groupBy('other.hashtag')
			->orderBy('total', 'desc')
			->addOrderBy('other.hashtag', 'asc')
			->setMaxResults($limit);

		$related = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$name = (string)$data['hashtag'];
			if ($name !== '') {
				$related[$name] = (int)$data['total'];
			}
		}
		$cursor->closeCursor();

		return $related;
	}

	public function searchHashtags(string $hashtag, bool $all, ?int $limit = null): array {
		$qb = $this->getHashtagsSelectSql();
		$qb->searchInHashtag($hashtag, $all);
		$qb->limitResults(min($limit ?? self::SEARCH_LIMIT, self::SEARCH_LIMIT));

		$hashtags = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$hashtags[] = $this->parseHashtagsSelectSql($data);
		}
		$cursor->closeCursor();

		return $hashtags;
	}
}
