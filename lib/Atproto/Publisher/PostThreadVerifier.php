<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Publisher;

use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;

/** Decides whether Bluesky's local records still contain the current post. */
final class PostThreadVerifier {
	public function __construct(
		private RecordMapper $mapper,
	) {
	}

	/** @param list<StoredRecord> $records root first, followed by continuations */
	public function isComplete(Stream $post, array $records): bool {
		if (!$this->hasValidContinuationChain($records)) {
			return false;
		}
		if (!$this->hasRequiredEmbedsOrFallback($post, $records)) {
			return false;
		}
		$sourceText = $this->mapper->sourceText($post);
		$expected = self::withoutWhitespace($sourceText);
		$pollOptions = $this->mapper->sourcePollOptions($post);
		if ($expected === '' && $pollOptions === []) {
			return true;
		}

		$plainText = $this->isPlainTextPost($post);
		$parts = [];
		$generatedLinks = [];
		foreach ($records as $recordIndex => $record) {
			$value = $record->value();
			$text = is_string($value['text'] ?? null) ? $value['text'] : '';
			$parts[] = $text;
			if (!$plainText) {
				continue;
			}
			foreach ($value['facets'] ?? [] as $facet) {
				if (($facet['features'][0]['uri'] ?? '') !== $post->pageUrl()) {
					continue;
				}
				$start = $facet['index']['byteStart'] ?? null;
				$end = $facet['index']['byteEnd'] ?? null;
				if (is_int($start) && is_int($end) && $start >= 0 && $end > $start && $end <= strlen($text)) {
					$generatedLinks[] = [$recordIndex, $start, $end];
				}
			}
		}
		$published = self::withoutWhitespace(implode('', $parts));
		if ($plainText) {
			if (self::sourceBodyMatchesParts($sourceText, $parts)) {
				return true;
			}

			// The generated Social URL can itself span a record boundary. Remove
			// candidate facets from the end until only the source body remains.
			usort($generatedLinks, static fn (array $a, array $b): int => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
			foreach ($generatedLinks as [$recordIndex, $start, $end]) {
				$parts[$recordIndex] = substr($parts[$recordIndex], 0, $start) . substr($parts[$recordIndex], $end);
				// Once the Social link's facet is removed, its preceding paragraph break is
				// trailing whitespace that may be trimmed at a part boundary.
				$parts[$recordIndex] = rtrim($parts[$recordIndex]);
				if (self::sourceBodyMatchesParts($sourceText, $parts)) {
					return true;
				}
			}

			return false;
		}
		if ($expected === '' || str_contains($published, $expected)) {
			$bodyEnd = strpos($published, $expected) + strlen($expected);
			$tail = substr($published, $bodyEnd);
			$pageUrl = self::withoutWhitespace($post->pageUrl());
			if ($pageUrl !== '') {
				$linkPosition = strpos($tail, $pageUrl);
				if ($linkPosition !== false) {
					$tail = substr($tail, 0, $linkPosition);
				}
			}
			$offset = 0;
			foreach ($pollOptions as $option) {
				$option = self::withoutWhitespace($option);
				$position = strpos($tail, $option, $offset);
				if ($position === false) {
					return false;
				}
				$offset = $position + strlen($option);
			}

			return true;
		}

		return false;
	}

	/** @param list<StoredRecord> $records */
	private function hasRequiredEmbedsOrFallback(Stream $post, array $records): bool {
		$attachments = $post->getAttachments();
		$quote = trim($post->getQuote());
		if ($attachments === [] && $quote === '') {
			return true;
		}

		$rootValue = $records[0]->value();
		$embed = $rootValue['embed'] ?? null;
		$pageLink = self::hasTrailingPageLink($post, $records);
		if ($attachments !== [] && !self::hasMediaEmbed($embed) && !$pageLink) {
			return false;
		}
		if ($quote !== '' && !self::hasRecordEmbed($embed)) {
			$published = implode('', array_map(static fn (StoredRecord $record): string => is_string($record->value()['text'] ?? null) ? $record->value()['text'] : '', $records));
			if (!str_contains(self::withoutWhitespace($published), self::withoutWhitespace($quote))) {
				return false;
			}
		}

		return true;
	}

	private static function hasMediaEmbed(mixed $embed): bool {
		if (!is_array($embed)) {
			return false;
		}
		$type = $embed['$type'] ?? '';
		if (in_array($type, ['app.bsky.embed.images', 'app.bsky.embed.gallery', 'app.bsky.embed.video'], true)) {
			return true;
		}

		return $type === 'app.bsky.embed.recordWithMedia' && self::hasMediaEmbed($embed['media'] ?? null);
	}

	private static function hasRecordEmbed(mixed $embed): bool {
		return is_array($embed) && in_array($embed['$type'] ?? '', ['app.bsky.embed.record', 'app.bsky.embed.recordWithMedia'], true);
	}

	/** @param list<StoredRecord> $records */
	private static function hasTrailingPageLink(Stream $post, array $records): bool {
		$last = $records[array_key_last($records)] ?? null;
		if (!$last instanceof StoredRecord) {
			return false;
		}
		$text = $last->value()['text'] ?? '';
		if (!is_string($text) || $text === '') {
			return false;
		}
		foreach ($last->value()['facets'] ?? [] as $facet) {
			if (($facet['features'][0]['uri'] ?? '') === $post->pageUrl()
				&& ($facet['index']['byteEnd'] ?? null) === strlen($text)) {
				return true;
			}
		}

		return false;
	}

	/** @param list<StoredRecord> $records */
	private function hasValidContinuationChain(array $records): bool {
		if ($records === []) {
			return false;
		}
		if (count($records) === 1) {
			return true;
		}

		$root = $records[0];
		$rootValue = $root->value();
		$threadRoot = $rootValue['reply']['root'] ?? [
			'uri' => $root->uri(),
			'cid' => $root->cid->toString(),
		];
		if (!is_array($threadRoot) || !is_string($threadRoot['uri'] ?? null) || !is_string($threadRoot['cid'] ?? null)) {
			return false;
		}

		$parent = ['uri' => $root->uri(), 'cid' => $root->cid->toString()];
		foreach (array_slice($records, 1) as $record) {
			$reply = $record->value()['reply'] ?? null;
			if (!is_array($reply)
				|| !self::sameStrongRef($reply['root'] ?? null, $threadRoot)
				|| !self::sameStrongRef($reply['parent'] ?? null, $parent)) {
				return false;
			}
			$parent = ['uri' => $record->uri(), 'cid' => $record->cid->toString()];
		}

		return true;
	}

	private static function sameStrongRef(mixed $actual, array $expected): bool {
		return is_array($actual)
			&& ($actual['uri'] ?? null) === $expected['uri']
			&& ($actual['cid'] ?? null) === $expected['cid'];
	}

	private function isPlainTextPost(Stream $post): bool {
		return !$post instanceof Question
			&& trim($post->getSpoilerText()) === ''
			&& trim($post->getQuote()) === ''
			&& trim($post->getInReplyTo()) === ''
			&& $post->getAttachments() === [];
	}

	private static function withoutWhitespace(string $text): string {
		return preg_replace('/\\s+/u', '', $text) ?? $text;
	}

	/**
	 * Thread splitting may trim whitespace where two records meet, but a word
	 * or other source text must never be lost or altered inside a record.
	 *
	 * @param list<string> $parts
	 */
	private static function sourceBodyMatchesParts(string $source, array $parts): bool {
		$cursor = 0;
		foreach ($parts as $part) {
			if ($part === '') {
				continue;
			}
			$position = strpos($source, $part, $cursor);
			if ($position === false) {
				return false;
			}
			$between = substr($source, $cursor, $position - $cursor);
			if ($between !== '' && preg_match('/^\\s+$/u', $between) !== 1) {
				return false;
			}
			$cursor = $position + strlen($part);
		}
		$remainder = substr($source, $cursor);

		return $remainder === '' || preg_match('/^\\s+$/u', $remainder) === 1;
	}
}
