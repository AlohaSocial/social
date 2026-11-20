<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Interfaces\Object\DocumentInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\AttachmentMeta;
use OCA\Social\Model\Client\AttachmentMetaDim;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Security\HtmlSanitizer;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What a PeerTube `Video` says, in the terms the rest of this app speaks.
 *
 * A `Video` is modelled as a `Note` with its wire type in `subtype` (see
 * `AP::NOTE_LIKE_TYPES`); what makes it a video (the file, the thumbnail, the
 * duration, the publishing actor) lives elsewhere in the object and is read
 * out here. Nothing here fetches anything.
 */
class PeerTubeService {
	use TArrayTools;

	/** The wire type this class is about. */
	public const TYPE = 'Video';

	public function __construct(
		private DocumentInterface $documentInterface,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The one link this app will play, in the order it prefers them. `video/mp4`
	 * plays from a bare `<video src>` everywhere; HLS-only instances publish
	 * `application/x-mpegURL`, which only Safari plays natively, taken as a last
	 * resort.
	 */
	private const PLAYABLE = ['video/mp4', 'video/webm', 'video/ogg', 'application/x-mpegURL'];

	/** Sources above this are ignored: nobody is streaming 4K through a proxy. */
	private const MAX_HEIGHT = 1080;

	/** How far into nested `tag` lists to look for a file. */
	private const MAX_LINK_DEPTH = 2;

	/** How long a derived title may be; PeerTube's own ceiling is 120. */
	private const MAX_TITLE = 120;

	/**
	 * The file to play, as a `Document` that is never mirrored. The row is what
	 * the streaming route checks a request against, so it cannot proxy any URL.
	 *
	 * `localCopy` is the streamed sentinel, which keeps the caching cron
	 * (`getNotCachedDocuments()`) off it; `resizedCopy` is left for the
	 * thumbnail, which `VideoAttachmentService` mirrors.
	 *
	 * @param array $data the wire `Video`
	 *
	 * @return Document|null null when it publishes nothing this app can play
	 */
	public function source(array $data, ?ACore $parent = null): ?Document {
		$best = $this->bestSource($this->links($data));
		if ($best === null) {
			return null;
		}

		$document = new Document($parent);
		$document->setId($best['href'])
			->setUrl($best['href']);
		$document->setMediaType($best['mediaType']);
		$document->setMimeType($best['mediaType']);
		$document->setLocalCopy(Document::COPY_STREAMED);
		$document->setLocalCopySize($best['width'], $best['height']);
		$document->setDescription($this->title($data));
		$document->setPublic(true);

		return $document;
	}

	/**
	 * The still to show before anything is played: PeerTube's `icon`, one
	 * `Image` or a list of them, of which the largest is taken.
	 *
	 * @param array $data the wire `Video`
	 *
	 * @return array{url: string, mediaType: string}|null null when it published none
	 */
	public function thumbnail(array $data): ?array {
		$best = null;
		$bestArea = -1;

		foreach ($this->asList($data['icon'] ?? null) as $icon) {
			$url = $this->urlOf($icon);
			if ($url === '' || !$this->isHttp($url)) {
				continue;
			}

			// PeerTube states it; an instance that does not is taken at its
			// word that `icon` is a picture, which is all the field can be
			$mediaType = is_array($icon) ? self::text($icon['mediaType'] ?? 'image/jpeg') : 'image/jpeg';
			if (!str_starts_with($mediaType, 'image/')) {
				continue;
			}

			$area = is_array($icon) ? (int)($icon['width'] ?? 0) * (int)($icon['height'] ?? 0) : 0;
			if ($area > $bestArea) {
				$bestArea = $area;
				$best = ['url' => $url, 'mediaType' => $mediaType];
			}
		}

		return $best;
	}

	/**
	 * The page a reader is sent to when they want the video itself: the
	 * `text/html` link, which is the short `/w/xxx` form rather than the object
	 * id. '' when the object carries no such link.
	 */
	public function watchUrl(array $data): string {
		foreach ($this->links($data) as $link) {
			if (($link['mediaType'] ?? '') === 'text/html' && $this->isHttp((string)$link['href'])) {
				return (string)$link['href'];
			}
		}

		return '';
	}

	/**
	 * Whether replies to this video need approval: FEP-5624, PeerTube's
	 * `commentsPolicy` (`1` enabled, `2` disabled, `3` requires approval), or a
	 * `canReply` without the public collection. Both spellings are read, since
	 * instances run both. Lets a reply written here say it awaits approval.
	 *
	 * @param array $data the wire `Video`
	 */
	public function repliesNeedApproval(array $data): bool {
		$policy = $data['commentsPolicy'] ?? null;
		if (is_array($policy)) {
			// newer PeerTube sends `{id: 3, label: "Requires approval"}`
			$policy = $policy['id'] ?? null;
		}
		if (is_numeric($policy)) {
			return (int)$policy === 3;
		}

		if (!array_key_exists('canReply', $data)) {
			return false;
		}

		// an audience that is stated and is not everybody: the ones left out
		// are the ones who have to be approved, and from here that is us
		foreach ($this->asList($data['canReply']) as $who) {
			$id = is_array($who) ? self::text($who['id'] ?? '') : self::text($who);
			if ($id === ACore::CONTEXT_PUBLIC) {
				return false;
			}
		}

		return true;
	}

	/**
	 * How long it runs, in whole seconds, from the `PT113S` xsd:duration form
	 * PeerTube writes; only the time half is read.
	 *
	 * @return int 0 when it says nothing usable
	 */
	public function duration(array $data): int {
		$duration = trim(self::text($data['duration'] ?? ''));
		if ($duration === '') {
			return 0;
		}

		if (preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?$/', $duration, $matches) !== 1) {
			return 0;
		}

		return (int)($matches[1] ?? 0) * 3600
			+ (int)($matches[2] ?? 0) * 60
			+ (int)round((float)($matches[3] ?? 0));
	}

	/**
	 * Everything else a `Video` says that a `Note` has nowhere to put: category,
	 * licence, language, chapters, captions, support line and counters. Kept as
	 * one block in the post's details, since a watch page wants all of it or
	 * none.
	 *
	 * @param array $data the wire `Video`
	 *
	 * @return array<string, mixed> empty when the object says none of it
	 */
	public function videoMeta(array $data): array {
		$meta = array_filter([
			// the title, for a watch page's heading; a `Note` has none
			'title' => mb_substr($this->title($data), 0, self::MAX_TITLE),
			// PeerTube sends these as `{id, label}`; the label is what a reader
			// wants and the id means nothing off its own instance
			'category' => $this->labelOf($data['category'] ?? null),
			'licence' => $this->labelOf($data['licence'] ?? null),
			'language' => $this->labelOf($data['language'] ?? null),
			'support' => trim(self::text($data['support'] ?? '')),
			'originally_published_at' => trim(self::text($data['originallyPublishedAt'] ?? '')),
		], static fn (string $value): bool => $value !== '');

		$duration = $this->duration($data);
		if ($duration > 0) {
			$meta['duration'] = $duration;
		}

		foreach (['views' => 'views', 'likes' => 'likes', 'dislikes' => 'dislikes'] as $key => $field) {
			$count = $data[$field] ?? null;
			if (is_numeric($count)) {
				$meta[$key] = max(0, (int)$count);
			}
		}

		// `downloadEnabled` absent means yes, PeerTube's default, so only a stated
		// refusal is recorded
		if (array_key_exists('downloadEnabled', $data)) {
			$meta['download'] = (bool)$data['downloadEnabled'];
		}

		if (!empty($data['isLiveBroadcast'])) {
			$meta['live'] = true;
		}

		$chapters = $this->chapters($data);
		if ($chapters !== []) {
			$meta['chapters'] = $chapters;
		}

		$captions = $this->captions($data);
		if ($captions !== []) {
			$meta['captions'] = $captions;
		}

		return $meta;
	}

	/**
	 * A field PeerTube sends as `{id, label}`, as the label — or as itself
	 * where an instance sends a bare string.
	 */
	private function labelOf(mixed $value): string {
		if (is_string($value)) {
			return trim($value);
		}

		if (is_array($value)) {
			return trim(self::text($value['label'] ?? $value['identifier'] ?? ''));
		}

		return '';
	}

	/**
	 * The chapters of a video, from FEP-6f7d's `hasParts`: a name and an offset
	 * in seconds.
	 *
	 * @return array<int, array{title: string, start: int}>
	 */
	private function chapters(array $data): array {
		$parts = $data['hasParts'] ?? $data['hasPart'] ?? null;
		if (is_array($parts) && isset($parts['orderedItems'])) {
			$parts = $parts['orderedItems'];
		}

		$chapters = [];
		foreach ($this->asList($parts) as $part) {
			if (!is_array($part)) {
				continue;
			}

			$title = trim(self::text($part['name'] ?? ''));
			$start = $part['startOffset'] ?? null;
			if ($title === '' || !is_numeric($start)) {
				continue;
			}

			$chapters[] = ['title' => mb_substr($title, 0, 200), 'start' => max(0, (int)$start)];
		}

		usort($chapters, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

		return array_slice($chapters, 0, 200);
	}

	/**
	 * The subtitle tracks a video publishes: the `text/vtt` links in `url`, the
	 * only place they can be fetched from.
	 *
	 * @return array<int, array{language: string, url: string}>
	 */
	private function captions(array $data): array {
		$languages = [];
		foreach ($this->asList($data['subtitleLanguage'] ?? null) as $entry) {
			$id = is_array($entry) ? self::text($entry['identifier'] ?? $entry['id'] ?? '') : self::text($entry);
			$url = is_array($entry) ? self::text($entry['url'] ?? '') : '';
			if ($id !== '' && $url !== '' && $this->isHttp($url)) {
				$languages[] = ['language' => mb_substr($id, 0, 15), 'url' => $url];
			}
		}

		foreach ($this->links($data) as $link) {
			if ($link['mediaType'] !== 'text/vtt') {
				continue;
			}

			$languages[] = [
				'language' => mb_substr($link['language'], 0, 15),
				'url' => $link['href'],
			];
		}

		$seen = [];
		$captions = [];
		foreach ($languages as $caption) {
			if ($caption['url'] === '' || isset($seen[$caption['url']])) {
				continue;
			}

			$seen[$caption['url']] = true;
			$captions[] = $caption;
		}

		return array_slice($captions, 0, 40);
	}

	/**
	 * Who published it. PeerTube's `attributedTo` lists the channel (a `Group`)
	 * and the account behind it (a `Person`); the channel wins, as it signs the
	 * `Create`, is what readers follow, and lists the video on PeerTube.
	 *
	 * @return string '' when the list holds no actor with an id
	 */
	public function attributedTo(array $data): string {
		$person = '';

		foreach ($this->asList($data['attributedTo'] ?? null) as $actor) {
			if (is_string($actor)) {
				return $actor;
			}

			$id = self::text($actor['id'] ?? '');
			if ($id === '' || !$this->isHttp($id)) {
				continue;
			}

			if (($actor['type'] ?? '') === 'Group') {
				return $id;
			}

			if ($person === '') {
				$person = $id;
			}
		}

		return $person;
	}

	/**
	 * The video as a post: its title, linked to the page it plays on, and the
	 * description under it. The title is not copied into the note's `name`,
	 * which means a poll vote's option here (`PollService::handleIncomingVote`).
	 *
	 * The description is sanitised HTML, unless the object declares
	 * `text/markdown`, as PeerTube does: then it is escaped first and given a
	 * little markdown, so a remote server can never get markup past the
	 * sanitiser.
	 */
	public function content(array $data): string {
		$content = '';

		if ($this->title($data) !== '') {
			$content = '<p>' . $this->linkedTitle($data) . '</p>';
		}

		$description = trim(self::text($data['content'] ?? ''));
		if ($description === '') {
			return $content;
		}

		if (!in_array(self::text($data['mediaType'] ?? ''), ['text/markdown', 'text/plain'], true)) {
			return $content . HtmlSanitizer::sanitize($description, self::text($data['id'] ?? ''));
		}

		foreach (preg_split('/\R{2,}/', $description) as $paragraph) {
			$paragraph = trim($paragraph);
			if ($paragraph === '') {
				continue;
			}

			$content .= '<p>' . nl2br($this->markdown($paragraph), false) . '</p>';
		}

		return $content;
	}

	/**
	 * The little markdown a video description uses, as HTML, rather than raw
	 * asterisks and brackets.
	 *
	 * The text is escaped first, so every tag is one this method wrote. Links
	 * are made only of `http(s)` targets, with the escaped href. Bold before
	 * italic, since `**` would read as two `*`. Block constructs are not handled.
	 */
	private function markdown(string $text): string {
		$text = $this->escape($text);

		// [label](https://…) -- the label may hold anything but a bracket
		$text = (string)preg_replace_callback(
			'/\[([^\]]*)\]\((https?:\/\/[^\s)]+)\)/',
			static fn (array $m): string
				=> '<a href="' . $m[2] . '" rel="nofollow noopener noreferrer" target="_blank">'
					. ($m[1] === '' ? $m[2] : $m[1]) . '</a>',
			$text
		);

		// a bare url, but never one already inside the href or the label of a
		// link this method just wrote
		$text = (string)preg_replace(
			'/(?<![">])\b(https?:\/\/[^\s<]+[^\s<.,;:!?)\]])/',
			'<a href="$1" rel="nofollow noopener noreferrer" target="_blank">$1</a>',
			$text
		);

		$text = (string)preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '<strong>$1</strong>', $text);
		$text = (string)preg_replace('/(?<!\*)\*(?=\S)([^*]+?)(?<=\S)\*(?!\*)/s', '<em>$1</em>', $text);

		return $text;
	}

	/** The title, as a link to the watch page when there is one. */
	private function linkedTitle(array $data): string {
		$title = $this->escape($this->title($data));
		$watch = $this->watchUrl($data);

		if ($watch === '') {
			return $title;
		}

		return '<a href="' . $this->escape($watch) . '">' . $title . '</a>';
	}

	private function title(array $data): string {
		return trim(self::text($data['name'] ?? ''));
	}

	/**
	 * The playable link with the most pixels this app is willing to proxy, and
	 * `application/x-mpegURL` only when there is no file at all, since Chrome and
	 * Firefox cannot open it.
	 *
	 * @param array<int, array{href: string, mediaType: string, width: int, height: int, language: string}> $links
	 */
	private function bestSource(array $links): ?array {
		$best = null;
		$fallback = null;

		foreach ($links as $link) {
			if (!in_array($link['mediaType'], self::PLAYABLE, true)) {
				continue;
			}

			if ($link['mediaType'] === 'application/x-mpegURL') {
				$fallback ??= $link;
				continue;
			}

			if ($link['height'] > self::MAX_HEIGHT) {
				continue;
			}

			if ($best === null || $link['height'] > $best['height']) {
				$best = $link;
			}
		}

		return $best ?? $fallback;
	}

	/**
	 * A remote field as text: what is not a scalar — an object or a list
	 * where a string belongs — is no text at all, rather than "Array" and a
	 * warning.
	 */
	private static function text(mixed $value): string {
		return is_scalar($value) ? (string)$value : '';
	}

	/**
	 * `url` normalised to a flat list of http(s) links (no `magnet:`). On a
	 * PeerTube `Video` it mixes the watch page, files, the HLS playlist and
	 * torrents, and an HLS instance hangs each resolution's file off the
	 * playlist link's `tag`, which is walked too.
	 *
	 * @param int $depth guards against a `tag` that refers back to its own link
	 *
	 * @return array<int, array{href: string, mediaType: string, width: int, height: int, language: string}>
	 */
	private function links(array $data, string $key = 'url', int $depth = 0): array {
		if ($depth > self::MAX_LINK_DEPTH) {
			return [];
		}

		$links = [];

		foreach ($this->asList($data[$key] ?? null) as $link) {
			if (is_string($link)) {
				$link = ['href' => $link, 'mediaType' => 'text/html'];
			}

			if (!is_array($link)) {
				continue;
			}

			if (isset($link['tag'])) {
				$links = array_merge($links, $this->links($link, 'tag', $depth + 1));
			}

			$href = self::text($link['href'] ?? $link['url'] ?? '');
			if ($href === '' || !$this->isHttp($href)) {
				continue;
			}

			// `rel: ["metadata", …]` marks a description *of* the file rather
			// than the file: it is json, and playing it plays nothing
			$rel = $link['rel'] ?? [];
			if (in_array('metadata', is_array($rel) ? $rel : [$rel], true)) {
				continue;
			}

			$links[] = [
				'href' => $href,
				'mediaType' => self::text($link['mediaType'] ?? ''),
				'width' => (int)($link['width'] ?? 0),
				'height' => (int)($link['height'] ?? 0),
				// a caption link says which language it is in, and nothing
				// else in the list has one
				'language' => is_array($link['language'] ?? null)
					? self::text($link['language']['identifier'] ?? '')
					: self::text($link['language'] ?? ''),
			];
		}

		return $links;
	}

	/**
	 * A field that may be one object, a list of them, or absent, as a list.
	 *
	 * @return array<int, mixed>
	 */
	private function asList(mixed $value): array {
		if ($value === null || $value === '') {
			return [];
		}

		if (is_string($value)) {
			return [$value];
		}

		if (!is_array($value)) {
			return [];
		}

		// a single object is an associative array; a list is not
		return array_is_list($value) ? $value : [$value];
	}

	/** The `url` of an `Image`, which is itself sometimes a `Link`. */
	private function urlOf(mixed $icon): string {
		if (is_string($icon)) {
			return $icon;
		}

		if (!is_array($icon)) {
			return '';
		}

		$url = $icon['url'] ?? '';
		if (is_string($url)) {
			return $url;
		}

		foreach ($this->asList($url) as $entry) {
			if (is_string($entry)) {
				return $entry;
			}

			$href = self::text($entry['href'] ?? $entry['url'] ?? '');
			if ($href !== '') {
				return $href;
			}
		}

		return '';
	}

	private function isHttp(string $url): bool {
		return str_starts_with($url, 'https://') || str_starts_with($url, 'http://');
	}

	private function escape(string $text): string {
		return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	// ---- the other direction: what this instance publishes ----

	/**
	 * The one video attachment of a post, when the post is a video: exactly one
	 * and nothing else, or it stays a `Note`. Static and pure, as everything
	 * below, since `Note::jsonSerialize()` calls it, and kept here so writing
	 * and reading cannot drift apart.
	 *
	 * @param MediaAttachment[] $attachments
	 */
	public static function soleVideo(array $attachments): ?MediaAttachment {
		if (count($attachments) !== 1) {
			return null;
		}

		$only = reset($attachments);

		return ($only instanceof MediaAttachment && $only->getType() === 'video') ? $only : null;
	}

	/**
	 * A local post, reshaped as the `Video` object PeerTube publishes. On top of
	 * the note it adds `name` (see `titleFor()`), `duration` (`PT113S`), `icon`
	 * (the poster frame) and `url` as a list with the page and the file, which is
	 * what PeerTube plays. `attachment` stays, for Mastodon-family servers.
	 *
	 * @param array $note the note as it would otherwise have been published
	 * @param array<array{height: int, size: int, bandwidth: int, href: string}> $rungs
	 *                                                                                  the sizes this video also exists at, where it has been laddered
	 */
	public static function asVideo(
		array $note,
		MediaAttachment $video,
		string $watchUrl,
		array $attributedTo,
		int $views = 0,
		array $stated = [],
		array $rungs = [],
	): ?array {
		$meta = $video->getMeta();
		$duration = (int)round((float)($meta?->getDuration() ?? 0));
		$icon = self::iconFor($video);

		// PeerTube requires `duration`, `icon` and an `attributedTo` with a
		// `Group`; a post lacking any of them (no ffmpeg, no channel) goes out as
		// the `Note`, which Mastodon and Pixelfed read.
		if ($duration <= 0 || $icon === null || $attributedTo === []) {
			return null;
		}

		$note['type'] = self::TYPE;
		// what the author called it, where they did; a first-line title is only a
		// fallback
		$note['name'] = ($stated['title'] ?? '') !== ''
			? mb_substr((string)$stated['title'], 0, self::MAX_TITLE)
			: self::titleFor($note, $video);

		foreach (['category', 'licence'] as $field) {
			if (($stated[$field] ?? '') !== '') {
				$note[$field] = (string)$stated[$field];
			}
		}
		$note['attributedTo'] = $attributedTo;
		// PeerTube refuses a `Video` without a valid `uuid`; derived from the
		// post's id, so a redelivery is the same video
		$note['uuid'] = self::uuidFor((string)($note['id'] ?? ''));
		$note['views'] = max(0, $views);
		// mandatory, and this app writes one only on a post that has been
		// edited; an unedited video is as up to date as it is published
		if (($note['updated'] ?? '') === '') {
			$note['updated'] = (string)($note['published'] ?? gmdate('c'));
		}
		// PeerTube's defaults, stated explicitly
		$note['state'] = 1;
		$note['waitTranscoding'] = false;
		$note['downloadEnabled'] = true;
		$note['sensitive'] = (bool)($note['sensitive'] ?? false);
		$note['isLiveBroadcast'] = false;
		// this app has replies on every post and no way to turn them off
		$note['commentsPolicy'] = 1;
		$note['icon'] = [$icon];
		$note['duration'] = 'PT' . $duration . 'S';

		// this app's posts are HTML, said explicitly, since PeerTube's own are
		// markdown
		if (($note['content'] ?? '') !== '') {
			$note['mediaType'] = 'text/html';
		}

		$note['url'] = self::urlsFor($video, $watchUrl, $rungs);

		// PeerTube states it on every video and its clients read it; this app
		// has replies on every post and no way to turn them off
		$note['commentsEnabled'] = true;

		// PeerTube fetches both counts for every video, and a missing one crashes
		// it
		$id = (string)($note['id'] ?? '');
		$note['likes'] = $id . Stream::LIKES_PATH;
		$note['dislikes'] = $id . Stream::DISLIKES_PATH;

		return $note;
	}

	/**
	 * What to call it: the post's first line, where somebody writing about a
	 * video puts its name, else the alt text. `name` on a Note is a poll vote's
	 * option, so it is never read.
	 */
	private static function titleFor(array $note, MediaAttachment $video): string {
		$content = trim(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", (string)($note['content'] ?? ''))));
		$firstLine = trim((string)strtok($content, "\n"));

		foreach ([$firstLine, trim($video->getDescription())] as $candidate) {
			$candidate = html_entity_decode($candidate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
			if ($candidate !== '') {
				return mb_substr($candidate, 0, self::MAX_TITLE);
			}
		}

		// something has to be there: `name` is how a video is listed, and a
		// blank row is worse than a dull one
		return 'Video';
	}

	/**
	 * A UUID derived from the post's id, since PeerTube refuses a `Video` without
	 * one and a redelivered video must be the same video. A name hash, but marked
	 * version 4, the only version PeerTube's `isUUIDValid()` accepts.
	 *
	 * Public because `PeerTubeApiService` must give the same video the same uuid.
	 */
	public static function uuidFor(string $id): string {
		$hash = sha1('social:video:' . $id);

		return sprintf(
			'%s-%s-4%s-%x%s-%s',
			substr($hash, 0, 8),
			substr($hash, 8, 4),
			substr($hash, 13, 3),
			// the variant: one of 8, 9, a or b
			(hexdec($hash[16]) & 0x3) | 0x8,
			substr($hash, 17, 3),
			substr($hash, 20, 12)
		);
	}

	/** The poster frame, as the `Image` PeerTube puts in `icon`. */
	private static function iconFor(MediaAttachment $video): ?array {
		$preview = $video->getPreviewUrl();
		if ($preview === '' || $preview === (string)$video->getUrl()) {
			// no poster (no ffmpeg at upload), so `preview_url` is the video itself
			return null;
		}

		$icon = ['type' => 'Image', 'mediaType' => 'image/jpeg', 'url' => $preview];

		$small = $video->getMeta()?->getSmall();
		if ($small?->getWidth() > 0 && $small?->getHeight() > 0) {
			$icon['width'] = $small->getWidth();
			$icon['height'] = $small->getHeight();
		}

		return $icon;
	}

	/**
	 * `url` as PeerTube writes it: the watch page, then the file as uploaded, and,
	 * for a laddered video, a streaming playlist with a `Link` tag per rung, as
	 * PeerTube publishes its own.
	 *
	 * @param array<array{height: int, size: int, bandwidth: int, href: string}> $rungs
	 */
	private static function urlsFor(MediaAttachment $video, string $watchUrl, array $rungs = []): array {
		$urls = [];

		if ($watchUrl !== '') {
			$urls[] = ['type' => 'Link', 'mediaType' => 'text/html', 'href' => $watchUrl];
		}

		$file = (string)$video->getUrl();
		if ($file === '') {
			return $urls;
		}

		$link = [
			'type' => 'Link',
			// the full type: a peer that does not sniff has nothing else to go on
			'mediaType' => ($video->getMediaType() !== '') ? $video->getMediaType() : 'video/mp4',
			'href' => $file,
		];

		$original = $video->getMeta()?->getOriginal();
		if ($original?->getWidth() > 0 && $original?->getHeight() > 0) {
			$link['width'] = $original->getWidth();
			$link['height'] = $original->getHeight();
		}

		// `isRemoteVideoUrlValid()` drops a file link without integer `height` and
		// `size`; `fps` is optional
		$link['size'] = $video->getSizeBytes();
		$fps = (int)round((float)($video->getMeta()?->getFps() ?? 0));
		if ($fps > 0) {
			$link['fps'] = $fps;
		}

		$urls[] = $link;

		$playlist = self::playlistFor($video, $rungs);
		if ($playlist !== null) {
			$urls[] = $playlist;
		}

		return $urls;
	}

	/**
	 * The ladder as PeerTube's streaming-playlist link: PeerTube takes the
	 * resolutions from the `Link` tags rather than the playlist. No `Infohash`
	 * tags, which belong to its WebTorrent transport.
	 *
	 * @param array<array{height: int, size: int, bandwidth: int, href: string}> $rungs
	 */
	private static function playlistFor(MediaAttachment $video, array $rungs): ?array {
		$master = $video->getHlsUrl();
		if ($master === '' || $rungs === []) {
			return null;
		}

		$tags = [];
		foreach ($rungs as $rung) {
			if (($rung['href'] ?? '') === '' || ($rung['height'] ?? 0) < 1) {
				continue;
			}

			$tags[] = [
				'type' => 'Link',
				// a rung is one fragmented MP4 whose segments are byte ranges into it
				'mediaType' => 'video/mp4',
				'href' => $rung['href'],
				'height' => (int)$rung['height'],
				'size' => (int)($rung['size'] ?? 0),
			];
		}

		if ($tags === []) {
			return null;
		}

		return [
			'type' => 'Link',
			'mediaType' => 'application/x-mpegURL',
			'href' => $master,
			'tag' => $tags,
		];
	}

	/**
	 * The video as this app's one media attachment for it: a player pointed at
	 * the streaming route, a mirrored still, and the duration.
	 *
	 * Two cache rows: the video's, never filled, and the thumbnail's, an ordinary
	 * image row, so `/media/{uuid}` answers it as an image and no uuid is on two
	 * rows.
	 *
	 * @param array $data the wire `Video`
	 * @param ACore $parent the post it belongs to
	 *
	 * @return MediaAttachment[] empty when it publishes nothing playable
	 */
	public function attachments(array $data, ACore $parent): array {
		$source = $this->source($data, $parent);
		if ($source === null) {
			return [];
		}

		try {
			$this->documentInterface->save($source);
		} catch (Throwable $e) {
			$this->logger->warning('could not record a federated video', ['exception' => $e]);

			return [];
		}

		$media = $source->convertToMediaAttachment($this->urlGenerator);
		$this->describe($media, $data);

		$preview = $this->preview($data, $parent);
		if ($preview !== '') {
			$media->setPreviewUrl($preview);
		}

		return [$media];
	}

	/**
	 * What the player knows before it has loaded a frame: the size PeerTube
	 * published for the file it chose, and the running time.
	 */
	private function describe(MediaAttachment $media, array $data): void {
		$meta = $media->getMeta() ?? new AttachmentMeta();
		$duration = $this->duration($data);

		if ($duration > 0) {
			$meta->setDuration((float)$duration);

			$original = $meta->getOriginal() ?? new AttachmentMetaDim();
			$original->setDuration((float)$duration);
			$meta->setOriginal($original);
		}

		$media->setMeta($meta);
	}

	/**
	 * The still, cached here, as a url on this instance; '' when the video
	 * published none or it could not be fetched. A failure is swallowed, and the
	 * empty `local_copy` it leaves is what the caching cron retries.
	 */
	private function preview(array $data, ACore $parent): string {
		$thumbnail = $this->thumbnail($data);
		if ($thumbnail === null) {
			return '';
		}

		$still = new Document($parent);
		$still->setId($thumbnail['url'])
			->setUrl($thumbnail['url']);
		$still->setMediaType($thumbnail['mediaType']);
		$still->setMimeType($thumbnail['mediaType']);
		$still->setPublic(true);

		try {
			$this->documentInterface->save($still);
		} catch (Throwable $e) {
			$this->logger->debug('could not cache a video thumbnail', ['exception' => $e]);

			return '';
		}

		if ($still->getLocalCopy() === '') {
			return '';
		}

		return $still->convertToMediaAttachment($this->urlGenerator)->getPreviewUrl();
	}
}
