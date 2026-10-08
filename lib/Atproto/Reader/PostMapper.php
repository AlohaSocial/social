<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Model\ActivityPub\ACore;

/**
 * A Bluesky post, as the AppView shows it in a feed, as the `Create` of a
 * `Note` a Fediverse server would have sent — so the one import path
 * stores it with every side effect a post has here. A repost in a feed is
 * the `Announce` the boosting account would have sent.
 *
 * What the mapping decides: the post's text and facets become HTML; the
 * pictures of an image embed become attachments pointing at the CDN; a
 * reply names its parent, a quote the quoted post; the moderation labels
 * of §12.1 become `sensitive` and a content warning; every post is public
 * and addressed to the author's followers collection, which is what the
 * home timeline joins on. Counts and the `at://` URI ride in `details`.
 */
class PostMapper {
	public const DETAIL = 'atproto';

	private const IMAGES = 'app.bsky.embed.images#view';
	private const VIDEO = 'app.bsky.embed.video#view';
	private const EXTERNAL = 'app.bsky.embed.external#view';
	private const RECORD = 'app.bsky.embed.record#view';
	private const RECORD_WITH_MEDIA = 'app.bsky.embed.recordWithMedia#view';
	private const VIEW_RECORD = 'app.bsky.embed.record#viewRecord';
	private const REPOST = 'app.bsky.feed.defs#reasonRepost';

	/** labels that make the pictures sensitive and warn when the post did not */
	private const ADULT = ['porn', 'sexual', 'nudity', 'graphic-media', 'nsfl', 'gore'];
	/** labels that hide the post altogether */
	private const HIDE = ['!hide', '!takedown'];

	/**
	 * The `Create` for a post view, or null when the post must not be stored.
	 *
	 * @param array $post an `app.bsky.feed.defs#postView`
	 */
	public function create(array $post): ?array {
		$note = $this->note($post);
		if ($note === null) {
			return null;
		}

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
	 * The `Announce` of a repost in a feed, or null when the item is no repost.
	 *
	 * @param array $item an `app.bsky.feed.defs#feedViewPost`
	 */
	public function announce(array $item): ?array {
		$reason = $item['reason'] ?? null;
		if (!is_array($reason) || ($reason['$type'] ?? '') !== self::REPOST) {
			return null;
		}
		$by = (string)($reason['by']['did'] ?? '');
		$postId = BlueskyIds::postIdOfUri((string)($item['post']['uri'] ?? ''));
		if ($by === '' || $postId === '') {
			return null;
		}
		$repostUri = (string)($reason['uri'] ?? '');
		$parsed = $repostUri !== '' ? Syntax::parseAtUri($repostUri) : null;
		$rkey = $parsed !== null ? $parsed['rkey'] : (BlueskyIds::parsePostId($postId)['rkey'] ?? '');
		$published = self::datetime((string)($reason['indexedAt'] ?? ''));

		return [
			'id' => BlueskyIds::actorId($by) . '/repost/' . $rkey,
			'type' => 'Announce',
			'actor' => BlueskyIds::actorId($by),
			'object' => $postId,
			'published' => $published,
			'to' => [ACore::CONTEXT_PUBLIC],
			'cc' => [BlueskyIds::followersId($by)],
		];
	}

	/**
	 * The `Note` for a post view, or null when a label hides it.
	 */
	public function note(array $post): ?array {
		$uri = (string)($post['uri'] ?? '');
		$id = BlueskyIds::postIdOfUri($uri);
		$did = (string)($post['author']['did'] ?? '');
		$handle = strtolower((string)($post['author']['handle'] ?? ''));
		$record = is_array($post['record'] ?? null) ? $post['record'] : [];
		if ($id === '' || $did === '' || BlueskyIds::didOf($id) !== $did) {
			return null;
		}
		$labels = ActorMapper::labelValues($post['labels'] ?? []);
		if (array_intersect($labels, self::HIDE) !== []) {
			return null;
		}
		$rkey = BlueskyIds::parsePostId($id)['rkey'] ?? '';
		$text = (string)($record['text'] ?? '');
		$content = FacetRenderer::html($text, $record['facets'] ?? []);
		$embed = is_array($post['embed'] ?? null) ? $post['embed'] : [];
		[$attachments, $quote, $appended] = $this->embed($embed, $text, $id);
		$content .= $appended;

		$warning = $this->warning($labels);
		$adult = array_intersect($labels, self::ADULT) !== [];
		$published = self::datetime((string)($record['createdAt'] ?? ''), (string)($post['indexedAt'] ?? ''));

		$note = [
			'id' => $id,
			'type' => 'Note',
			'attributedTo' => BlueskyIds::actorId($did),
			'url' => BlueskyIds::postUrl($handle !== '' ? $handle : $did, $rkey),
			'published' => $published,
			'to' => [ACore::CONTEXT_PUBLIC],
			'cc' => [BlueskyIds::followersId($did)],
			'content' => $content,
			'summary' => $warning,
			'sensitive' => $adult || $warning !== '',
			'tag' => $this->tags($record['facets'] ?? []),
			'attachment' => $attachments,
			'inReplyTo' => BlueskyIds::postIdOfUri((string)($record['reply']['parent']['uri'] ?? '')),
			'_atproto' => [
				'uri' => $uri,
				'cid' => (string)($post['cid'] ?? ''),
				'likes' => (int)($post['likeCount'] ?? 0),
				'reposts' => (int)($post['repostCount'] ?? 0),
				'replies' => (int)($post['replyCount'] ?? 0),
				'quotes' => (int)($post['quoteCount'] ?? 0),
				'labels' => $labels,
				'indexed_at' => (string)($post['indexedAt'] ?? ''),
			],
		];
		if ($quote !== '') {
			$note['quote'] = $quote;
		}
		$langs = $record['langs'] ?? [];
		if (is_array($langs) && is_string($langs[0] ?? null) && $langs[0] !== '') {
			$note['contentMap'] = [$langs[0] => $content];
		}
		if ($note['inReplyTo'] === '') {
			unset($note['inReplyTo']);
		}

		return $note;
	}

	/**
	 * The pictures, the quoted post and what is appended to the text for an
	 * embed the post has no other way to show.
	 *
	 * @return array{0: list<array>, 1: string, 2: string}
	 */
	private function embed(array $embed, string $text, string $postId): array {
		$type = (string)($embed['$type'] ?? '');
		if ($type === self::RECORD_WITH_MEDIA) {
			[$attachments, , $appended] = $this->embed(is_array($embed['media'] ?? null) ? $embed['media'] : [], $text, $postId);
			// the nested record view is of one known type, so the JSON names none
			$record = is_array($embed['record'] ?? null) ? $embed['record'] : [];
			[, $quote] = $this->embed($record + ['$type' => self::RECORD], $text, $postId);

			return [$attachments, $quote, $appended];
		}
		switch ($type) {
			case self::IMAGES:
				$attachments = [];
				foreach (is_array($embed['images'] ?? null) ? $embed['images'] : [] as $image) {
					$url = (string)($image['fullsize'] ?? '');
					if ($url === '') {
						continue;
					}
					$attachment = ['type' => 'Image', 'mediaType' => ActorMapper::mediaTypeOf($url), 'url' => $url, 'name' => (string)($image['alt'] ?? '')];
					$ratio = $image['aspectRatio'] ?? null;
					if (is_array($ratio) && (int)($ratio['width'] ?? 0) > 0 && (int)($ratio['height'] ?? 0) > 0) {
						$attachment['width'] = (int)$ratio['width'];
						$attachment['height'] = (int)$ratio['height'];
					}
					$attachments[] = $attachment;
				}

				return [$attachments, '', ''];
			case self::RECORD:
				$record = $embed['record'] ?? [];
				if (is_array($record) && ($record['$type'] ?? '') === self::VIEW_RECORD) {
					return [[], BlueskyIds::postIdOfUri((string)($record['uri'] ?? '')), ''];
				}

				return [[], '', ''];
			case self::EXTERNAL:
				$uri = (string)($embed['external']['uri'] ?? '');
				if (!preg_match('#^https?://#i', $uri) || str_contains($text, $uri)) {
					return [[], '', ''];
				}
				$title = trim((string)($embed['external']['title'] ?? ''));

				return [[], '', '<p><a href="' . self::escape($uri) . '" rel="nofollow noopener noreferrer" target="_blank">' . self::escape($title !== '' ? $title : $uri) . '</a></p>'];
			case self::VIDEO:
				$parsed = BlueskyIds::parsePostId($postId);
				$url = $parsed === null ? '' : BlueskyIds::postUrl($parsed['did'], $parsed['rkey']);

				return [[], '', '<p><a href="' . self::escape($url) . '" rel="nofollow noopener noreferrer" target="_blank">' . self::escape('Video on Bluesky') . '</a></p>'];
		}

		return [[], '', ''];
	}

	/**
	 * Mentions and hashtags as the tags a Fediverse post carries them in.
	 *
	 * @return list<array{type: string, href: string, name: string}>
	 */
	private function tags(mixed $facets): array {
		$tags = [];
		foreach (is_array($facets) ? $facets : [] as $facet) {
			foreach (is_array($facet['features'] ?? null) ? $facet['features'] : [] as $feature) {
				$type = (string)($feature['$type'] ?? '');
				if ($type === 'app.bsky.richtext.facet#mention' && is_string($feature['did'] ?? null) && $feature['did'] !== '') {
					$tags[] = ['type' => 'Mention', 'href' => BlueskyIds::actorId($feature['did']), 'name' => '@' . $feature['did']];
				} elseif ($type === 'app.bsky.richtext.facet#tag' && is_string($feature['tag'] ?? null) && $feature['tag'] !== '') {
					$tags[] = ['type' => 'Hashtag', 'href' => BlueskyIds::hashtagUrl($feature['tag']), 'name' => '#' . $feature['tag']];
				}
			}
		}

		return $tags;
	}

	/**
	 * The content warning the labels ask for: the post's own `!warn` with
	 * no text of its own, or the service's label named.
	 */
	private function warning(array $labels): string {
		$named = [];
		foreach ($labels as $label) {
			if ($label === '!warn') {
				$named[] = 'Content warning';
			} elseif (!str_starts_with($label, '!')) {
				$named[] = ucfirst(str_replace('-', ' ', $label));
			}
		}

		return implode(', ', array_unique($named));
	}

	/** An ISO instant for the import, from the record's time or the index time. */
	public static function datetime(string ...$candidates): string {
		foreach ($candidates as $candidate) {
			$time = strtotime($candidate);
			if ($candidate !== '' && $time !== false && $time > 0) {
				return gmdate('Y-m-d\TH:i:s\Z', $time);
			}
		}

		return gmdate('Y-m-d\TH:i:s\Z');
	}

	private static function escape(string $text): string {
		return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
	}
}
