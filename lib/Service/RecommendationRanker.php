<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

/**
 * Transparent v1 linear ranker. Weights are an initial, uncalibrated
 * hypothesis; missing evidence is neutral and never fabricated.
 */
final class RecommendationRanker {
	public const ALGORITHM_VERSION = 25;
	public const FEATURE_VERSION = 12;

	/** @var array<string, float> */
	private const BALANCED_WEIGHTS = [
		'topic_match' => 0.04,
		'automatic_interest' => 0.15,
		'behavioral_topic' => 0.08,
		'behavioral_author' => 0.04,
		'behavioral_format' => 0.02,
		'author_affinity' => 0.18,
		'is_followed' => 0.12,
		'freshness' => 0.07,
		'format_match' => 0.10,
		'usefulness' => 0.08,
		'feedback_alignment' => 0.07,
		'novelty' => 0.05,
	];

	/** @var array<string, array<string, float>> */
	private const BALANCE_WEIGHTS = [
		'following' => [
			'topic_match' => 0.03,
			'automatic_interest' => 0.15,
			'behavioral_topic' => 0.07,
			'behavioral_author' => 0.06,
			'behavioral_format' => 0.02,
			'author_affinity' => 0.18,
			'is_followed' => 0.18,
			'freshness' => 0.06,
			'format_match' => 0.08,
			'usefulness' => 0.06,
			'feedback_alignment' => 0.07,
			'novelty' => 0.04,
		],
		'discovery' => [
			'topic_match' => 0.07,
			'automatic_interest' => 0.15,
			'behavioral_topic' => 0.09,
			'behavioral_author' => 0.02,
			'behavioral_format' => 0.02,
			'author_affinity' => 0.18,
			'is_followed' => 0.08,
			'freshness' => 0.06,
			'format_match' => 0.08,
			'usefulness' => 0.07,
			'feedback_alignment' => 0.07,
			'novelty' => 0.11,
		],
	];

	/**
	 * @param list<array<string, mixed>> $candidates
	 * @return list<array<string, mixed>>
	 */
	public function rank(array $candidates, string $contentBalance = 'balanced'): array {
		$weights = self::BALANCE_WEIGHTS[$contentBalance] ?? self::BALANCED_WEIGHTS;
		$ranked = [];
		foreach ($candidates as $candidate) {
			if (!isset($candidate['nid']) || !is_scalar($candidate['nid']) || (isset($candidate['eligible']) && $candidate['eligible'] !== true)) {
				continue;
			}

			$features = [
				'topic_match' => self::signed($candidate['topic_match'] ?? null, 0.0),
				'automatic_interest' => self::signed($candidate['automatic_interest'] ?? null, 0.0),
				'behavioral_topic' => self::signed($candidate['behavioral_topic'] ?? null, 0.0),
				'behavioral_author' => self::signed($candidate['behavioral_author'] ?? null, 0.0),
				'behavioral_format' => self::signed($candidate['behavioral_format'] ?? null, 0.0),
				'author_affinity' => self::unit($candidate['author_affinity'] ?? null, 0.0),
				'is_followed' => self::binary($candidate['is_followed'] ?? null, 0.0),
				'freshness' => self::unit($candidate['freshness'] ?? null, 0.5),
				'format_match' => self::signed($candidate['format_match'] ?? null, 0.0),
				'usefulness' => self::unit($candidate['usefulness'] ?? null, 0.5),
				'feedback_alignment' => self::signed($candidate['feedback_alignment'] ?? null, 0.0),
				'novelty' => self::unit($candidate['novelty'] ?? null, 0.0),
				'exposure_penalty' => self::unit($candidate['exposure_penalty'] ?? null, 0.0),
			];

			$score = 0.0;
			foreach ($weights as $key => $weight) {
				$feature = $features[$key];
				$score += $weight * (in_array($key, ['topic_match', 'automatic_interest', 'behavioral_topic', 'behavioral_author', 'behavioral_format', 'format_match', 'feedback_alignment'], true)
					? ($feature + 1.0) / 2.0
					: $feature);
			}
			$score -= 0.12 * $features['exposure_penalty'];

			$candidate['rank_score'] = $score;
			$candidate['rank_features'] = $features;
			$candidate['algorithm_version'] = self::ALGORITHM_VERSION;
			$candidate['feature_version'] = self::FEATURE_VERSION;
			$ranked[] = $candidate;
		}

		usort($ranked, static function (array $left, array $right): int {
			$scoreOrder = $right['rank_score'] <=> $left['rank_score'];
			if ($scoreOrder !== 0) {
				return $scoreOrder;
			}

			$reliabilityOrder = self::unit($right['source_reliability'] ?? null, 0.5)
				<=> self::unit($left['source_reliability'] ?? null, 0.5);
			if ($reliabilityOrder !== 0) {
				return $reliabilityOrder;
			}

			$canonicalOrder = strcmp(self::canonicalKey($left), self::canonicalKey($right));
			return $canonicalOrder !== 0 ? $canonicalOrder : strcmp((string)$left['nid'], (string)$right['nid']);
		});

		return $ranked;
	}

	private static function unit(mixed $value, float $default): float {
		if (!is_numeric($value) || !is_finite((float)$value)) {
			return $default;
		}

		return max(0.0, min(1.0, (float)$value));
	}

	private static function signed(mixed $value, float $default): float {
		if (!is_numeric($value) || !is_finite((float)$value)) {
			return $default;
		}

		return max(-1.0, min(1.0, (float)$value));
	}

	private static function binary(mixed $value, float $default): float {
		if ($value === true || $value === 1 || $value === 1.0) {
			return 1.0;
		}
		if ($value === false || $value === 0 || $value === 0.0) {
			return 0.0;
		}
		return $default;
	}

	/** @param array<string, mixed> $candidate */
	private static function canonicalKey(array $candidate): string {
		$key = $candidate['canonical_key'] ?? $candidate['nid'] ?? '';
		return is_scalar($key) ? (string)$key : '';
	}
}
