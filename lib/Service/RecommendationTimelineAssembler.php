<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Stream;

/** Turns profile and post data into rankable candidates and rendered feed items. */
class RecommendationTimelineAssembler {
	private const MAX_TERMS = 20;

	public function __construct(
		private RecommendationTopicCatalog $catalog,
		private RecommendationFeatureExtractor $features,
		private RecommendationExplanationService $explanations,
		private RecommendationBehavioralProfile $behavioralProfile,
	) {
	}

	/** @param array<string, float> $behavioral @return list<array{id: string, aliases: list<string>, labels: array{en: string, de: string}}> */
	public function candidateTopics(array $behavioral): array {
		$topics = [];
		arsort($behavioral, SORT_NUMERIC);
		foreach ($behavioral as $topicId => $affinity) {
			if ($affinity <= 0.0) {
				continue;
			}
			$topic = $this->catalog->find($topicId);
			if ($topic === null || !$topic['enabled'] || in_array('sensitive-topic', $topic['policyTags'], true)) {
				continue;
			}
			$topics[] = ['id' => $topicId, 'aliases' => $topic['aliases'], 'labels' => $topic['labels']];
			if (count($topics) >= 20) {
				break;
			}
		}
		return $topics;
	}

	/** @param list<array{id: string, aliases: list<string>, labels: array{en: string, de: string}}> $topics @return list<string> */
	public function terms(array $topics, int $now): array {
		$aliasesByTopic = [];
		foreach ($topics as $topic) {
			$aliases = array_values(array_unique(array_filter(
				array_map('trim', $topic['aliases']),
				static fn (string $alias): bool => $alias !== ''
			)));
			if ($aliases !== []) {
				$aliasesByTopic[] = $aliases;
			}
		}

		// Keep indexed lookup bounded while rotating through large automatic
		// profiles over successive hourly refreshes.
		$count = count($aliasesByTopic);
		if ($count === 0) {
			return [];
		}
		$start = (intdiv($now, 3600) * self::MAX_TERMS) % $count;
		$terms = [];
		for ($offset = 0; $offset < $count && count($terms) < self::MAX_TERMS; $offset++) {
			$aliases = $aliasesByTopic[($start + $offset) % $count];
			if (!in_array($aliases[0], $terms, true)) {
				$terms[] = $aliases[0];
			}
		}
		// Use remaining capacity for alternate spellings after each topic has had
		// a primary-alias opportunity in the rotating window.
		for ($aliasIndex = 1; $aliasIndex < self::MAX_TERMS && count($terms) < self::MAX_TERMS; $aliasIndex++) {
			for ($offset = 0; $offset < $count && count($terms) < self::MAX_TERMS; $offset++) {
				$aliases = $aliasesByTopic[($start + $offset) % $count];
				if (isset($aliases[$aliasIndex]) && !in_array($aliases[$aliasIndex], $terms, true)) {
					$terms[] = $aliases[$aliasIndex];
				}
			}
		}

		return $terms;
	}

	/** @param array<string, mixed> $feedback @return array<string, mixed>|null */
	public function candidate(
		Stream $post,
		string $nid,
		array $topics,
		string $media,
		int $now,
		array $feedback,
		array $behavioral,
		array $followedAuthors,
		array $exposurePenalties,
		array $automaticSignals,
	): ?array {
		$features = $this->features->extract($post, true);
		$allTopicIds = array_keys($features['topics']);
		$selected = [];
		foreach ($topics as $topic) {
			if (isset($features['topics'][$topic['id']])) {
				$selected[$topic['id']] = $topic;
			}
		}
		if (!RecommendationMediaFilter::matches($features['format'], $media)) {
			return null;
		}
		$bestMatch = 0.0;
		$evidence = '';
		foreach ($selected as $topicId => $topic) {
			$match = $features['topics'][$topicId];
			$score = (float)$match['score'];
			if ($score > $bestMatch) {
				$bestMatch = $score;
				$evidence = (string)$match['evidence'];
			}
		}

		$format = $features['format'];
		$author = $post->getAttributedTo();
		$isFollowed = isset($followedAuthors[$author]);
		$exposurePenalty = $exposurePenalties[$nid] ?? 0.0;
		$authorAffinity = $this->behavioralProfile->authorAffinity($author, $behavioral['authors']);
		$behavioralTopic = $this->behavioralProfile->topicAffinity($allTopicIds, $behavioral['topics']);
		$automaticInterest = $automaticSignals[$nid] ?? 0.0;
		// Media-only posts have no caption to classify. Keep them in the
		// bounded discovery pool for cold-start readers; the upstream candidate
		// sources and all visibility, moderation, language and hide checks still
		// apply before a candidate reaches this assembler.
		$mediaOnly = in_array($format, ['image', 'video', 'short'], true);
		if ($selected === [] && $automaticInterest <= 0.0 && $behavioralTopic <= 0.0 && $authorAffinity <= 0.0 && !$isFollowed && $allTopicIds === [] && !$mediaOnly) {
			return null;
		}
		$authorFeedback = $author === '' ? null : ($feedback['authors'][hash('sha256', $author)] ?? null);
		$formatFeedback = $feedback['formats'][$format] ?? null;
		$clusterKey = RecommendationContentCluster::key($allTopicIds, $format, $this->catalog);
		$similarityFeedback = $clusterKey === null ? null : ($feedback['similarities'][$clusterKey] ?? null);
		$published = $post->getPublishedTime();
		return [
			'nid' => $nid,
			'canonical_key' => $post->getId() !== '' ? $post->getId() : $nid,
			'author_key' => $author,
			'topic_ids' => $allTopicIds,
			'diversity_topics' => $this->features->diversityTopics($allTopicIds),
			// Used only by the in-memory re-ranker; snapshots store NIDs and the
			// bounded explanation, never post-derived text terms.
			'story_terms' => $features['story_terms'] ?? [],
			'matching_topic_ids' => array_keys($selected),
			'protocol' => RecommendationProtocol::of($post),
			'published_at' => $published,
			'format' => $format,
			'topic_match' => $bestMatch,
			'automatic_interest' => $automaticInterest,
			'behavioral_topic' => $behavioralTopic,
			'behavioral_author' => $authorAffinity,
			'behavioral_format' => $this->behavioralProfile->formatAffinity($format, $behavioral['formats']),
			'is_followed' => $isFollowed ? 1.0 : 0.0,
			'novelty' => RecommendationBehavioralProfile::novelty($authorAffinity, $isFollowed, $exposurePenalty),
			'freshness' => RecommendationFreshness::score($post, $now, $format),
			'author_affinity' => $authorFeedback === 'MORE_AUTHOR' ? 1.0 : 0.0,
			'exposure_penalty' => $exposurePenalty,
			'format_match' => $formatFeedback === 'MORE_FORMAT' ? 1.0 : 0.0,
			'feedback_alignment' => match ($similarityFeedback) {
				'MORE_SIMILAR' => 1.0,
				'LESS_SIMILAR' => -1.0,
				default => 0.0,
			},
			'source_reliability' => 0.5,
			'evidence' => $evidence,
			'rank_score' => 0.0,
		];
	}

	/** @param array<string, mixed> $candidate @return array<string, mixed> */
	public function explain(array $candidate, int $profileVersion, int $createdAt): array {
		return $this->explanations->explain($candidate, $profileVersion, $createdAt);
	}

	/** @param array<string, Stream> $posts @param list<array<string, mixed>> $candidates @param list<array{id: string, aliases: list<string>, labels: array{en: string, de: string}}> $topics @param list<string> $explorationNids @param list<array{nid: string, reason: array<string, mixed>}> $explanationEntries @return list<Stream> */
	public function hydrate(array $posts, array $candidates, array $topics, array $explorationNids = [], array $explanationEntries = []): array {
		$byId = [];
		foreach ($topics as $topic) {
			$byId[$topic['id']] = $topic;
		}
		$explanationsByNid = [];
		foreach ($explanationEntries as $entry) {
			$explanationsByNid[$entry['nid']] = $entry['reason'];
		}
		$items = [];
		foreach ($candidates as $candidate) {
			$post = $posts[$candidate['nid']] ?? null;
			if (!$post instanceof Stream) {
				continue;
			}
			/** @var list<array{id: string, labels: array{en: string, de: string}}> $matched */
			$matched = [];
			foreach ($candidate['matching_topic_ids'] as $topicId) {
				if (isset($byId[$topicId])) {
					$matched[] = ['id' => $topicId, 'labels' => $byId[$topicId]['labels']];
				}
			}
			$isExploration = ($candidate['_exploration_slot'] ?? false) === true
				|| in_array((string)$candidate['nid'], $explorationNids, true);
			$interestReason = $isExploration
				? 'exploration'
				: (($candidate['automatic_interest'] ?? 0.0) > 0.0 || ($candidate['behavioral_topic'] ?? 0.0) > 0.0 || ($candidate['behavioral_author'] ?? 0.0) > 0.0
					? 'automatic'
					: ((float)($candidate['is_followed'] ?? 0.0) === 1.0 ? 'followed_author' : 'discovery'));
			$explanation = $explanationsByNid[(string)$candidate['nid']]
				?? $this->explanations->explain($candidate, 0, 0);
			$post->setExportFormat(ACore::FORMAT_LOCAL)->setInterest([
				'tags' => [],
				'reason' => $interestReason,
				'topics' => $matched,
				'selection_stage' => 'RERANK',
				'explanation' => $explanation,
			]);
			$items[] = $post;
		}
		return $items;
	}
}
