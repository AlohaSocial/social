<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Service\RecommendationRanker;
use OCA\Social\Service\RecommendationReRanker;
use PHPUnit\Framework\TestCase;

class RecommendationRankerTest extends TestCase {
	public function testExplicitTopicEvidenceRanksAboveNeutralCandidate(): void {
		$ranker = new RecommendationRanker();
		$ranked = $ranker->rank([
			['nid' => '2', 'topic_match' => 0.0],
			['nid' => '1', 'topic_match' => 1.0, 'freshness' => 0.9],
		]);

		$this->assertSame(['1', '2'], array_column($ranked, 'nid'));
		$this->assertSame(RecommendationRanker::ALGORITHM_VERSION, $ranked[0]['algorithm_version']);
		$this->assertGreaterThan($ranked[1]['rank_score'], $ranked[0]['rank_score']);
	}

	public function testTiesAreStableAndMissingSignalsAreNeutral(): void {
		$ranker = new RecommendationRanker();
		$ranked = $ranker->rank([
			['nid' => 'z', 'topic_match' => NAN, 'freshness' => INF],
			['nid' => 'a'],
		]);

		$this->assertSame(['a', 'z'], array_column($ranked, 'nid'));
		$this->assertSame(0.0, $ranked[1]['rank_features']['topic_match']);
		$this->assertSame(0.5, $ranked[1]['rank_features']['freshness']);
		$this->assertTrue(is_finite($ranked[1]['rank_score']));
	}

	public function testTiesUseCanonicalPostIdentityBeforeLocalDatabaseId(): void {
		$ranked = (new RecommendationRanker())->rank([
			['nid' => 'local-z', 'canonical_key' => 'ap://example.org/posts/a'],
			['nid' => 'local-a', 'canonical_key' => 'at://did:plc:example/app.bsky.feed.post/b'],
		]);

		$this->assertSame([
			'ap://example.org/posts/a',
			'at://did:plc:example/app.bsky.feed.post/b',
		], array_column($ranked, 'canonical_key'));
		$this->assertSame(['local-z', 'local-a'], array_column($ranked, 'nid'));
	}

	public function testMissingAndUnverifiedFollowFeaturesAreNeutral(): void {
		$ranked = (new RecommendationRanker())->rank([
			['nid' => 'missing'],
			['nid' => 'invalid', 'is_followed' => 0.4, 'author_affinity' => null],
		]);

		foreach ($ranked as $candidate) {
			$this->assertSame(0.0, $candidate['rank_features']['is_followed']);
			$this->assertSame(0.0, $candidate['rank_features']['author_affinity']);
		}
	}

	public function testScoreFeaturesAreClippedToTheirDocumentedDomains(): void {
		$ranked = (new RecommendationRanker())->rank([[
			'nid' => '1',
			'topic_match' => 3,
			'author_affinity' => -2,
			'format_match' => 4,
			'exposure_penalty' => 2,
		]]);

		$this->assertSame(1.0, $ranked[0]['rank_features']['topic_match']);
		$this->assertSame(0.0, $ranked[0]['rank_features']['behavioral_topic']);
		$this->assertSame(0.0, $ranked[0]['rank_features']['author_affinity']);
		$this->assertSame(1.0, $ranked[0]['rank_features']['format_match']);
		$this->assertSame(1.0, $ranked[0]['rank_features']['exposure_penalty']);
	}

	public function testBehavioralAffinityChangesTheRankWithoutChangingEligibility(): void {
		$ranked = (new RecommendationRanker())->rank([
			['nid' => 'new-interest', 'topic_match' => 0.7, 'behavioral_topic' => 1.0],
			['nid' => 'neutral', 'topic_match' => 0.7],
			['nid' => 'hidden', 'topic_match' => 1.0, 'behavioral_topic' => 1.0, 'eligible' => false],
		]);

		$this->assertSame(['new-interest', 'neutral'], array_column($ranked, 'nid'));
		$this->assertSame(1.0, $ranked[0]['rank_features']['behavioral_topic']);
	}

	public function testNoveltyFeatureGivesUnfamiliarAuthorsABoundedExplorationLift(): void {
		$ranked = (new RecommendationRanker())->rank([
			['nid' => 'familiar', 'topic_match' => 0.7, 'novelty' => 0.0],
			['nid' => 'unfamiliar', 'topic_match' => 0.7, 'novelty' => 1.0],
		]);

		$this->assertSame(['unfamiliar', 'familiar'], array_column($ranked, 'nid'));
		$this->assertSame(1.0, $ranked[0]['rank_features']['novelty']);
		$this->assertSame(0.0, $ranked[1]['rank_features']['novelty']);
	}

	public function testContentBalanceChangesRankingWeightsForFollowingAndDiscovery(): void {
		$ranker = new RecommendationRanker();
		$candidates = [
			['nid' => 'followed', 'topic_match' => 0.7, 'is_followed' => 1.0, 'novelty' => 0.0],
			['nid' => 'new-voice', 'topic_match' => 0.7, 'is_followed' => 0.0, 'novelty' => 1.0],
		];

		$this->assertSame('followed', $ranker->rank($candidates, 'following')[0]['nid']);
		$this->assertSame('new-voice', $ranker->rank($candidates, 'discovery')[0]['nid']);
		$this->assertSame(
			array_column($ranker->rank($candidates), 'nid'),
			array_column($ranker->rank($candidates, 'unsupported'), 'nid'),
			'Unknown settings safely use the balanced profile.'
		);
	}

	public function testPageAssemblyDeduplicatesAndRelaxesAuthorCapWhenNoAlternativeRemains(): void {
		$ranked = [
			['nid' => '1', 'canonical_key' => 'same', 'rank_score' => 1.0, 'author_key' => 'alice'],
			['nid' => '2', 'canonical_key' => 'same', 'rank_score' => 0.99, 'author_key' => 'alice'],
			['nid' => '3', 'rank_score' => 0.98, 'author_key' => 'alice'],
			['nid' => '4', 'rank_score' => 0.97, 'author_key' => 'alice'],
			['nid' => '5', 'rank_score' => 0.96, 'author_key' => 'bob'],
			['nid' => '6', 'rank_score' => 0.95, 'author_key' => 'carol', 'eligible' => false],
		];

		$page = (new RecommendationReRanker())->page($ranked, 4);

		$this->assertSame(['1', '3', '5', '4'], array_column($page, 'nid'));
		$this->assertTrue($page[2]['_promoted_by_diversity'], 'the reason record can identify an actual diversity placement');
		$this->assertCount(count(array_unique(array_column($page, 'nid'))), $page);
	}

	public function testAuthorCapSurfacesOtherAuthorsWhenTheirPostsAreLowerRanked(): void {
		$ranked = [];
		for ($i = 1; $i <= 8; $i++) {
			$ranked[] = ['nid' => 'alice-' . $i, 'rank_score' => 0.99 - ($i * 0.001), 'author_key' => 'alice'];
		}
		for ($i = 1; $i <= 4; $i++) {
			$ranked[] = ['nid' => 'bob-' . $i, 'rank_score' => 0.30 - ($i * 0.001), 'author_key' => 'bob'];
		}

		$page = (new RecommendationReRanker())->page($ranked, 4);

		$this->assertSame(['alice-1', 'alice-2', 'bob-1', 'bob-2'], array_column($page, 'nid'));
		$this->assertLessThanOrEqual(2, count(array_filter($page, static fn (array $candidate): bool => $candidate['author_key'] === 'alice')));
	}

	public function testCombinedAuthorAndTopicCapsRelaxWhenNoCandidateSatisfiesBoth(): void {
		$page = (new RecommendationReRanker())->page([
			['nid' => 'alice-1', 'rank_score' => 1.0, 'author_key' => 'alice', 'topic_ids' => ['topic.one']],
			['nid' => 'alice-2', 'rank_score' => 0.99, 'author_key' => 'alice', 'topic_ids' => ['topic.one']],
			['nid' => 'bob-1', 'rank_score' => 0.98, 'author_key' => 'bob', 'topic_ids' => ['topic.one']],
			['nid' => 'alice-other-topic', 'rank_score' => 0.80, 'author_key' => 'alice', 'topic_ids' => ['topic.two']],
			['nid' => 'bob-saturated-topic', 'rank_score' => 0.79, 'author_key' => 'bob', 'topic_ids' => ['topic.one']],
		], 5);

		$this->assertCount(5, $page, 'when each remaining candidate conflicts with one cap, the caps relax instead of dropping visible posts');
		$this->assertSame('alice-other-topic', $page[3]['nid'], 'the higher ranked alternative topic is selected first');
	}

	public function testAutomaticTopicConcentrationYieldsToAnotherTopicAfterThreeMatches(): void {
		$ranked = [];
		for ($i = 1; $i <= 10; $i++) {
			$ranked[] = ['nid' => (string)$i, 'rank_score' => 1.0 - $i / 100, 'topic_ids' => ['topic.one']];
		}
		$ranked[] = ['nid' => 'other', 'rank_score' => 0.89, 'topic_ids' => ['topic.two']];
		$ranker = new RecommendationReRanker();

		$normal = $ranker->page($ranked, 11);

		$this->assertSame('other', $normal[3]['nid'], 'automatic ranking should surface another topic after three matches');
	}

	public function testRepeatedHighScoringStoriesDoNotFillThePageWhenOtherTopicsAreAvailable(): void {
		$ranked = [];
		for ($i = 1; $i <= 30; $i++) {
			$ranked[] = [
				'nid' => 'security-story-' . $i,
				'rank_score' => 0.98 - ($i * 0.001),
				'author_key' => 'author-' . $i,
				'topic_ids' => ['technology', 'software', 'software.security'],
			];
		}
		for ($i = 1; $i <= 20; $i++) {
			$ranked[] = [
				'nid' => 'different-topic-' . $i,
				'rank_score' => 0.80 - ($i * 0.001),
				'author_key' => 'different-author-' . $i,
				'topic_ids' => ['topic.' . $i],
			];
		}

		$page = (new RecommendationReRanker())->page($ranked, 20, 'topic-diversity-regression');
		$repeatedStories = array_filter($page, static fn (array $candidate): bool => str_starts_with($candidate['nid'], 'security-story-'));

		$this->assertLessThanOrEqual(3, count($repeatedStories), 'one story cluster should not crowd out other subjects in the first page');
		$this->assertContains('different-topic-1', array_column($page, 'nid'));
	}

	public function testOneStoryCannotReturnAfterTwentyOtherPostsInTheSameSnapshot(): void {
		$ranked = [];
		$incidentTerms = ['php', 'security', 'release', 'maintainer', 'vulnerability', 'patch', 'disclosure', 'exploit'];
		for ($i = 1; $i <= 40; $i++) {
			$ranked[] = [
				'nid' => 'incident-' . $i,
				'rank_score' => 0.99 - ($i * 0.001),
				'story_terms' => [...$incidentTerms, 'detail' . $i],
			];
		}
		for ($i = 1; $i <= 60; $i++) {
			$ranked[] = [
				'nid' => 'unrelated-' . $i,
				'rank_score' => 0.80 - ($i * 0.001),
				'story_terms' => ['garden' . $i, 'community' . $i, 'event' . $i, 'local' . $i, 'meeting' . $i, 'weekend' . $i, 'people' . $i],
			];
		}

		$page = (new RecommendationReRanker())->page($ranked, 100, 'story-cap-whole-snapshot');
		$incidentPosts = array_filter($page, static fn (array $candidate): bool => str_starts_with($candidate['nid'], 'incident-'));

		$this->assertCount(4, $incidentPosts, 'one extra incident may fill the page after distinct alternatives are exhausted');
		$this->assertCount(64, $page, 'remaining incident candidates are omitted after one sparse-feed overflow');
	}

	public function testRecentlyOverexposedStoriesYieldToUnseenAlternativesAcrossFeedRefreshes(): void {
		$ranked = [];
		for ($i = 1; $i <= 8; $i++) {
			$ranked[] = [
				'nid' => 'seen-php-incident-' . $i,
				'rank_score' => 0.99 - ($i * 0.001),
				'exposure_penalty' => 0.9,
				'story_terms' => ['php', 'security', 'incident', 'vulnerability', 'patch', 'disclosure', 'exploit', 'detail' . $i],
			];
		}
		for ($i = 1; $i <= 8; $i++) {
			$ranked[] = [
				'nid' => 'unseen-topic-' . $i,
				'rank_score' => 0.7 - ($i * 0.001),
				'exposure_penalty' => 0.0,
				'story_terms' => ['garden' . $i, 'community' . $i, 'event' . $i, 'local' . $i, 'meeting' . $i, 'weekend' . $i, 'people' . $i],
			];
		}

		$page = (new RecommendationReRanker())->page($ranked, 5, 'story-fatigue-after-refresh');

		$this->assertCount(5, $page);
		$this->assertSame(
			[],
			array_values(array_filter($page, static fn (array $candidate): bool => str_starts_with($candidate['nid'], 'seen-php-incident-'))),
			'recently overexposed stories yield while the page has unseen alternatives',
		);
	}

	public function testAnOverexposedStoryCanFillThePageWhenNoUnseenAlternativeExists(): void {
		$ranked = [];
		for ($i = 1; $i <= 5; $i++) {
			$ranked[] = [
				'nid' => 'only-seen-story-' . $i,
				'rank_score' => 0.9 - ($i * 0.01),
				'exposure_penalty' => 0.9,
				'story_terms' => ['php', 'security', 'incident', 'vulnerability', 'patch', 'disclosure', 'exploit'],
			];
		}

		$page = (new RecommendationReRanker())->page($ranked, 5, 'fatigue-fallback');

		$this->assertCount(4, $page, 'one fatigued story overflow may fill a sparse page');
		$this->assertSame(['only-seen-story-1', 'only-seen-story-2', 'only-seen-story-3', 'only-seen-story-4'], array_column($page, 'nid'));
	}

	public function testSimilarStoryVariantsCannotEvadeTheCapThroughAnOverlapChain(): void {
		$variants = [
			['a', 'b', 'c', 'd', 'e', 'f'],
			['a', 'b', 'c', 'd', 'e', 'g'],
			['a', 'b', 'c', 'd', 'g', 'h'],
		];
		$ranked = [];
		foreach ($variants as $variant => $terms) {
			for ($post = 1; $post <= 4; $post++) {
				$ranked[] = [
					'nid' => 'story-' . $variant . '-' . $post,
					'rank_score' => 1.0 - (count($ranked) * 0.001),
					'story_terms' => $terms,
				];
			}
		}

		$page = (new RecommendationReRanker())->page($ranked, 12, 'overlap-chain-story-cap');

		$this->assertCount(4, $page, 'a sparse page may show one additional item from the connected story cluster');
		$this->assertSame(['story-0-1', 'story-0-2', 'story-0-3', 'story-0-4'], array_column($page, 'nid'));
		foreach ($page as $candidate) {
			$this->assertArrayNotHasKey('_story_cluster', $candidate, 'internal cluster IDs are not exposed');
		}
	}

	public function testSimilarStoriesWithoutSharedTopicTagsAreLimitedWhenOtherPostsAreAvailable(): void {
		$ranked = [];
		$sharedTerms = ['php', 'security', 'release', 'maintainer', 'vulnerability', 'patch', 'disclosure', 'exploit'];
		for ($i = 1; $i <= 12; $i++) {
			$ranked[] = [
				'nid' => 'same-incident-' . $i,
				'rank_score' => 0.99 - ($i * 0.001),
				'author_key' => 'incident-author-' . $i,
				'story_terms' => [...$sharedTerms, 'detail' . $i],
			];
		}
		for ($i = 1; $i <= 12; $i++) {
			$ranked[] = [
				'nid' => 'other-story-' . $i,
				'rank_score' => 0.80 - ($i * 0.001),
				'author_key' => 'other-author-' . $i,
				'story_terms' => ['garden' . $i, 'community' . $i, 'event' . $i, 'local' . $i, 'meeting' . $i, 'weekend' . $i, 'people' . $i],
			];
		}

		$page = (new RecommendationReRanker())->page($ranked, 20, 'story-diversity-without-tags');
		$incidentPosts = array_filter($page, static fn (array $candidate): bool => str_starts_with($candidate['nid'], 'same-incident-'));

		$this->assertCount(4, $incidentPosts);
		$this->assertCount(16, $page, 'one story overflow fills a slot after other stories run out');
		$this->assertContains('other-story-1', array_column($page, 'nid'));
		foreach ($page as $candidate) {
			$this->assertArrayNotHasKey('story_terms', $candidate, 'text terms stay transient and are not exposed with ranked items');
		}
	}

	public function testACommonShortPhraseDoesNotMergeAStoryWithAnUnrelatedLongPost(): void {
		$incidentTerms = ['php', 'security', 'incident', 'patch', 'release', 'vulnerability'];
		$ranked = [];
		for ($i = 1; $i <= 8; $i++) {
			$ranked[] = [
				'nid' => 'incident-' . $i,
				'rank_score' => 0.99 - ($i * 0.001),
				'story_terms' => [...$incidentTerms, 'detail' . $i],
			];
		}
		$ranked[] = [
			'nid' => 'long-unrelated-post',
			'rank_score' => 0.70,
			'story_terms' => [...$incidentTerms, ...array_map(static fn (int $i): string => 'unrelated' . $i, range(1, 54))],
		];

		$page = (new RecommendationReRanker())->page($ranked, 5, 'short-overlap-is-not-a-story');
		$incidentPosts = array_filter($page, static fn (array $candidate): bool => str_starts_with($candidate['nid'], 'incident-'));

		$this->assertCount(4, $incidentPosts, 'one additional item may appear after the alternative is used');
		$this->assertContains('long-unrelated-post', array_column($page, 'nid'));
		$this->assertCount(5, $page);
	}

	public function testSharedOpeningKeepsLongCoverageOfOneStoryTogether(): void {
		$opening = array_map(static fn (int $i): string => 'headline' . $i, range(1, 20));
		$ranked = [];
		for ($i = 1; $i <= 8; $i++) {
			$ranked[] = [
				'nid' => 'long-incident-' . $i,
				'rank_score' => 0.99 - ($i * 0.001),
				'story_terms' => [...$opening, ...array_map(static fn (int $term): string => 'article' . $i . 'term' . $term, range(1, 40))],
			];
		}
		$ranked[] = [
			'nid' => 'other-story',
			'rank_score' => 0.7,
			'story_terms' => ['garden', 'community', 'event', 'local', 'meeting', 'weekend', 'people'],
		];

		$page = (new RecommendationReRanker())->page($ranked, 5, 'shared-opening-long-articles');
		$incidentPosts = array_filter($page, static fn (array $candidate): bool => str_starts_with($candidate['nid'], 'long-incident-'));

		$this->assertCount(4, $incidentPosts);
		$this->assertContains('other-story', array_column($page, 'nid'));
		$this->assertCount(5, $page);
	}

	public function testHalfMatchingHeadlinesClusterEvenWhenArticleBodiesDiffer(): void {
		$sharedOpening = array_map(static fn (int $i): string => 'incident' . $i, range(1, 10));
		$ranked = [];
		for ($i = 1; $i <= 8; $i++) {
			$headline = $i % 2 === 0
				? [...$sharedOpening, ...array_map(static fn (int $term): string => 'variant' . $term, range(1, 10))]
				: [...$sharedOpening, ...array_map(static fn (int $term): string => 'original' . $term, range(1, 10))];
			$ranked[] = [
				'nid' => 'coverage-' . $i,
				'rank_score' => 0.99 - ($i * 0.001),
				'story_terms' => [...$headline, ...array_map(static fn (int $term): string => 'body' . $i . 'term' . $term, range(1, 40))],
			];
		}
		$ranked[] = [
			'nid' => 'different-event',
			'rank_score' => 0.7,
			'story_terms' => ['garden', 'community', 'event', 'local', 'meeting', 'weekend', 'people'],
		];

		$page = (new RecommendationReRanker())->page($ranked, 5, 'shared-half-headline');
		$coverage = array_filter($page, static fn (array $candidate): bool => str_starts_with($candidate['nid'], 'coverage-'));

		$this->assertCount(4, $coverage);
		$this->assertContains('different-event', array_column($page, 'nid'));
		$this->assertCount(5, $page);
	}

	public function testSimilarStoryLimitStillAppliesWhenItWouldLeaveThePageUnderfilled(): void {
		$terms = ['php', 'security', 'release', 'maintainer', 'vulnerability', 'patch', 'disclosure', 'exploit'];
		$ranked = [];
		for ($i = 1; $i <= 5; $i++) {
			$ranked[] = ['nid' => 'only-story-' . $i, 'rank_score' => 1.0 - ($i * 0.01), 'story_terms' => $terms];
		}

		$page = (new RecommendationReRanker())->page($ranked, 5, 'story-diversity-no-alternative');

		$this->assertCount(4, $page, 'a sparse page allows one item above the ordinary story limit');
		$this->assertSame(['only-story-1', 'only-story-2', 'only-story-3', 'only-story-4'], array_column($page, 'nid'));
	}

	public function testShortPHPHeadlineVariantsShareTheStoryCap(): void {
		$ranked = [];
		for ($i = 1; $i <= 5; $i++) {
			$terms = match ($i % 3) {
				0 => ['php', 'security', 'patch', 'release'],
				1 => ['php', 'security', 'patch', 'incident'],
				default => ['php', 'security', 'patch', 'vulnerability'],
			};
			$ranked[] = ['nid' => 'php-security-' . $i, 'rank_score' => 1.0 - ($i * 0.01), 'story_terms' => $terms];
		}
		for ($i = 1; $i <= 5; $i++) {
			$ranked[] = ['nid' => 'other-short-story-' . $i, 'rank_score' => 0.8 - ($i * 0.01), 'story_terms' => ['local', 'music', 'festival', 'summer']];
		}

		$page = (new RecommendationReRanker())->page($ranked, 10, 'short-story-diversity');
		$incidentPosts = array_filter($page, static fn (array $candidate): bool => str_starts_with($candidate['nid'], 'php-security-'));

		$this->assertCount(4, $incidentPosts, 'one extra variant may appear when no other stories remain');
		$this->assertCount(8, $page, 'each story may overflow once after the alternatives are exhausted');
	}

	public function testThreeTermStoryTitlesAreGroupedWhenTheirWordsMatchExactly(): void {
		$ranked = [];
		for ($i = 1; $i <= 5; $i++) {
			$ranked[] = ['nid' => 'php-incident-' . $i, 'rank_score' => 1.0 - ($i * 0.01), 'story_terms' => ['php', 'security', 'incident']];
		}
		for ($i = 1; $i <= 5; $i++) {
			$ranked[] = ['nid' => 'local-event-' . $i, 'rank_score' => 0.8 - ($i * 0.01), 'story_terms' => ['local', 'music', 'festival']];
		}

		$page = (new RecommendationReRanker())->page($ranked, 10, 'three-term-story-diversity');
		$incidentPosts = array_filter($page, static fn (array $candidate): bool => str_starts_with($candidate['nid'], 'php-incident-'));

		$this->assertCount(4, $incidentPosts);
		$this->assertCount(8, $page);
	}

	public function testAHighInterestTopicCannotOverrideTheDiversityCapWhenAlternativesExist(): void {
		$ranked = [];
		for ($i = 1; $i <= 10; $i++) {
			$ranked[] = [
				'nid' => 'php-security-' . $i,
				'rank_score' => 0.99 - ($i * 0.001),
				'topic_ids' => ['software', 'software.backend'],
				'diversity_topics' => ['software.backend'],
			];
		}
		$ranked[] = [
			'nid' => 'community-story',
			'rank_score' => 0.1,
			'topic_ids' => ['community.civic', 'community'],
			'diversity_topics' => ['community.civic'],
		];

		$page = (new RecommendationReRanker())->page($ranked, 4, 'repeated-event-diversity');

		$this->assertSame(['php-security-1', 'php-security-2', 'php-security-3', 'community-story'], array_column($page, 'nid'));
	}

	public function testPHPVulnerabilityReportsDoNotCrowdOutOtherTopics(): void {
		$ranked = [];
		for ($i = 1; $i <= 8; $i++) {
			$ranked[] = [
				'nid' => 'php-vulnerability-' . $i,
				'rank_score' => 0.99 - ($i * 0.001),
				'topic_ids' => ['technology', 'tech.security', 'software', 'software.backend'],
				'diversity_topics' => ['tech.security', 'software.backend'],
			];
		}
		$ranked[] = ['nid' => 'community-story', 'rank_score' => 0.40, 'diversity_topics' => ['community.civic']];
		$ranked[] = ['nid' => 'garden-story', 'rank_score' => 0.39, 'diversity_topics' => ['environment.gardens']];

		$page = (new RecommendationReRanker())->page($ranked, 5, 'php-security-topic-diversity');
		$securityReports = array_filter($page, static fn (array $candidate): bool => in_array('tech.security', $candidate['diversity_topics'] ?? [], true));

		$this->assertCount(3, $securityReports);
		$this->assertContains('community-story', array_column($page, 'nid'));
		$this->assertContains('garden-story', array_column($page, 'nid'));
	}

	public function testMixedTopicCandidatesDoNotBypassASaturatedTopicWhileFreshAlternativesRemain(): void {
		$ranked = [];
		for ($i = 1; $i <= 3; $i++) {
			$ranked[] = ['nid' => 'security-' . $i, 'rank_score' => 1.0 - ($i / 100), 'diversity_topics' => ['software.security']];
		}
		$ranked[] = [
			'nid' => 'security-and-civic',
			'rank_score' => 0.96,
			'diversity_topics' => ['software.security', 'community.civic'],
		];
		$ranked[] = ['nid' => 'civic-only', 'rank_score' => 0.95, 'diversity_topics' => ['community.civic']];

		$page = (new RecommendationReRanker())->page($ranked, 5, 'mixed-topic-diversity');

		$this->assertSame(['security-1', 'security-2', 'security-3', 'civic-only', 'security-and-civic'], array_column($page, 'nid'));
	}

	public function testTopicConcentrationRelaxesWhenThereAreNoOtherTopicsToShow(): void {
		$ranked = [];
		for ($i = 1; $i <= 5; $i++) {
			$ranked[] = ['nid' => 'topic-' . $i, 'rank_score' => 0.9 - ($i / 100), 'topic_ids' => ['topic.one']];
		}

		$page = (new RecommendationReRanker())->page($ranked, 5, 'single-topic-coverage');

		$this->assertCount(5, $page);
		$this->assertSame(['topic-1', 'topic-2', 'topic-3', 'topic-4', 'topic-5'], array_column($page, 'nid'));
	}

	public function testQualifiedExplorationUsesOneStableBoundedSlot(): void {
		$ranked = [];
		for ($i = 0; $i < 14; $i++) {
			$ranked[] = [
				'nid' => 'normal-' . $i,
				'canonical_key' => 'normal-' . $i,
				'rank_score' => 0.99 - $i * 0.002,
				'author_key' => 'author-' . $i,
				'is_followed' => 0.0,
			];
		}
		$ranked[] = [
			'nid' => 'explore',
			'canonical_key' => 'at://did:example/post/explore',
			'rank_score' => 0.90,
			'author_key' => 'new-author',
			'is_followed' => 0.0,
			'topic_match' => 0.8,
			'matching_topic_ids' => ['tech.apple'],
			'novelty' => 0.9,
			'exposure_penalty' => 0.0,
		];
		for ($i = 15; $i < 24; $i++) {
			$ranked[] = [
				'nid' => 'normal-' . $i,
				'rank_score' => 0.80 - $i * 0.002,
				'author_key' => 'author-' . $i,
				'is_followed' => 0.0,
			];
		}

		$reranker = new RecommendationReRanker();
		$page = $reranker->page($ranked, 20, 'repeatable-session-seed');
		$again = $reranker->page($ranked, 20, 'repeatable-session-seed');

		$this->assertSame(array_column($page, 'nid'), array_column($again, 'nid'));
		$this->assertSame('explore', $page[9]['nid']);
		$this->assertTrue($page[9]['_exploration_slot']);
		$this->assertFalse($page[9]['_promoted_by_diversity']);
		$this->assertCount(1, array_filter($page, static fn (array $item): bool => ($item['_exploration_slot'] ?? false) === true));
		$this->assertArrayNotHasKey('_base_position', $page[9]);
	}

	public function testOfflineCompositionProfilesCanCompareFollowingAndDiscoveryWeights(): void {
		$ranked = [];
		for ($i = 0; $i < 14; $i++) {
			$ranked[] = ['nid' => 'normal-' . $i, 'rank_score' => 0.99 - $i * 0.002, 'author_key' => 'author-' . $i, 'is_followed' => 0.0];
		}
		$ranked[] = [
			'nid' => 'explore', 'rank_score' => 0.90, 'author_key' => 'new-author', 'is_followed' => 0.0,
			'topic_match' => 0.8, 'matching_topic_ids' => ['tech.apple'], 'novelty' => 0.9, 'exposure_penalty' => 0.0,
		];
		for ($i = 15; $i < 24; $i++) {
			$ranked[] = ['nid' => 'normal-' . $i, 'rank_score' => 0.80 - $i * 0.002, 'author_key' => 'author-' . $i, 'is_followed' => 0.0];
		}
		$reranker = new RecommendationReRanker();
		$following = $reranker->page($ranked, 20, 'balance-seed', 'following');
		$balanced = $reranker->page($ranked, 20, 'balance-seed', 'balanced');
		$discovery = $reranker->page($ranked, 20, 'balance-seed', 'discovery');

		$this->assertSame([], array_filter($following, static fn (array $item): bool => ($item['_exploration_slot'] ?? false) === true));
		$this->assertSame('explore', $balanced[9]['nid']);
		$this->assertSame('explore', $discovery[4]['nid']);
		$this->assertTrue($discovery[4]['_exploration_slot']);
	}

	public function testExplorationNeverSelectsFollowedUnmatchedExposedOrDistantCandidates(): void {
		foreach ([
			['is_followed' => 1.0],
			['topic_match' => 0.2],
			['novelty' => 0.2],
			['exposure_penalty' => 0.8],
			['matching_topic_ids' => []],
			['rank_score' => 0.1],
		] as $overrides) {
			$ranked = [];
			for ($i = 0; $i < 14; $i++) {
				$ranked[] = ['nid' => 'normal-' . $i, 'rank_score' => 0.99 - $i * 0.002, 'author_key' => 'author-' . $i, 'is_followed' => 0.0];
			}
			$ranked[] = array_merge([
				'nid' => 'candidate',
				'rank_score' => 0.90,
				'author_key' => 'candidate-author',
				'is_followed' => 0.0,
				'topic_match' => 0.8,
				'matching_topic_ids' => ['tech.apple'],
				'novelty' => 0.9,
				'exposure_penalty' => 0.0,
			], $overrides);

			$page = (new RecommendationReRanker())->page($ranked, 10, 'stable-seed');
			$this->assertSame([], array_filter($page, static fn (array $item): bool => ($item['_exploration_slot'] ?? false) === true));
		}
	}
}
