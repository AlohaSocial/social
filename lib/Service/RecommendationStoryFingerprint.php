<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

/** Owner-scoped fingerprints used only to limit repeated feed exposure. */
final class RecommendationStoryFingerprint {
	private const MIN_TERMS = 3;
	private const MINHASH_COUNT = 64;
	public const MINHASH_BAND_COUNT = 16;
	public const SHORT_STORY_MATCH_COUNT = 6;
	public const HEADLINE_MATCH_COUNT = 6;
	private const ROWS_PER_BAND = 4;

	/**
	 * A private exact-post key for a post whose contents cannot safely be
	 * fingerprinted (for example, text under a sensitive-topic policy).
	 */
	public static function exactPostKey(string $postId, string $secret, string $owner): ?string {
		if ($postId === '' || $secret === '' || $owner === '') {
			return null;
		}

		return hash_hmac('sha256', $owner . "\0post-exposure-v1\0" . $postId, $secret);
	}

	/**
	 * Keep cached story tokens comparable without retaining readable post words.
	 * Tokens are scoped to this instance secret and one reader.
	 *
	 * @param list<string> $terms
	 * @return list<string>
	 */
	public static function opaqueTerms(array $terms, string $secret, string $owner): array {
		if ($secret === '' || $owner === '') {
			return [];
		}

		return array_values(array_unique(array_map(
			static fn (string $term): string => hash_hmac('sha256', $owner . "\0story-cache-term-v1\0" . $term, $secret),
			$terms,
		)));
	}

	/**
	 * @param list<string> $terms bounded, normalised terms extracted from a post
	 * @param list<string> $headlineTerms opening terms extracted from a post
	 * @return list<string> owner-scoped body and optional headline fingerprints, plus a backwards-compatible exact key for short stories
	 */
	public static function keys(array $terms, string $secret, string $owner, array $headlineTerms = []): array {
		if ($secret === '' || $owner === '') {
			return [];
		}

		$terms = self::normaliseTerms($terms);
		if (count($terms) < self::MIN_TERMS) {
			return [];
		}
		$termCount = count($terms);
		$shortStory = count($terms) < 6;
		$keys = [];
		if ($shortStory) {
			sort($terms, SORT_STRING);
			$canonical = implode("\0", $terms);
			// Keep the historical exact key so existing exposure rows still match.
			$keys[] = hash_hmac('sha256', $owner . "\0story-exact-v1\0" . 0 . "\0" . $canonical, $secret);
		}

		// MinHash estimates Jaccard similarity directly. Longer stories use
		// sixteen four-value bands; short headlines use individual samples
		// below, where requiring several matches gives better recall.
		$firstTerm = array_shift($terms);
		if ($firstTerm === null) {
			return [];
		}
		$firstToken = hash_hmac('sha256', $owner . "\0story-term-v2\0" . $firstTerm, $secret, true);
		$signatures = [];
		for ($seed = 0; $seed < self::MINHASH_COUNT; $seed++) {
			$signatures[$seed] = hash('xxh3', pack('N', $seed) . $firstToken, true);
		}
		foreach ($terms as $term) {
			$token = hash_hmac('sha256', $owner . "\0story-term-v2\0" . $term, $secret, true);
			for ($seed = 0; $seed < self::MINHASH_COUNT; $seed++) {
				$hash = hash('xxh3', pack('N', $seed) . $token, true);
				if (strcmp($hash, $signatures[$seed]) < 0) {
					$signatures[$seed] = $hash;
				}
			}
		}
		if ($shortStory) {
			// A one-row MinHash key collides with probability equal to Jaccard
			// similarity. Short headlines need more independent samples than
			// four-row bands can provide at the same storage cost.
			for ($seed = 0; $seed < self::MINHASH_BAND_COUNT; $seed++) {
				$keys[] = hash_hmac(
					'sha256',
					$owner . "\0story-minhash-short-v3\0" . $seed . "\0" . $signatures[$seed],
					$secret,
				);
			}

			return $keys;
		}

		for ($band = 0; $band < self::MINHASH_BAND_COUNT; $band++) {
			$values = array_slice($signatures, $band * self::ROWS_PER_BAND, self::ROWS_PER_BAND);
			$keys[] = hash_hmac('sha256', $owner . "\0story-minhash-v2\0" . $band . "\0" . implode('', $values), $secret);
		}

		$headlineTerms = self::normaliseTerms($headlineTerms);
		if (count($headlineTerms) >= self::HEADLINE_MATCH_COUNT && $termCount > count($headlineTerms)) {
			$headlineTokens = array_map(
				static fn (string $term): string => hash_hmac('sha256', $owner . "\0story-headline-term-v1\0" . $term, $secret, true),
				$headlineTerms,
			);
			for ($seed = 0; $seed < self::MINHASH_BAND_COUNT; $seed++) {
				$minimum = null;
				foreach ($headlineTokens as $token) {
					$hash = hash('xxh3', pack('N', $seed) . $token, true);
					if ($minimum === null || strcmp($hash, $minimum) < 0) {
						$minimum = $hash;
					}
				}
				if ($minimum === null) {
					continue;
				}
				$keys[] = hash_hmac('sha256', $owner . "\0story-headline-v1\0" . $seed . "\0" . $minimum, $secret);
			}
		}

		return $keys;
	}

	/** @param array<array-key, mixed> $terms @return list<string> */
	private static function normaliseTerms(array $terms): array {
		return array_values(array_unique(array_filter(
			array_map(static fn (mixed $term): string => is_string($term) ? mb_strtolower(trim($term), 'UTF-8') : '', $terms),
			static fn (string $term): bool => $term !== ''
		)));
	}
}
