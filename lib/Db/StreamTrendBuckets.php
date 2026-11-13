<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tools\DbTime;
use OCP\DB\QueryBuilder\IQueryBuilder;
use Throwable;

/**
 * The hourly hashtag buckets the trend windows are counted from.
 *
 * `social_tag_hour` holds, for each hashtag and hour, how many public statuses
 * published in that hour carry it: the rows `countHashtagsInWindows()` used to
 * join and group over the whole ten-day window on every trends pass, counted
 * once per hour instead. Hourly, not daily: the narrowest window is an hour,
 * and the part of a window that does not cover a whole bucket is counted
 * exactly from the posts (`countHashtagsFromBuckets()`), which with daily
 * buckets would be up to a day of posts on every pass.
 *
 * A bucket is recounted from the posts, never incremented, so it is exactly
 * what the join would say for its hour at the moment it was counted. An hour
 * is recounted (`settleTrendBuckets()`) when something in it may have changed:
 * a post stored since the last pass (found by `creation`, which is indexed),
 * or an hour marked in `social_tag_dirty` by a deletion or an edit, which can
 * change an hour long after its posts were stored. Only the hours of the
 * widest window and after are kept.
 *
 * A trait used by `StreamRequest` for the reason `StreamTimelines` gives: it
 * separates the file, not the object.
 */
trait StreamTrendBuckets {
	/** the app config key holding when the buckets were last settled; absent until they were built */
	public const TREND_BUCKETS_MARK = 'trend_buckets_mark';
	/** one bucket, in seconds */
	public const TREND_BUCKET = 3600;
	/** how far back buckets are kept: the widest trend window and the bucket its edge falls in */
	public const TREND_BUCKET_SPAN = 864000 + self::TREND_BUCKET;
	/** how far before the last pass a post's `creation` is looked at again, for a write that was in flight */
	private const TREND_CREATION_OVERLAP = 300;

	/** The start of the bucket a moment falls in. */
	public static function trendHourOf(int $time): int {
		return $time - ((($time % self::TREND_BUCKET) + self::TREND_BUCKET) % self::TREND_BUCKET);
	}

	/** The oldest bucket kept. */
	protected function trendBucketFloor(): int {
		return self::trendHourOf(time() - self::TREND_BUCKET_SPAN);
	}

	/**
	 * The uses of hashtags that trends count, on `$qb`: the tag rows of public
	 * statuses, which is the rule `HashtagsRequest::related()` counts by — a
	 * tag used inside a followers-only thread or a direct message is not public
	 * knowledge, and counting it published the tag, and its usage count,
	 * through `/api/v1/trends/tags`, `tagHistory()` and search.
	 */
	private function trendTagUses(SocialQueryBuilder $qb): void {
		$expr = $qb->expr();
		$qb->from(self::TABLE_STREAM_TAGS, 'st')
			->innerJoin('st', self::TABLE_STREAM, 's', $expr->eq('s.id_prim', 'st.stream_id'))
			->andWhere($expr->eq(
				's.visibility', $qb->createNamedParameter(Stream::TYPE_PUBLIC)
			));
		$qb->setDefaultSelectAlias('s');
		$qb->limitToStatusTypes();
	}

	/**
	 * Brings every bucket up to date: built whole the first time, and
	 * afterwards only the hours something may have changed in recounted.
	 */
	public function settleTrendBuckets(): void {
		$now = time();
		$floor = $this->trendBucketFloor();
		$mark = $this->configService->getAppValueInt(self::TREND_BUCKETS_MARK);

		if ($mark <= 0) {
			$this->rebuildTrendBuckets($floor);
		} else {
			$hours = array_merge(
				$this->takeDirtyTrendHours(),
				$this->trendHoursStoredSince($mark - self::TREND_CREATION_OVERLAP, $floor)
			);
			$this->recountTrendHours(array_filter(
				array_unique($hours), static fn (int $hour): bool => $hour >= $floor
			));
		}

		foreach ([self::TABLE_TAG_HOURS, self::TABLE_TAG_HOURS_DIRTY] as $table) {
			$qb = $this->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->lt('hour_start', $qb->createNamedParameter($floor, IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();
		}

		$this->configService->setAppValue(self::TREND_BUCKETS_MARK, (string)$now);
	}

	/**
	 * The buckets of every hour from `$floor` on, counted in one read of the
	 * tag rows: the one-time backfill, and what a lost mark falls back to.
	 */
	protected function rebuildTrendBuckets(int $floor): void {
		$qb = $this->getQueryBuilder();
		$qb->select('st.hashtag', 's.published_time');
		$this->trendTagUses($qb);
		$qb->andWhere($qb->expr()->gte(
			's.published_time', $qb->createNamedParameter(new DateTime('@' . $floor), IQueryBuilder::PARAM_DATE)
		));

		$counts = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$hour = self::trendHourOf(DbTime::toTimestamp($data['published_time'] ?? ''));
			$hashtag = (string)$data['hashtag'];
			$counts[$hour][$hashtag] = ($counts[$hour][$hashtag] ?? 0) + 1;
		}
		$cursor->closeCursor();

		$this->dbConnection->beginTransaction();
		try {
			foreach ([self::TABLE_TAG_HOURS, self::TABLE_TAG_HOURS_DIRTY] as $table) {
				$this->getQueryBuilder()->delete($table)->executeStatement();
			}
			foreach ($counts as $hour => $uses) {
				$this->insertTrendBuckets($hour, $uses);
			}
			$this->dbConnection->commit();
		} catch (Throwable $e) {
			$this->dbConnection->rollBack();
			throw $e;
		}
	}

	/**
	 * Recounts each of these hours from the posts, replacing its buckets.
	 *
	 * @param int[] $hours
	 */
	protected function recountTrendHours(array $hours): void {
		sort($hours);
		foreach ($hours as $hour) {
			$qb = $this->getQueryBuilder();
			$qb->select('st.hashtag')
				->selectAlias($qb->func()->count('*'), 'uses');
			$this->trendTagUses($qb);
			$qb->andWhere($qb->expr()->gte(
				's.published_time', $qb->createNamedParameter(new DateTime('@' . $hour), IQueryBuilder::PARAM_DATE)
			))
				->andWhere($qb->expr()->lt(
					's.published_time',
					$qb->createNamedParameter(new DateTime('@' . ($hour + self::TREND_BUCKET)), IQueryBuilder::PARAM_DATE)
				))
				->groupBy('st.hashtag');

			$uses = [];
			$cursor = $qb->executeQuery();
			while ($data = $cursor->fetch()) {
				$uses[(string)$data['hashtag']] = (int)$data['uses'];
			}
			$cursor->closeCursor();

			$this->dbConnection->beginTransaction();
			try {
				$delete = $this->getQueryBuilder();
				$delete->delete(self::TABLE_TAG_HOURS)
					->where($delete->expr()->eq('hour_start', $delete->createNamedParameter($hour, IQueryBuilder::PARAM_INT)));
				$delete->executeStatement();
				$this->insertTrendBuckets($hour, $uses);
				$this->dbConnection->commit();
			} catch (Throwable $e) {
				$this->dbConnection->rollBack();
				throw $e;
			}
		}
	}

	/** @param array<string, int> $uses hashtag => uses in the hour */
	private function insertTrendBuckets(int $hour, array $uses): void {
		foreach ($uses as $hashtag => $count) {
			if ($count <= 0) {
				continue;
			}
			$qb = $this->getQueryBuilder();
			$qb->insert(self::TABLE_TAG_HOURS)
				->setValue('hashtag', $qb->createNamedParameter((string)$hashtag))
				->setValue('hour_start', $qb->createNamedParameter($hour, IQueryBuilder::PARAM_INT))
				->setValue('uses', $qb->createNamedParameter($count, IQueryBuilder::PARAM_INT));
			$qb->executeStatement();
		}
	}

	/**
	 * The hours of the public statuses stored since a moment, by their
	 * publication: a post that arrives late counts in the hour it says it was
	 * published in, as the windows count it.
	 *
	 * @return int[]
	 */
	protected function trendHoursStoredSince(int $since, int $floor): array {
		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$qb->select('s.published_time')
			->from(self::TABLE_STREAM, 's')
			->where($expr->gte('s.creation', $qb->createNamedParameter(new DateTime('@' . $since), IQueryBuilder::PARAM_DATE)))
			->andWhere($expr->gte('s.published_time', $qb->createNamedParameter(new DateTime('@' . $floor), IQueryBuilder::PARAM_DATE)))
			->andWhere($expr->eq('s.visibility', $qb->createNamedParameter(Stream::TYPE_PUBLIC)));
		$qb->setDefaultSelectAlias('s');
		$qb->limitToStatusTypes();

		$hours = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$hours[self::trendHourOf(DbTime::toTimestamp($data['published_time'] ?? ''))] = true;
		}
		$cursor->closeCursor();

		return array_keys($hours);
	}

	/**
	 * The hours marked dirty, taken off the list before they are recounted:
	 * a mark made while they are is kept for the next pass.
	 *
	 * @return int[]
	 */
	protected function takeDirtyTrendHours(): array {
		$qb = $this->getQueryBuilder();
		$qb->select('hour_start')->from(self::TABLE_TAG_HOURS_DIRTY);

		$hours = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$hours[] = (int)$data['hour_start'];
		}
		$cursor->closeCursor();

		if ($hours !== []) {
			$delete = $this->getQueryBuilder();
			$delete->delete(self::TABLE_TAG_HOURS_DIRTY)
				->where($delete->expr()->in('hour_start', $delete->createNamedParameter($hours, IQueryBuilder::PARAM_INT_ARRAY)));
			$delete->executeStatement();
		}

		return $hours;
	}

	/**
	 * Marks the hours of these posts for a recount: called before they are
	 * deleted, while their rows still say when they were published.
	 *
	 * @param string[] $prims
	 */
	public function markTrendHoursOf(array $prims): void {
		if ($prims === []) {
			return;
		}

		$qb = $this->getQueryBuilder();
		$qb->select('s.published_time')
			->from(self::TABLE_STREAM, 's')
			->where($qb->expr()->in('s.id_prim', $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->gte(
				's.published_time',
				$qb->createNamedParameter(new DateTime('@' . $this->trendBucketFloor()), IQueryBuilder::PARAM_DATE)
			));

		$cursor = $qb->executeQuery();
		$rows = $cursor->fetchAll();
		$cursor->closeCursor();

		foreach ($rows as $data) {
			$this->markTrendHour(DbTime::toTimestamp($data['published_time'] ?? ''));
		}
	}

	/** Marks the hour a post was published in for a recount, if it is one that is kept. */
	public function markTrendHour(int $publishedTime): void {
		$hour = self::trendHourOf($publishedTime);
		if ($publishedTime <= 0 || $hour < $this->trendBucketFloor()) {
			return;
		}

		$this->dbConnection->insertIgnoreConflict(self::TABLE_TAG_HOURS_DIRTY, ['hour_start' => $hour]);
	}

	/**
	 * Each window counted off the buckets: the whole buckets from the first
	 * hour that starts inside the window on, and the part of the window before
	 * that hour exactly from the posts, so a window starts on its second as
	 * the join it replaced started it.
	 *
	 * @param array<string, int> $windows name => since, as a timestamp, none before `trendBucketFloor()`
	 *
	 * @return array<string, array<string, int>> name => (hashtag => how many posts used it)
	 */
	protected function countHashtagsFromBuckets(array $windows): array {
		$result = array_fill_keys(array_keys($windows), []);
		$aliases = [];
		$firstHours = [];
		foreach (array_keys($windows) as $i => $name) {
			$aliases[$name] = 'w' . $i;
			$firstHours[$name] = self::trendHourOf($windows[$name] + self::TREND_BUCKET - 1);
		}

		$add = static function (array &$result, string $name, string $hashtag, int $count): void {
			if ($count > 0) {
				$result[$name][$hashtag] = ($result[$name][$hashtag] ?? 0) + $count;
			}
		};

		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$qb->select('hashtag');
		foreach ($windows as $name => $since) {
			$qb->selectAlias(
				$qb->createFunction(
					'SUM(CASE WHEN '
					. $expr->gte('hour_start', $qb->createNamedParameter($firstHours[$name], IQueryBuilder::PARAM_INT))
					. ' THEN uses ELSE 0 END)'
				),
				$aliases[$name]
			);
		}
		$qb->from(self::TABLE_TAG_HOURS)
			->where($expr->gte('hour_start', $qb->createNamedParameter(min($firstHours), IQueryBuilder::PARAM_INT)))
			->groupBy('hashtag');
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			foreach ($aliases as $name => $alias) {
				$add($result, $name, (string)$data['hashtag'], (int)($data[$alias] ?? 0));
			}
		}
		$cursor->closeCursor();

		$edges = array_filter(
			$windows, static fn (int $since, string $name): bool => $since < $firstHours[$name], ARRAY_FILTER_USE_BOTH
		);
		if ($edges === []) {
			return $result;
		}

		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$qb->select('st.hashtag');
		$ranges = [];
		foreach ($edges as $name => $since) {
			$range = $expr->andX(
				$expr->gte('s.published_time', $qb->createNamedParameter(new DateTime('@' . $since), IQueryBuilder::PARAM_DATE)),
				$expr->lt('s.published_time', $qb->createNamedParameter(new DateTime('@' . $firstHours[$name]), IQueryBuilder::PARAM_DATE))
			);
			$ranges[] = $range;
			$qb->selectAlias(
				$qb->createFunction(
					'SUM(CASE WHEN '
					. $expr->gte('s.published_time', $qb->createNamedParameter(new DateTime('@' . $since), IQueryBuilder::PARAM_DATE))
					. ' AND '
					. $expr->lt('s.published_time', $qb->createNamedParameter(new DateTime('@' . $firstHours[$name]), IQueryBuilder::PARAM_DATE))
					. ' THEN 1 ELSE 0 END)'
				),
				$aliases[$name]
			);
		}
		$this->trendTagUses($qb);
		$qb->andWhere($expr->orX(...$ranges))
			->groupBy('st.hashtag');
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			foreach (array_keys($edges) as $name) {
				$add($result, $name, (string)$data['hashtag'], (int)($data[$aliases[$name]] ?? 0));
			}
		}
		$cursor->closeCursor();

		return $result;
	}
}
