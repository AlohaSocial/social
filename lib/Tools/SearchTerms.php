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
 * `mb_strtolower()`; everything else separates words. Markup is not text, so
 * it is taken out first — `href`, `span` and `class` are in nearly every post
 * and no reader is looking for them. Script that does not separate words with
 * spaces (Chinese, Japanese, Thai) becomes one long word per run, cut at the
 * column width: it is found from its beginning only.
 */
final class SearchTerms {
	/** Shorter words are not stored and not searched for. */
	public const MIN_LENGTH = 2;

	/**
	 * The column width, in characters. A longer word is stored cut, and a
	 * longer search term is cut the same way, so the two still meet.
	 */
	public const MAX_LENGTH = 32;

	/**
	 * How many distinct words of one post are stored: a post longer than any
	 * conversation is still found by what it is about, and one written to
	 * fill the table is bounded.
	 */
	public const MAX_PER_POST = 400;

	/**
	 * How short a last search word may be and still match as a prefix. A
	 * shorter one matches as a whole word: `ai` is a word somebody looks for,
	 * and as a prefix it is half the dictionary.
	 *
	 * It is also the length of a word's head (see `head()`), so every prefix
	 * a search can ask for begins with a whole head.
	 */
	public const MIN_PREFIX = 3;

	/**
	 * The distinct words of a post's stored markup, in the order they first
	 * appear.
	 *
	 * @return list<string>
	 */
	public static function ofHtml(string $html): array {
		// a tag separates what is either side of it: `<p>one</p><p>two</p>`
		// is two words, and without the space the flattener makes it one
		$text = ACore::withoutMarkup(str_replace('<', ' <', $html));

		return self::ofText(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), self::MAX_PER_POST);
	}

	/**
	 * The distinct words of plain text, in the order they first appear.
	 *
	 * @return list<string>
	 */
	public static function ofText(string $text, int $max = self::MAX_PER_POST): array {
		$text = mb_strtolower(mb_scrub($text, 'UTF-8'), 'UTF-8');
		$words = preg_split('/[^\p{L}\p{N}\p{M}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

		$terms = [];
		foreach (($words === false) ? [] : $words as $word) {
			if (mb_strlen($word, 'UTF-8') < self::MIN_LENGTH) {
				continue;
			}

			$terms[mb_substr($word, 0, self::MAX_LENGTH, 'UTF-8')] = true;
			if (count($terms) >= $max) {
				break;
			}
		}

		return array_map('strval', array_keys($terms));
	}

	/**
	 * The first MIN_PREFIX characters of a word, stored beside it.
	 *
	 * A prefix match is `LIKE 'zeb%'`, and PostgreSQL answers that from an
	 * ordinary index only under the C collation, which a Nextcloud database
	 * rarely has. An equality on the head is answered from an index on any
	 * database and in any collation, and in nid order; the `LIKE` then only
	 * sifts the words of that one head.
	 */
	public static function head(string $term): string {
		return mb_substr($term, 0, self::MIN_PREFIX, 'UTF-8');
	}

	/**
	 * What a search asks for: the words every answer must hold, and the
	 * beginning of one more — the last word of the query, so that a search
	 * typed a letter at a time finds `zebra` from `zeb`.
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
