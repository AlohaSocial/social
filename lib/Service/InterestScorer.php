<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\FollowedTagsRequest;
use OCA\Social\Model\Interest;

/**
 * The arithmetic of For you, and nothing else.
 *
 * No database, no clock of its own and no configuration read behind the
 * caller's back: every number it needs is handed to it, so every rule the
 * feature has can be tested as a rule. `InterestService` does the reading and
 * the writing around it; `InterestFeedService` uses the weights to rank posts.
 *
 * The rules, in the order a signal meets them:
 *
 *  - **What a look is worth** is decided against the reader's own habits, not
 *    a stopwatch: the time a post would take to read at an ordinary pace is
 *    worked out from its length and its pictures, and the ratio of the actual
 *    time to that is compared with the ratio this reader usually shows. A fast
 *    reader and a slow one both linger on what interests them — at different
 *    speeds.
 *  - **A post's signal is split** evenly across its hashtags, so a post with
 *    ten tags teaches ten tags a little rather than each of them a lot; past
 *    fifteen it is tag spam and teaches nothing.
 *  - **Scores decay** with a half-life, worked out when a score is read rather
 *    than by a job that rewrites every row, so what someone was into last
 *    spring does not run their feed today.
 *  - **The list** is every pinned tag at the rank it was pinned to, and the
 *    rest — manual, followed and learned tags above the threshold — filling
 *    the free ranks by score, up to the cap.
 */
class InterestScorer {
	/** Reading an empty post still takes a moment. */
	public const BASE_MS = 1200;
	/** About 1700 characters a minute: an unhurried reading pace. */
	public const PER_CHAR_MS = 35;
	/** A picture is looked at, not read. */
	public const PER_MEDIA_MS = 1500;
	/** Past this a post is long, and nobody is expected to read all of it. */
	public const EXPECTED_CAP_MS = 20000;

	/**
	 * A video longer than this counts as watched once this much of it was:
	 * one look is capped at `InterestService::MAX_DWELL_MS`, so a longer
	 * expectation could never be met.
	 */
	public const EXPECTED_WATCH_CAP_MS = 30000;
	/** Watched this much of it, or more, and a video held the reader. */
	public const WATCHED_SHARE = 0.9;
	/** Half of it is a video they watched rather than swiped past. */
	public const WATCHED_SOME = 0.5;

	/** How quickly the reader's own pace follows what they do. */
	public const BASELINE_ALPHA = 0.05;

	public const SIGNAL_LONG_DWELL = 1.0;
	public const SIGNAL_DWELL = 0.3;
	public const SIGNAL_SKIP = -0.2;
	public const SIGNAL_OPEN = 1.5;
	public const SIGNAL_MEDIA = 1.0;
	public const SIGNAL_LINK = 1.0;
	public const SIGNAL_FAVOURITE = 2.0;
	public const SIGNAL_BOOST = 3.0;
	public const SIGNAL_REPLY = 3.0;
	public const SIGNAL_BOOKMARK = 3.0;
	public const SIGNAL_MUTE = -1.0;
	public const SIGNAL_LESS = -3.0;

	/** A post with more tags than this is tag spam, and teaches nothing. */
	public const MAX_TAGS = 15;

	public const SCORE_MIN = -10.0;
	public const SCORE_MAX = 50.0;

	/** What a tag the reader has turned away from weighs in the feed. */
	public const NEGATIVE_WEIGHT = -0.5;

	/** How many learned tags just under the threshold are offered. */
	public const CANDIDATES = 10;

	/** Fewer listed interests than this and the feed is padded. */
	public const THIN = 3;

	/** A score that moved by more than this over a week has a trend. */
	public const TREND = 0.2;

	private const DAY = 86400;
	private const WEEK = 604800;

	public function __construct(
		private float $halfLifeDays = 30.0,
		private float $threshold = 3.0,
		private int $cap = 30,
	) {
	}

	public function getThreshold(): float {
		return $this->threshold;
	}

	public function getCap(): int {
		return $this->cap;
	}

	/**
	 * How long a post takes to read at an ordinary pace.
	 *
	 * @param int $chars visible characters of text
	 * @param int $media pictures and videos
	 */
	public function expectedDwellMs(int $chars, int $media): int {
		$expected = self::BASE_MS + self::PER_CHAR_MS * max(0, $chars) + self::PER_MEDIA_MS * max(0, $media);

		return min(self::EXPECTED_CAP_MS, $expected);
	}

	/**
	 * What a look was worth, and the reader's pace after it.
	 *
	 * `r` is how long they looked over how long the post takes to read. Twice
	 * their usual `r` is a post that held them; their usual or more is one
	 * they read; less is one they only glanced at, which says nothing either
	 * way. The pace moves a little towards every look.
	 *
	 * @return array{0: float, 1: float} the signal, and the new baseline
	 */
	public function classifyDwell(int $dwellMs, int $expectedMs, float $baseline): array {
		$ratio = (float)max(0, $dwellMs) / (float)max(1, $expectedMs);
		$baseline = ($baseline > 0) ? $baseline : 1.0;

		if ($ratio >= 2.0 * $baseline) {
			$signal = self::SIGNAL_LONG_DWELL;
		} elseif ($ratio >= $baseline) {
			$signal = self::SIGNAL_DWELL;
		} else {
			$signal = 0.0;
		}

		// capped, so one post left on screen during lunch does not teach the
		// reader's pace that they read at a twentieth of their speed
		$next = (1.0 - self::BASELINE_ALPHA) * $baseline + self::BASELINE_ALPHA * min($ratio, 10.0);

		return [$signal, $next];
	}

	/**
	 * How long a video takes to watch: its running time, up to the cap.
	 *
	 * @param int $durationMs how long it runs
	 */
	public function expectedWatchMs(int $durationMs): int {
		return max(1, min(self::EXPECTED_WATCH_CAP_MS, $durationMs));
	}

	/**
	 * What watching a video was worth. Most or all of it is a video that held
	 * the reader, as a long look at a post is; half of it one they watched;
	 * less says nothing either way, the swipe away within a moment being a
	 * `skip` of its own.
	 */
	public function classifyWatch(int $watchedMs, int $expectedMs): float {
		$share = (float)max(0, $watchedMs) / (float)max(1, $expectedMs);

		if ($share >= self::WATCHED_SHARE) {
			return self::SIGNAL_LONG_DWELL;
		}

		return ($share >= self::WATCHED_SOME) ? self::SIGNAL_DWELL : 0.0;
	}

	/**
	 * A signal shared among a post's hashtags.
	 *
	 * @param string[] $hashtags as the post carries them
	 *
	 * @return array<string, float> normalised tag => its share
	 */
	public function share(float $signal, array $hashtags): array {
		$tags = [];
		foreach ($hashtags as $hashtag) {
			$tag = FollowedTagsRequest::normalise((string)$hashtag);
			if ($tag !== '') {
				$tags[$tag] = true;
			}
		}

		$count = count($tags);
		if ($count === 0 || $count > self::MAX_TAGS || $signal === 0.0) {
			return [];
		}

		return array_fill_keys(array_keys($tags), $signal / (float)$count);
	}

	/** A score as it stands now, `$now - $scoredAt` seconds after it was set. */
	public function decayed(float $score, int $scoredAt, int $now): float {
		if ($scoredAt <= 0 || $now <= $scoredAt || $this->halfLifeDays <= 0) {
			return $score;
		}

		return $score * 2.0 ** (-(float)($now - $scoredAt) / ($this->halfLifeDays * (float)self::DAY));
	}

	/**
	 * Adds a signal to a row, in place.
	 *
	 * The row's score is decayed to now first, so the old score and the new
	 * signal are in the same currency. A pinned or manual row does not decay —
	 * the reader said so, and that does not wear off. When the last signal
	 * came in an earlier week, the score as that week ends is kept for the
	 * trend.
	 */
	public function fold(Interest $interest, float $delta, int $now): Interest {
		$current = $this->current($interest, $now);

		if ($interest->getScoredAt() > 0
			&& intdiv($interest->getScoredAt(), self::WEEK) !== intdiv($now, self::WEEK)) {
			$interest->setScoreWeek($current);
		}

		$interest->setScore(max(self::SCORE_MIN, min(self::SCORE_MAX, $current + $delta)))
			->setScoredAt($now);

		return $interest;
	}

	/** The row's score now: decayed, unless the reader fixed it. */
	public function current(Interest $interest, int $now): float {
		if ($interest->isManual() || $interest->isPinned()) {
			return $interest->getScore();
		}

		return $this->decayed($interest->getScore(), $interest->getScoredAt(), $now);
	}

	/**
	 * Moves the times on rows by a pause, so a pause does not cost the reader
	 * a week of decay.
	 *
	 * @param Interest[] $interests
	 */
	public function shift(array $interests, int $seconds): void {
		if ($seconds <= 0) {
			return;
		}

		foreach ($interests as $interest) {
			if ($interest->getScoredAt() > 0) {
				$interest->setScoredAt($interest->getScoredAt() + $seconds);
			}
		}
	}

	/**
	 * The reader's interests, in rank order, and the tags just under them.
	 *
	 * @param Interest[] $rows every row the reader has
	 * @param string[] $followed the hashtags they follow, normalised
	 *
	 * @return array{
	 *     listed: list<array{tag: string, rank: int, source: string, pinned: bool, score: float, trend: ?string}>,
	 *     candidates: list<array{tag: string, score: float}>,
	 *     negative: list<string>,
	 *     thin: bool,
	 * }
	 */
	public function assemble(array $rows, array $followed, int $now): array {
		$followed = array_fill_keys($followed, true);
		$entries = [];
		foreach ($rows as $row) {
			$entries[$row->getHashtag()] = $row;
		}
		foreach (array_keys($followed) as $tag) {
			$entries[$tag] ??= new Interest((string)$tag);
		}

		$pinned = [];
		$floating = [];
		$candidates = [];
		$negative = [];
		foreach ($entries as $tag => $row) {
			$tag = (string)$tag;
			$score = $this->current($row, $now);
			$isFollowed = isset($followed[$tag]);
			$source = $isFollowed ? 'followed' : ($row->isManual() ? 'manual' : 'learned');
			if ($isFollowed || $row->isManual()) {
				$score = max($score, $this->threshold);
			}

			$entry = [
				'tag' => $tag,
				'source' => $source,
				'pinned' => $row->isPinned(),
				'score' => round($score, 2),
				'trend' => ($source === 'learned') ? $this->trend($score, $row->getScoreWeek()) : null,
				'position' => $row->getPosition(),
			];

			if ($row->isPinned()) {
				$pinned[] = $entry;
			} elseif ($source !== 'learned' || $score >= $this->threshold) {
				$floating[] = $entry;
			} elseif ($score > 0) {
				$candidates[] = ['tag' => $tag, 'score' => round($score, 2)];
			} elseif ($score < 0) {
				$negative[] = $tag;
			}
		}

		usort($pinned, static fn (array $a, array $b): int => [$a['position'], $a['tag']] <=> [$b['position'], $b['tag']]);
		usort($floating, static fn (array $a, array $b): int => [$b['score'], $a['tag']] <=> [$a['score'], $b['tag']]);

		// the cap limits what floats in; a pin is the reader's word and stays
		$length = max(count($pinned), min($this->cap, count($pinned) + count($floating)));
		$slots = array_fill(0, $length, null);
		foreach ($pinned as $entry) {
			$slots[$this->freeSlot($slots, (int)$entry['position'])] = $entry;
		}

		$overflow = $floating;
		foreach ($slots as $index => $slot) {
			if ($slot === null) {
				$slots[$index] = array_shift($overflow);
			}
		}

		// a tag above the threshold that did not fit is still a tag the reader
		// might want: first in line among the candidates
		foreach ($overflow as $entry) {
			if ($entry['source'] === 'learned') {
				array_unshift($candidates, ['tag' => $entry['tag'], 'score' => $entry['score']]);
			}
		}
		usort($candidates, static fn (array $a, array $b): int => [$b['score'], $a['tag']] <=> [$a['score'], $b['tag']]);

		$listed = [];
		foreach (array_values(array_filter($slots)) as $rank => $entry) {
			unset($entry['position']);
			$listed[] = ['tag' => $entry['tag'], 'rank' => $rank] + $entry;
		}

		return [
			'listed' => $listed,
			'candidates' => array_slice($candidates, 0, self::CANDIDATES),
			'negative' => $negative,
			'thin' => count($listed) < self::THIN,
		];
	}

	/**
	 * The slot a pin lands in: the one it asked for, or the nearest free one
	 * after it, or before it when the list ends first.
	 *
	 * @param array<int, mixed> $slots
	 */
	private function freeSlot(array $slots, int $wanted): int {
		$last = count($slots) - 1;
		$wanted = max(0, min($wanted, $last));
		for ($index = $wanted; $index <= $last; $index++) {
			if ($slots[$index] === null) {
				return $index;
			}
		}
		for ($index = $wanted - 1; $index >= 0; $index--) {
			if ($slots[$index] === null) {
				return $index;
			}
		}

		return $last;
	}

	private function trend(float $score, ?float $week): ?string {
		if ($week === null || abs($week) < 0.5) {
			return null;
		}

		$change = ($score - $week) / abs($week);
		if ($change > self::TREND) {
			return 'up';
		}
		if ($change < -self::TREND) {
			return 'down';
		}

		return null;
	}

	/**
	 * What a listed tag weighs in the feed, by its rank rather than its raw
	 * score: dragging a tag up has to have an effect the reader can predict.
	 * 1.0 at the top, a half by rank seven, under a fifth by rank thirty.
	 */
	public function weight(int $rank): float {
		return 1.0 / (1.0 + 0.15 * (float)max(0, $rank));
	}

	/**
	 * How well a post matches, from the weights of the tags it carries.
	 *
	 * At most three of its positive matches count, so piling on tags does not
	 * buy a post the top of the feed; every tag the reader turned away from
	 * counts against it.
	 *
	 * @param float[] $weights the weight of each listed tag the post carries
	 */
	public function relevance(array $weights): float {
		$positive = array_values(array_filter($weights, static fn (float $w): bool => $w > 0));
		$negative = array_filter($weights, static fn (float $w): bool => $w < 0);
		rsort($positive);

		return (float)array_sum(array_slice($positive, 0, 3)) + (float)array_sum($negative);
	}

	/** Halves a day: yesterday's perfect match ranks with today's half one. */
	public function recency(int $ageSeconds): float {
		return 2.0 ** (-(float)max(0, $ageSeconds) / (float)self::DAY);
	}
}
