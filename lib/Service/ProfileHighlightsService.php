<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;

/**
 * The few numbers that say what an account is like, for the top of its
 * profile: when it started, how the last weeks went, and what it keeps
 * writing about.
 *
 * Local accounts only: this instance holds only part of a remote account's
 * history, and a partial chart would look like a quiet account. Public posts
 * only, as on the profile timeline, so every viewer sees the same chart and
 * no hidden post is revealed by a count.
 */
class ProfileHighlightsService {
	/** How many weeks the little chart covers. */
	public const WEEKS = 12;

	/** A week, in seconds. */
	private const WEEK = 7 * 24 * 3600;

	/**
	 * How many posts the chart reads at most, so a very prolific account
	 * cannot make a profile view an unbounded query; the most recent posts
	 * are kept.
	 */
	private const MAX_POSTS = 5000;

	/** How many hashtags are named. */
	private const TOP_HASHTAGS = 3;

	/**
	 * How far back the "posting since" and the hashtags look: a year, so the
	 * tags reflect what the account writes about lately.
	 */
	private const HASHTAG_WINDOW = 365 * 24 * 3600;

	public function __construct(
		private StreamRequest $streamRequest,
	) {
	}

	/**
	 * @param Person $actor whose profile is being looked at
	 * @param int $now unix time, injectable so the buckets can be tested
	 * @return array{
	 *     available: bool,
	 *     since: int,
	 *     weeks: list<int>,
	 *     week_starts: int,
	 *     hashtags: list<array{name: string, count: int}>
	 * }
	 */
	public function forActor(Person $actor, int $now = 0): array {
		$now = ($now > 0) ? $now : time();

		if (!$actor->isLocal()) {
			return $this->nothing($now);
		}

		$actorId = $actor->getId();
		if ($actorId === '') {
			return $this->nothing($now);
		}

		// buckets run back from the start of the current week, so the last one
		// is the week in progress rather than a part-week ending right now
		$currentWeekStart = $now - ($now % self::WEEK);
		$from = $currentWeekStart - (self::WEEKS - 1) * self::WEEK;

		$weeks = array_fill(0, self::WEEKS, 0);
		foreach ($this->streamRequest->publishedTimesByAuthor($actorId, $from, self::MAX_POSTS) as $time) {
			$offset = $time - $from;

			// Both ends of the window are checked in seconds, not on the bucket
			// number: intdiv() truncates towards zero, so a post just older than
			// `$from` would land in bucket 0, and one published after the query
			// would be past the last bucket.
			if ($offset < 0 || $offset >= self::WEEKS * self::WEEK) {
				continue;
			}

			$weeks[intdiv($offset, self::WEEK)]++;
		}

		return [
			'available' => true,
			'since' => $this->firstPostTime($actor),
			'weeks' => $weeks,
			'week_starts' => $from,
			'hashtags' => $this->streamRequest->topHashtagsByAuthor(
				$actorId, $now - self::HASHTAG_WINDOW, self::TOP_HASHTAGS
			),
		];
	}

	/**
	 * When this account started posting here: the actor's creation date, not
	 * its oldest surviving post.
	 *
	 * @return int unix time, or 0 when the actor carries no creation date
	 */
	private function firstPostTime(Person $actor): int {
		return max(0, $actor->getCreation());
	}

	/**
	 * The answer for an account this instance cannot honestly chart:
	 * `available` false, so the profile leaves the section out rather than
	 * drawing zeroes.
	 *
	 * @return array{
	 *     available: bool,
	 *     since: int,
	 *     weeks: list<int>,
	 *     week_starts: int,
	 *     hashtags: list<array{name: string, count: int}>
	 * }
	 */
	private function nothing(int $now): array {
		return [
			'available' => false,
			'since' => 0,
			'weeks' => [],
			'week_starts' => $now,
			'hashtags' => [],
		];
	}
}
