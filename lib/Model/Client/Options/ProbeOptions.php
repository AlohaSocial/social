<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client\Options;

use JsonSerializable;
use OCA\Social\Tools\Nid;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\IRequest;

/**
 * Class ProbeOptions
 *
 * @package OCA\Social\Model\Client\Options
 */
class ProbeOptions extends CoreOptions implements JsonSerializable {
	use TArrayTools;

	public const HOME = 'home';
	public const PUBLIC = 'public';
	public const DIRECT = 'direct';
	public const ACCOUNT = 'account';
	public const FAVOURITES = 'favourites';
	public const BOOKMARKS = 'bookmarks';
	public const HASHTAG = 'hashtag';
	public const NOTIFICATIONS = 'notifications';

	public const FOLLOWERS = 'followers';
	public const FOLLOWING = 'following';

	/**
	 * What `media_type` may narrow `only_media` to.
	 *
	 * The first half of the attachment's MIME type, which is what
	 * `Document::convertToMediaAttachment()` stores as its `type`, plus
	 * `visual` for the combined photo/video timeline.
	 */
	public const MEDIA_TYPES = ['image', 'video', 'audio', 'visual'];

	private string $probe = '';
	private bool $local = false;
	private bool $remote = false;
	private bool $onlyMedia = false;
	private bool $onlyVideo = false;
	private bool $onlyShort = false;
	private bool $onlyNews = false;
	private string $mediaType = '';
	private int|string $minId = 0;
	private int|string $maxId = 0;
	private int|string $since = 0;
	/** Upper bound on how many items one request may ask for. */
	public const MAX_LIMIT = 50;

	/**
	 * Upper bound on how many items a read this server makes for itself may
	 * ask for (`setLookback()`): a list derived from the notifications held,
	 * which a client's page could never reach back far enough for.
	 */
	public const MAX_LOOKBACK = 400;

	private int $limit = 20;
	private bool $inverted = false;
	private string $argument = '';
	private array $types = [];
	private array $excludeTypes = [];
	private string $accountId = '';
	private bool $withBoosts = false;
	private bool $excludeReplies = false;
	private string $tagged = '';

	public function __construct(?IRequest $request = null) {
		if ($request !== null) {
			$this->fromArray($request->getParams());
		}
	}

	/**
	 * @return string
	 */
	public function getProbe(): string {
		return $this->probe;
	}

	/**
	 * @param string $probe
	 *
	 * @return ProbeOptions
	 */
	public function setProbe(string $probe): self {
		$this->probe = strtolower($probe);

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isLocal(): bool {
		return $this->local;
	}

	/**
	 * @param bool $local
	 *
	 * @return ProbeOptions
	 */
	public function setLocal(bool $local): self {
		$this->local = $local;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isRemote(): bool {
		return $this->remote;
	}

	/**
	 * @param bool $remote
	 *
	 * @return ProbeOptions
	 */
	public function setRemote(bool $remote): self {
		$this->remote = $remote;

		return $this;
	}

	/**
	 * Where a timeline's posts may come from, as the client narrowed it:
	 * `true` for this instance's own posts (`local=true`), `false` for every
	 * other instance's (`remote=true`), `null` for all of them.
	 *
	 * Asking for both is asking for no narrowing at all — a post cannot be
	 * from here and from elsewhere — so `local` wins and the timeline stays
	 * whole, rather than answering with the empty intersection nobody asked
	 * for.
	 */
	public function originLimit(): ?bool {
		if ($this->isLocal()) {
			return true;
		}

		return $this->isRemote() ? false : null;
	}

	/**
	 * @return bool
	 */
	public function isOnlyMedia(): bool {
		return $this->onlyMedia;
	}

	/**
	 * @param bool $onlyMedia
	 *
	 * @return ProbeOptions
	 */
	public function setOnlyMedia(bool $onlyMedia): self {
		$this->onlyMedia = $onlyMedia;

		return $this;
	}

	/**
	 * Whether the caller wants only the posts that are a video.
	 *
	 * This app's own, where Mastodon has only `only_media`: a video timeline
	 * asking that question would answer with every holiday photo on the
	 * instance. It is a narrowing of `only_media` and not a replacement for
	 * it -- a client that sends both gets the same answer as one that sends
	 * this alone.
	 */
	public function isOnlyVideo(): bool {
		return $this->onlyVideo;
	}

	public function setOnlyVideo(bool $onlyVideo): self {
		$this->onlyVideo = $onlyVideo;

		return $this;
	}

	/**
	 * Whether the caller wants only the posts that are a short video.
	 *
	 * This app's own, and narrower than `only_video`: a post whose single
	 * video is short and upright, by the rule in `Stream::shortKindOf()`.
	 * It wins over `only_video`, `only_media` and `media_type` when they are
	 * sent with it, since every short is all of those.
	 */
	public function isOnlyShort(): bool {
		return $this->onlyShort;
	}

	public function setOnlyShort(bool $onlyShort): self {
		$this->onlyShort = $onlyShort;

		return $this;
	}

	/**
	 * Whether the caller wants only the posts that are news.
	 *
	 * This app's own, like `only_video`, and a narrowing of nothing: news is not
	 * a kind of media. A post is news when it is an `Article` or a `Page`, or
	 * when its text links somewhere else — see `Stream::newsKindOf()`, which is
	 * where that is decided, once, on the write.
	 */
	public function isOnlyNews(): bool {
		return $this->onlyNews;
	}

	public function setOnlyNews(bool $onlyNews): self {
		$this->onlyNews = $onlyNews;

		return $this;
	}

	/**
	 * @return int|string
	 */
	public function getMinId(): int|string {
		return $this->minId;
	}

	/**
	 * @param int|string $minId
	 *
	 * @return ProbeOptions
	 */
	public function setMinId(int|string $minId): self {
		$this->minId = Nid::fromStorage($minId);

		return $this;
	}

	/**
	 * @return int|string
	 */
	public function getMaxId(): int|string {
		return $this->maxId;
	}

	/**
	 * @param int|string $maxId
	 *
	 * @return ProbeOptions
	 */
	public function setMaxId(int|string $maxId): self {
		$this->maxId = Nid::fromStorage($maxId);

		return $this;
	}

	/**
	 * @return int|string
	 */
	public function getSince(): int|string {
		return $this->since;
	}

	/**
	 * @param int|string $since
	 *
	 * @return ProbeOptions
	 */
	public function setSince(int|string $since): self {
		$this->since = Nid::fromStorage($since);

		return $this;
	}

	/**
	 * @return int
	 */
	public function getLimit(): int {
		return $this->limit;
	}

	/**
	 * @param int $limit
	 *
	 * @return ProbeOptions
	 */
	public function setLimit(int $limit): self {
		// Clamp: the limit reaches setMaxResults() directly, so an unbounded or
		// negative value from the request would be a memory-limit fatal or invalid SQL.
		$this->limit = max(1, min(self::MAX_LIMIT, $limit));

		return $this;
	}

	/**
	 * The limit of a read this server makes for itself rather than for a
	 * client, which may reach past `MAX_LIMIT` up to `MAX_LOOKBACK`. Never
	 * set from a request: what a client asks for goes through `setLimit()`.
	 */
	public function setLookback(int $limit): self {
		$this->limit = max(1, min(self::MAX_LOOKBACK, $limit));

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isInverted(): bool {
		return $this->inverted;
	}

	/**
	 * @param bool $inverted
	 *
	 * @return ProbeOptions
	 */
	public function setInverted(bool $inverted): self {
		$this->inverted = $inverted;

		return $this;
	}

	/**
	 * @param string $argument
	 *
	 * @return ProbeOptions
	 */
	public function setArgument(string $argument): self {
		$this->argument = $argument;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getArgument(): string {
		return $this->argument;
	}

	/**
	 * @param array $types
	 *
	 * @return ProbeOptions
	 */
	public function setTypes(array $types): self {
		$this->types = $types;

		return $this;
	}

	/**
	 * @return array
	 */
	public function getTypes(): array {
		return $this->types;
	}

	/**
	 * @param array $excludeTypes
	 *
	 * @return ProbeOptions
	 */
	public function setExcludeTypes(array $excludeTypes): self {
		$this->excludeTypes = $excludeTypes;

		return $this;
	}

	/**
	 * @return array
	 */
	public function getExcludeTypes(): array {
		return $this->excludeTypes;
	}

	/**
	 * @param string $accountId
	 *
	 * @return ProbeOptions
	 */
	/**
	 * Narrows `only_media` to one kind of attachment.
	 *
	 * A Social extension rather than a Mastodon parameter: Mastodon has
	 * `only_media` and nothing finer, and a profile that offers Photos and
	 * Videos as separate tabs needs the difference asked of the database
	 * rather than sorted out afterwards — a page filtered in the client is a
	 * page that can come back empty while there are still videos to find.
	 *
	 * `media_type=video` is the same question `only_video` asks and gets the
	 * same answer, PeerTube's `Video` objects included: the two are one
	 * predicate in `StreamRequest::filterMedia()`, so a profile's Videos tab
	 * and the Videos timeline cannot come to disagree about what a video is.
	 *
	 * The visual extension combines photos and videos. Any other value is read
	 * as "no preference": the value arrives from a query string.
	 *
	 * @param string $mediaType `image`, `video`, `audio`, `visual` (images/videos), or '' for all of them
	 */
	public function setMediaType(string $mediaType): self {
		$this->mediaType = in_array($mediaType, self::MEDIA_TYPES, true) ? $mediaType : '';

		return $this;
	}

	public function getMediaType(): string {
		return $this->mediaType;
	}

	public function setAccountId(string $accountId): self {
		$this->accountId = $accountId;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getAccountId(): string {
		return $this->accountId;
	}

	/**
	 * Whether an account's timeline lists its boosts beside its posts, as
	 * Mastodon's `accounts/{id}/statuses` does. Off unless the caller asks:
	 * every other reader of the account probe — the outbox, an export, the
	 * statistics — is about what the account wrote.
	 */
	public function isWithBoosts(): bool {
		return $this->withBoosts;
	}

	public function setWithBoosts(bool $withBoosts): self {
		$this->withBoosts = $withBoosts;

		return $this;
	}

	/**
	 * Whether an account's timeline leaves out its replies to other accounts,
	 * keeping the ones to its own posts (a thread), as Mastodon's
	 * `exclude_replies` does.
	 */
	public function isExcludeReplies(): bool {
		return $this->excludeReplies;
	}

	public function setExcludeReplies(bool $excludeReplies): self {
		$this->excludeReplies = $excludeReplies;

		return $this;
	}

	/** The hashtag an account's timeline is limited to, `''` for none: Mastodon's `tagged`. */
	public function getTagged(): string {
		return $this->tagged;
	}

	public function setTagged(string $tagged): self {
		$this->tagged = $tagged;

		return $this;
	}

	/**
	 * @param array $arr
	 *
	 * @return ProbeOptions
	 */
	public function fromArray(array $arr): self {
		$this->setLocal($this->getBool('local', $arr, $this->isLocal()));
		$this->setRemote($this->getBool('remote', $arr, $this->isRemote()));
		$this->setOnlyMedia($this->getBool('only_media', $arr, $this->isOnlyMedia()));
		$this->setOnlyVideo($this->getBool('only_video', $arr, $this->isOnlyVideo()));
		$this->setOnlyShort($this->getBool('only_short', $arr, $this->isOnlyShort()));
		$this->setOnlyNews($this->getBool('only_news', $arr, $this->isOnlyNews()));
		$this->setMediaType($this->get('media_type', $arr, $this->getMediaType()));
		$this->setMinId($this->get('min_id', $arr, '0'));
		$this->setMaxId($this->get('max_id', $arr, '0'));
		$this->setSince($this->get('since', $arr, '0'));
		$this->setLimit($this->getInt('limit', $arr, $this->getLimit()));
		$this->setArgument($this->get('argument', $arr, $this->getArgument()));

		return $this;
	}

	/**
	 * @return array
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return
			[
				'probe' => $this->getProbe(),
				'accountId' => $this->getAccountId(),
				'local' => $this->isLocal(),
				'remote' => $this->isRemote(),
				'only_media' => $this->isOnlyMedia(),
				'only_video' => $this->isOnlyVideo(),
				'only_short' => $this->isOnlyShort(),
				'only_news' => $this->isOnlyNews(),
				'media_type' => $this->getMediaType(),
				'min_id' => $this->getMinId(),
				'max_id' => $this->getMaxId(),
				'since' => $this->getSince(),
				'limit' => $this->getLimit(),
				'argument' => $this->getArgument()
			];
	}
}
