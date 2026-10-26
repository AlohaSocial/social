<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use InvalidArgumentException;
use OCA\Social\Service\RecommendationOfflineEvaluator;
use OCA\Social\Service\RecommendationRanker;
use OCA\Social\Service\RecommendationReRanker;
use PHPUnit\Framework\TestCase;

class RecommendationOfflineEvaluatorTest extends TestCase {
	public function testComparesBaselinesAndExcludesUnshowableCandidates(): void {
		$report = $this->evaluator()->evaluate($this->dataset());

		$this->assertSame(10, count($report['variants']));
		$this->assertArrayHasKey('h0_ranker', $report['variants']);
		$this->assertArrayHasKey('h0_plus_rerank', $report['variants']);
		$this->assertArrayHasKey('h0_following_plus_rerank', $report['variants']);
		$this->assertArrayHasKey('h0_discovery_plus_rerank', $report['variants']);
		$this->assertSame(0.5, $report['dataset']['eligibility']['source_recall']);
		$this->assertSame(0.2, $report['dataset']['eligibility']['candidate_coverage_mean']);
		$this->assertSame([
			'eligible_and_available' => 1,
			'non_public' => 0,
			'not_discoverable' => 1,
			'deleted_or_not_visible' => 1,
			'explicitly_hidden_topic' => 1,
			'language_excluded' => 0,
			'source_unavailable' => 1,
		], $report['dataset']['eligibility']['failure_breakdown']['counts']);
		$this->assertSame(5, $report['dataset']['eligibility']['failure_breakdown']['profile_post_record_count']);
		foreach ($report['profiles']['reader']['h0_ranker'] as $unused) {
			// The profile result contains aggregate measurements only, never post content.
			$this->assertIsScalar($unused);
		}
		$this->assertSame(1, $report['profiles']['reader']['h0_ranker']['candidate_count']);
		$this->assertEquals(1.0, $report['profiles']['reader']['h0_ranker']['hard_control_compliance']);
		$this->assertSame(1, $report['profiles']['reader']['h0_plus_rerank']['returned_relevant']);
		$this->assertFalse($report['methodology']['weights_promoted']);
	}

	public function testRejectsMalformedDatasets(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->evaluator()->evaluate(['name' => 'invalid', 'profiles' => [], 'posts' => []]);
	}

	public function testCommittedSyntheticFixtureCoversBothProtocolsAndSurfacesRerankerTradeoffs(): void {
		$path = dirname(__DIR__, 2) . '/tools/recommendations/fixtures/synthetic-v1.json';
		$dataset = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		$report = $this->evaluator()->evaluate($dataset);

		$this->assertCount(12, $report['persona_scenarios']);
		$this->assertSame(33, $report['dataset']['corpus_record_count']);
		$this->assertSame(32, $report['dataset']['corpus_unique_post_count']);
		$this->assertArrayHasKey('activitypub', $report['dataset']['eligibility']['source_recall_by_protocol']);
		$this->assertArrayHasKey('atproto', $report['dataset']['eligibility']['source_recall_by_protocol']);
		foreach (['non_public', 'not_discoverable', 'deleted_or_not_visible', 'explicitly_hidden_topic', 'language_excluded', 'source_unavailable'] as $reason) {
			$this->assertGreaterThan(0, $report['dataset']['eligibility']['failure_breakdown']['counts'][$reason], $reason);
		}
		$this->assertSame(12 * 33, $report['dataset']['eligibility']['failure_breakdown']['profile_post_record_count']);
		$this->assertGreaterThan(0.0, $report['variants']['h0_ranker']['duplicate_rate']);
		$this->assertSame(0.0, $report['variants']['h0_plus_rerank']['duplicate_rate']);
		$this->assertSame(2, $report['schema_version']);
		$reader = $report['profiles']['nextcloud-open-source'];
		$this->assertGreaterThan(
			$reader['h0_plus_rerank']['story_repeat_rate'],
			$reader['h0_ranker']['story_repeat_rate'],
			'the reranker should reduce repeated coverage of the synthetic Nextcloud release story',
		);
		$this->assertGreaterThanOrEqual(
			3,
			$reader['h0_ranker']['unique_stories'],
			'the ranked fixture page should include several distinct stories',
		);
		$this->assertGreaterThanOrEqual(8, $reader['h0_ranker']['identifiable_story_posts']);
		$this->assertLessThan(
			$reader['h0_ranker']['largest_story_share'],
			$reader['h0_plus_rerank']['largest_story_share'],
			'the reranker should reduce the share of the page occupied by the largest synthetic story',
		);
		$this->assertFalse($report['methodology']['weights_promoted']);
	}

	private function evaluator(): RecommendationOfflineEvaluator {
		return new RecommendationOfflineEvaluator(new RecommendationRanker(), new RecommendationReRanker());
	}

	/** @return array<string, mixed> */
	private function dataset(): array {
		return [
			'name' => 'tiny-fixture',
			'profiles' => [[
				'id' => 'reader',
				'topic_grades' => ['topic.alpha' => 3, 'topic.hidden' => 3],
				'judgement_topic_grades' => ['topic.alpha' => 3, 'topic.hidden' => 3],
				'hidden_topics' => ['topic.hidden'],
				'followed_authors' => [],
				'languages' => ['en'],
			]],
			'posts' => [
				$this->post('available', ['topic.alpha']),
				$this->post('unavailable', ['topic.alpha'], ['available' => false]),
				$this->post('hidden', ['topic.hidden']),
				$this->post('unlisted', ['topic.alpha'], ['discoverable' => false]),
				$this->post('deleted', ['topic.alpha'], ['deleted' => true, 'user_visible' => false]),
			],
		];
	}

	/** @param list<string> $topics @param array<string, mixed> $overrides @return array<string, mixed> */
	private function post(string $id, array $topics, array $overrides = []): array {
		return array_merge([
			'id' => $id,
			'canonical_key' => 'ap://' . $id,
			'author' => 'author-' . $id,
			'protocol' => 'activitypub',
			'source' => 'AP_TAG_REMOTE',
			'topics' => $topics,
			'legacy_hashtag_topics' => $topics,
			'format' => 'text',
			'age_hours' => 1,
			'legacy_score' => 1.0,
			'language' => 'en',
			'public' => true,
			'discoverable' => true,
			'deleted' => false,
			'user_visible' => true,
			'available' => true,
		], $overrides);
	}
}
