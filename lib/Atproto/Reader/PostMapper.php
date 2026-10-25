<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Atproto\Protocol\Cid;
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
 * of §12.1 become `sensitive` and a content warning; a post is public
 * and addressed to the author's followers collection, which is what the
 * home timeline joins on — unless its author asked apps not to show them
 * to logged-out people (`!no-unauthenticated`), when it is addressed to
 * the followers alone. Counts and the `at://` URI ride in `details`.
 */
class PostMapper {
	public const DETAIL = Details::ATPROTO;

	public function __construct(
		private LocalRecordResolver $local,
	) {
	}

	private const IMAGES = 'app.bsky.embed.images#view';
	private const RECORD_IMAGES = 'app.bsky.embed.images';
	private const GALLERY = 'app.bsky.embed.gallery#view';
	private const VIDEO = 'app.bsky.embed.video#view';
	private const RECORD_VIDEO = 'app.bsky.embed.video';
	private const EXTERNAL = 'app.bsky.embed.external#view';
	private const RECORD_EXTERNAL = 'app.bsky.embed.external';
	private const RECORD = 'app.bsky.embed.record#view';
	private const RECORD_RECORD = 'app.bsky.embed.record';
	private const RECORD_WITH_MEDIA = 'app.bsky.embed.recordWithMedia#view';
	private const RECORD_RECORD_WITH_MEDIA = 'app.bsky.embed.recordWithMedia';
	private const VIEW_RECORD = 'app.bsky.embed.record#viewRecord';
	private const VIEW_DETACHED = 'app.bsky.embed.record#viewDetached';
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
	 * The self-label asking apps not to show the account, or the post, to
	 * people who are not logged in. Followers-only is the audience here that
	 * keeps the post out of anonymous pages, the public timelines, search and
	 * federation addressed to the public, while the people who chose to
	 * follow the account still see it; unlisted would still serve it to
	 * anybody with the link.
	 */
	public const NO_UNAUTHENTICATED = '!no-unauthenticated';

	/**
	 * The `Create` for a post view, or null when the post must not be stored.
	 *
	 * @param array $post an `app.bsky.feed.defs#postView`
	 */
	public function create(array $post, string $pds = ''): ?array {
		$note = $this->note($post, $pds);
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
		] + self::audience(BlueskyIds::followersId($by), self::isLoggedInOnly(is_array($item['post'] ?? null) ? $item['post'] : []));
	}

	/**
	 * Whether the post, or its author's profile, carries the label asking
	 * apps not to show it to people who are not logged in.
	 *
	 * @param array $post an `app.bsky.feed.defs#postView`
	 */
	public static function isLoggedInOnly(array $post): bool {
		return in_array(self::NO_UNAUTHENTICATED, ActorMapper::labelValues($post['labels'] ?? []), true)
			|| in_array(self::NO_UNAUTHENTICATED, ActorMapper::labelValues($post['author']['labels'] ?? []), true);
	}

	/**
	 * Who a post or a boost is addressed to: the public, with the followers
	 * collection the home timeline joins on in `cc`; the followers alone when
	 * it is not for logged-out people.
	 *
	 * @return array{to: list<string>, cc: list<string>}
	 */
	private static function audience(string $followers, bool $loggedInOnly): array {
		return $loggedInOnly
			? ['to' => [$followers], 'cc' => []]
			: ['to' => [ACore::CONTEXT_PUBLIC], 'cc' => [$followers]];
	}

	/**
	 * The `Note` for a post view, or null when a label hides it.
	 */
	public function note(array $post, string $pds = ''): ?array {
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
		$viewEmbed = is_array($post['embed'] ?? null) ? $post['embed'] : [];
		$embed = self::hasUsableEmbedView($viewEmbed, $did)
			? $viewEmbed
			: (is_array($record['embed'] ?? null) ? $record['embed'] : $viewEmbed);
		[$attachments, $quote, $appended] = $this->embed($embed, $text, $id, $did, $pds);
		if (self::isLoggedInOnly($post)) {
			$attachments = [];
		}
		$content .= $appended;
		$card = self::cardOf($embed, $did);
		if ($card !== null && !str_contains($content, $card['url'])) {
			$content .= '<p><a href="' . self::escape($card['url']) . '" rel="nofollow noopener noreferrer" target="_blank">' . self::escape($card['title']) . '</a></p>';
		}

		$warning = $this->warning($labels);
		$adult = array_intersect($labels, self::ADULT) !== [];
		$published = self::datetime((string)($record['createdAt'] ?? ''), (string)($post['indexedAt'] ?? ''));
		$streamedBlobs = [];
		foreach ($attachments as $attachment) {
			if (str_starts_with((string)($attachment['url'] ?? ''), rtrim($pds, '/') . '/xrpc/com.atproto.sync.getBlob?')) {
				$streamedBlobs[] = $attachment['url'];
			}
		}

		$note = [
			'id' => $id,
			'type' => 'Note',
			'attributedTo' => BlueskyIds::actorId($did),
			'url' => BlueskyIds::postUrl($handle !== '' ? $handle : $did, $rkey),
			'published' => $published,
		] + self::audience(BlueskyIds::followersId($did), self::isLoggedInOnly($post)) + [
			'content' => $content,
			'summary' => $warning,
			'sensitive' => $adult || $warning !== '',
			'tag' => $this->tags($text, $record['facets'] ?? [], (string)($record['reply']['parent']['uri'] ?? ''), $record['tags'] ?? []),
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
			] + ($streamedBlobs === [] ? [] : ['streamed_blobs' => $streamedBlobs]) + (self::quoteDetached($embed) ? [
				// the quoted author detached this quote, so it is withdrawn
				// here too (PostStore)
				'quote_detached' => true,
			] : []) + ($card === null ? [] : [
				// the card of a feed, a list or a starter pack the post embeds
				'card' => $card,
			]),
		];
		$video = self::videoOf($embed, $did);
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

	/** Whether an embed has raw blobs needing a PDS lookup or recovery. */
	public static function hasGenericBlobs(mixed $embed): bool {
		if (!is_array($embed)) {
			return false;
		}
		$type = (string)($embed['$type'] ?? '');
		if (in_array($type, [self::RECORD_WITH_MEDIA, self::RECORD_RECORD_WITH_MEDIA], true)) {
			// The wrapper itself is a known lexicon, but its media union can be
			// supplied by another lexicon. Follow it just as embed() does so a
			// file blob can be recovered when the PDS endpoint becomes available.
			return self::hasGenericBlobs($embed['media'] ?? null);
		}
		if (in_array($type, [
			self::IMAGES, self::GALLERY, self::VIDEO, self::RECORD_VIDEO,
			self::EXTERNAL, self::RECORD_EXTERNAL, self::RECORD, self::RECORD_RECORD,
		], true)) {
			return false;
		}
		$walk = static function (mixed $value, int $depth = 0) use (&$walk): bool {
			if ($depth > 8 || !is_array($value)) {
				return false;
			}
			if (($value['$type'] ?? '') === 'blob' || isset($value['ref']) || isset($value['cid'])) {
				$mime = strtolower((string)($value['mimeType'] ?? ''));
				$ref = is_array($value['ref'] ?? null) ? $value['ref'] : [];
				$cid = (string)($ref['$link'] ?? $value['cid'] ?? '');
				return Cid::isValid($cid)
					&& preg_match('~^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+-]+$~', $mime) === 1
					&& (!str_starts_with($mime, 'image/') || self::cdnImageFormat($mime) === '');
			}
			foreach ($value as $child) {
				if ($walk($child, $depth + 1)) {
					return true;
				}
			}
			return false;
		};

		return $walk($embed);
	}

	/**
	 * The pictures, the quoted post and what is appended to the text for an
	 * embed the post has no other way to show.
	 *
	 * @return array{0: list<array>, 1: string, 2: string}
	 */
	private function embed(array $embed, string $text, string $postId, string $authorDid, string $pds = ''): array {
		$type = (string)($embed['$type'] ?? '');
		if ($type === self::RECORD_WITH_MEDIA) {
			[$attachments, , $appended] = $this->embed(is_array($embed['media'] ?? null) ? $embed['media'] : [], $text, $postId, $authorDid, $pds);
			// the nested record view is of one known type, so the JSON names none
			$record = is_array($embed['record'] ?? null) ? $embed['record'] : [];
			[, $quote] = $this->embed($record + ['$type' => self::RECORD], $text, $postId, $authorDid, $pds);

			return [$attachments, $quote, $appended];
		}
		if ($type === self::RECORD_RECORD_WITH_MEDIA) {
			[$attachments, , $appended] = $this->embed(is_array($embed['media'] ?? null) ? $embed['media'] : [], $text, $postId, $authorDid, $pds);
			$record = is_array($embed['record'] ?? null) ? $embed['record'] : [];
			$uri = (string)($record['uri'] ?? '');

			return [$attachments, Syntax::isAtUri($uri) ? $this->local->postId($uri) : '', $appended];
		}
		switch ($type) {
			case self::IMAGES:
				return [self::imagesOf(is_array($embed['images'] ?? null) ? $embed['images'] : [], 'thumb'), '', ''];
			case self::RECORD_IMAGES:
				return [self::recordImagesOf(is_array($embed['images'] ?? null) ? $embed['images'] : [], $authorDid, $pds), '', ''];
			case self::GALLERY:
				return [self::imagesOf(is_array($embed['items'] ?? null) ? $embed['items'] : [], 'thumbnail'), '', ''];
			case self::RECORD:
				$record = $embed['record'] ?? [];
				// a quote the quoted author detached is still a quote, shown
				// as withdrawn (`quoteDetached()`)
				if (is_array($record) && in_array($record['$type'] ?? '', [self::VIEW_RECORD, self::VIEW_DETACHED], true)) {
					return [[], $this->local->postId((string)($record['uri'] ?? '')), ''];
				}

				return [[], '', ''];
			case self::RECORD_RECORD:
				$record = is_array($embed['record'] ?? null) ? $embed['record'] : [];
				$uri = (string)($record['uri'] ?? '');

				return [[], Syntax::isAtUri($uri) ? $this->local->postId($uri) : '', ''];
			case self::EXTERNAL:
			case self::RECORD_EXTERNAL:
				$external = is_array($embed['external'] ?? null) ? $embed['external'] : [];
				$uri = (string)($external['uri'] ?? '');
				if (!preg_match('#^https?://#i', $uri) || str_contains($text, $uri)) {
					return [[], '', ''];
				}
				$title = trim((string)($external['title'] ?? ''));

				return [[], '', '<p><a href="' . self::escape($uri) . '" rel="nofollow noopener noreferrer" target="_blank">' . self::escape($title !== '' ? $title : $uri) . '</a></p>'];
			case self::VIDEO:
			case self::RECORD_VIDEO:
				if (self::videoOf($embed, $authorDid) !== null) {
					return [[], '', ''];
				}
				$parsed = BlueskyIds::parsePostId($postId);
				$url = $parsed === null ? '' : BlueskyIds::postUrl($parsed['did'], $parsed['rkey']);

				return [[], '', '<p><a href="' . self::escape($url) . '" rel="nofollow noopener noreferrer" target="_blank">' . self::escape('Video') . '</a></p>'];
		}

		return [self::genericBlobAttachments($embed, $authorDid, $pds), '', ''];
	}

	/**
	 * Blobs in third-party ATProto embed lexicons. The standard embed unions
	 * above keep their dedicated CDN/player handling; unknown records are
	 * exposed only as validated PDS getBlob links, later streamed by this server.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function genericBlobAttachments(array $embed, string $did, string $pds): array {
		if (!Syntax::isDid($did)) {
			return [];
		}
		$blobs = [];
		self::collectGenericBlobs($embed, $blobs);
		$attachments = [];
		$seen = [];
		foreach ($blobs as [$cid, $mime, $size, $blobName]) {
			if (isset($seen[$cid])) {
				continue;
			}
			$seen[$cid] = true;
			if (str_starts_with($mime, 'image/')) {
				$imageUrl = self::blobImageUrl(['ref' => ['$link' => $cid], 'mimeType' => $mime], $did);
				if ($imageUrl !== '') {
					$attachments[] = ['type' => 'Image', 'mediaType' => $mime === 'image/jpg' ? 'image/jpeg' : $mime, 'url' => $imageUrl, 'name' => self::blobName($blobName, $mime)];
					continue;
				}
				// The public CDN only has transformations for a short list of
				// image formats. Keep other valid image blobs as downloadable
				// files through their verified PDS instead of silently dropping
				// the attachment.
			}
			if (!self::isSafePds($pds)) {
				continue;
			}
			$url = rtrim($pds, '/') . '/xrpc/com.atproto.sync.getBlob?' . http_build_query(['did' => $did, 'cid' => $cid], '', '&', PHP_QUERY_RFC3986);
			$filename = self::blobName($blobName, $mime);
			$attachment = ['type' => 'Document', 'mediaType' => $mime, 'url' => $url, 'name' => $filename];
			if ($size > 0) {
				$attachment['size'] = $size;
			}
			$attachments[] = $attachment;
		}

		return $attachments;
	}

	/**
	 * @param list<array{0: string, 1: string, 2: int, 3: string}> $blobs
	 */
	private static function collectGenericBlobs(mixed $value, array &$blobs, int $depth = 0, string $name = ''): void {
		if ($depth > 8 || count($blobs) >= 10 || !is_array($value)) {
			return;
		}
		foreach (['name', 'filename', 'fileName'] as $key) {
			if (is_string($value[$key] ?? null) && trim($value[$key]) !== '') {
				$name = trim($value[$key]);
				break;
			}
		}
		if (($value['$type'] ?? '') === 'blob' || isset($value['ref']) || isset($value['cid'])) {
			$ref = is_array($value['ref'] ?? null) ? $value['ref'] : [];
			$cid = (string)($ref['$link'] ?? $value['cid'] ?? '');
			$mime = strtolower(trim((string)($value['mimeType'] ?? '')));
			if (Cid::isValid($cid) && preg_match('~^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+-]+$~', $mime) === 1) {
				$blobs[] = [$cid, $mime, (int)($value['size'] ?? 0), $name];
			}
			return;
		}
		foreach ($value as $child) {
			self::collectGenericBlobs($child, $blobs, $depth + 1, $name);
			if (count($blobs) >= 10) {
				return;
			}
		}
	}

	private static function isSafePds(string $pds): bool {
		$parts = parse_url($pds);

		return is_array($parts) && strtolower($parts['scheme'] ?? '') === 'https'
			&& is_string($parts['host'] ?? null) && $parts['host'] !== ''
			&& !isset($parts['user']) && !isset($parts['pass'])
			&& !isset($parts['query']) && !isset($parts['fragment'])
			&& in_array($parts['path'] ?? '', ['', '/'], true)
			&& filter_var($parts['host'], FILTER_VALIDATE_IP) === false
			&& preg_match('/(?:^|\.)(?:localhost|local)$/i', $parts['host']) !== 1;
	}

	private static function blobName(string $candidate, string $mime): string {
		$name = basename(str_replace('\\', '/', $candidate));
		$name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '');
		if ($name !== '' && $name !== '.' && $name !== '..' && !self::isOpaqueBlobName($name)) {
			return mb_substr($name, 0, 180);
		}
		[$type, $subtype] = array_pad(explode('/', strtolower($mime), 2), 2, '');
		$subtype = preg_replace('/[^a-z0-9.+-]/', '', $subtype) ?? '';
		$known = [
			'application/pdf' => ['PDF document', 'pdf'],
			'application/zip' => ['ZIP archive', 'zip'],
			'application/x-zip-compressed' => ['ZIP archive', 'zip'],
			'application/gzip' => ['Gzip archive', 'gz'],
			'application/msword' => ['Word document', 'doc'],
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['Word document', 'docx'],
			'application/vnd.ms-excel' => ['Excel spreadsheet', 'xls'],
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['Excel spreadsheet', 'xlsx'],
			'application/vnd.ms-powerpoint' => ['PowerPoint presentation', 'ppt'],
			'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['PowerPoint presentation', 'pptx'],
			'text/plain' => ['Text document', 'txt'],
			'audio/mpeg' => ['MP3 audio', 'mp3'],
			'audio/mp4' => ['M4A audio', 'm4a'],
			'video/mp4' => ['MP4 video', 'mp4'],
			'video/webm' => ['WebM video', 'webm'],
			'image/jpeg' => ['JPEG image', 'jpg'],
			'image/jpg' => ['JPEG image', 'jpg'],
			'image/png' => ['PNG image', 'png'],
			'image/webp' => ['WebP image', 'webp'],
			'image/gif' => ['GIF image', 'gif'],
			'image/avif' => ['AVIF image', 'avif'],
			'image/heic' => ['HEIC image', 'heic'],
			'image/heif' => ['HEIF image', 'heif'],
		];
		[$label, $extension] = $known[$mime] ?? self::genericBlobLabel($type, $subtype);

		return $label . ($extension !== '' ? '.' . $extension : '');
	}

	/** A blob key can identify its bytes without being a reader-facing filename. */
	private static function isOpaqueBlobName(string $name): bool {
		$stem = preg_replace('/\.[^.]+$/', '', $name) ?? $name;

		return preg_match('/^\d+$/', $stem) === 1
			|| preg_match('/^[0-9a-f]{8}-[0-9a-f-]{27,}$/i', $stem) === 1
			|| preg_match('/^baf[a-z2-7]{20,}$/i', $stem) === 1
			|| preg_match('/^[a-z0-9_-]{40,}$/i', $stem) === 1;
	}

	/** @return array{0: string, 1: string} */
	private static function genericBlobLabel(string $type, string $subtype): array {
		$extension = preg_match('/^[a-z0-9]{1,8}$/', $subtype) === 1 ? $subtype : '';
		$label = match ($type) {
			'image' => ($extension !== '' ? strtoupper($extension) : 'Image') . ' image',
			'audio' => ($extension !== '' ? strtoupper($extension) : 'Audio') . ' audio',
			'video' => ($extension !== '' ? strtoupper($extension) : 'Video') . ' video',
			'text' => 'Text file',
			default => ($extension !== '' ? strtoupper($extension) : 'File') . ' file',
		};

		return [$label, $extension];
	}

	/** Whether an AppView embed view has enough data to be useful on this client. */
	private static function hasUsableEmbedView(array $embed, string $authorDid): bool {
		return match ((string)($embed['$type'] ?? '')) {
			self::IMAGES => self::imagesOf(is_array($embed['images'] ?? null) ? $embed['images'] : [], 'thumb') !== [],
			self::GALLERY => self::imagesOf(is_array($embed['items'] ?? null) ? $embed['items'] : [], 'thumbnail') !== [],
			self::VIDEO => self::videoOf($embed, $authorDid) !== null,
			self::EXTERNAL => self::cardOf($embed, $authorDid) !== null,
			self::RECORD => is_array($embed['record'] ?? null) && $embed['record'] !== [],
			self::RECORD_WITH_MEDIA => is_array($embed['record'] ?? null)
				&& $embed['record'] !== []
				&& self::hasUsableEmbedView(is_array($embed['media'] ?? null) ? $embed['media'] : [], $authorDid),
			default => false,
		};
	}

	/** @return list<array> */
	private static function imagesOf(array $images, string $thumbnailKey): array {
		$attachments = [];
		foreach ($images as $image) {
			if (!is_array($image)) {
				continue;
			}
			$url = (string)($image['fullsize'] ?? '');
			if (!preg_match('#^https://#i', $url)) {
				$url = (string)($image[$thumbnailKey] ?? '');
			}
			if (!preg_match('#^https://#i', $url)) {
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

		return $attachments;
	}

	/**
	 * Images in the original post record carry a blob reference instead of the
	 * CDN URLs the AppView adds to its embed view. Build the public image URL
	 * only for validated image blobs; never turn an arbitrary CID or MIME type
	 * from a record into a fetch target.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function recordImagesOf(array $images, string $authorDid, string $pds = ''): array {
		$attachments = [];
		foreach ($images as $image) {
			if (!is_array($image)) {
				continue;
			}
			$blob = is_array($image['image'] ?? null) ? $image['image'] : [];
			$mimeType = strtolower((string)($blob['mimeType'] ?? ''));
			$url = self::blobImageUrl($blob, $authorDid);
			if ($url === '') {
				$ref = is_array($blob['ref'] ?? null) ? $blob['ref'] : [];
				$cid = (string)($ref['$link'] ?? $blob['cid'] ?? '');
				if (!str_starts_with($mimeType, 'image/') || !Cid::isValid($cid) || !self::isSafePds($pds)) {
					continue;
				}
				// Bluesky's image CDN only transforms a small set of formats.
				// For other valid image blobs, keep the post's alt text and ratio
				// and stream the original from the author's verified PDS.
				$url = rtrim($pds, '/') . '/xrpc/com.atproto.sync.getBlob?' . http_build_query(['did' => $authorDid, 'cid' => $cid], '', '&', PHP_QUERY_RFC3986);
			}
			$mimeType = $mimeType === 'image/jpg' ? 'image/jpeg' : $mimeType;
			$previewable = self::cdnImageFormat($mimeType) !== '' || $mimeType === 'image/avif';
			$name = (string)($image['alt'] ?? '');
			$type = 'Image';
			if (!$previewable && str_starts_with($url, rtrim($pds, '/') . '/xrpc/com.atproto.sync.getBlob?')) {
				// Keep formats browsers do not reliably render as a file download;
				// the PDS proxy marks these as attachments and supplies their name.
				$type = 'Document';
				$name = self::blobName($name, $mimeType);
				if (preg_match('/\.[a-z0-9]{1,8}$/i', $name) !== 1) {
					[, $extension] = self::genericBlobLabel('image', (string)substr($mimeType, strlen('image/')));
					if ($extension !== '') {
						$name .= '.' . $extension;
					}
				}
			}
			$attachment = [
				'type' => $type,
				'mediaType' => $mimeType === 'image/jpg' ? 'image/jpeg' : $mimeType,
				'url' => $url,
				'name' => $name,
			];
			$ratio = $image['aspectRatio'] ?? null;
			if (is_array($ratio) && (int)($ratio['width'] ?? 0) > 0 && (int)($ratio['height'] ?? 0) > 0) {
				$attachment['width'] = (int)$ratio['width'];
				$attachment['height'] = (int)$ratio['height'];
			}
			$attachments[] = $attachment;
		}

		return $attachments;
	}

	/** The public CDN URL of a validated raw image blob, or '' when it is not one. */
	private static function blobImageUrl(mixed $blob, string $authorDid): string {
		if (!is_array($blob) || !Syntax::isDid($authorDid)) {
			return '';
		}
		$ref = is_array($blob['ref'] ?? null) ? $blob['ref'] : [];
		$cid = (string)($ref['$link'] ?? $blob['cid'] ?? '');
		$mimeType = strtolower((string)($blob['mimeType'] ?? ''));
		$format = self::cdnImageFormat($mimeType);
		if ($format === '' || !Cid::isValid($cid)) {
			return '';
		}

		return 'https://cdn.bsky.app/img/feed_fullsize/plain/' . $authorDid . '/' . $cid . '@' . $format;
	}

	/** The image formats the public Bluesky CDN exposes as feed images. */
	private static function cdnImageFormat(string $mimeType): string {
		return match ($mimeType) {
			'image/jpeg', 'image/jpg' => 'jpeg',
			'image/png' => 'png',
			'image/webp' => 'webp',
			'image/gif' => 'gif',
			default => '',
		};
	}

	/**
	 * Whether the post quotes one whose author detached the quote: the
	 * AppView shows the quoted post as `viewDetached`, alone or beside
	 * pictures.
	 */
	private static function quoteDetached(array $embed): bool {
		$record = $embed['record'] ?? null;
		if (is_array($record) && is_array($record['record'] ?? null)) {
			$record = $record['record'];
		}

		return is_array($record) && ($record['$type'] ?? '') === self::VIEW_DETACHED;
	}

	/**
	 * The card for a feed, a list or a starter pack a post embeds — alone or
	 * beside pictures — from what the AppView says of it, so its page on
	 * bsky.app need not be read.
	 *
	 * @return array{url: string, title: string, description: string, image: string, provider: string}|null
	 */
	public static function cardOf(array $embed, string $authorDid = ''): ?array {
		if (in_array(($embed['$type'] ?? ''), [self::EXTERNAL, self::RECORD_EXTERNAL], true)) {
			$external = is_array($embed['external'] ?? null) ? $embed['external'] : [];
			$url = (string)($external['uri'] ?? '');
			if (!preg_match('#^https?://#i', $url)) {
				return null;
			}
			$thumbnail = $external['thumb'] ?? '';
			$image = is_string($thumbnail)
				? (preg_match('#^https://#i', $thumbnail) ? $thumbnail : '')
				: self::blobImageUrl($thumbnail, $authorDid);

			return [
				'url' => $url,
				'title' => trim((string)($external['title'] ?? '')) ?: $url,
				'description' => (string)($external['description'] ?? ''),
				'image' => $image,
				'provider' => parse_url($url, PHP_URL_HOST) ?: '',
			];
		}
		if (($embed['$type'] ?? '') === self::RECORD_WITH_MEDIA) {
			$media = is_array($embed['media'] ?? null) ? $embed['media'] : [];
			if (in_array(($media['$type'] ?? ''), [self::EXTERNAL, self::RECORD_EXTERNAL], true)) {
				return self::cardOf($media, $authorDid);
			}
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
				'Feed' . $by,
			],
			self::LIST_VIEW => [
				'https://bsky.app/profile/' . $owner . '/lists/' . $rkey,
				(string)($view['name'] ?? ''), (string)($view['description'] ?? ''), (string)($view['avatar'] ?? ''),
				'List' . $by,
			],
			self::STARTER_PACK_VIEW => [
				'https://bsky.app/starter-pack/' . $owner . '/' . $rkey,
				(string)($view['record']['name'] ?? ''), (string)($view['record']['description'] ?? ''),
				(string)($creator['avatar'] ?? ''),
				'Starter pack' . $by,
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
	public static function videoOf(array $embed, string $authorDid = ''): ?array {
		if (($embed['$type'] ?? '') === self::RECORD_WITH_MEDIA) {
			$embed = is_array($embed['media'] ?? null) ? $embed['media'] : [];
		}
		$type = (string)($embed['$type'] ?? '');
		if ($type === self::RECORD_VIDEO) {
			$blob = is_array($embed['video'] ?? null) ? $embed['video'] : [];
			$mimeType = strtolower((string)($blob['mimeType'] ?? ''));
			$ref = is_array($blob['ref'] ?? null) ? $blob['ref'] : [];
			$cid = (string)($ref['$link'] ?? $blob['cid'] ?? '');
			if (!Syntax::isDid($authorDid) || !Cid::isValid($cid)
				|| !in_array($mimeType, ['video/mp4', 'video/mpeg', 'video/webm', 'video/quicktime', 'image/gif'], true)) {
				return null;
			}
			$base = 'https://video.bsky.app/watch/' . rawurlencode($authorDid) . '/' . $cid;
			$ratio = is_array($embed['aspectRatio'] ?? null) ? $embed['aspectRatio'] : [];

			return [
				'playlist' => $base . '/playlist.m3u8',
				'thumbnail' => $base . '/thumbnail.jpg',
				'width' => max(0, (int)($ratio['width'] ?? 0)),
				'height' => max(0, (int)($ratio['height'] ?? 0)),
			];
		}
		if ($type !== self::VIDEO) {
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
	 * Mentions and hashtags as the tags a Fediverse post carries them in:
	 * the hashtags in the text, and the ones the record lists beside it
	 * (`tags`), which Bluesky's hashtag search finds the post by too. A
	 * mention is named by its handle — the `@handle` the text shows at the
	 * facet, since the record carries only the DID — and the DID is in the
	 * target alone.
	 *
	 * @return list<array{type: string, href: string, name: string}>
	 */
	private function tags(string $text, mixed $facets, string $parentUri = '', mixed $outline = []): array {
		$tags = [];
		// a reply to a local post addresses its author, which is what makes
		// the notification here; Bluesky names nobody in the reply itself
		$parent = Syntax::parseAtUri($parentUri);
		if ($parent !== null) {
			$author = $this->local->actorId($parent['authority']);
			if ($author !== '') {
				$handle = $this->local->handleOf($parent['authority']);
				$tags[] = ['type' => 'Mention', 'href' => $author, 'name' => '@' . ($handle !== '' ? $handle : $parent['authority'])];
			}
		}
		foreach (is_array($facets) ? $facets : [] as $facet) {
			foreach (is_array($facet['features'] ?? null) ? $facet['features'] : [] as $feature) {
				$type = (string)($feature['$type'] ?? '');
				if ($type === 'app.bsky.richtext.facet#mention' && is_string($feature['did'] ?? null) && $feature['did'] !== '') {
					$shown = self::mentionText($text, $facet['index'] ?? null);
					$tags[] = ['type' => 'Mention', 'href' => $this->local->mentionTarget($feature['did']), 'name' => $shown !== '' ? $shown : '@' . $feature['did']];
				} elseif ($type === 'app.bsky.richtext.facet#tag' && is_string($feature['tag'] ?? null) && $feature['tag'] !== '') {
					$tags[] = ['type' => 'Hashtag', 'href' => BlueskyIds::hashtagUrl($feature['tag']), 'name' => '#' . $feature['tag']];
				}
			}
		}
		$named = array_map(static fn (array $tag): string => mb_strtolower($tag['name']), $tags);
		foreach (is_array($outline) ? array_slice($outline, 0, 8) : [] as $tag) {
			$tag = is_string($tag) ? ltrim(trim($tag), '#') : '';
			if ($tag !== '' && !in_array(mb_strtolower('#' . $tag), $named, true)) {
				$named[] = mb_strtolower('#' . $tag);
				$tags[] = ['type' => 'Hashtag', 'href' => BlueskyIds::hashtagUrl($tag), 'name' => '#' . $tag];
			}
		}

		return $tags;
	}

	/**
	 * What the text shows at a mention facet: `@handle`, or '' when the
	 * range is not one (ill-formed, or the text there is no handle).
	 */
	private static function mentionText(string $text, mixed $index): string {
		$start = (int)($index['byteStart'] ?? -1);
		$end = (int)($index['byteEnd'] ?? -1);
		if (!is_array($index) || $start < 0 || $end <= $start || $end > strlen($text)) {
			return '';
		}
		$shown = substr($text, $start, $end - $start);

		return preg_match('/^@[^\s@]+$/u', $shown) === 1 ? $shown : '';
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
