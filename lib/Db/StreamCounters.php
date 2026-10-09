<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Dislike;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Details;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The counters a post carries — replies, likes, boosts, dislikes — and the
 * schedule on which a remote post's own server is asked for its half of them.
 *
 * A trait used by `StreamRequest` for the reason `StreamTimelines` gives: it
 * separates the file, not the object.
 */
trait StreamCounters {
	/**
	 * @param ?DateTime $countsAt when this instance last heard the origin's
	 *                            totals for the post, for a write that heard
	 *                            them; null leaves the schedule where it is
	 *
	 * @see \OCA\Social\Service\RemoteCountService
	 */
	public function updateDetails(Stream $stream, ?DateTime $countsAt = null): void {
		$qb = $this->getStreamUpdateSql();
		$qb->set('details', $qb->createNamedParameter(json_encode($stream->getDetailsAll())));

		if ($countsAt !== null) {
			$qb->set('counts_at', $qb->createNamedParameter($countsAt, IQueryBuilder::PARAM_DATE));
		}

		$qb->limitToIdPrim($qb->prim($stream->getId()));
		$qb->executeStatement();
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
	 * Counts the replies to a post again and stores the total on it.
	 *
	 * A recount rather than a bump, because the two things that change it —
	 * a reply arriving and a reply being deleted — do not both know which way.
	 * `remote_replies` is what the post's own instance reported and is carried
	 * across untouched: nothing here can see the replies that live over there.
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
	 * A counter is the origin's half — what the post's own server last said,
	 * kept in `details` — plus what this instance holds of it, and the second
	 * half is counted inside the UPDATE rather than before it. That is what
	 * makes two of these on one post safe to run together: neither carries a
	 * number it counted earlier, so whichever runs last writes a count that
	 * includes everything committed before it. The counters used to be keys of
	 * the `details` JSON, written back whole by every writer of any key, and
	 * two likes — or a like and a boost — arriving together kept one of them.
	 *
	 * The stored values are read back onto `$post`, so a caller that goes on
	 * to use it (a notification carries a copy of the post) has the numbers
	 * the row has.
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
	 *
	 * Replies are rows of the table being updated, which MySQL refuses to read
	 * in the same statement unless the read is materialised first; an
	 * aggregate inside a derived table is never merged into the outer query,
	 * so it always is.
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
	 * The remote posts whose counts were last heard from their own server
	 * long enough ago to be worth asking again — including every one never
	 * asked, which has no `counts_at` at all.
	 *
	 * Oldest post first, so a pass that runs out of budget leaves the posts
	 * that have waited longest at the front of the next one rather than
	 * wherever they fell.
	 *
	 * @param DateTime $due not asked since
	 * @param int $limit 0 is every post that is due
	 *
	 * @return Stream[]
	 */
	public function getRemoteStreamsDueForCounts(DateTime $due, int $limit = 0): array {
		$qb = $this->getStreamSelectSql();
		$qb->limitToLocal(false);
		$qb->limitToDBFieldDateTime('counts_at', $due, true);
		$qb->orderBy('s.published_time', 'asc');

		if ($limit > 0) {
			$qb->setMaxResults($limit);
		}

		return $this->getStreamsFromRequest($qb);
	}

	/**
	 * Stamps a post as having been asked for its counts, leaving whatever
	 * they are stored as alone. Written even when the answer did not come, so
	 * that a host which is gone is asked once per interval rather than on
	 * every pass.
	 */
	public function markCountsRefreshed(string $id, DateTime $when): void {
		$qb = $this->getStreamUpdateSql();
		$qb->set('counts_at', $qb->createNamedParameter($when, IQueryBuilder::PARAM_DATE));
		$qb->limitToIdPrim($qb->prim($id));
		$qb->executeStatement();
	}
}
