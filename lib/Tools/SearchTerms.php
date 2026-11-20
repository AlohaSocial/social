<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tools;

use OCA\Social\Model\ActivityPub\ACore;

/**
 * The words of a post, in the one form `social_search_term` stores and a
 * search compares.
 *
 * A word is a run of letters, digits and combining marks, lowercased with
 * `mb_strtolower()`; everything else separates words, and markup is removed
 * first. Scripts without spaces (Chinese, Japanese, Thai) become one word per
 * run, cut at the column width, so they are found from their beginning only.
 */
final class SearchTerms {
	/** Shorter words are not stored and not searched for. */
	public const MIN_LENGTH = 2;

	/**
	 * The column width, in characters; words and search terms are cut alike so
	 * they still meet.
	 */
	public const MAX_LENGTH = 32;

	/**
	 * How many distinct words of one post are stored, bounding a post written to
	 * fill the table.
	 */
	public const MAX_PER_POST = 400;

	/**
	 * How short a last search word may be and still match as a prefix; a shorter
	 * one matches as a whole word (`ai` as a prefix is half the dictionary).
	 * Also the length of a word's head (see `head()`).
	 */
	public const MIN_PREFIX = 3;

	/**
	 * English and German function words, not stored because no search narrows
	 * on them. A search made only of these uses the bounded scan
	 * (`StreamSearch::searchContent()`).
	 */
	public const STOP_WORDS = [
		'the', 'be', 'to', 'of', 'and', 'in', 'that', 'have', 'it', 'for', 'not', 'on', 'with', 'he', 'as', 'you',
		'do', 'at', 'this', 'but', 'his', 'by', 'from', 'they', 'we', 'her', 'she', 'or', 'an', 'will', 'my',
		'all', 'would', 'there', 'their', 'what', 'so', 'up', 'out', 'if', 'about', 'who', 'which', 'me', 'when',
		'can', 'no', 'just', 'him', 'into', 'your', 'some', 'could', 'them', 'than', 'then', 'its', 'our', 'these',
		'also', 'is', 'are', 'was', 'were', 'been', 'has', 'had', 'am', 'us', 'those', 'how', 'any', 'did', 'does',
		'very', 'more', 'most', 'other', 'such', 'only', 'own', 'same', 'too', 'should', 'here', 'where', 'why',
		'because', 'while', 'each', 'both', 'few', 'through', 'over', 'under', 'again', 'once', 'off', 'nor',
		'being', 'having', 'doing', 'until', 'against', 'between', 'during', 'before', 'after', 'above', 'below',
		'down', 'der', 'die', 'das', 'und', 'ist', 'nicht', 'ich', 'du', 'er', 'sie', 'es', 'wir', 'ihr', 'ein',
		'eine', 'einen', 'einem', 'einer', 'eines', 'den', 'dem', 'des', 'zu', 'mit', 'auf', 'für', 'von', 'im',
		'auch', 'sich', 'dass', 'wie', 'aber', 'als', 'bei', 'nach', 'noch', 'nur', 'oder', 'wenn', 'wird', 'sind',
		'war', 'hat', 'haben', 'kann', 'mich', 'mir', 'dir', 'dich', 'uns', 'euch', 'man', 'aus', 'um', 'über',
		'vor', 'durch', 'doch', 'schon', 'hier', 'sehr', 'mein', 'dein', 'sein', 'ja', 'zum', 'zur', 'vom', 'beim',
		'ins', 'ob', 'weil', 'diese', 'dieser', 'dieses', 'wo',
	];

	/** @var array<string, true>|null STOP_WORDS as a set */
	private static ?array $stopSet = null;

	/**
	 * The distinct words of a post's stored markup, in the order they first
	 * appear: what it is indexed under, so without the function words unless
	 * `$withStopWords` asks for the words as written.
	 *
	 * @return list<string>
	 */
	public static function ofHtml(string $html, bool $withStopWords = false): array {
		// A tag separates words: `<p>one</p><p>two</p>` is two words.
		$text = ACore::withoutMarkup(str_replace('<', ' <', $html));

		return self::ofText(
			html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), self::MAX_PER_POST, $withStopWords
		);
	}

	/**
	 * The distinct words of plain text, in the order they first appear.
	 *
	 * @return list<string>
	 */
	public static function ofText(string $text, int $max = self::MAX_PER_POST, bool $withStopWords = true): array {
		$text = mb_strtolower(mb_scrub($text, 'UTF-8'), 'UTF-8');
		$words = preg_split('/[^\p{L}\p{N}\p{M}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

		$terms = [];
		foreach (($words === false) ? [] : $words as $word) {
			if (mb_strlen($word, 'UTF-8') < self::MIN_LENGTH || (!$withStopWords && self::isStopWord($word))) {
				continue;
			}

			$terms[mb_substr($word, 0, self::MAX_LENGTH, 'UTF-8')] = true;
			if (count($terms) >= $max) {
				break;
			}
		}

		return array_map('strval', array_keys($terms));
	}

	/** Whether a normalised word is one of STOP_WORDS, which the index does not hold. */
	public static function isStopWord(string $word): bool {
		self::$stopSet ??= array_fill_keys(self::STOP_WORDS, true);

		return isset(self::$stopSet[$word]);
	}

	/**
	 * The first MIN_PREFIX characters of a word, stored beside it. PostgreSQL
	 * serves `LIKE 'zeb%'` from an index only under the C collation, but an
	 * equality on the head works everywhere; `LIKE` then sifts that head's words.
	 */
	public static function head(string $term): string {
		return mb_substr($term, 0, self::MIN_PREFIX, 'UTF-8');
	}

	/**
	 * What a search asks for: the words every answer must hold, and the last
	 * word of the query as a prefix, so `zeb` finds `zebra` while typing.
	 *
	 * @return array{whole: list<string>, prefix: string}
	 */
	public static function ofQuery(string $query): array {
		$words = self::ofText($query, 16);
		if ($words === []) {
			return ['whole' => [], 'prefix' => ''];
		}

		$last = $words[count($words) - 1];
		if (mb_strlen($last, 'UTF-8') < self::MIN_PREFIX || mb_strlen($last, 'UTF-8') >= self::MAX_LENGTH) {
			return ['whole' => $words, 'prefix' => ''];
		}

		return ['whole' => array_slice($words, 0, -1), 'prefix' => $last];
	}
}
