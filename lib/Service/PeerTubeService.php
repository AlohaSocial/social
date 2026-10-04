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
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What a PeerTube `Video` says, in the terms the rest of this app speaks.
 *
 * A `Video` is modelled as a `Note` carrying its wire type in `subtype` (see
 * `AP::NOTE_LIKE_TYPES`) -- that is what makes it storable, queryable and
 * readable by a Mastodon client. What it was missing is everything that makes
 * it a *video*: the file to play, the thumbnail to show before it plays, how
 * long it runs, and which of the two actors in `attributedTo` published it.
 * None of those live where an ordinary `Note` keeps them, so they are read out
 * here.
 *
 * Nothing in this class fetches anything. It reads the object that already
 * arrived in the inbox and says what is in it; the caller decides what to do
 * with that.
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
	 * The one link this app will play, in the order it prefers them.
	 *
	 * `video/mp4` is the only thing every browser can play from a bare
	 * `<video src>`, so a PeerTube in "web videos" mode is played directly.
	 * An instance transcoding to HLS only publishes `application/x-mpegURL`
	 * instead, which Safari plays natively and nothing else does; it is taken
	 * as a last resort rather than dropped, because the alternative for those
	 * videos is no player at all.
	 */
	private const PLAYABLE = ['video/mp4', 'video/webm', 'video/ogg', 'application/x-mpegURL'];

	/** Sources above this are ignored: nobody is streaming 4K through a proxy. */
	private const MAX_HEIGHT = 1080;

	/** How far into nested `tag` lists to look for a file. */
	private const MAX_LINK_DEPTH = 2;

	/** How long a derived title may be; PeerTube's own ceiling is 120. */
	private const MAX_TITLE = 120;

	/**
	 * The file to play, as a `Document` that is deliberately never mirrored.
	 *
	 * A `Document` rather than a bare url because that is what the rest of the
	 * app -- the attachment list, the media route, the client entity -- is
	 * built out of, and because the row it becomes is what the streaming route
	 * checks a request against: a proxy that would fetch any url it is handed
	 * is an open redirect with a server behind it.
	 *
	 * `localCopy` is the streamed sentinel rather than empty, which is what
	 * keeps the caching cron's hands off it -- `getNotCachedDocuments()` only
	 * looks at rows whose `local_copy` is empty, and a two-hour talk is not
	 * something to copy onto somebody's Nextcloud. `resizedCopy` is left for
	 * the thumbnail, which *is* small enough to mirror and is filled in by
	 * `VideoAttachmentService`.
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
	 * The still to show before anything is played: PeerTube's `icon`, which is
	 * one `Image` on older instances and a list of them on newer ones. The
	 * largest is taken -- they are all a few dozen kilobytes, and the grid
	 * draws them at whatever size it likes.
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
			$mediaType = is_array($icon) ? (string)($icon['mediaType'] ?? 'image/jpeg') : 'image/jpeg';
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
	 * Whether replies to this video have to be approved before anybody sees
	 * them — FEP-5624, which PeerTube ≥ 6.2 implements as `commentsPolicy`.
	 *
	 * `1` is enabled, `2` disabled and `3` "requires approval"; `canReply`
	 * names who may reply without being approved, so a video that publishes one
	 * which does not include the public collection is moderating its comments
	 * too. Read in both spellings because the two arrived a version apart and
	 * instances run both.
	 *
	 * Without this a reply written here looked posted, sat in a queue on the
	 * other side, and either appeared a day later or never — with nothing
	 * anywhere to say which.
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
			$id = is_array($who) ? (string)($who['id'] ?? '') : (string)$who;
			if ($id === ACore::CONTEXT_PUBLIC) {
				return false;
			}
		}

		return true;
	}

	/**
	 * How long it runs, in whole seconds.
	 *
	 * ActivityStreams says `duration` is an xsd:duration, and PeerTube always
	 * writes the simple `PT113S` form of one. Only the time half is read: a
	 * video measured in months is not a video.
	 *
	 * @return int 0 when it says nothing usable
	 */
	public function duration(array $data): int {
		$duration = trim((string)($data['duration'] ?? ''));
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
	 * Everything else a `Video` says that a `Note` has nowhere to put.
	 *
	 * A video is not a post with a rectangle in it. It has a category, a
	 * licence, a language, chapters, captions, a "support the author" line and
	 * two counters — every one of which PeerTube publishes and none of which
	 * this app read, so a federated video arrived as a paragraph and a player
	 * with all of that thrown away.
	 *
	 * Kept together in one block on the post's details rather than spread over
	 * a dozen columns: it is local, derived data about somebody else's
	 * document, which is what the details column is for, and a watch page wants
	 * all of it or none.
	 *
	 * @param array $data the wire `Video`
	 *
	 * @return array<string, mixed> empty when the object says none of it
	 */
	public function videoMeta(array $data): array {
		$meta = array_filter([
			// what the video is *called*. A `Note` has no title and this app
			// puts it in the first line of the content, which is right for a
			// timeline card and not enough for a page about the video: a watch
			// page wants a heading, and it cannot take one out of a paragraph.
			'title' => mb_substr($this->title($data), 0, self::MAX_TITLE),
			// PeerTube sends these as `{id, label}`; the label is what a reader
			// wants and the id means nothing off its own instance
			'category' => $this->labelOf($data['category'] ?? null),
			'licence' => $this->labelOf($data['licence'] ?? null),
			'language' => $this->labelOf($data['language'] ?? null),
			'support' => trim((string)($data['support'] ?? '')),
			'originally_published_at' => trim((string)($data['originallyPublishedAt'] ?? '')),
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

		// `downloadEnabled` is the author saying whether the file may be saved.
		// Absent means yes, which is PeerTube's own default, so only a stated
		// refusal is recorded — an absent field must not read as one.
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
			return trim((string)($value['label'] ?? $value['identifier'] ?? ''));
		}

		return '';
	}

	/**
	 * The chapters of a video, from FEP-6f7d's `hasParts`.
	 *
	 * A chapter is a name and a moment, which is all a seek list needs. The
	 * offsets are seconds; anything that is not a number is not a chapter.
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

			$title = trim((string)($part['name'] ?? ''));
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
	 * The subtitle tracks a video publishes.
	 *
	 * PeerTube hangs them off `url` as `text/vtt` links carrying the language,
	 * which is the only place they are actually addressable — `subtitleLanguage`
	 * on its own names languages with nothing to fetch for them.
	 *
	 * @return array<int, array{language: string, url: string}>
	 */
	private function captions(array $data): array {
		$languages = [];
		foreach ($this->asList($data['subtitleLanguage'] ?? null) as $entry) {
			$id = is_array($entry) ? (string)($entry['identifier'] ?? $entry['id'] ?? '') : (string)$entry;
			$url = is_array($entry) ? (string)($entry['url'] ?? '') : '';
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
	 * Who published it.
	 *
	 * PeerTube sends `attributedTo` as a list of two actors -- the channel, a
	 * `Group`, and the account behind it, a `Person` -- where every other
	 * server sends one id as a string. `Stream::import()` asks for a string and
	 * so read neither, leaving every federated video attributed to nobody.
	 *
	 * The channel wins, because the channel is what the `Create` is signed by,
	 * what a reader follows, and what the video is listed under on PeerTube
	 * itself.
	 *
	 * @return string '' when the list holds no actor with an id
	 */
	public function attributedTo(array $data): string {
		$person = '';

		foreach ($this->asList($data['attributedTo'] ?? null) as $actor) {
			if (is_string($actor)) {
				return $actor;
			}

			$id = (string)($actor['id'] ?? '');
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
	 * description under it.
	 *
	 * The title is what a video is *called*, and a `Video` keeps it in `name`
	 * where a `Note` has no title at all -- so a timeline that only read
	 * `content` showed the description of a video whose name it never
	 * mentioned. It is not copied into the note's `name` instead, because that
	 * field means one thing here, the option a poll vote chose (see
	 * `PollService::handleIncomingVote`), and giving it a second meaning is how
	 * a post would end up counted as a vote.
	 *
	 * The description is passed through as html, as every other object's
	 * `content` is -- *unless* the object says it is not, which PeerTube does:
	 * it declares `text/markdown` in the object's own `mediaType` and sends the
	 * description raw. That is then escaped and its blank lines become
	 * paragraphs, which is as much of markdown as a timeline needs. Believing
	 * the declaration in both directions is the point: escaping html would show
	 * somebody's tags to them, and rendering markdown as html would hand a
	 * remote server a way to put markup in a post that never went through a
	 * sanitiser of its own.
	 */
	public function content(array $data): string {
		$content = '';

		if ($this->title($data) !== '') {
			$content = '<p>' . $this->linkedTitle($data) . '</p>';
		}

		$description = trim((string)($data['content'] ?? ''));
		if ($description === '') {
			return $content;
		}

		if (!in_array((string)($data['mediaType'] ?? ''), ['text/markdown', 'text/plain'], true)) {
			return $content . $description;
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
	 * The little of markdown a video description actually uses, as html.
	 *
	 * Left alone, a PeerTube description reads as `**Take back the control of
	 * your videos! [#JoinPeertube](https://joinpeertube.org)**` -- asterisks,
	 * brackets and a URL in parentheses, in the middle of a timeline. Mastodon
	 * shows exactly that, and it is not a good reason to.
	 *
	 * The order is the safety: the text is **escaped first**, so every tag
	 * below is one this method wrote and there is no path by which a remote
	 * server's markup survives. A link is only made of an `http(s)` target --
	 * `[click here](javascript:…)` stays the text it was -- and the href is the
	 * escaped form, so a `"` in a url cannot end the attribute.
	 *
	 * Bold before italic, because `**` would otherwise be read as two `*`.
	 * Block constructs (headings, lists, code fences) are deliberately not
	 * handled: they are rare in a video description and each one is a way to
	 * get this wrong.
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
		return trim((string)($data['name'] ?? ''));
	}

	/**
	 * The playable link with the most pixels this app is willing to proxy, and
	 * `application/x-mpegURL` only when there is no file at all -- a playlist
	 * that Chrome and Firefox cannot open must never be preferred over an mp4
	 * that they can.
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
	 * `url` normalised to a flat list of links.
	 *
	 * It is a string on every other kind of object, one `Link` on some, and on
	 * a PeerTube `Video` a list that mixes the watch page, one file per
	 * resolution, the HLS playlist, a torrent and a magnet URI. Only http(s)
	 * survives -- `magnet:` is not something to hand a `<video>`.
	 *
	 * The nesting is not decoration. A PeerTube transcoding to HLS -- which is
	 * the default, and what a public instance actually federates -- publishes
	 * *one* top-level link, the `.m3u8` playlist, and hangs the playable file
	 * for each resolution off that link's `tag` instead. Reading only the top
	 * level therefore found nothing but a playlist, which is to say nothing
	 * Chrome or Firefox can open, on the majority of real videos. So `tag` is
	 * walked as well, and the files inside it are links like any other.
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

			$href = (string)($link['href'] ?? $link['url'] ?? '');
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
				'mediaType' => (string)($link['mediaType'] ?? ''),
				'width' => (int)($link['width'] ?? 0),
				'height' => (int)($link['height'] ?? 0),
				// a caption link says which language it is in, and nothing
				// else in the list has one
				'language' => is_array($link['language'] ?? null)
					? (string)($link['language']['identifier'] ?? '')
					: (string)($link['language'] ?? ''),
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

			$href = (string)($entry['href'] ?? $entry['url'] ?? '');
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
	 * The one video attachment of a post, when the post is a video.
	 *
	 * Exactly one, and nothing else alongside it. A `Video` object *is* the
	 * video -- it has one title, one duration, one file -- so a post carrying a
	 * video and three photographs is a post, not a video, and stays a `Note`.
	 *
	 * Static and pure, like everything below it: this half is called from
	 * `Note::jsonSerialize()`, which is a model with no container to reach
	 * into, and it lives in this file rather than beside the caller so that
	 * what this app writes and what it reads cannot drift apart.
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
	 * A local post, reshaped as the `Video` object PeerTube publishes.
	 *
	 * Everything already in `$note` stays -- id, actor, audience, published,
	 * sensitive, tags, replies -- and what a `Video` adds on top is what makes
	 * it findable as a video rather than as a post that happens to have one:
	 *
	 * - `name`, the title. A `Note` has no such field, so it is derived; see
	 *   `titleFor()`.
	 * - `duration`, in the xsd form PeerTube writes (`PT113S`).
	 * - `icon`, the poster frame, which this instance generates on upload.
	 * - `url` as a **list**: the web page, and a `Link` for the file itself.
	 *   That list is the one thing PeerTube actually looks at, and a `Video`
	 *   without it is a video nothing can play.
	 *
	 * `attachment` is deliberately **kept** as well. A `Video` carries its file
	 * in `url` and has no need of it, but every Mastodon-family server reads
	 * `attachment` and nothing else, and this post rendered there with an
	 * inline player before this method existed. Publishing the two together
	 * costs a few hundred bytes and is the difference between gaining PeerTube
	 * and trading Mastodon for it.
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

		// PeerTube's validator makes `duration`, `icon` and a non-empty
		// `attributedTo` mandatory, and its builder refuses a video whose
		// `attributedTo` holds no `Group` at all. A post that cannot satisfy
		// all three — no poster because the server has no ffmpeg, no duration
		// for the same reason, no channel because the account has none — is
		// therefore published as the `Note` it would have been, which Mastodon
		// and Pixelfed both read. Sending a `Video` that is going to be thrown
		// away is strictly worse than sending the shape that works.
		if ($duration <= 0 || $icon === null || $attributedTo === []) {
			return null;
		}

		$note['type'] = self::TYPE;
		// what the author called it, where they called it anything. A title
		// taken out of the first line of the post is a guess, and a guess is
		// only worth making when nobody has answered the question.
		$note['name'] = ($stated['title'] ?? '') !== ''
			? mb_substr((string)$stated['title'], 0, self::MAX_TITLE)
			: self::titleFor($note, $video);

		foreach (['category', 'licence'] as $field) {
			if (($stated[$field] ?? '') !== '') {
				$note[$field] = (string)$stated[$field];
			}
		}
		$note['attributedTo'] = $attributedTo;
		// `isUUIDValid` is checked before anything else is read, and a `Video`
		// without one is refused outright. Derived from the post's own id so
		// that the same post is the same video however often it is delivered.
		$note['uuid'] = self::uuidFor((string)($note['id'] ?? ''));
		$note['views'] = max(0, $views);
		// mandatory, and this app writes one only on a post that has been
		// edited; an unedited video is as up to date as it is published
		if (($note['updated'] ?? '') === '') {
			$note['updated'] = (string)($note['published'] ?? gmdate('c'));
		}
		// the defaults PeerTube would have filled in, said out loud: a reader
		// that states them is a reader whose intent cannot be guessed wrong
		$note['state'] = 1;
		$note['waitTranscoding'] = false;
		$note['downloadEnabled'] = true;
		$note['sensitive'] = (bool)($note['sensitive'] ?? false);
		$note['isLiveBroadcast'] = false;
		// this app has replies on every post and no way to turn them off
		$note['commentsPolicy'] = 1;
		$note['icon'] = [$icon];
		$note['duration'] = 'PT' . $duration . 'S';

		// what the content actually is, said out loud. PeerTube declares
		// `text/markdown` for its own; this app's posts are html, and a peer
		// that assumed otherwise would show somebody their own tags.
		if (($note['content'] ?? '') !== '') {
			$note['mediaType'] = 'text/html';
		}

		$note['url'] = self::urlsFor($video, $watchUrl, $rungs);

		// PeerTube states it on every video and its clients read it; this app
		// has replies on every post and no way to turn them off
		$note['commentsEnabled'] = true;

		// PeerTube fetches both counts for every video it takes in, and writes
		// whatever it got: without them it wrote an undefined count and the
		// unhandled failure took the whole server down
		$id = (string)($note['id'] ?? '');
		$note['likes'] = $id . Stream::LIKES_PATH;
		$note['dislikes'] = $id . Stream::DISLIKES_PATH;

		return $note;
	}

	/**
	 * What to call it.
	 *
	 * A `Note` has no title and this app does not ask for one, so it is taken
	 * from the first line of the post -- which is where somebody writing about
	 * a video puts its name -- with the alt text as the fallback for a post
	 * that is nothing but the video.
	 *
	 * `name` on a Note means the option a poll vote chose, so nothing here
	 * reads it: a title that came from that field would be a vote.
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
	 * A UUID for a post that has none.
	 *
	 * PeerTube checks `uuid` before it reads anything else and refuses a
	 * `Video` whose one is not a UUID. This app addresses a post by its URL and
	 * has no such field, so one is derived from the id — the same id in, the
	 * same UUID out, every time, which is what makes a redelivered video the
	 * same video rather than a second one.
	 *
	 * Shaped as a version-4 UUID although it is a hash of a name, because
	 * PeerTube's `isUUIDValid()` accepts version 4 and nothing else. It used
	 * to say version 5, which is what it is, and PeerTube refused every
	 * `Video` this app sent on that one field. The two nibbles that carry the
	 * version and the variant are set by hand; the rest is the hash, so it
	 * stays as stable as it was.
	 *
	 * Public because `PeerTubeApiService` needs the **same** answer: a video
	 * seen through the PeerTube client API and the same video seen over
	 * ActivityPub must carry one uuid and not two that nothing can tell apart.
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
			// no poster: this instance had no ffmpeg when the video was
			// uploaded, and `preview_url` is the video itself
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
	 * `url` as PeerTube writes it: the page a person watches on, then the file
	 * a player opens.
	 *
	 * The file link is always there: whatever was uploaded, at one size, which
	 * is what a reader with no HLS falls back to. Where the video has been
	 * laddered there is a streaming playlist beside it, in the shape PeerTube
	 * publishes its own — the master's address, and a `Link` tag per rung so a
	 * peer knows what it is being offered without fetching the playlist first.
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
			// the full type, not the `video` half a client entity carries: a
			// peer that does not sniff the file has nothing else to go on
			'mediaType' => ($video->getMediaType() !== '') ? $video->getMediaType() : 'video/mp4',
			'href' => $file,
		];

		$original = $video->getMeta()?->getOriginal();
		if ($original?->getWidth() > 0 && $original?->getHeight() > 0) {
			$link['width'] = $original->getWidth();
			$link['height'] = $original->getHeight();
		}

		// `isRemoteVideoUrlValid()` wants `height` and `size` as integers and
		// drops a file link that has neither, so a `Video` whose only link went
		// out without them arrived with nothing to play. `fps` is optional and
		// sent where it is known.
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
	 * The ladder as PeerTube's streaming-playlist link.
	 *
	 * PeerTube reads `mediaType: application/x-mpegURL` as "this is HLS" and
	 * takes the resolutions from the `Link` tags underneath rather than by
	 * fetching the playlist, so a link with no tags is one it accepts and then
	 * has no files for. No `Infohash` tag: those are BitTorrent info hashes for
	 * PeerTube's WebTorrent transport, which this app does not have and must
	 * not invent.
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
				// a rung is one fragmented MP4 whose segments are byte ranges
				// into it, which is a `video/mp4` to anything that fetches it
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
	 * the streaming route, a still that *is* mirrored here, and how long it
	 * runs.
	 *
	 * Two cache rows, not one. The video's says where the bytes are and is
	 * never filled; the thumbnail's is an ordinary image row, fetched and
	 * resized like any other, which is what lets `/media/{uuid}` answer it
	 * with `image/jpeg` instead of the video's own media type. Hanging the
	 * still off the video row's `resized_copy` instead would have put one uuid
	 * on two rows, and `getByCopy()` would answer with whichever the database
	 * felt like.
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
	 * The still, cached here, as a url on this instance -- '' when the video
	 * published none or it could not be fetched.
	 *
	 * A thumbnail that will not download is not worth losing the video over,
	 * so a failure here is swallowed; the row it leaves behind has an empty
	 * `local_copy`, which is exactly what the caching cron comes back for.
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
