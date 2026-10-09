<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Publisher;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use OCA\Social\Atproto\Lexicon\Lexicon;

/**
 * A post's HTML as Bluesky text: plain text with facets on UTF-8 byte
 * offsets, cut to what a Bluesky post may hold.
 *
 * Paragraphs become blank lines and `<br>` a newline; a hashtag and a
 * mention keep their text, a link its href (or `text (href)` when the text
 * is something else). Facets: `#tag` for hashtags, `#link` for every URL,
 * `#mention` for a mention the caller can name a DID for and `#link` to the
 * profile otherwise.
 *
 * The HTML is read into pieces — runs of text, breaks, and the spans a
 * facet covers — and the whitespace is tidied on the pieces before the
 * text is joined, so every offset is computed once, on the final text.
 */
class TextMapper {
	/** Bluesky's limit */
	public const MAX_GRAPHEMES = 300;
	public const MAX_BYTES = 3000;
	/** where a long post is cut, leaving room for the ellipsis and the link */
	public const CUT_AT = 280;

	private const BREAK = "\n";
	private const PARAGRAPH = "\n\n";

	/**
	 * @param callable(string, string): ?string $didFor the DID of a mention, given its text and href, or null
	 * @return array{text: string, facets: array<int, array{index: array{byteStart: int, byteEnd: int}, features: array}>}
	 */
	public function fromHtml(string $html, callable $didFor): array {
		$pieces = [];
		$this->walk(self::parse($html), $pieces, $didFor);

		return self::join(self::tidy($pieces));
	}

	/**
	 * Cuts text that does not fit and appends a line with the link to the
	 * whole post. The cut falls on the last word boundary before CUT_AT
	 * graphemes; a facet past the cut is dropped, and one `#link` facet
	 * covers the appended URL.
	 *
	 * @param array<int, array{index: array{byteStart: int, byteEnd: int}, features: array}> $facets
	 * @param string[] $extraLines lines added before the link whether or not the text was cut ("+2 more pictures")
	 * @param bool $alwaysLink append the link even to text that fits (a poll's votes are here)
	 * @return array{text: string, facets: array, truncated: bool}
	 */
	public function fit(string $text, array $facets, string $url, array $extraLines = [], bool $alwaysLink = false): array {
		$extra = $extraLines === [] ? '' : self::PARAGRAPH . implode(self::BREAK, $extraLines);
		$tail = self::PARAGRAPH . $url;
		$truncated = Lexicon::graphemes($text . $extra) > self::MAX_GRAPHEMES || strlen($text . $extra) > self::MAX_BYTES;
		if ($truncated) {
			$room = max(1, min(self::CUT_AT, self::MAX_GRAPHEMES - Lexicon::graphemes($extra . $tail) - 1));
			$text = self::cut($text, $room, self::MAX_BYTES - strlen($extra . $tail) - strlen('…')) . '…';
			$end = strlen($text) - strlen('…');
			$facets = array_values(array_filter($facets, static fn (array $facet): bool => $facet['index']['byteEnd'] <= $end));
		}
		if (!$truncated && $extra === '' && !$alwaysLink) {
			return ['text' => $text, 'facets' => $facets, 'truncated' => false];
		}

		$text .= $extra . $tail;
		$facets[] = self::link(strlen($text) - strlen($url), strlen($text), $url);

		return ['text' => $text, 'facets' => $facets, 'truncated' => $truncated];
	}

	/**
	 * The facets of text that was never HTML (a poll's options, a warning
	 * line): its URLs and hashtags.
	 */
	public static function facetsOf(string $text): array {
		$facets = [];
		if (preg_match_all('#https?://[^\s<>"\')\]]+#u', $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
			foreach ($matches[0] as [$url, $offset]) {
				$url = rtrim($url, '.,;:!?');
				$facets[] = self::link($offset, $offset + strlen($url), $url);
			}
		}
		if (preg_match_all('/(?<=^|\s)#(\p{L}[\p{L}\p{N}_]*)/u', $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
			foreach ($matches[1] as [$tag, $offset]) {
				$facets[] = self::tag($offset - 1, $offset + strlen($tag), $tag);
			}
		}
		usort($facets, static fn (array $a, array $b): int => $a['index']['byteStart'] <=> $b['index']['byteStart']);

		return $facets;
	}

	private static function parse(string $html): DOMNode {
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$document->loadHTML('<?xml encoding="UTF-8"><div id="root">' . $html . '</div>', LIBXML_NONET);
		libxml_use_internal_errors($previous);

		return $document->getElementById('root') ?? $document;
	}

	/**
	 * @param array<int, array{t: string, f: ?array}> $pieces
	 * @param callable(string, string): ?string $didFor
	 */
	private function walk(DOMNode $node, array &$pieces, callable $didFor): void {
		foreach ($node->childNodes as $child) {
			if ($child instanceof DOMText) {
				$pieces[] = ['t' => (string)preg_replace('/\s+/u', ' ', $child->wholeText), 'f' => null];
				continue;
			}
			if (!$child instanceof DOMElement) {
				continue;
			}
			switch (strtolower($child->tagName)) {
				case 'br':
					$pieces[] = ['t' => self::BREAK, 'f' => null];
					break;
				case 'p':
				case 'div':
				case 'blockquote':
				case 'pre':
				case 'li':
				case 'ul':
				case 'ol':
				case 'h1':
				case 'h2':
				case 'h3':
				case 'h4':
					$pieces[] = ['t' => self::PARAGRAPH, 'f' => null];
					$this->walk($child, $pieces, $didFor);
					$pieces[] = ['t' => self::PARAGRAPH, 'f' => null];
					break;
				case 'a':
					$this->anchor($child, $pieces, $didFor);
					break;
				case 'img':
					$pieces[] = ['t' => $child->getAttribute('alt'), 'f' => null];
					break;
				case 'script':
				case 'style':
					break;
				default:
					$this->walk($child, $pieces, $didFor);
			}
		}
	}

	/**
	 * @param callable(string, string): ?string $didFor
	 */
	private function anchor(DOMElement $anchor, array &$pieces, callable $didFor): void {
		$href = trim($anchor->getAttribute('href'));
		$label = trim((string)preg_replace('/\s+/u', ' ', $anchor->textContent));
		$class = ' ' . $anchor->getAttribute('class') . ' ';

		if (str_contains($class, ' hashtag ') || ($anchor->getAttribute('rel') === 'tag' && str_starts_with($label, '#'))) {
			$tag = ltrim($label, '#');
			if ($tag !== '') {
				$pieces[] = ['t' => '#' . $tag, 'f' => ['$type' => 'app.bsky.richtext.facet#tag', 'tag' => $tag]];
			}

			return;
		}
		if (str_contains($class, ' mention ')) {
			$did = $didFor($label, $href);
			$feature = $did !== null
				? ['$type' => 'app.bsky.richtext.facet#mention', 'did' => $did]
				: (self::isHttp($href) ? ['$type' => 'app.bsky.richtext.facet#link', 'uri' => $href] : null);
			$pieces[] = ['t' => $label, 'f' => $feature];

			return;
		}
		if (!self::isHttp($href)) {
			$pieces[] = ['t' => $label, 'f' => null];

			return;
		}
		$bare = rtrim((string)preg_replace('#^https?://#', '', $href), '/');
		if ($label === '' || $label === $href || rtrim($label, '/') === $bare || rtrim($label, '/…') === substr($bare, 0, strlen(rtrim($label, '/…')))) {
			$pieces[] = ['t' => $href, 'f' => ['$type' => 'app.bsky.richtext.facet#link', 'uri' => $href]];

			return;
		}
		$pieces[] = ['t' => $label . ' (', 'f' => null];
		$pieces[] = ['t' => $href, 'f' => ['$type' => 'app.bsky.richtext.facet#link', 'uri' => $href]];
		$pieces[] = ['t' => ')', 'f' => null];
	}

	private static function isHttp(string $href): bool {
		return preg_match('#^https?://\S+$#', $href) === 1 && strlen($href) <= 2048;
	}

	/**
	 * Whitespace on the pieces: no spaces beside a break, at most one blank
	 * line in a row, nothing at the ends.
	 *
	 * @param array<int, array{t: string, f: ?array}> $pieces
	 * @return array<int, array{t: string, f: ?array}>
	 * @psalm-suppress InvalidReturnType, InvalidReturnStatement the pieces only ever change their text
	 */
	private static function tidy(array $pieces): array {
		$out = [];
		foreach ($pieces as $piece) {
			if ($piece['f'] !== null) {
				$out[] = $piece;
				continue;
			}
			$isBreak = $piece['t'] === self::BREAK || $piece['t'] === self::PARAGRAPH;
			if ($isBreak) {
				$last = count($out) - 1;
				if ($last >= 0 && $out[$last]['f'] === null && !self::isBreak($out[$last]['t'])) {
					$out[$last]['t'] = rtrim($out[$last]['t'], ' ');
					if ($out[$last]['t'] === '') {
						array_pop($out);
						$last--;
					}
				}
				if ($last >= 0 && self::isBreak($out[$last]['t'])) {
					$out[$last]['t'] = $piece['t'] === self::PARAGRAPH || $out[$last]['t'] === self::PARAGRAPH ? self::PARAGRAPH : self::BREAK;
					continue;
				}
				$out[] = $piece;
				continue;
			}
			$last = count($out) - 1;
			$text = $piece['t'];
			if ($last >= 0 && $out[$last]['f'] === null && self::isBreak($out[$last]['t'])) {
				$text = ltrim($text, ' ');
			}
			if ($text !== '') {
				$out[] = ['t' => $text, 'f' => null];
			}
		}
		while ($out !== [] && $out[0]['f'] === null && (self::isBreak($out[0]['t']) || trim($out[0]['t']) === '')) {
			array_shift($out);
		}
		if ($out !== [] && $out[0]['f'] === null) {
			$out[0]['t'] = ltrim($out[0]['t'], ' ');
		}
		while ($out !== [] && $out[count($out) - 1]['f'] === null && (self::isBreak($out[count($out) - 1]['t']) || trim($out[count($out) - 1]['t']) === '')) {
			array_pop($out);
		}
		if ($out !== [] && $out[count($out) - 1]['f'] === null) {
			$out[count($out) - 1]['t'] = rtrim($out[count($out) - 1]['t'], ' ');
		}

		return $out;
	}

	private static function isBreak(string $text): bool {
		return $text === self::BREAK || $text === self::PARAGRAPH;
	}

	/**
	 * @param array<int, array{t: string, f: ?array}> $pieces
	 * @return array{text: string, facets: array}
	 */
	private static function join(array $pieces): array {
		$text = '';
		$facets = [];
		foreach ($pieces as $piece) {
			$start = strlen($text);
			$text .= $piece['t'];
			if ($piece['f'] !== null && $piece['t'] !== '') {
				$facets[] = ['index' => ['byteStart' => $start, 'byteEnd' => strlen($text)], 'features' => [$piece['f']]];
			}
		}

		return ['text' => $text, 'facets' => $facets];
	}

	/**
	 * The last word boundary before $graphemes, and under $bytes.
	 */
	public static function cut(string $text, int $graphemes, int $bytes): string {
		preg_match_all('/\X/u', $text, $matches);
		$clusters = array_slice($matches[0], 0, $graphemes);
		$cut = implode('', $clusters);
		while (strlen($cut) > $bytes && $clusters !== []) {
			array_pop($clusters);
			$cut = implode('', $clusters);
		}
		if (strlen($cut) < strlen($text) && preg_match('/^(.*)\s\S*$/su', $cut, $m) === 1 && trim($m[1]) !== '') {
			$cut = $m[1];
		}

		return rtrim($cut, " \n.,;:");
	}

	private static function link(int $start, int $end, string $url): array {
		return ['index' => ['byteStart' => $start, 'byteEnd' => $end], 'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => $url]]];
	}

	private static function tag(int $start, int $end, string $tag): array {
		return ['index' => ['byteStart' => $start, 'byteEnd' => $end], 'features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => $tag]]];
	}
}
