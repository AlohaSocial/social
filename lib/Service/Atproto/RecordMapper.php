<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Atproto;

use OCA\Social\Model\ActivityPub\ACore;
use OCP\IURLGenerator;

/**
 * One Bluesky record read as the document this app stores: a `Note` and the
 * `Create` that carries it.
 *
 * The record is the raw `value` a PDS serves — plain text, byte offsets,
 * `at://` URIs and blobs — and nothing downstream understands any of that.
 * What comes out of here is ActivityPub, so that a post read from AT-Proto
 * goes through the same save, the same recipients, the same notifications
 * and the same timeline queries as a post delivered by a peer, and differs
 * only in where it came from.
 *
 * Three translations carry most of the weight:
 *
 * - **the ids.** `at://did:plc:…/app.bsky.feed.post/3m…` becomes the local
 *   id, which keeps the collection name as its path segment: the record's
 *   own name is in the id, so the way back (`idOfUri()`) is a rename and
 *   nothing else.
 * - **the rich text.** Bluesky addresses facets in *bytes* of the UTF-8
 *   text, and a facet may nest inside another (a link inside a bold run).
 *   The text is split at every boundary and each piece escaped on its own,
 *   which is the only order that keeps byte offsets true after the escaping
 *   has changed the string's length.
 * - **the audience.** Every Bluesky post is public: there are no followers-
 *   only posts there, so `to` is the public collection and `cc` the author's
 *   followers collection, which is what puts the post on the home timeline
 *   of everybody who follows the account here.
 */
class RecordMapper {
	public function __construct(
		private AtprotoIdentity $identity,
		private IURLGenerator $urlGenerator,
	) {
	}

	/**
	 * The `Create` one feed post is read as, ready for
	 * `ImportService::parseIncomingRequest()`.
	 *
	 * @param array<string, mixed> $record the `value` of a listRecords entry
	 *
	 * @return array<string, mixed>
	 */
	public function create(string $did, string $rkey, array $record, string $pds): array {
		$note = $this->note($did, $rkey, $record, $pds);

		return [
			'id' => $note['id'] . '/activity',
			'type' => 'Create',
			'actor' => $note['attributedTo'],
			'published' => $note['published'],
			'to' => $note['to'],
			'cc' => $note['cc'],
			'object' => $note,
		];
	}

	/**
	 * The `Note` a feed post record is.
	 *
	 * @param array<string, mixed> $record the record's own `value`
	 *
	 * @return array<string, mixed>
	 */
	public function note(string $did, string $rkey, array $record, string $pds): array {
		$author = $this->identity->actorId($did);
		$text = (string)($record['text'] ?? '');
		$facets = is_array($record['facets'] ?? null) ? (array)$record['facets'] : [];

		$note = [
			'id' => $this->identity->recordId($did, 'app.bsky.feed.post', $rkey),
			'type' => 'Note',
			'attributedTo' => $author,
			'content' => $this->richtext($text, $facets, $did),
			'published' => $this->published($record),
			// public, as every Bluesky post is: the address the home
			// timeline's recipient row is written from
			'to' => [ACore::CONTEXT_PUBLIC],
			'cc' => [$author . '/followers'],
			// the profile page it was published on, which is what a reader
			// follows when the local id says nothing to them
			'url' => 'https://bsky.app/profile/' . $did . '/post/' . $rkey,
			'tag' => $this->tags($text, $facets, $did),
			'sensitive' => $this->sensitive($record),
		];

		$language = (string)(is_array($record['langs'] ?? null) ? ($record['langs'][0] ?? '') : '');
		if ($language !== '') {
			$note['language'] = $language;
		}

		$parent = $this->atUriOf($record['reply']['parent']['uri'] ?? null);
		if ($parent !== '') {
			$note['inReplyTo'] = $parent;
		}

		$attachments = $this->attachments($record, $did, $pds);
		if ($attachments !== []) {
			$note['attachment'] = $attachments;
		}

		$quote = $this->quoted($record);
		if ($quote !== '') {
			$note['quote'] = $quote;
		}

		return $note;
	}

	// ------------------------------------------------------------ rich text

	/**
	 * The record's text as HTML: every facet applied at the byte range it
	 * addresses, and the rest escaped.
	 *
	 * @param array<int, mixed> $facets
	 */
	public function richtext(string $text, array $facets, string $did): string {
		$length = strlen($text);
		if ($length === 0) {
			return '';
		}

		// every boundary a facet starts or ends at, plus both ends of the
		// text: the pieces between two boundaries are the only spans that
		// every covering facet either contains wholly or lies outside of
		$marks = [0, $length];
		$covering = [];
		foreach ($facets as $facet) {
			if (!is_array($facet)) {
				continue;
			}

			$start = (int)($facet['index']['byteStart'] ?? -1);
			$end = (int)($facet['index']['byteEnd'] ?? -1);
			if ($start < 0 || $end <= $start || $end > $length) {
				// an offset the text does not have: the record was edited
				// under us, or a peer cannot count. Left out rather than
				// trusted — a wrong offset would cut a character in half
				continue;
			}

			$marks[] = $start;
			$marks[] = $end;
			$covering[] = [$start, $end, is_array($facet['features'] ?? null) ? (array)$facet['features'] : []];
		}

		$marks = array_values(array_unique($marks));
		sort($marks);

		$html = '';
		foreach ($marks as $i => $start) {
			if (!isset($marks[$i + 1])) {
				break;
			}

			$end = $marks[$i + 1];
			$piece = $this->escape(substr($text, $start, $end - $start));

			foreach ($covering as [$from, $to, $features]) {
				if ($from > $start || $to < $end) {
					continue;
				}

				$piece = $this->wrap($piece, $features, $did);
			}

			$html .= $piece;
		}

		return $html;
	}

	/**
	 * One piece of text wrapped in what its facets say it is. The features
	 * arrive in the order the record lists them, so the outermost span is
	 * the one that was written first, which is how a reader nests them.
	 */
	private function wrap(string $html, array $features, string $did): string {
		foreach ($features as $feature) {
			if (!is_array($feature)) {
				continue;
			}

			$anchor = $this->anchor($html, $feature, $did);
			if ($anchor !== null) {
				$html = $anchor;
			}
		}

		return $html;
	}

	/**
	 * The anchor a facet asks for, or `null` for the facets with nothing to
	 * link — the bold/italic/strikethrough style facets are rendered by the
	 * reader from the post's own markup rather than as HTML here, because
	 * every other server's post reaches this app as anchors and nothing else.
	 */
	private function anchor(string $html, array $feature, string $did): ?string {
		$type = (string)($feature['$type'] ?? '');

		if ($type === 'app.bsky.richtext.facet#link') {
			$uri = trim((string)($feature['uri'] ?? ''));
			if (!in_array((string)parse_url($uri, PHP_URL_SCHEME), ['http', 'https'], true)) {
				return null;
			}

			return '<a href="' . $this->escape($uri) . '" rel="nofollow noopener noreferrer">'
				. $html . '</a>';
		}

		if ($type === 'app.bsky.richtext.facet#mention') {
			$handle = ltrim($html, '@');
			$actor = $this->identity->actorId((string)($feature['did'] ?? ''));

			return '<a href="' . $this->escape($actor) . '" class="u-url mention" rel="nofollow noopener noreferrer">'
				. $this->escape('@' . $handle) . '</a>';
		}

		if ($type === 'app.bsky.richtext.facet#tag') {
			$tag = ltrim(trim((string)($feature['tag'] ?? '')), '#');
			if ($tag === '') {
				return null;
			}

			return '<a href="' . $this->escape($this->tagUrl($tag)) . '" class="mention hashtag" rel="tag">'
				. $this->escape('#' . $tag) . '</a>';
		}

		return null;
	}

	/**
	 * The `tag` array the reader builds mentions and hashtags out of: the
	 * anchors above are what a person sees, these are what `Note::import()`
	 * counts and what a client turns into a link it knows about.
	 *
	 * @param array<int, mixed> $facets
	 *
	 * @return array<int, array<string, string>>
	 */
	public function tags(string $text, array $facets, string $did): array {
		$tags = [];
		foreach ($facets as $facet) {
			if (!is_array($facet)) {
				continue;
			}

			$start = (int)($facet['index']['byteStart'] ?? -1);
			$end = (int)($facet['index']['byteEnd'] ?? -1);
			if ($start < 0 || $end <= $start || $end > strlen($text)) {
				continue;
			}

			foreach ((array)($facet['features'] ?? []) as $feature) {
				if (!is_array($feature)) {
					continue;
				}

				$type = (string)($feature['$type'] ?? '');
				$label = trim(substr($text, $start, $end - $start));

				if ($type === 'app.bsky.richtext.facet#mention' && $label !== '') {
					$tags[] = [
						'type' => 'Mention',
						'href' => $this->identity->actorId((string)($feature['did'] ?? '')),
						'name' => $label,
					];
				}

				if ($type === 'app.bsky.richtext.facet#tag') {
					$tag = ltrim(trim((string)($feature['tag'] ?? '')), '#');
					if ($tag !== '') {
						$tags[] = [
							'type' => 'Hashtag',
							'href' => $this->tagUrl($tag),
							'name' => '#' . $tag,
						];
					}
				}
			}
		}

		return $tags;
	}

	// ---------------------------------------------------------- attachments

	/**
	 * The pictures and linked pages a record carries, as attachments the
	 * cache will copy: a blob becomes the PDS's own `getBlob` address, which
	 * answers for any public repository whether or not it is on the Bluesky
	 * network, and a link is the page it points at.
	 *
	 * @param array<string, mixed> $record
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function attachments(array $record, string $did, string $pds): array {
		$attachments = [];

		foreach ($this->embeds($record) as $embed) {
			foreach ((array)($embed['images'] ?? []) as $image) {
				if (!is_array($image)) {
					continue;
				}

				$blob = (string)($image['image']['ref']['$link'] ?? '');
				if ($blob === '') {
					continue;
				}

				$attachment = [
					'type' => 'Image',
					'url' => $this->blobUrl($did, $blob, $pds),
					'mediaType' => (string)($image['image']['mimeType'] ?? 'image/jpeg'),
					'name' => (string)($image['alt'] ?? ''),
				];
				if ($attachment['url'] !== '') {
					$attachments[] = $attachment;
				}
			}

			$external = $embed['external'] ?? null;
			if (is_array($external) && (string)($external['uri'] ?? '') !== '') {
				$attachments[] = [
					'type' => 'Document',
					'url' => (string)$external['uri'],
					'mediaType' => (string)($external['mime'] ?? '') ?: 'text/html',
					'name' => (string)($external['title'] ?? ''),
					'summary' => (string)($external['description'] ?? ''),
				];
			}
		}

		return $attachments;
	}

	/**
	 * The embeds of a record: the one it has, plus the two halves of
	 * `recordWithMedia` — a quote and the picture it was posted with.
	 *
	 * @param array<string, mixed> $record
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function embeds(array $record): array {
		$embed = $record['embed'] ?? null;
		if (!is_array($embed)) {
			return [];
		}

		$embeds = [$embed];
		if (($embed['$type'] ?? '') === 'app.bsky.embed.recordWithMedia') {
			foreach (['record', 'media'] as $half) {
				if (is_array($embed[$half] ?? null)) {
					$embeds[] = (array)$embed[$half];
				}
			}
		}

		return $embeds;
	}

	/**
	 * The post a record quotes, as this instance's id for it.
	 *
	 * @param array<string, mixed> $record
	 */
	private function quoted(array $record): string {
		foreach ($this->embeds($record) as $embed) {
			if (!in_array(($embed['$type'] ?? ''), ['app.bsky.embed.record', 'app.bsky.embed.recordWithMedia'], true)) {
				continue;
			}

			$uri = $embed['record']['uri'] ?? ($embed['record']['record']['uri'] ?? '');
			$quoted = $this->atUriOf($uri);
			if ($quoted !== '') {
				return $quoted;
			}
		}

		return '';
	}

	// ------------------------------------------------------------- helpers

	/**
	 * `at://did/collection/rkey` as this instance's id for that record, or
	 * `''` when it names no post this app stores.
	 *
	 * @param mixed $uri
	 */
	public function atUriOf(mixed $uri): string {
		if (!is_string($uri) || !str_starts_with($uri, 'at://')) {
			return '';
		}

		$parts = explode('/', substr($uri, 5));
		if (count($parts) !== 3) {
			return '';
		}

		[$did, $collection, $rkey] = $parts;
		if (!str_starts_with($did, 'did:') || $rkey === '' || $collection !== 'app.bsky.feed.post') {
			return '';
		}

		return $this->identity->recordId($did, $collection, $rkey);
	}

	/**
	 * A record's `createdAt` as ActivityPub dates it. A record without one
	 * — which no feed post has, but a malformed one could — is dated now
	 * rather than dropped, because a post with no date is a post every
	 * timeline sorts to the end of.
	 *
	 * @param array<string, mixed> $record
	 */
	private function published(array $record): string {
		$time = strtotime((string)($record['createdAt'] ?? ''));

		return gmdate('Y-m-d\TH:i:s\Z', $time !== false ? $time : time());
	}

	/**
	 * Whether the record labels itself as something a reader should be
	 * warned about. Bluesky's self-labels are the author's own — exactly
	 * what a `sensitive` flag means here — and nothing else is guessed at:
	 * moderation labels from other services are not this instance's to act
	 * on while reading.
	 *
	 * @param array<string, mixed> $record
	 */
	private function sensitive(array $record): bool {
		$labels = $record['labels'] ?? null;
		if (!is_array($labels)) {
			return false;
		}

		foreach ((array)($labels['labels'] ?? $labels) as $label) {
			$value = is_array($label) ? (string)($label['val'] ?? '') : '';
			if (in_array($value, ['porn', 'sexual', 'graphic-media', 'nudity'], true)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Where a hashtag written here leads: the hashtag timeline this app
	 * serves, the same URL `StreamService::addHashtag()` writes.
	 */
	private function tagUrl(string $tag): string {
		return $this->urlGenerator->linkToRouteAbsolute(
			'social.Navigation.timeline', ['path' => 'tags/' . mb_strtolower($tag, 'UTF-8')]
		);
	}

	/**
	 * A blob as its public address on the PDS that holds it.
	 */
	private function blobUrl(string $did, string $cid, string $pds): string {
		return rtrim($pds, '/')
			. '/xrpc/com.atproto.sync.getBlob?did=' . rawurlencode($did)
			. '&cid=' . rawurlencode($cid);
	}

	private function escape(string $text): string {
		return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
