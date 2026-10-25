<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Tools\SearchTerms;

/** Deterministic, local-only features used to retrieve recommendation candidates. */
final class RecommendationFeatureExtractor {
	public const VERSION = 8;
	private const MAX_CONTENT_CHARS = 20000;
	// A 20,000-character body cannot contain more than 10,000 two-character
	// words separated by at least one character; cover it fully for both
	// sensitive-topic exclusion and story sampling.
	private const MAX_TERMS = 10000;
	private const MAX_STORY_TERMS = 60;
	private const STORY_HEADLINE_TERMS = 20;
	/** @var array<string, list<array{alias: string, terms: list<string>}>> */
	private array $compiledAliases = [];
	/** @var list<array{terms: list<string>, hashtag: string}> */
	private array $compiledSensitiveAliases = [];

	public function __construct(
		private RecommendationTopicCatalog $catalog,
	) {
		foreach ($this->catalog->all() as $topic) {
			$aliases = [];
			$topicAliases = $topic['aliases'];
			if (in_array('sensitive-topic', $topic['policyTags'], true) && $topic['parentId'] === null) {
				$topicAliases[] = (string)$topic['id'];
			}
			foreach (array_unique($topicAliases) as $alias) {
				$terms = SearchTerms::ofText($alias, 20, true);
				if ($terms !== []) {
					$aliases[] = ['alias' => $alias, 'terms' => $terms];
					if (in_array('sensitive-topic', $topic['policyTags'], true)) {
						$this->compiledSensitiveAliases[] = ['terms' => $terms, 'hashtag' => self::normaliseHashtag($alias)];
					}
				}
			}
			$this->compiledAliases[$topic['id']] = $aliases;
		}
	}

	/**
	 * Extract broad topic and format features without inferring a reader profile.
	 * Topics marked sensitive are deliberately omitted until a separate reviewed
	 * policy allows their use in recommendation ranking.
	 *
	 * @return array{version: int, topics: array<string, array{score: float, evidence: string}>, format: string, language: string, story_terms?: list<string>, story_headline_terms?: list<string>}
	 */
	public function extract(Stream $post, bool $includeStoryTerms = false): array {
		$content = $post->getContent();
		$contentExceedsBound = mb_strlen($content, 'UTF-8') > self::MAX_CONTENT_CHARS;
		$bodySegments = self::contentSegments(mb_substr($content, 0, self::MAX_CONTENT_CHARS, 'UTF-8'));
		$hashtagTerms = array_map(self::normaliseHashtag(...), $post->getHashtags());
		// If the body was clipped, a sensitive marker might be in the unseen
		// tail. Do not create any story fingerprints from a partial view.
		$hasSensitiveEvidence = $includeStoryTerms && ($contentExceedsBound
			|| self::hasSensitiveEvidence($bodySegments, $hashtagTerms, $this->compiledSensitiveAliases));
		$storyTerms = $includeStoryTerms && !$hasSensitiveEvidence
			? self::storyTermsFromSegments($bodySegments)
			: [];
		$matches = [];

		foreach ($this->compiledAliases as $topicId => $aliases) {
			$topic = $this->catalog->find($topicId);
			if ($topic === null || in_array('sensitive-topic', $topic['policyTags'], true)) {
				continue;
			}
			foreach ($aliases as $compiledAlias) {
				$alias = $compiledAlias['alias'];
				if (self::containsAlias($topicId, $alias, $compiledAlias['terms'], $bodySegments, $topic['aliases'])) {
					$matches[$topicId] = ['score' => 0.72, 'evidence' => 'text'];
					break;
				}
				$aliasKey = self::normaliseHashtag($alias);
				if ($aliasKey !== '' && in_array($aliasKey, $hashtagTerms, true)) {
					$matches[$topicId] = ['score' => 1.0, 'evidence' => 'hashtag'];
					break;
				}
			}
		}

		// A known child also supplies its non-sensitive parents, while keeping
		// the evidence source and score attached to each feature.
		foreach (array_keys($matches) as $topicId) {
			$parent = $this->catalog->parentOf($topicId);
			while ($parent !== null) {
				$parentTopic = $this->catalog->find($parent);
				if ($parentTopic === null || in_array('sensitive-topic', $parentTopic['policyTags'], true)) {
					break;
				}
				$matches[$parent] ??= $matches[$topicId];
				$parent = $this->catalog->parentOf($parent);
			}
		}

		$features = [
			'version' => self::VERSION,
			'topics' => $matches,
			'format' => self::formatOf($post),
			'language' => strtolower(trim($post->getLanguage())),
		];
		if ($includeStoryTerms) {
			$features['story_terms'] = $storyTerms;
			$features['story_headline_terms'] = array_slice($storyTerms, 0, 20);
		}

		return $features;
	}

	/**
	 * Extract only the bounded, sensitive-aware story fingerprint terms.
	 * Legacy feed diversity does not need topic classification or media metadata.
	 *
	 * @return list<string>
	 */
	public function extractStoryTerms(Stream $post): array {
		$content = $post->getContent();
		if (mb_strlen($content, 'UTF-8') > self::MAX_CONTENT_CHARS) {
			return [];
		}

		$segments = self::contentSegments($content);
		$hashtags = array_map(self::normaliseHashtag(...), $post->getHashtags());
		if (self::hasSensitiveEvidence($segments, $hashtags, $this->compiledSensitiveAliases)) {
			return [];
		}

		return self::storyTermsFromSegments($segments);
	}

	/** @param list<list<string>> $segments @return list<string> */
	private static function storyTermsFromSegments(array $segments): array {
		$terms = [];
		$seen = [];
		foreach ($segments as $segment) {
			foreach ($segment as $term) {
				if (!SearchTerms::isStopWord($term) && !isset($seen[$term])) {
					$seen[$term] = true;
					$terms[] = $term;
				}
			}
		}

		return self::boundedStoryTerms($terms);
	}

	/**
	 * Keep the opening words, which usually name the story, and sample the
	 * remainder across the whole post so long articles do not fingerprint only
	 * their introduction. The source is already bounded to MAX_TERMS.
	 *
	 * @param list<string> $terms
	 * @return list<string>
	 */
	private static function boundedStoryTerms(array $terms): array {
		if (count($terms) <= self::MAX_STORY_TERMS) {
			return $terms;
		}

		$headline = array_slice($terms, 0, self::STORY_HEADLINE_TERMS);
		$remainder = array_slice($terms, self::STORY_HEADLINE_TERMS);
		$sampleCount = self::MAX_STORY_TERMS - count($headline);
		$last = count($remainder) - 1;
		$sampled = [];
		for ($sample = 0; $sample < $sampleCount; $sample++) {
			$index = (int)round($sample * $last / max(1, $sampleCount - 1));
			$sampled[] = $remainder[$index];
		}

		return [...$headline, ...$sampled];
	}

	/** @param list<string> $aliasTerms @param list<list<string>> $segments @param list<string> $aliases */
	private static function containsAlias(string $topicId, string $alias, array $aliasTerms, array $segments, array $aliases): bool {
		foreach ($segments as $terms) {
			if (!self::containsSequence($terms, $aliasTerms)) {
				continue;
			}
			if ($topicId !== 'tech.apple' || $alias !== 'apple' || self::hasAppleTechnologyContext($terms, $aliases)) {
				return true;
			}
		}

		return false;
	}

	/** @param list<string> $terms @param list<string> $aliases */
	private static function hasAppleTechnologyContext(array $terms, array $aliases): bool {
		$contextAliases = array_merge($aliases, ['macos', 'macbook', 'ios', 'app store', 'tim cook', 'computer', 'laptop', 'smartphone', 'device']);
		foreach ($contextAliases as $alias) {
			if ($alias === 'apple') {
				continue;
			}
			$aliasTerms = SearchTerms::ofText($alias, 20, true);
			if ($aliasTerms !== [] && self::containsSequence($terms, $aliasTerms)) {
				return true;
			}
		}

		return false;
	}

	/** @param list<string> $haystack @param list<string> $needle */
	private static function containsSequence(array $haystack, array $needle): bool {
		$length = count($needle);
		if ($length === 0 || $length > count($haystack)) {
			return false;
		}
		for ($offset = 0, $end = count($haystack) - $length; $offset <= $end; $offset++) {
			if (array_slice($haystack, $offset, $length) === $needle) {
				return true;
			}
		}

		return false;
	}

	/** @param list<list<string>> $segments @param list<string> $hashtags @param list<array{terms: list<string>, hashtag: string}> $sensitiveAliases */
	private static function hasSensitiveEvidence(array $segments, array $hashtags, array $sensitiveAliases): bool {
		foreach ($sensitiveAliases as $alias) {
			if ($alias['hashtag'] !== '' && in_array($alias['hashtag'], $hashtags, true)) {
				return true;
			}
			foreach ($segments as $segment) {
				if (self::containsSequence($segment, $alias['terms'])) {
					return true;
				}
			}
		}

		return false;
	}

	/** @return list<list<string>> */
	private static function contentSegments(string $html): array {
		$html = preg_replace('#```[\\s\\S]*?```|~~~[\\s\\S]*?~~~|`[^`\\r\\n]*`#u', ' ', $html) ?? $html;
		$html = preg_replace('/^[\\t ]{0,3}>.*(?:\\R|$)/mu', ' ', $html) ?? $html;
		$html = preg_replace('#<(blockquote|pre|code)\\b[^>]*>.*?</\\1\\s*>#isu', ' ', $html) ?? $html;
		$text = ACore::withoutMarkup(str_replace('<', ' <', $html));
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
		$text = mb_strtolower(mb_scrub($text, 'UTF-8'), 'UTF-8');
		$sentences = preg_split('/(?<=[.!?。！？])\s+|[\r\n]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
		$segments = [];
		$termCount = 0;
		foreach (($sentences === false) ? [] : $sentences as $sentence) {
			$words = preg_split('/[^\p{L}\p{N}\p{M}]+/u', $sentence, -1, PREG_SPLIT_NO_EMPTY);
			$terms = [];
			foreach (($words === false) ? [] : $words as $word) {
				if (mb_strlen($word, 'UTF-8') < SearchTerms::MIN_LENGTH) {
					continue;
				}
				$terms[] = mb_substr($word, 0, SearchTerms::MAX_LENGTH, 'UTF-8');
				$termCount++;
				if ($termCount >= self::MAX_TERMS) {
					break 2;
				}
			}
			if ($terms !== []) {
				$segments[] = $terms;
			}
		}

		return $segments;
	}

	private static function normaliseHashtag(string $value): string {
		$value = mb_strtolower(ltrim(trim($value), '#'), 'UTF-8');

		return preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? '';
	}

	private static function formatOf(Stream $post): string {
		$short = Stream::shortKindOf((string)json_encode(array_map(
			static fn (MediaAttachment $attachment): array => $attachment->asLocal(),
			$post->getAttachments()
		)));
		if ($short !== Stream::SHORT_KIND_NONE) {
			return 'short';
		}
		if ($post->isVideo()) {
			return 'video';
		}
		foreach ($post->getAttachments() as $attachment) {
			if ($attachment->getType() === 'image') {
				return 'image';
			}
			if ($attachment->getType() === 'audio') {
				return 'audio';
			}
		}

		return $post instanceof Question ? 'poll' : 'text';
	}
}
