<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

/**
 * A Bluesky post's text and facets as the HTML a post carries here: links
 * as anchors, mentions as the h-card anchors Mastodon makes, hashtags as
 * tag links, paragraphs and line breaks as in the text. Facets address
 * UTF-8 byte ranges; an ill-formed one (overlapping, past the end) is
 * ignored rather than trusted.
 */
final class FacetRenderer {
	private const LINK = 'app.bsky.richtext.facet#link';
	private const MENTION = 'app.bsky.richtext.facet#mention';
	private const TAG = 'app.bsky.richtext.facet#tag';

	public static function html(string $text, mixed $facets): string {
		$ranges = self::ranges($text, $facets);
		$out = '';
		$at = 0;
		foreach ($ranges as $range) {
			$out .= self::escape(substr($text, $at, $range['start'] - $at));
			$out .= self::feature($range['feature'], substr($text, $range['start'], $range['end'] - $range['start']));
			$at = $range['end'];
		}
		$out .= self::escape(substr($text, $at));

		return self::paragraphs($out);
	}

	/**
	 * The facets that fit the text, in order, none overlapping.
	 *
	 * @return list<array{start: int, end: int, feature: array}>
	 */
	private static function ranges(string $text, mixed $facets): array {
		$length = strlen($text);
		$ranges = [];
		foreach (is_array($facets) ? $facets : [] as $facet) {
			if (!is_array($facet)) {
				continue;
			}
			$start = (int)($facet['index']['byteStart'] ?? -1);
			$end = (int)($facet['index']['byteEnd'] ?? -1);
			if ($start < 0 || $end > $length || $end <= $start) {
				continue;
			}
			$feature = self::firstFeature($facet['features'] ?? []);
			if ($feature === null) {
				continue;
			}
			$ranges[] = ['start' => $start, 'end' => $end, 'feature' => $feature];
		}
		usort($ranges, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
		$kept = [];
		$last = 0;
		foreach ($ranges as $range) {
			if ($range['start'] < $last) {
				continue;
			}
			$kept[] = $range;
			$last = $range['end'];
		}

		return $kept;
	}

	private static function firstFeature(mixed $features): ?array {
		foreach (is_array($features) ? $features : [] as $feature) {
			if (is_array($feature) && in_array($feature['$type'] ?? '', [self::LINK, self::MENTION, self::TAG], true)) {
				return $feature;
			}
		}

		return null;
	}

	private static function feature(array $feature, string $text): string {
		$shown = self::escape($text);
		switch ($feature['$type']) {
			case self::LINK:
				$uri = (string)($feature['uri'] ?? '');
				if (!preg_match('#^https?://#i', $uri)) {
					return $shown;
				}

				return '<a href="' . self::escape($uri) . '" rel="nofollow noopener noreferrer" target="_blank">' . $shown . '</a>';
			case self::MENTION:
				$did = (string)($feature['did'] ?? '');
				if ($did === '') {
					return $shown;
				}

				return '<span class="h-card"><a href="' . self::escape(BlueskyIds::actorId($did)) . '" class="u-url mention">' . $shown . '</a></span>';
			case self::TAG:
				$tag = (string)($feature['tag'] ?? '');
				if ($tag === '') {
					return $shown;
				}

				return '<a href="' . self::escape(BlueskyIds::hashtagUrl($tag)) . '" class="mention hashtag" rel="tag">' . $shown . '</a>';
		}

		return $shown;
	}

	private static function paragraphs(string $html): string {
		$paragraphs = preg_split('/\n{2,}/', $html) ?: [];
		$out = '';
		foreach ($paragraphs as $paragraph) {
			$paragraph = trim($paragraph, "\n");
			if ($paragraph === '') {
				continue;
			}
			$out .= '<p>' . str_replace("\n", '<br>', $paragraph) . '</p>';
		}

		return $out;
	}

	private static function escape(string $text): string {
		return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
	}
}
