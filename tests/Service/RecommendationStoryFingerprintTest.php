<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Service\RecommendationStoryFingerprint;
use PHPUnit\Framework\TestCase;

class RecommendationStoryFingerprintTest extends TestCase {
	public function testSimilarStoriesShareSeveralOwnerScopedBandsWithoutStoringTerms(): void {
		$secret = 'an instance secret used only for test signatures';
		$first = ['php', 'security', 'incident', 'patch', 'release', 'maintainer', 'vulnerability', 'disclosure'];
		$variant = ['vulnerability', 'patch', 'php', 'security', 'incident', 'release', 'maintainer', 'hotfix'];

		$firstKeys = RecommendationStoryFingerprint::keys($first, $secret, 'alice');
		$variantKeys = RecommendationStoryFingerprint::keys($variant, $secret, 'alice');

		$this->assertCount(16, $firstKeys);
		$this->assertGreaterThanOrEqual(2, count(array_intersect($firstKeys, $variantKeys)));
		$unrelated = RecommendationStoryFingerprint::keys(
			['gardening', 'seedlings', 'tomatoes', 'greenhouse', 'watering', 'compost', 'harvest', 'soil'],
			$secret,
			'alice',
		);
		$this->assertLessThan(2, count(array_intersect($firstKeys, $unrelated)), 'unrelated stories must not meet the fuzzy-match threshold');
		$this->assertSame($firstKeys, RecommendationStoryFingerprint::keys(array_reverse($first), $secret, 'alice'));
		$this->assertNotSame($firstKeys, RecommendationStoryFingerprint::keys($first, $secret, 'bob'));
		$this->assertNotSame($firstKeys, RecommendationStoryFingerprint::keys($first, 'another instance secret', 'alice'));
	}

	public function testShortTextAndMissingSecretDoNotProduceStorySignatures(): void {
		$this->assertSame([], RecommendationStoryFingerprint::keys(['php', 'security'], 'secret', 'alice'));
		$this->assertSame([], RecommendationStoryFingerprint::keys(['php', 'security', 'incident'], '', 'alice'));
		$this->assertSame([], RecommendationStoryFingerprint::keys(['php', 'security', 'incident'], 'secret', ''));
	}

	public function testExactPostKeysAreHmacsScopedToOwnerAndSecret(): void {
		$key = RecommendationStoryFingerprint::exactPostKey('123', 'server-secret', 'alice');

		$this->assertNotNull($key);
		$this->assertSame($key, RecommendationStoryFingerprint::exactPostKey('123', 'server-secret', 'alice'));
		$this->assertNotSame($key, RecommendationStoryFingerprint::exactPostKey('123', 'server-secret', 'bob'));
		$this->assertNotSame($key, RecommendationStoryFingerprint::exactPostKey('123', 'another-secret', 'alice'));
		$this->assertNotSame($key, RecommendationStoryFingerprint::exactPostKey('124', 'server-secret', 'alice'));
		$this->assertNull(RecommendationStoryFingerprint::exactPostKey('123', '', 'alice'));
		$this->assertNull(RecommendationStoryFingerprint::exactPostKey('123', 'server-secret', ''));
		$this->assertNull(RecommendationStoryFingerprint::exactPostKey('', 'server-secret', 'alice'));
	}

	public function testShortStoriesKeepTheirExactKeyAndGetFuzzyBandsForHeadlineVariants(): void {
		$secret = 'an instance secret used only for test signatures';
		$first = RecommendationStoryFingerprint::keys(['php', 'security', 'incident', 'patch'], $secret, 'alice');
		$variant = RecommendationStoryFingerprint::keys(['php', 'security', 'incident', 'hotfix'], $secret, 'alice');

		$this->assertCount(17, $first, 'short stories keep the legacy exact key and add sixteen MinHash samples');
		$this->assertSame(
			hash_hmac('sha256', "alice\0story-exact-v1\0" . 0 . "\0incident\0patch\0php\0security", $secret),
			$first[0],
			'existing exact exposure rows still match after upgrade',
		);
		$this->assertGreaterThanOrEqual(RecommendationStoryFingerprint::SHORT_STORY_MATCH_COUNT, count(array_intersect(array_slice($first, 1), array_slice($variant, 1))));
	}

	public function testLongStoryFingerprintsAlsoIncludeOwnerScopedHeadlineSamples(): void {
		$secret = 'an instance secret used only for test signatures';
		$headline = array_map(static fn (int $i): string => 'headline' . $i, range(1, 20));
		$first = RecommendationStoryFingerprint::keys(
			[...$headline, ...array_map(static fn (int $i): string => 'firstbody' . $i, range(1, 40))],
			$secret,
			'alice',
			$headline,
		);
		$variant = RecommendationStoryFingerprint::keys(
			[...$headline, ...array_map(static fn (int $i): string => 'variantbody' . $i, range(1, 40))],
			$secret,
			'alice',
			$headline,
		);
		$otherOwner = RecommendationStoryFingerprint::keys(
			[...$headline, ...array_map(static fn (int $i): string => 'variantbody' . $i, range(1, 40))],
			$secret,
			'bob',
			$headline,
		);

		$this->assertCount(RecommendationStoryFingerprint::MINHASH_BAND_COUNT * 2, $first);
		$this->assertSame(
			array_slice($first, RecommendationStoryFingerprint::MINHASH_BAND_COUNT),
			array_slice($variant, RecommendationStoryFingerprint::MINHASH_BAND_COUNT),
		);
		$this->assertNotSame(
			array_slice($first, RecommendationStoryFingerprint::MINHASH_BAND_COUNT),
			array_slice($otherOwner, RecommendationStoryFingerprint::MINHASH_BAND_COUNT),
		);
		foreach ($first as $key) {
			$this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $key);
		}
	}

	public function testHeadlineFingerprintsAreKeptWhenOnlyOneBodyTermFollows(): void {
		$headline = array_map(static fn (int $i): string => 'headline' . $i, range(1, 20));
		$keys = RecommendationStoryFingerprint::keys(
			[...$headline, 'body'],
			'an instance secret used only for test signatures',
			'alice',
			$headline,
		);

		$this->assertCount(RecommendationStoryFingerprint::MINHASH_BAND_COUNT * 2, $keys);
	}
}
