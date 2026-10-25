<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

/** Selects a deterministic page with bounded author, topic and story repetition. */
final class RecommendationReRanker {
	private const WINDOW_SIZE = 20;
	private const MAX_AUTHOR_IN_WINDOW = 2;
	private const MAX_TOPIC_IN_WINDOW = 3;
	private const MAX_FORMAT_IN_WINDOW = 5;
	private const FORMAT_CONCENTRATION_COST = 0.08;
	private const MAX_STORY_IN_PAGE = 3;
	private const MAX_STORY_OVERFLOW = 1;
	private const MIN_STORY_TERMS = 3;
	private const MIN_FUZZY_STORY_OVERLAP = 3;
	private const MAX_STORY_TERMS = 60;
	private const HEADLINE_TERMS = 20;
	private const MIN_HEADLINE_OVERLAP = 6;
	private const MIN_HEADLINE_SIMILARITY = 0.50;
	private const MIN_STORY_OVERLAP = 0.60;
	private const TOPIC_CONCENTRATION_COST = 0.18;
	private const STORY_FATIGUE_THRESHOLD = 0.75;
	private const LOOKAHEAD = 100;
	private const DEFAULT_EXPLORATION_INTERVAL = 10;
	private const EXPLORATION_MIN_RANK_DISTANCE = 4;
	private const EXPLORATION_MIN_TOPIC_MATCH = 0.65;
	private const EXPLORATION_MIN_NOVELTY = 0.75;
	private const EXPLORATION_MAX_EXPOSURE = 0.25;
	private const EXPLORATION_MAX_SCORE_DROP = 0.20;

	/**
	 * @param list<array<string, mixed>> $rankedCandidates
	 * @return list<array<string, mixed>>
	 */
	public function page(array $rankedCandidates, int $pageSize = 20, ?string $sessionSeed = null, string $contentBalance = 'balanced'): array {
		$pageSize = max(0, min(100, $pageSize));
		$explorationInterval = match ($contentBalance) {
			'following' => self::DEFAULT_EXPLORATION_INTERVAL * 2,
			'discovery' => max(1, intdiv(self::DEFAULT_EXPLORATION_INTERVAL, 2)),
			default => self::DEFAULT_EXPLORATION_INTERVAL,
		};
		$remaining = [];
		$seen = [];
		foreach ($rankedCandidates as $candidate) {
			if (($candidate['eligible'] ?? true) !== true || !isset($candidate['nid'])) {
				continue;
			}
			$key = (string)($candidate['canonical_key'] ?? $candidate['nid']);
			if ($key === '' || isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$candidate['_base_position'] = count($remaining);
			$remaining[] = $candidate;
		}
		foreach (self::storyClusterIds($remaining) as $index => $cluster) {
			$remaining[$index]['_story_cluster'] = $cluster;
		}
		$sessionSeed ??= hash('sha256', implode("\0", array_keys($seen)));

		$selected = [];
		while ($remaining !== [] && count($selected) < $pageSize) {
			$window = array_slice($remaining, 0, self::LOOKAHEAD);
			$saturatedTopics = array_fill_keys(self::saturatedTopics($selected), true);
			$saturatedAuthors = array_fill_keys(self::saturatedAuthors($selected), true);
			$hasDiverseAlternative = false;
			$hasUnseenAlternative = false;
			foreach ($window as $candidate) {
				$author = (string)($candidate['author_key'] ?? '');
				$storySaturated = self::storyCount($candidate, $selected) >= self::MAX_STORY_IN_PAGE;
				$topicSaturated = self::allTopicsSaturated(self::topicIds($candidate), $saturatedTopics);
				$authorSaturated = $author !== '' && isset($saturatedAuthors[$author]);
				if (!$storySaturated && !$topicSaturated && !$authorSaturated) {
					$hasDiverseAlternative = true;
					if (self::exposurePenalty($candidate) < self::STORY_FATIGUE_THRESHOLD) {
						$hasUnseenAlternative = true;
					}
					if ($hasUnseenAlternative) {
						break;
					}
				}
			}
			$bestIndex = 0;
			$bestAdjusted = -INF;
			$adjustedScores = [];
			foreach ($window as $index => $candidate) {
				$author = (string)($candidate['author_key'] ?? '');
				$storyCount = self::storyCount($candidate, $selected);
				$storySaturated = $storyCount >= self::MAX_STORY_IN_PAGE;
				$topicSaturated = self::allTopicsSaturated(self::topicIds($candidate), $saturatedTopics);
				$authorSaturated = $author !== '' && isset($saturatedAuthors[$author]);
				$storyOverflow = $storySaturated
					&& !$hasDiverseAlternative
					&& $storyCount < self::MAX_STORY_IN_PAGE + self::MAX_STORY_OVERFLOW;
				$exposureFatigued = self::exposurePenalty($candidate) >= self::STORY_FATIGUE_THRESHOLD;
				if (($storySaturated && !$storyOverflow)
					|| ($hasDiverseAlternative && ($topicSaturated || $authorSaturated))
					|| ($hasUnseenAlternative && $exposureFatigued)) {
					continue;
				}
				$topics = self::topicIds($candidate);
				$penalty = 0.0;
				if ($author !== '') {
					$recentAuthorCount = self::recentCount($selected, $author, 'author_key');
					$penalty += max(0.0, (float)($recentAuthorCount - self::MAX_AUTHOR_IN_WINDOW + 1)) * 0.08;
				}
				foreach ($topics as $topic) {
					$recentTopicCount = self::recentTopicCount($selected, $topic);
					$penalty += max(0.0, (float)($recentTopicCount - self::MAX_TOPIC_IN_WINDOW + 1)) * self::TOPIC_CONCENTRATION_COST;
				}
				$format = (string)($candidate['format'] ?? '');
				if ($format !== '') {
					$recentFormatCount = self::recentCount($selected, $format, 'format');
					$penalty += max(0.0, (float)($recentFormatCount - self::MAX_FORMAT_IN_WINDOW + 1)) * self::FORMAT_CONCENTRATION_COST;
				}
				$adjusted = (float)($candidate['rank_score'] ?? 0.0) - $penalty;
				$adjustedScores[$index] = $adjusted;
				if ($adjusted > $bestAdjusted) {
					$bestAdjusted = $adjusted;
					$bestIndex = $index;
				}
			}
			if ($bestAdjusted === -INF) {
				break;
			}
			$slot = count($selected) + 1;
			$wasExploration = false;
			if ($slot % $explorationInterval === 0) {
				$explorationIndex = self::explorationCandidate($window, $adjustedScores, $bestAdjusted, $slot, $sessionSeed);
				if ($explorationIndex !== null) {
					$bestIndex = $explorationIndex;
					$wasExploration = true;
				}
			}

			$chosen = $remaining[$bestIndex];
			if ($wasExploration) {
				$chosen['_exploration_slot'] = true;
			}
			$chosen['_promoted_by_diversity'] = !$wasExploration && $bestIndex > 0;
			unset($chosen['_base_position']);
			array_splice($remaining, $bestIndex, 1);
			$selected[] = $chosen;
		}
		foreach ($selected as &$candidate) {
			unset($candidate['story_terms']);
			unset($candidate['_story_cluster']);
		}
		unset($candidate);

		return $selected;
	}

	/** @param list<array<string, mixed>> $window @param array<int, float> $adjustedScores */
	private static function explorationCandidate(array $window, array $adjustedScores, float $bestAdjusted, int $slot, string $sessionSeed): ?int {
		$eligible = [];
		foreach ($window as $index => $candidate) {
			if (!self::qualifiesForExploration($candidate, $adjustedScores[$index] ?? -INF, $bestAdjusted, $slot)) {
				continue;
			}
			$key = (string)($candidate['canonical_key'] ?? $candidate['nid'] ?? '');
			$eligible[$index] = hash('sha256', $sessionSeed . "\0" . $key);
		}
		if ($eligible === []) {
			return null;
		}
		asort($eligible, SORT_STRING);
		return array_key_first($eligible);
	}

	/** @param array<string, mixed> $candidate */
	private static function qualifiesForExploration(array $candidate, float $adjusted, float $bestAdjusted, int $slot): bool {
		$number = static fn (mixed $value): float => is_numeric($value) && is_finite((float)$value) ? (float)$value : 0.0;
		$matchingTopics = $candidate['matching_topic_ids'] ?? [];
		return ($candidate['_base_position'] ?? PHP_INT_MAX) >= $slot - 1 + self::EXPLORATION_MIN_RANK_DISTANCE
			&& is_array($matchingTopics) && $matchingTopics !== []
			&& $number($candidate['is_followed'] ?? 1.0) === 0.0
			&& $number($candidate['topic_match'] ?? 0.0) >= self::EXPLORATION_MIN_TOPIC_MATCH
			&& $number($candidate['novelty'] ?? 0.0) >= self::EXPLORATION_MIN_NOVELTY
			&& $number($candidate['exposure_penalty'] ?? 1.0) <= self::EXPLORATION_MAX_EXPOSURE
			&& $adjusted >= $bestAdjusted - self::EXPLORATION_MAX_SCORE_DROP;
	}

	/** @param list<array<string, mixed>> $selected */
	private static function recentCount(array $selected, string $value, string $field): int {
		$recent = array_slice($selected, -self::WINDOW_SIZE);
		$count = 0;
		foreach ($recent as $candidate) {
			if (($candidate[$field] ?? '') === $value) {
				$count++;
			}
		}

		return $count;
	}

	/** @param list<array<string, mixed>> $selected */
	private static function recentTopicCount(array $selected, string $topic): int {
		$recent = array_slice($selected, -self::WINDOW_SIZE);
		$count = 0;
		foreach ($recent as $candidate) {
			if (in_array($topic, self::topicIds($candidate), true)) {
				$count++;
			}
		}

		return $count;
	}

	/** @param list<array<string, mixed>> $selected @return list<string> */
	private static function saturatedTopics(array $selected): array {
		$counts = [];
		foreach (array_slice($selected, -self::WINDOW_SIZE) as $candidate) {
			foreach (self::topicIds($candidate) as $topic) {
				$counts[$topic] = ($counts[$topic] ?? 0) + 1;
			}
		}

		return array_keys(array_filter($counts, static fn (int $count): bool => $count >= self::MAX_TOPIC_IN_WINDOW));
	}

	/** @param list<array<string, mixed>> $selected @return list<string> */
	private static function saturatedAuthors(array $selected): array {
		$counts = [];
		foreach (array_slice($selected, -self::WINDOW_SIZE) as $candidate) {
			$author = (string)($candidate['author_key'] ?? '');
			if ($author !== '') {
				$counts[$author] = ($counts[$author] ?? 0) + 1;
			}
		}

		return array_keys(array_filter($counts, static fn (int $count): bool => $count >= self::MAX_AUTHOR_IN_WINDOW));
	}

	/** @param list<string> $topics @param array<string, true> $saturated */
	private static function allTopicsSaturated(array $topics, array $saturated): bool {
		if ($topics === []) {
			return false;
		}
		foreach ($topics as $topic) {
			if (!isset($saturated[$topic])) {
				return false;
			}
		}

		return true;
	}

	/** @param array<string, mixed> $candidate @param list<array<string, mixed>> $selected */
	private static function storyCount(array $candidate, array $selected): int {
		$cluster = $candidate['_story_cluster'] ?? null;
		if (!is_int($cluster)) {
			return 0;
		}
		$count = 0;
		foreach ($selected as $previous) {
			if (($previous['_story_cluster'] ?? null) === $cluster) {
				$count++;
			}
		}

		return $count;
	}

	/** @param array<string, mixed> $candidate */
	private static function exposurePenalty(array $candidate): float {
		$value = $candidate['exposure_penalty'] ?? 0.0;

		return is_numeric($value) && is_finite((float)$value)
			? min(1.0, max(0.0, (float)$value))
			: 0.0;
	}

	/**
	 * Connect pairwise similar candidates into story groups. Similarity is not
	 * transitive, so connected components keep a sequence of headline variants
	 * from evading the page-wide story cap through an intermediate post.
	 *
	 * @param list<array<string, mixed>> $candidates
	 * @return array<int, int> candidate index => story cluster, only for identifiable stories
	 */
	public static function storyClusterIds(array $candidates): array {
		$parents = array_keys($candidates);
		$termsByIndex = [];
		foreach ($candidates as $index => $candidate) {
			$terms = self::storyTerms($candidate);
			if (count($terms) >= self::MIN_STORY_TERMS) {
				$termsByIndex[$index] = $terms;
			}
		}

		$indices = array_keys($termsByIndex);
		$postings = [];
		foreach ($indices as $index) {
			$terms = $termsByIndex[$index];
			$overlaps = [];
			foreach ($terms as $term) {
				foreach ($postings[$term] ?? [] as $priorIndex) {
					$overlaps[$priorIndex] = ($overlaps[$priorIndex] ?? 0) + 1;
				}
			}

			$termSet = array_fill_keys($terms, true);
			$headlineSet = array_fill_keys(array_slice($terms, 0, self::HEADLINE_TERMS), true);
			foreach ($overlaps as $priorIndex => $overlap) {
				if ($overlap < self::MIN_FUZZY_STORY_OVERLAP) {
					continue;
				}
				$leftRoot = self::storyRoot($parents, $index);
				$rightRoot = self::storyRoot($parents, $priorIndex);
				if ($leftRoot === $rightRoot
					|| !self::sameStoryTerms($terms, $termsByIndex[$priorIndex], $termSet, $headlineSet)) {
					continue;
				}
				$parents[$rightRoot] = $leftRoot;
			}

			foreach ($terms as $term) {
				$postings[$term][] = $index;
			}
		}

		$clusterIds = [];
		$nextCluster = 0;
		foreach ($indices as $index) {
			$root = self::storyRoot($parents, $index);
			if (!isset($clusterIds[$root])) {
				$clusterIds[$root] = $nextCluster++;
			}
			$clusterIds[$index] = $clusterIds[$root];
		}

		return array_intersect_key($clusterIds, $termsByIndex);
	}

	/** @param list<int> $parents */
	private static function storyRoot(array &$parents, int $index): int {
		$root = $index;
		while ($parents[$root] !== $root) {
			$root = $parents[$root];
		}
		while ($parents[$index] !== $index) {
			$next = $parents[$index];
			$parents[$index] = $root;
			$index = $next;
		}
		return $root;
	}

	/** @param list<string> $left @param list<string> $right @param array<string, true>|null $leftSet @param array<string, true>|null $leftHeadlineSet */
	private static function sameStoryTerms(array $left, array $right, ?array $leftSet = null, ?array $leftHeadlineSet = null): bool {
		$leftCount = count($left);
		$rightCount = count($right);
		if (min($leftCount, $rightCount) < self::MIN_STORY_TERMS) {
			return false;
		}
		$leftSet ??= array_fill_keys($left, true);
		$overlap = 0;
		foreach ($right as $term) {
			$overlap += isset($leftSet[$term]) ? 1 : 0;
		}

		$union = $leftCount + $rightCount - $overlap;
		if ($overlap >= self::MIN_FUZZY_STORY_OVERLAP
			&& $union > 0
			&& $overlap / $union >= self::MIN_STORY_OVERLAP) {
			return true;
		}

		$leftHeadline = array_slice($left, 0, self::HEADLINE_TERMS);
		$rightHeadline = array_slice($right, 0, self::HEADLINE_TERMS);
		if (min(count($leftHeadline), count($rightHeadline)) < self::MIN_HEADLINE_OVERLAP) {
			return false;
		}
		$leftHeadlineSet ??= array_fill_keys($leftHeadline, true);
		$headlineOverlap = 0;
		foreach ($rightHeadline as $term) {
			$headlineOverlap += isset($leftHeadlineSet[$term]) ? 1 : 0;
		}
		$headlineTerms = count($leftHeadline) + count($rightHeadline);

		return $headlineOverlap >= self::MIN_HEADLINE_OVERLAP
			&& (2 * $headlineOverlap) / $headlineTerms >= self::MIN_HEADLINE_SIMILARITY;
	}

	/** @param array<string, mixed> $candidate @return list<string> */
	private static function storyTerms(array $candidate): array {
		$terms = $candidate['story_terms'] ?? [];
		if (!is_array($terms)) {
			return [];
		}

		return array_slice(
			array_values(array_unique(array_filter(
				$terms,
				static fn (mixed $term): bool => is_string($term) && $term !== ''
			))),
			0,
			self::MAX_STORY_TERMS
		);
	}

	/** @param array<string, mixed> $candidate @return list<string> */
	private static function topicIds(array $candidate): array {
		$raw = $candidate['diversity_topics'] ?? null;
		if (!is_array($raw) || $raw === []) {
			$raw = $candidate['topic_ids'] ?? [];
		}

		return is_array($raw)
			? array_values(array_unique(array_filter($raw, static fn (mixed $topic): bool => is_string($topic) && $topic !== '')))
			: [];
	}
}
