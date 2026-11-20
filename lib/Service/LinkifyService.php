<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Security\HtmlSanitizer;

/**
 * Turns the plain text somebody typed into the HTML every other implementation
 * publishes: paragraphs, and links for URLs, mentions and hashtags. Peers
 * render `content` as given and linkify nothing themselves.
 *
 * The text is escaped first and markup is only built around escaped pieces,
 * never by interpolating raw input. The entities are found once, by
 * {@see self::entitiesIn()}, the same list `PostService` takes recipients and
 * hashtags from and `StreamService` builds the `tag` array from; a link is
 * only emitted for an entity with a `tag` entry, which is what a receiving
 * instance checks a mention against.
 */
class LinkifyService {
	public const TYPE_URL = 'url';
	/**
	 * What a URL counts for against `max_characters`, whatever its length:
	 * Mastodon's `characters_reserved_per_url`.
	 */
	public const URL_LENGTH = 23;
	public const TYPE_MENTION = 'mention';
	public const TYPE_HASHTAG = 'hashtag';

	/**
	 * The one pass over the text. URLs come first in the alternation, so
	 * `https://example.invalid/#section` and `.../@bob` stay links. A mention
	 * is not preceded by a word character (`frank@example.org` is an email
	 * address); nor is a hashtag (`issue#42`), which also may not follow `&`.
	 * Neither may end in punctuation, so `@bob@example.invalid.` ends a sentence.
	 */
	private const PATTERN = '~'
		. '(?P<url>\bhttps?://[^\s<>"]+)'
		. '|(?<![\w@])@(?P<mention>[\w\-]+(?:\.[\w\-]+)*(?:@[\w\-]+(?:\.[\w\-]+)+)?)'
		. '|(?<![\w&])\#(?P<hashtag>[\p{L}\p{N}_]+)'
		. '~u';

	/** Trailing characters that end a sentence rather than a URL. */
	private const URL_TRAILING = '.,;:!?\'"';

	/** Closing brackets that belong to the URL only if it opened them. */
	private const URL_BRACKETS = [')' => '(', ']' => '[', '}' => '{'];

	/**
	 * Every URL, mention and hashtag in the text, in the order they appear.
	 *
	 * `text` is the matched substring, `name` is it without its leading sigil,
	 * and `offset` is a byte offset into `$text`.
	 *
	 * @return list<array{type: string, text: string, name: string, offset: int}>
	 */
	public function entitiesIn(string $text): array {
		if (preg_match_all(self::PATTERN, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
			// invalid UTF-8: no entity is better than a half-parsed one, and
			// the caller still escapes and publishes the text
			return [];
		}

		$entities = [];
		foreach ($matches as $match) {
			foreach ([self::TYPE_URL, self::TYPE_MENTION, self::TYPE_HASHTAG] as $type) {
				if (($match[$type][1] ?? -1) < 0) {
					continue;
				}

				$name = $match[$type][0];
				if ($type === self::TYPE_URL) {
					$name = $this->withoutTrailingPunctuation($name);
				}

				$entities[] = [
					'type' => $type,
					'name' => $name,
					'text' => $type === self::TYPE_URL
						? $name
						: ($type === self::TYPE_MENTION ? '@' : '#') . $name,
					'offset' => $match[0][1],
				];
			}
		}

		return $entities;
	}

	/**
	 * The length of a post's text as Mastodon counts it against
	 * `max_characters`: every URL as URL_LENGTH characters, and a remote
	 * mention as its `@name` alone, as clients are told to count.
	 */
	public static function countableLength(string $text): int {
		$length = mb_strlen($text);
		foreach ((new self())->entitiesIn($text) as $entity) {
			if ($entity['type'] === self::TYPE_URL) {
				$length += self::URL_LENGTH - mb_strlen($entity['text']);
			} elseif ($entity['type'] === self::TYPE_MENTION && ($at = mb_strpos($entity['name'], '@')) !== false) {
				$length -= mb_strlen($entity['name']) - $at;
			}
		}

		return $length;
	}

	/**
	 * The outbound `content` of a post: the text as HTML, with a link for every
	 * entity the `tag` array vouches for.
	 *
	 * @param array<int, array<string, string>> $tags the note's `tag` array,
	 *                                                as `StreamService` built it
	 */
	public function toHtml(string $text, array $tags): string {
		$hrefs = $this->hrefsByName($tags);

		$html = '';
		$cursor = 0;
		foreach ($this->entitiesIn($text) as $entity) {
			$html .= $this->escape(substr($text, $cursor, $entity['offset'] - $cursor));
			$html .= $this->markup($entity, $hrefs);
			$cursor = $entity['offset'] + strlen($entity['text']);
		}
		$html .= $this->escape(substr($text, $cursor));

		return $this->paragraphs($html);
	}

	/**
	 * The accounts the text links to, as the hrefs `toHtml()` gives them.
	 *
	 * @param array<int, array<string, string>> $tags the note's `tag` array
	 * @return list<string>
	 */
	public function mentionedHrefs(string $text, array $tags): array {
		$hrefs = $this->hrefsByName($tags)[self::TYPE_MENTION];

		$linked = [];
		foreach ($this->entitiesIn($text) as $entity) {
			$href = ($entity['type'] === self::TYPE_MENTION) ? ($hrefs[mb_strtolower($entity['name'])] ?? '') : '';
			if ($href !== '') {
				$linked[$href] = true;
			}
		}

		return array_keys($linked);
	}

	/**
	 * The href each entity may be linked to, keyed by lower-cased name. An
	 * entity with no `tag` entry (an unresolved mention) gets no link.
	 *
	 * @param array<int, array<string, string>> $tags
	 * @return array{mention: array<string, string>, hashtag: array<string, string>}
	 */
	private function hrefsByName(array $tags): array {
		$hrefs = [self::TYPE_MENTION => [], self::TYPE_HASHTAG => []];

		foreach ($tags as $tag) {
			$type = match ((string)($tag['type'] ?? '')) {
				'Mention' => self::TYPE_MENTION,
				'Hashtag' => self::TYPE_HASHTAG,
				default => '',
			};
			$name = ltrim((string)($tag['name'] ?? ''), '@#');
			$href = (string)($tag['href'] ?? '');

			// a tag whose href this app would refuse to render from a peer is
			// not one it may emit either
			if ($type === '' || $name === '' || $href === '' || !HtmlSanitizer::isAllowedUrl($href)) {
				continue;
			}

			$hrefs[$type][mb_strtolower($name)] = $href;
		}

		return $hrefs;
	}

	/**
	 * @param array{type: string, text: string, name: string, offset: int} $entity
	 * @param array{mention: array<string, string>, hashtag: array<string, string>} $hrefs
	 */
	private function markup(array $entity, array $hrefs): string {
		if ($entity['type'] === self::TYPE_URL) {
			return $this->anchor($entity['name'], $entity['text'], '');
		}

		$href = $hrefs[$entity['type']][mb_strtolower($entity['name'])] ?? '';
		if ($href === '') {
			return $this->escape($entity['text']);
		}

		// Mastodon's own classes: `u-url mention` for an actor, `mention
		// hashtag` with `rel="tag"` for a tag; the frontend reads the same.
		// The label is the whole handle as typed, since the composer rebuilds
		// its text from this HTML on edit and would lose the domain.
		return $entity['type'] === self::TYPE_MENTION
			? $this->anchor($href, $entity['text'], 'u-url mention')
			: $this->anchor($href, $entity['text'], 'mention hashtag', 'tag');
	}

	private function anchor(string $href, string $label, string $class, string $rel = ''): string {
		if (!HtmlSanitizer::isAllowedUrl($href)) {
			return $this->escape($label);
		}

		$attributes = ' href="' . $this->escape($href) . '"';
		if ($class !== '') {
			$attributes .= ' class="' . $this->escape($class) . '"';
		}
		// a bare link out of a post must neither pass a referrer nor hand over
		// window.opener, which is the rule HtmlSanitizer applies to an incoming
		// one
		$attributes .= ' rel="' . $this->escape($rel !== '' ? $rel : 'nofollow noopener noreferrer') . '"';

		return '<a' . $attributes . '>' . $this->escape($label) . '</a>';
	}

	/**
	 * Blank-line-separated blocks become paragraphs and every other newline a
	 * break, as Mastodon publishes; a bare newline in HTML is whitespace.
	 */
	private function paragraphs(string $html): string {
		$blocks = preg_split('~\R[ \t]*\R\s*~u', trim($html, "\r\n")) ?: [];

		$paragraphs = '';
		foreach ($blocks as $block) {
			$block = trim($block, "\r\n");
			if (trim($block) === '') {
				continue;
			}

			$paragraphs .= '<p>' . preg_replace('~\R~u', '<br />', $block) . '</p>';
		}

		return $paragraphs;
	}

	private function escape(string $text): string {
		return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE);
	}

	/**
	 * A URL runs to the end of the word, and the end of a word is where a
	 * sentence's punctuation begins. A closing bracket is kept only when the
	 * URL opened it, so `(see https://example.invalid/a_(b))` keeps its inner
	 * pair and loses the outer one.
	 */
	private function withoutTrailingPunctuation(string $url): string {
		while ($url !== '') {
			$last = substr($url, -1);

			if (str_contains(self::URL_TRAILING, $last)) {
				$url = substr($url, 0, -1);
				continue;
			}

			if (isset(self::URL_BRACKETS[$last])
				&& substr_count($url, self::URL_BRACKETS[$last]) < substr_count($url, $last)) {
				$url = substr($url, 0, -1);
				continue;
			}

			break;
		}

		return $url;
	}
}
