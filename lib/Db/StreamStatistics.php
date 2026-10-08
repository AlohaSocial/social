<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Reads that answer with a number or a tally rather than a page of posts:
 * the admin page's activity, profile highlights, hashtag trends and the
 * statistics page.
 *
 * A trait used by `StreamRequest` for the reason `StreamTimelines` gives: it
 * separates the file, not the object.
 */
trait StreamStatistics {
	/**
	 * What this instance's own people have been writing, for the two numbers
	 * an administrator asks for first: how busy is it, and how many of the
	 * accounts are actually used.
	 *
	 * Local posts only. A count that included the fediverse's would say how
	 * much this server has *received*, which is a number about everybody
	 * else's activity and about this instance's retention settings.
	 *
	 * @param int $since unix time to count from
	 * @return array{posts: int, authors: int}
	 */
	public function localActivitySince(int $since): array {
		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();

		$date = new DateTime();
		$date->setTimestamp($since);

		$qb->selectAlias($qb->func()->count('s.id'), 'posts')
			->selectAlias($qb->createFunction('COUNT(DISTINCT s.attributed_to_prim)'), 'authors')
			->from(self::TABLE_STREAM, 's')
			->where($expr->eq('s.local', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($expr->in(
				's.type',
				$qb->createNamedParameter([Note::TYPE, Question::TYPE], IQueryBuilder::PARAM_STR_ARRAY)
			))
			->andWhere($expr->gte(
				's.published_time', $qb->createNamedParameter($date, IQueryBuilder::PARAM_DATE)
			));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return [
			'posts' => (int)($data['posts'] ?? 0),
			'authors' => (int)($data['authors'] ?? 0),
		];
	}

	/**
	 * When each of an author's public posts was published, since a point in
	 * time.
	 *
	 * Only the timestamps: what the profile's little activity chart needs is
	 * how many posts fell in each week, and hydrating the posts to count them
	 * would read every column of every note to throw all of it away. Public
	 * posts only — a chart drawn from what the viewer happens to be allowed to
	 * see would be a different chart per viewer, and a chart that counted
	 * posts the viewer cannot see would leak that they exist.
	 *
	 * Boosts are left out by `limitToStatusTypes()`: a boost is something the
	 * account did, not something it wrote.
	 *
	 * @param string $actorId the author
	 * @param int $since unix time to start at
	 * @param int $limit a ceiling, so a prolific account cannot make this
	 *                   query grow without bound
	 * @return list<int> publication times, newest first
	 */
	public function publishedTimesByAuthor(string $actorId, int $since, int $limit = 2000): array {
		if ($actorId === '' || $limit < 1) {
			return [];
		}

		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();

		$date = new DateTime();
		$date->setTimestamp($since);

		$qb->select('s.published_time')
			->from(self::TABLE_STREAM, 's')
			->where($expr->gte(
				's.published_time', $qb->createNamedParameter($date, IQueryBuilder::PARAM_DATE)
			))
			->orderBy('s.published_time', 'desc')
			->setMaxResults($limit);

		$qb->setDefaultSelectAlias('s');
		$qb->limitToAttributedTo($actorId, true);
		$qb->limitToStatusTypes();

		// the recipients are where "public" is recorded, the same join the
		// author's public timeline uses
		$qb->selectDestFollowing('sd', '');
		$qb->innerJoinStreamDest('recipient', 'id_prim', 'sd', 's');
		$qb->limitToDest(ACore::CONTEXT_PUBLIC, 'recipient', '', 'sd');

		$times = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$time = (int)strtotime((string)$data['published_time']);
			if ($time > 0) {
				$times[] = $time;
			}
		}
		$cursor->closeCursor();

		return $times;
	}

	/**
	 * The hashtags an author uses most, over their public posts since a point
	 * in time.
	 *
	 * Grouped in SQL rather than walked in PHP: unlike the per-post details
	 * blob, a hashtag is a row of its own in `social_stream_tag`, so the three
	 * databases can all count them the same way.
	 *
	 * @param string $actorId the author
	 * @param int $since unix time to start at
	 * @param int $limit how many to name
	 * @return list<array{name: string, count: int}> most used first
	 */
	public function topHashtagsByAuthor(string $actorId, int $since, int $limit = 3): array {
		if ($actorId === '' || $limit < 1) {
			return [];
		}

		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();

		$date = new DateTime();
		$date->setTimestamp($since);

		$qb->select('st.hashtag')
			->selectAlias($qb->func()->count('*'), 'total')
			->from(self::TABLE_STREAM_TAGS, 'st')
			->innerJoin('st', self::TABLE_STREAM, 's', $expr->eq('s.id_prim', 'st.stream_id'))
			->where($expr->eq('s.attributed_to_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($expr->gte(
				's.published_time', $qb->createNamedParameter($date, IQueryBuilder::PARAM_DATE)
			))
			->groupBy('st.hashtag')
			->orderBy('total', 'desc')
			->addOrderBy('st.hashtag', 'asc')
			->setMaxResults($limit);

		$qb->setDefaultSelectAlias('s');
		$qb->limitToStatusTypes();

		// public posts only, through the same recipient join as the chart
		// above: the profile is read by strangers, and a tag used only in
		// followers-only posts is a fact about those posts
		$qb->selectDestFollowing('sd', '');
		$qb->innerJoinStreamDest('recipient', 'id_prim', 'sd', 's');
		$qb->limitToDest(ACore::CONTEXT_PUBLIC, 'recipient', '', 'sd');

		$tags = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$name = (string)$data['hashtag'];
			if ($name === '') {
				continue;
			}
			$tags[] = ['name' => $name, 'count' => (int)$data['total']];
		}
		$cursor->closeCursor();

		return $tags;
	}

	/**
	 * How often each hashtag was used since a point in time.
	 *
	 * One window of `countHashtagsInWindows()`, for a caller that wants one.
	 *
	 * @return array<string, int> hashtag => how many posts used it
	 */
	public function countHashtagsSince(int $since): array {
		return $this->countHashtagsInWindows(['since' => $since])['since'];
	}

	/**
	 * How often each hashtag was used in each of several windows, in one pass.
	 *
	 * This is what the trends cron needs, and all it needs. It used to hydrate
	 * the posts themselves — every column of every note, plus its action row —
	 * and count the tags in PHP, bounded to a sample of the most recent
	 * thousand notes; on a busy instance all five windows saw the same
	 * thousand notes and every period therefore reported the same count. Then
	 * it was one grouped query per window, which read the tag rows of the
	 * widest window five times over; now the widest window is read once and
	 * each narrower one is a conditional sum over the same rows.
	 *
	 * A hashtag used in none of a window's posts is absent from that window
	 * rather than zero.
	 *
	 * @param array<string, int> $windows name => since, as a timestamp
	 *
	 * @return array<string, array<string, int>> name => (hashtag => how many posts used it)
	 */
	public function countHashtagsInWindows(array $windows): array {
		$result = array_fill_keys(array_keys($windows), []);
		if ($windows === []) {
			return $result;
		}

		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();

		$qb->select('st.hashtag');
		$aliases = [];
		foreach (array_keys($windows) as $i => $name) {
			$date = new DateTime();
			$date->setTimestamp($windows[$name]);
			$aliases[$name] = 'w' . $i;
			$qb->selectAlias(
				$qb->createFunction(
					'SUM(CASE WHEN '
					. $expr->gte('s.published_time', $qb->createNamedParameter($date, IQueryBuilder::PARAM_DATE))
					. ' THEN 1 ELSE 0 END)'
				),
				'w' . $i
			);
		}

		$widest = new DateTime();
		$widest->setTimestamp(min($windows));
		$qb->from(self::TABLE_STREAM_TAGS, 'st')
			->innerJoin('st', self::TABLE_STREAM, 's', $expr->eq('s.id_prim', 'st.stream_id'))
			->where($expr->gte(
				's.published_time', $qb->createNamedParameter($widest, IQueryBuilder::PARAM_DATE)
			))
			// public posts only, the rule `HashtagsRequest::related()` counts
			// by: a tag used inside a followers-only thread or a direct message
			// is not public knowledge, and counting it published the tag — and
			// its usage count — through `/api/v1/trends/tags`, `tagHistory()`
			// and search
			->andWhere($expr->eq(
				's.visibility', $qb->createNamedParameter(Stream::TYPE_PUBLIC)
			))
			->groupBy('st.hashtag');

		$qb->setDefaultSelectAlias('s');
		$qb->limitToStatusTypes();

		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			foreach ($aliases as $name => $alias) {
				$count = (int)($data[$alias] ?? 0);
				if ($count > 0) {
					$result[$name][(string)$data['hashtag']] = $count;
				}
			}
		}
		$cursor->closeCursor();

		return $result;
	}

	/**
	 * Who an account actually talks with, and how often.
	 *
	 * Both directions are the same self-join on the stream, read from either
	 * end: `PARTNERS_INBOUND` counts the replies other people wrote to this
	 * account's posts, `PARTNERS_OUTBOUND` the replies this account wrote to
	 * theirs. The window applies to the reply in both cases — it is the reply
	 * that happened in the window, whatever the age of the post it answers.
	 *
	 * The account itself is excluded: a thread somebody continues on their own
	 * is not a conversation with anybody, and left in it would outrank every
	 * real partner.
	 *
	 * @param string $actorId the account whose conversations
	 * @param int $direction self::PARTNERS_INBOUND or self::PARTNERS_OUTBOUND
	 * @param int $since only replies from this moment on, or 0 for all of them
	 *
	 * @return list<array{id: string, account: string, replies: int}> most talkative first
	 */
	public function countConversationPartners(
		string $actorId,
		int $direction,
		int $since = 0,
		int $limit = 10,
	): array {
		if ($actorId === '') {
			return [];
		}

		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$prim = $qb->prim($actorId);

		// 'r' is always the reply, 'p' always the post it answers; which of the
		// two belongs to this account is the whole difference between the
		// directions
		$partner = ($direction === self::PARTNERS_INBOUND) ? 'r' : 'p';
		$mine = ($direction === self::PARTNERS_INBOUND) ? 'p' : 'r';

		$qb->select($partner . '.attributed_to')
			->selectAlias($qb->func()->count('*'), 'total')
			->from(self::TABLE_STREAM, 'r')
			->innerJoin('r', self::TABLE_STREAM, 'p', $expr->eq('p.id_prim', 'r.in_reply_to_prim'))
			->where($expr->eq(
				$mine . '.attributed_to_prim', $qb->createNamedParameter($prim)
			))
			->andWhere($expr->neq(
				$partner . '.attributed_to_prim', $qb->createNamedParameter($prim)
			))
			->andWhere($expr->nonEmptyString($partner . '.attributed_to'))
			->groupBy($partner . '.attributed_to')
			->orderBy('total', 'desc')
			->setMaxResults($limit);

		if ($since > 0) {
			$date = new DateTime();
			$date->setTimestamp($since);
			$qb->andWhere($expr->gte(
				'r.published_time', $qb->createNamedParameter($date, IQueryBuilder::PARAM_DATE)
			));
		}

		$qb->setDefaultSelectAlias('r');
		$qb->limitToStatusTypes();

		$partners = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$id = (string)$data['attributed_to'];
			$partners[] = [
				'id' => $id,
				'account' => $this->handleFromActorId($id),
				'replies' => (int)$data['total'],
			];
		}
		$cursor->closeCursor();

		return $partners;
	}

	/**
	 * The handle an actor URI belongs to, for a name to put on a number.
	 *
	 * The cache is the only place a remote actor's handle is written down, and
	 * an actor this account has held a conversation with is in it by
	 * definition. If it is not, the URI's own last segment and host say who it
	 * was well enough for a list of names.
	 */
	private function handleFromActorId(string $id): string {
		$qb = $this->getQueryBuilder();
		$qb->select('account')
			->from(self::TABLE_CACHE_ACTORS)
			->where($qb->expr()->eq('id_prim', $qb->createNamedParameter($qb->prim($id))))
			->setMaxResults(1);

		$cursor = $qb->executeQuery();
		$account = (string)($cursor->fetchOne() ?: '');
		$cursor->closeCursor();

		if ($account !== '') {
			return $account;
		}

		$host = (string)parse_url($id, PHP_URL_HOST);
		$name = basename((string)parse_url($id, PHP_URL_PATH));

		return ($name === '' || $host === '') ? $id : $name . '@' . $host;
	}

	/**
	 * Who boosted each of these posts.
	 *
	 * One query for the whole set rather than one per post: the statistics
	 * page asks about a month of posts at once, and a round trip per post to
	 * fill in one column is a page that gets slower the more an account posts.
	 *
	 * The same account boosting the same post twice is one audience, so the
	 * actors come back deduplicated per post.
	 *
	 * @param string[] $ids the posts, by id
	 * @param int $limit how many Announce rows to read at most
	 * @return array<string, list<string>> post id => the actors that boosted it
	 */
	public function boostersOf(array $ids, int $limit = 5000): array {
		if ($ids === [] || $limit < 1) {
			return [];
		}

		$qb = $this->getQueryBuilder();

		$byPrim = [];
		foreach ($ids as $id) {
			$prim = $qb->prim($id);
			if ($prim !== '') {
				$byPrim[$prim] = $id;
			}
		}

		if ($byPrim === []) {
			return [];
		}

		$expr = $qb->expr();
		$qb->select('s.object_id_prim', 's.attributed_to')
			->from(self::TABLE_STREAM, 's')
			->where($expr->eq('s.type', $qb->createNamedParameter(Announce::TYPE)))
			->andWhere($expr->in(
				's.object_id_prim',
				$qb->createNamedParameter(array_keys($byPrim), IQueryBuilder::PARAM_STR_ARRAY)
			))
			->setMaxResults($limit);

		$boosters = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$id = $byPrim[(string)$data['object_id_prim']] ?? '';
			$actor = (string)$data['attributed_to'];
			if ($id === '' || $actor === '') {
				continue;
			}
			$boosters[$id][$actor] = $actor;
		}
		$cursor->closeCursor();

		return array_map(static fn (array $actors): array => array_values($actors), $boosters);
	}
}
