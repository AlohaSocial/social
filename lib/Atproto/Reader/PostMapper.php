<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\Details;

/**
 * A Bluesky post, as the AppView shows it in a feed, as the `Create` of a
 * `Note` a Fediverse server would have sent — so the one import path
 * stores it with every side effect a post has here. A repost in a feed is
 * the `Announce` the boosting account would have sent.
 *
 * What the mapping decides: the post's text and facets become HTML; the
 * pictures of an image embed become attachments pointing at the CDN, and
 * a video is a federated `Video` streamed from Bluesky's video CDN; a
 * reply names its parent, a quote the quoted post; the moderation labels
 * of §12.1 become `sensitive` and a content warning; every post is public
 * and addressed to the author's followers collection, which is what the
 * home timeline joins on. Counts and the `at://` URI ride in `details`.
 */
class PostMapper {
	public const DETAIL = Details::ATPROTO;

	public function __construct(
		private LocalRecordResolver $local,
	) {
	}

	private const IMAGES = 'app.bsky.embed.images#view';
	private const VIDEO = 'app.bsky.embed.video#view';
	private const EXTERNAL = 'app.bsky.embed.external#view';
	private const RECORD = 'app.bsky.embed.record#view';
	private const RECORD_WITH_MEDIA = 'app.bsky.embed.recordWithMedia#view';
	private const VIEW_RECORD = 'app.bsky.embed.record#viewRecord';
	/** the records a post can embed that are shown as a card */
	private const GENERATOR_VIEW = 'app.bsky.feed.defs#generatorView';
	private const LIST_VIEW = 'app.bsky.graph.defs#listView';
	private const STARTER_PACK_VIEW = 'app.bsky.graph.defs#starterPackViewBasic';
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
		$card = self::cardOf($embed);
		if ($card !== null && !str_contains($text, $card['url'])) {
			$content .= '<p><a href="' . self::escape($card['url']) . '" rel="nofollow noopener noreferrer" target="_blank">' . self::escape($card['title']) . '</a></p>';
		}

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
			'tag' => $this->tags($record['facets'] ?? [], (string)($record['reply']['parent']['uri'] ?? '')),
			'attachment' => $attachments,
			'inReplyTo' => $this->local->postId((string)($record['reply']['parent']['uri'] ?? '')),
			'_atproto' => [
				'uri' => $uri,
				'cid' => (string)($post['cid'] ?? ''),
				'likes' => (int)($post['likeCount'] ?? 0),
				'reposts' => (int)($post['repostCount'] ?? 0),
				'replies' => (int)($post['replyCount'] ?? 0),
				'quotes' => (int)($post['quoteCount'] ?? 0),
				'labels' => $labels,
				// each label with the labeler that applied it, for the choices a
				// person made per labeler (LabelerService::results())
				'label_sources' => self::labelSources($post['labels'] ?? []),
				'indexed_at' => (string)($post['indexedAt'] ?? ''),
				// what a reply from here names as its thread's root
				'reply_root' => self::strongRef($record['reply']['root'] ?? null),
			] + ($card === null ? [] : [
				// the card of a feed, a list or a starter pack the post embeds
				'card' => $card,
			]),
		];
		$video = self::videoOf($embed);
		if ($video !== null) {
			// the shape a federated video arrives in, so it is streamed through
			// this server's HLS proxy and never copied here, with its still
			$note['type'] = 'Video';
			$note['url'] = [
				['type' => 'Link', 'mediaType' => 'text/html', 'href' => $note['url']],
				['type' => 'Link', 'mediaType' => 'application/x-mpegURL', 'href' => $video['playlist'], 'width' => $video['width'], 'height' => $video['height']],
			];
			if ($video['thumbnail'] !== '') {
				$note['icon'] = ['type' => 'Image', 'mediaType' => 'image/jpeg', 'url' => $video['thumbnail']];
			}
		}
		if ($quote !== '') {
			$note['quote'] = $quote;
		}
		// a thread its author lets nobody reply to: the reply is not offered
		// here (a narrower gate is checked when somebody replies; Threadgates)
		if (($post['threadgate']['record']['allow'] ?? null) === []) {
			$note['interactionPolicy'] = ['canReply' => ['automaticApproval' => []]];
		}
		// a post read as a viewer its author's postgate keeps from quoting it
		if (($post['viewer']['embeddingDisabled'] ?? false) === true) {
			$note['interactionPolicy'] = ($note['interactionPolicy'] ?? []) + ['canQuote' => ['automaticApproval' => []]];
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
					return [[], $this->local->postId((string)($record['uri'] ?? '')), ''];
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
				if (self::videoOf($embed) !== null) {
					return [[], '', ''];
				}
				$parsed = BlueskyIds::parsePostId($postId);
				$url = $parsed === null ? '' : BlueskyIds::postUrl($parsed['did'], $parsed['rkey']);

				return [[], '', '<p><a href="' . self::escape($url) . '" rel="nofollow noopener noreferrer" target="_blank">' . self::escape('Video on Bluesky') . '</a></p>'];
		}

		return [[], '', ''];
	}

	/**
	 * The card for a feed, a list or a starter pack a post embeds — alone or
	 * beside pictures — from what the AppView says of it, so its page on
	 * bsky.app need not be read.
	 *
	 * @return array{url: string, title: string, description: string, image: string, provider: string}|null
	 */
	public static function cardOf(array $embed): ?array {
		if (($embed['$type'] ?? '') === self::RECORD_WITH_MEDIA) {
			$embed = ['$type' => self::RECORD, 'record' => $embed['record']['record'] ?? null];
		}
		$view = ($embed['$type'] ?? '') === self::RECORD && is_array($embed['record'] ?? null) ? $embed['record'] : [];
		$parsed = Syntax::parseAtUri((string)($view['uri'] ?? ''));
		$creator = is_array($view['creator'] ?? null) ? $view['creator'] : [];
		$handle = strtolower((string)($creator['handle'] ?? ''));
		$owner = $handle !== '' && $handle !== 'handle.invalid' ? $handle : (string)($parsed['authority'] ?? '');
		$rkey = (string)($parsed['rkey'] ?? '');
		if ($owner === '' || $rkey === '') {
			return null;
		}
		$by = $handle !== '' && $handle !== 'handle.invalid' ? ' by @' . $handle : '';
		[$url, $title, $description, $image, $provider] = match ((string)($view['$type'] ?? '')) {
			self::GENERATOR_VIEW => [
				'https://bsky.app/profile/' . $owner . '/feed/' . $rkey,
				(string)($view['displayName'] ?? ''), (string)($view['description'] ?? ''), (string)($view['avatar'] ?? ''),
				'Bluesky feed' . $by,
			],
			self::LIST_VIEW => [
				'https://bsky.app/profile/' . $owner . '/lists/' . $rkey,
				(string)($view['name'] ?? ''), (string)($view['description'] ?? ''), (string)($view['avatar'] ?? ''),
				'Bluesky list' . $by,
			],
			self::STARTER_PACK_VIEW => [
				'https://bsky.app/starter-pack/' . $owner . '/' . $rkey,
				(string)($view['record']['name'] ?? ''), (string)($view['record']['description'] ?? ''),
				(string)($creator['avatar'] ?? ''),
				'Bluesky starter pack' . $by,
			],
			default => ['', '', '', '', ''],
		};
		if ($url === '') {
			return null;
		}

		return [
			'url' => $url,
			'title' => $title !== '' ? $title : $url,
			'description' => $description,
			'image' => preg_match('#^https://#i', $image) === 1 ? $image : '',
			'provider' => $provider,
		];
	}

	/**
	 * The playable video of a post's embed, alone or beside a quote: its HLS
	 * playlist, its still and its size. Null when there is none, or while the
	 * video service has not made a playlist of it yet.
	 *
	 * @return array{playlist: string, thumbnail: string, width: int, height: int}|null
	 */
	public static function videoOf(array $embed): ?array {
		if (($embed['$type'] ?? '') === self::RECORD_WITH_MEDIA) {
			$embed = is_array($embed['media'] ?? null) ? $embed['media'] : [];
		}
		if (($embed['$type'] ?? '') !== self::VIDEO) {
			return null;
		}
		$playlist = (string)($embed['playlist'] ?? '');
		if (!preg_match('#^https://#i', $playlist)) {
			return null;
		}
		$thumbnail = (string)($embed['thumbnail'] ?? '');
		$ratio = is_array($embed['aspectRatio'] ?? null) ? $embed['aspectRatio'] : [];

		return [
			'playlist' => $playlist,
			'thumbnail' => preg_match('#^https://#i', $thumbnail) ? $thumbnail : '',
			'width' => max(0, (int)($ratio['width'] ?? 0)),
			'height' => max(0, (int)($ratio['height'] ?? 0)),
		];
	}

	/**
	 * Mentions and hashtags as the tags a Fediverse post carries them in.
	 *
	 * @return list<array{type: string, href: string, name: string}>
	 */
	private function tags(mixed $facets, string $parentUri = ''): array {
		$tags = [];
		// a reply to a local post addresses its author, which is what makes
		// the notification here; Bluesky names nobody in the reply itself
		$parent = Syntax::parseAtUri($parentUri);
		if ($parent !== null) {
			$author = $this->local->actorId($parent['authority']);
			if ($author !== '') {
				$tags[] = ['type' => 'Mention', 'href' => $author, 'name' => '@' . $parent['authority']];
			}
		}
		foreach (is_array($facets) ? $facets : [] as $facet) {
			foreach (is_array($facet['features'] ?? null) ? $facet['features'] : [] as $feature) {
				$type = (string)($feature['$type'] ?? '');
				if ($type === 'app.bsky.richtext.facet#mention' && is_string($feature['did'] ?? null) && $feature['did'] !== '') {
					$tags[] = ['type' => 'Mention', 'href' => $this->local->mentionTarget($feature['did']), 'name' => '@' . $feature['did']];
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

	/**
	 * @return list<array{src: string, val: string}>
	 */
	private static function labelSources(mixed $labels): array {
		$sources = [];
		foreach (is_array($labels) ? $labels : [] as $label) {
			if (is_array($label) && is_string($label['src'] ?? null) && is_string($label['val'] ?? null) && $label['val'] !== '' && Syntax::isDid($label['src'])) {
				$sources[] = ['src' => $label['src'], 'val' => $label['val']];
			}
		}

		return $sources;
	}

	/**
	 * @return array{uri: string, cid: string}|null
	 */
	private static function strongRef(mixed $value): ?array {
		if (!is_array($value) || !is_string($value['uri'] ?? null) || !is_string($value['cid'] ?? null) || !Syntax::isAtUri($value['uri'])) {
			return null;
		}

		return ['uri' => $value['uri'], 'cid' => $value['cid']];
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
