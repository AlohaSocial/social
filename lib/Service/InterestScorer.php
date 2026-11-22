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
 * The arithmetic of For you, with everything it needs handed in (no database,
 * clock or configuration), so each rule can be tested. `InterestService` reads
 * and writes around it; `InterestFeedService` ranks posts with the weights.
 *
 *  - Passive time on screen is neutral; only a Shorts player's measured
 *    playback ratio, explicit actions and skips teach the profile.
 *  - A post's signal is split evenly across its hashtags; past fifteen tags
 *    it is spam and teaches nothing.
 *  - Scores decay with a half-life, computed when read.
 *  - The list is followed tags and learned tags above the threshold, ranked
 *    by score up to the cap. Manual and pinned rows are ignored.
 */
class InterestScorer {
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
	 * Adds a signal to a row, in place, decaying its score to now first. When
	 * the last signal came in an earlier week, the score as that week ended is
	 * kept for the trend.
	 */
	public function fold(Interest $interest, float $delta, int $now): Interest {
		// manual and pinned rows do not seed automatic learning
		if ($interest->isManual() || $interest->isPinned()) {
			$interest = new Interest($interest->getHashtag());
		}

		$current = $this->current($interest, $now);

		if ($interest->getScoredAt() > 0
			&& intdiv($interest->getScoredAt(), self::WEEK) !== intdiv($now, self::WEEK)) {
			$interest->setScoreWeek($current);
		}

		$interest->setScore(max(self::SCORE_MIN, min(self::SCORE_MAX, $current + $delta)))
			->setScoredAt($now);

		return $interest;
	}

	/** The automatically learned score now. */
	public function current(Interest $interest, int $now): float {
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
			if ($row->isManual() || $row->isPinned()) {
				continue;
			}
			$entries[$row->getHashtag()] = $row;
		}
		foreach (array_keys($followed) as $tag) {
			$entries[$tag] ??= new Interest((string)$tag);
		}

		$floating = [];
		$candidates = [];
		$negative = [];
		foreach ($entries as $tag => $row) {
			$tag = (string)$tag;
			$score = $this->current($row, $now);
			$isFollowed = isset($followed[$tag]);
			$source = $isFollowed ? 'followed' : 'learned';
			if ($isFollowed) {
				$score = max($score, $this->threshold);
			}

			$entry = [
				'tag' => $tag,
				'source' => $source,
				'pinned' => false,
				'score' => round($score, 2),
				'trend' => ($source === 'learned') ? $this->trend($score, $row->getScoreWeek()) : null,
			];

			if ($source !== 'learned' || $score >= $this->threshold) {
				$floating[] = $entry;
			} elseif ($score > 0) {
				$candidates[] = ['tag' => $tag, 'score' => round($score, 2)];
			} elseif ($score < 0) {
				$negative[] = $tag;
			}
		}

		usort($floating, static fn (array $a, array $b): int => [$b['score'], $a['tag']] <=> [$a['score'], $b['tag']]);

		$listedEntries = array_slice($floating, 0, $this->cap);
		$overflow = array_slice($floating, $this->cap);

		// a tag above the threshold that did not fit is still a tag the reader
		// might want: first in line among the candidates
		foreach ($overflow as $entry) {
			if ($entry['source'] === 'learned') {
				array_unshift($candidates, ['tag' => $entry['tag'], 'score' => $entry['score']]);
			}
		}
		usort($candidates, static fn (array $a, array $b): int => [$b['score'], $a['tag']] <=> [$a['score'], $b['tag']]);

		$listed = [];
		foreach ($listedEntries as $rank => $entry) {
			$listed[] = ['tag' => $entry['tag'], 'rank' => $rank] + $entry;
		}

		return [
			'listed' => $listed,
			'candidates' => array_slice($candidates, 0, self::CANDIDATES),
			'negative' => $negative,
			'thin' => count($listed) < self::THIN,
		];
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
