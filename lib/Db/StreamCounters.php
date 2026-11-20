<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Dislike;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Details;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The counters a post carries (replies, likes, boosts, dislikes) and the
 * schedule for asking a remote post's server for its half of them. A trait of
 * `StreamRequest`, splitting the file, not the object (see `StreamTimelines`).
 */
trait StreamCounters {
	/**
	 * Writes the named keys of a post's `details` as the object holds them (a
	 * key the object lacks is removed), leaving every other stored key alone
	 * (`changeDetails()`), so concurrent writers' keys are not undone.
	 */
	public function updateDetails(Stream $stream, string $key, string ...$keys): void {
		$values = $stream->getDetailsAll();
		$this->changeDetails($stream->getId(), static function (array $details) use ($values, $key, $keys): array {
			foreach ([$key, ...$keys] as $name) {
				if (array_key_exists($name, $values)) {
					$details[$name] = $values[$name];
				} else {
					unset($details[$name]);
				}
			}

			return $details;
		});
	}

	/**
	 * Stores a note's `mentions` into the stored `details`, leaving the keys this
	 * instance worked out (origin counts, a refused quote) alone.
	 */
	public function updateMentions(Stream $stream): void {
		if (!$stream instanceof Note) {
			return;
		}

		$mentions = $stream->getDetails(Details::MENTIONS);
		$this->changeDetails($stream->getId(), static function (array $details) use ($mentions): array {
			$details[Details::MENTIONS] = $mentions;

			return $details;
		});
	}

	/**
	 * Changes some keys of a post's `details` and leaves every other key as
	 * stored. The write is a compare-and-swap (`replaceDetails()`); if another
	 * write came in between, the blob is read again and `$change` reapplied once.
	 *
	 * @param callable(array<array-key, mixed>): array<array-key, mixed> $change the details as stored => the details to store
	 *
	 * @return ?array<array-key, mixed> the details as they are stored now;
	 *                                  null where the post is not there, or its
	 *                                  details kept changing under the write
	 */
	public function changeDetails(string $id, callable $change): ?array {
		$prim = $this->getQueryBuilder()->prim($id);
		if ($prim === '') {
			return null;
		}

		for ($attempt = 0; $attempt < 2; $attempt++) {
			$stored = $this->storedDetails($prim);
			if ($stored === false) {
				return null;
			}

			$details = json_decode((string)$stored, true);
			$details = $change(is_array($details) ? $details : []);
			$blob = (string)json_encode($details);
			if ($blob === $stored || $this->replaceDetails($prim, $stored, $blob)) {
				return $details;
			}
		}

		$this->logger->info('the details of a post were not stored: they kept changing', ['stream' => $id]);

		return null;
	}

	/**
	 * The `details` blob of a post as stored: null for none, false where the
	 * post is not there.
	 */
	protected function storedDetails(string $prim): string|null|false {
		$qb = $this->getQueryBuilder();
		$qb->select('details')
			->from(self::TABLE_STREAM)
			->where($qb->expr()->eq('id_prim', $qb->createNamedParameter($prim)))
			->setMaxResults(1);
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		if (!is_array($row)) {
			return false;
		}

		return ($row['details'] === null) ? null : (string)$row['details'];
	}

	/**
	 * Writes `$blob` as a post's `details` only if they are still `$expected`,
	 * and says whether it did: an atomic compare-and-swap in one statement.
	 */
	protected function replaceDetails(string $prim, ?string $expected, string $blob): bool {
		$update = $this->getStreamUpdateSql();
		$update->set('details', $update->createNamedParameter($blob));
		$update->limitToIdPrim($prim);
		$update->andWhere(
			($expected === null)
				? $update->expr()->isNull('details')
				: $update->expr()->eq('details', $update->createNamedParameter($expected))
		);

		return $update->executeStatement() > 0;
	}

	/**
	 * The counters {@see self::recount()} writes: `details` key => its column,
	 * the `details` key holding the origin's half ('' for none), and the type
	 * of action counted here ('' for replies, which are posts).
	 */
	private const COUNTERS = [
		Details::REPLIES => [Stream::COUNTER_COLUMNS[Details::REPLIES], Details::REMOTE_REPLIES, ''],
		Details::LIKES => [Stream::COUNTER_COLUMNS[Details::LIKES], Details::REMOTE_LIKES, Like::TYPE],
		Details::BOOSTS => [Stream::COUNTER_COLUMNS[Details::BOOSTS], Details::REMOTE_BOOSTS, Announce::TYPE],
		Details::DISLIKES => [Stream::COUNTER_COLUMNS[Details::DISLIKES], '', Dislike::TYPE],
		Details::QUOTES => [Stream::COUNTER_COLUMNS[Details::QUOTES], Details::REMOTE_QUOTES, self::QUOTED],
	];

	/** what {@see self::countedHereSql()} counts for quotes: posts naming it as their quote */
	private const QUOTED = 'quote';

	/**
	 * Counts the posts quoting a post again and stores the total on it, as
	 * replies are counted; `remote_quotes` is what the post's own network
	 * reported.
	 *
	 * @param string $quoted the id of the post that was quoted
	 */
	public function recountQuotes(string $quoted): void {
		if ($quoted === '') {
			return;
		}

		try {
			$post = $this->getStreamById($quoted);
		} catch (StreamNotFoundException $e) {
			return;
		}

		$this->recount($post, Details::QUOTES);
	}

	/**
	 * How many posts held here quote a post.
	 */
	public function countQuotesOf(string $id): int {
		$qb = $this->getQueryBuilder();
		$prim = $qb->prim($id);
		if ($prim === '') {
			return 0;
		}
		$qb->select($qb->func()->count('*', 'count'))
			->from(self::TABLE_STREAM)
			->where($qb->expr()->eq('quote_prim', $qb->createNamedParameter($prim)));
		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($data['count'] ?? 0);
	}

	/**
	 * Counts the replies to a post again and stores the total on it; a recount
	 * because a deletion does not know the direction. `remote_replies`, the
	 * origin's count, is carried across untouched.
	 *
	 * @param string $inReplyTo the id of the post that was replied to
	 */
	public function recountReplies(string $inReplyTo): void {
		if ($inReplyTo === '') {
			return;
		}

		try {
			$parent = $this->getStreamById($inReplyTo);
		} catch (StreamNotFoundException $e) {
			return;
		}

		$this->recount($parent, Details::REPLIES);
	}

	/**
	 * Recounts some of a post's counters and stores them, each in its column.
	 *
	 * A counter is the origin's half (kept in `details`) plus what this instance
	 * holds, counted inside the UPDATE so concurrent recounts on one post cannot
	 * lose a change. The stored values are read back onto `$post`.
	 *
	 * @param string ...$counters keys of {@see self::COUNTERS}
	 */
	public function recount(Stream $post, string ...$counters): void {
		$qb = $this->getStreamUpdateSql();
		$prim = $qb->prim($post->getId());
		if ($prim === '') {
			return;
		}

		$columns = [];
		foreach ($counters as $counter) {
			if (!array_key_exists($counter, self::COUNTERS)) {
				continue;
			}

			[$column, $remote, $type] = self::COUNTERS[$counter];
			$origin = ($remote === '') ? 0 : $post->getDetailInt($remote);
			$qb->set($column, $qb->createFunction(
				(string)$qb->createNamedParameter($origin, IQueryBuilder::PARAM_INT)
				. ' + ' . $this->countedHereSql($qb, $prim, $type)
			));
			$columns[$counter] = $column;
		}

		if ($columns === []) {
			return;
		}

		$qb->limitToIdPrim($prim);
		$qb->executeStatement();

		$read = $this->getQueryBuilder();
		$read->select(...array_values($columns))
			->from(self::TABLE_STREAM)
			->where($read->expr()->eq('id_prim', $read->createNamedParameter($prim)))
			->setMaxResults(1);
		$cursor = $read->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		if (!is_array($row)) {
			return;
		}

		foreach ($columns as $counter => $column) {
			$post->setDetailInt($counter, max(0, (int)($row[$column] ?? 0)));
		}
	}

	/**
	 * What this instance holds of one counter of a post, as a scalar subquery.
	 * The aggregate sits in a derived table because MySQL refuses to read the
	 * table being updated unless the read is materialised.
	 *
	 * @param string $type the action counted, '' for replies, `quote` for quotes
	 */
	private function countedHereSql(SocialQueryBuilder $qb, string $prim, string $type): string {
		if ($type === self::QUOTED) {
			return '(SELECT `c` FROM (SELECT COUNT(*) AS `c` FROM ' . $qb->getTableName(self::TABLE_STREAM)
				. ' WHERE ' . $qb->getColumnName('quote_prim') . ' = ' . (string)$qb->createNamedParameter($prim)
				. ') `quotes`)';
		}
		if ($type === '') {
			return '(SELECT `c` FROM (SELECT COUNT(*) AS `c` FROM ' . $qb->getTableName(self::TABLE_STREAM)
				. ' WHERE ' . $qb->getColumnName('in_reply_to_prim') . ' = ' . (string)$qb->createNamedParameter($prim)
				. ') `replies`)';
		}

		return '(SELECT COUNT(*) FROM ' . $qb->getTableName(self::TABLE_ACTIONS)
			. ' WHERE ' . $qb->getColumnName('object_id_prim') . ' = ' . (string)$qb->createNamedParameter($prim)
			. ' AND ' . $qb->getColumnName('type') . ' = ' . (string)$qb->createNamedParameter($type) . ')';
	}

	/**
	 * The remote posts published since `$since` whose counts were last asked
	 * before `$due` or never. Never asked first, then the longest waiting, so
	 * every post gets its turn across passes. Boosts are left out: the post
	 * they repeat has the counts. The window is found through the
	 * `published_time` index.
	 *
	 * @param DateTime $due not asked since
	 * @param ?DateTime $since published since; null is every post, for a
	 *                         backfill that asks about all of them
	 * @param int $limit 0 is every post that is due
	 *
	 * @return Stream[]
	 */
	public function getRemoteStreamsDueForCounts(DateTime $due, ?DateTime $since, int $limit = 0): array {
		$qb = $this->getStreamSelectSql();
		$qb->limitToLocal(false);
		if ($since !== null) {
			$qb->andWhere($qb->expr()->gte('s.published_time', $qb->createNamedParameter($since, IQueryBuilder::PARAM_DATE)));
		}
		$qb->filterType(Announce::TYPE);
		$qb->limitToDBFieldDateTime('counts_at', $due, true);
		// NULL sorts differently per database; selected because a DISTINCT query
		// may only be ordered by what it selects.
		$countsAt = $qb->getColumnName('counts_at', 's');
		$qb->selectAlias($qb->createFunction('CASE WHEN ' . $countsAt . ' IS NULL THEN 0 ELSE 1 END'), 'counts_asked');
		$qb->orderBy('counts_asked', 'asc');
		$qb->addOrderBy('s.counts_at', 'asc');

		if ($limit > 0) {
			$qb->setMaxResults($limit);
		}

		return $this->getStreamsFromRequest($qb);
	}

	/**
	 * This instance's own posts that are also Bluesky records, published since
	 * `$since`, whose AppView counts were last asked before `$due` or never
	 * (`BlueskyPublishedCountSource`). Oldest first.
	 *
	 * @param int $limit 0 is every post that is due
	 *
	 * @return Stream[]
	 */
	public function getPublishedStreamsDueForCounts(DateTime $due, DateTime $since, int $limit = 0): array {
		$qb = $this->getStreamSelectSql();
		$qb->limitToLocal(true);
		$qb->andWhere($qb->expr()->gte('s.published_time', $qb->createNamedParameter($since, IQueryBuilder::PARAM_DATE)));
		$qb->limitToDBFieldDateTime('counts_at', $due, true);
		$qb->innerJoin('s', self::TABLE_ATPROTO_RECORD, 'rec', $qb->expr()->andX(
			$qb->expr()->eq('rec.local_id_prim', 's.id_prim'),
			$qb->expr()->eq('rec.collection', $qb->createNamedParameter(RecordMapper::POST))
		));
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$qb->orderBy('s.published_time', 'asc');

		if ($limit > 0) {
			$qb->setMaxResults($limit);
		}

		return $this->getStreamsFromRequest($qb);
	}

	/**
	 * Stamps a post as having been asked for its counts, even when no answer
	 * came, so a dead host is asked once per interval. A post its server says is
	 * gone is stamped ahead, to the time it is next worth asking.
	 */
	public function markCountsRefreshed(string $id, DateTime $when): void {
		$qb = $this->getStreamUpdateSql();
		$qb->set('counts_at', $qb->createNamedParameter($when, IQueryBuilder::PARAM_DATE));
		$qb->limitToIdPrim($qb->prim($id));
		$qb->executeStatement();
	}
}
