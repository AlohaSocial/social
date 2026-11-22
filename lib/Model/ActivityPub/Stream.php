<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub;

use DateTime;
use DateTimeZone;
use Exception;
use JsonSerializable;
use OCA\Social\AP;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidResourceEntryException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Interfaces\Object\DocumentInterface;
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Mention;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Playlist;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\Place;
use OCA\Social\Model\Details;
use OCA\Social\Model\StreamAction;
use OCA\Social\Model\StreamCard;
use OCA\Social\Security\HtmlSanitizer;
use OCA\Social\Service\AiContentService;
use OCA\Social\Service\VideoDeliveryHold;
use OCA\Social\Tools\ActivityPubEvent;
use OCA\Social\Tools\IQueryRow;
use OCA\Social\Tools\Model\Cache;
use OCA\Social\Tools\Model\CacheItem;
use OCA\Social\Traits\TDetails;
use OCP\IURLGenerator;
use OCP\Server;
use Psr\Log\LoggerInterface;
use Throwable;

class Stream extends ACore implements IQueryRow, JsonSerializable {
	/** Outbox imports store attachment rows for the media worker to download. */
	private bool $deferAttachmentDownloads = false;

	public function setDeferAttachmentDownloads(bool $defer): void {
		$this->deferAttachmentDownloads = $defer;
	}

	/**
	 * The six values `social_stream.media_kind` holds: the four `MEDIA_KINDS`,
	 * `''` for a post with no attachment and `mixed` for one with more than one
	 * kind. Closed because the column is seven characters wide and is filled
	 * from stored attachment JSON, which may hold any type a peer or an older
	 * row wrote (`"type":"application"` for a PDF).
	 */
	public const MEDIA_KIND_NONE = '';
	public const MEDIA_KIND_MIXED = 'mixed';

	/**
	 * The attachment types that are a kind of their own. Everything else is a
	 * file a client cannot render (`unknown`).
	 */
	public const MEDIA_KINDS = ['image', 'video', 'audio', 'gifv'];

	/**
	 * The three values `social_stream.news_kind` holds. `article` is a post
	 * that is an article (an `Article` or `Page`, see `AP::NOTE_LIKE_TYPES`);
	 * `link` is a note whose text carries an external link; `''` is neither.
	 * The News timeline asks for both.
	 */
	public const NEWS_KIND_NONE = '';
	public const NEWS_KIND_LINK = 'link';
	public const NEWS_KIND_ARTICLE = 'article';

	/** The subtypes that make a post an article in its own right. */
	public const ARTICLE_SUBTYPES = ['Article', 'Page'];

	/**
	 * The two values `social_stream.short_kind` holds once a post has been
	 * looked at: `short` for a short video, `''` for anything else. NULL stays
	 * on an older post that is not a video, since the backfill reads only videos.
	 *
	 * @see self::shortKindOf()
	 */
	public const SHORT_KIND_NONE = '';
	public const SHORT_KIND_SHORT = 'short';

	/**
	 * The longest a short may run, in seconds: the longest recording the
	 * short composer offers (`RECORD_LIMITS` in `src/utils/shortVideo.js`).
	 */
	public const SHORT_MAX_SECONDS = 180;

	/**
	 * The widest a short may be, as width divided by height: upright or
	 * square, never landscape.
	 */
	public const SHORT_MAX_ASPECT = 1.0;

	use TDetails;

	/** The longest `video_card.title`, in characters: as long as a video's own title is kept. */
	public const VIDEO_CARD_TITLE = 120;

	public const TYPE = 'Stream';

	public const TYPE_PUBLIC = 'public';
	public const TYPE_UNLISTED = 'unlisted';
	public const TYPE_FOLLOWERS = 'followers';
	public const TYPE_DIRECT = 'direct';
	public const TYPE_ANNOUNCE = 'announce';

	/**
	 * What is appended to a post's id to name the collection of its replies.
	 * The route that serves it has to agree with this, because this is the URL
	 * a peer dereferences.
	 */
	public const REPLIES_PATH = '/replies';

	/**
	 * What is appended to a post's id to name the counts PeerTube keeps on a
	 * video. PeerTube fetches both for every remote video and fails without them.
	 */
	public const LIKES_PATH = '/likes';
	public const DISLIKES_PATH = '/dislikes';

	/**
	 * The types a reader is shown as statuses, and may like, boost or
	 * bookmark: a note and a poll. The same list as a query is
	 * `SocialLimitsQueryBuilder::limitToStatusTypes()`.
	 */
	public const STATUS_TYPES = [Note::TYPE, Question::TYPE];

	public static function isStatusType(string $type): bool {
		return in_array($type, self::STATUS_TYPES, true);
	}

	/**
	 * The states Mastodon's Quote entity can be in: `accepted` once the quoted
	 * author approved it and the quoted post is held here and visible to the
	 * reader, `pending` while either is awaited, `rejected` when refused and
	 * `revoked` when the approval was withdrawn.
	 */
	public const QUOTE_ACCEPTED = 'accepted';
	public const QUOTE_PENDING = 'pending';
	public const QUOTE_REJECTED = 'rejected';
	public const QUOTE_REVOKED = 'revoked';

	/**
	 * The largest counter this app will repeat from another server, far above
	 * any real post's count.
	 */
	public const REMOTE_COUNT_CEILING = 10000000;

	/**
	 * The counters that are columns of `social_stream`: `details` key => column.
	 * Written by `StreamRequest::recount()`, read back over `details` by
	 * `importFromDatabase()`.
	 */
	public const COUNTER_COLUMNS = [
		Details::REPLIES => 'count_replies',
		Details::LIKES => 'count_likes',
		Details::BOOSTS => 'count_boosts',
		Details::DISLIKES => 'count_dislikes',
		Details::QUOTES => 'count_quotes',
	];

	/**
	 * The types whose model reads the stored wire object itself (`Question`
	 * its poll, `Playlist` its list), so a read that leaves `source` out
	 * still reads it for them.
	 */
	public const TYPES_READING_SOURCE = [Question::TYPE, Playlist::TYPE];

	/** The interactions an author can speak about, in this app's own names. */
	public const INTERACTION_REPLY = 'reply';
	public const INTERACTION_BOOST = 'boost';
	public const INTERACTION_LIKE = 'like';

	/** Which `interactionPolicy` clause each of them is. */
	public const INTERACTION_CLAUSES = [
		self::INTERACTION_REPLY => 'canReply',
		self::INTERACTION_BOOST => 'canAnnounce',
		self::INTERACTION_LIKE => 'canLike',
	];

	/**
	 * Who the author said may quote this post, as Mastodon 4.5 names the
	 * choices. `''` means nobody was asked and the visibility rule decides:
	 * public and unlisted posts are quotable.
	 */
	public const QUOTE_POLICY_PUBLIC = 'public';
	public const QUOTE_POLICY_FOLLOWERS = 'followers';
	public const QUOTE_POLICY_NOBODY = 'nobody';

	public const QUOTE_POLICIES = [
		self::QUOTE_POLICY_PUBLIC,
		self::QUOTE_POLICY_FOLLOWERS,
		self::QUOTE_POLICY_NOBODY,
	];

	/** Replies to this post are held until somebody approves them. */
	public const REPLY_POLICY_APPROVAL = 'approval';

	/**
	 * Who may reply to one of this instance's own posts: everybody (the
	 * default), the author's followers, the accounts the author follows, the
	 * accounts the post mentions, or nobody. The author always may.
	 */
	public const REPLY_RULE_EVERYONE = 'everyone';
	public const REPLY_RULE_FOLLOWERS = 'followers';
	public const REPLY_RULE_FOLLOWING = 'following';
	public const REPLY_RULE_MENTIONED = 'mentioned';
	public const REPLY_RULE_NOBODY = 'nobody';
	/** as many hidden replies as a threadgate's `hiddenReplies` holds */
	public const HIDDEN_REPLIES_KEPT = 300;
	/** as many detached quotes as a postgate's `detachedEmbeddingUris` holds */
	public const DETACHED_QUOTES_KEPT = 50;
	/** the prefix of a reply rule's part that names one of the author's lists */
	public const REPLY_RULE_LIST = 'list:';
	public const REPLY_RULES = [
		self::REPLY_RULE_EVERYONE, self::REPLY_RULE_FOLLOWERS, self::REPLY_RULE_FOLLOWING,
		self::REPLY_RULE_MENTIONED, self::REPLY_RULE_NOBODY,
	];

	/** This reply is waiting to be approved, has been, or was refused. */
	public const REPLY_PENDING = 'pending';
	public const REPLY_APPROVED = 'approved';
	public const REPLY_REJECTED = 'rejected';

	/**
	 * How many attachments a single post may bring in: Pixelfed's album
	 * ceiling, so a ten-photo album arrives whole.
	 */
	public const MAX_ATTACHMENTS = 10;

	/**
	 * Mastodon calls a followers-only post `private`; this app calls it
	 * `followers`. Both directions are translated, so a client's followers-only
	 * post is never addressed to the public collection.
	 */
	private const CLIENT_VISIBILITIES = [
		'public' => self::TYPE_PUBLIC,
		'unlisted' => self::TYPE_UNLISTED,
		'private' => self::TYPE_FOLLOWERS,
		'followers' => self::TYPE_FOLLOWERS,
		'direct' => self::TYPE_DIRECT,
	];

	/**
	 * Translate a visibility as a client writes it into this app's own
	 * vocabulary. Anything unrecognised becomes `direct`, the most restrictive
	 * option, so a wrong guess never publishes a private post.
	 */
	public static function visibilityFromClient(string $visibility): string {
		return self::CLIENT_VISIBILITIES[strtolower(trim($visibility))] ?? self::TYPE_DIRECT;
	}

	/**
	 * True when a client sent a visibility this app understands. Callers that
	 * can report an error to the client should use this and answer 422 rather
	 * than silently posting to nobody.
	 */
	public static function isKnownClientVisibility(string $visibility): bool {
		return array_key_exists(strtolower(trim($visibility)), self::CLIENT_VISIBILITIES);
	}

	/**
	 * The visibilities a client may ask for, for telling somebody which ones
	 * those are when they asked for something else.
	 *
	 * @return string[]
	 */
	public static function clientVisibilities(): array {
		return array_keys(self::CLIENT_VISIBILITIES);
	}

	/**
	 * The hashtags on this item. Only a Note stores any and overrides this;
	 * `StreamRequest` asks any Stream for them when it writes one.
	 *
	 * @return string[]
	 */
	public function getHashtags(): array {
		return [];
	}

	/**
	 * Translate this app's vocabulary back into what a client expects. An
	 * empty one is answered `public`: Mastodon's `Status` has no empty
	 * visibility, and this is only a label, since forwarding, boosting and
	 * reading are decided against the stored value.
	 */
	public static function visibilityForClient(string $visibility): string {
		return match ($visibility) {
			self::TYPE_FOLLOWERS => 'private',
			'' => self::TYPE_PUBLIC,
			default => $visibility,
		};
	}

	/**
	 * A notification this instance raises that is not an activity anybody
	 * sent: a poll of yours closing, an account you asked about posting, a
	 * moderator warning you, or a block cutting your follows. There is no
	 * ActivityPub verb for these, so the subtype is this app's own name.
	 */
	public const SUBTYPE_POLL = 'PollClosed';
	public const SUBTYPE_STATUS = 'NewStatus';
	public const SUBTYPE_WARNING = 'ModerationWarning';
	public const SUBTYPE_SEVERED = 'SeveredRelationships';

	/**
	 * Somebody answered one of your shorts. Pixelfed's own two names, since
	 * these arrive from Pixelfed and clients that know them draw them right.
	 */
	public const SUBTYPE_STORY_REACT = 'StoryReaction';
	public const SUBTYPE_STORY_REPLY = 'StoryReply';

	/** Somebody named you in a photo. */
	public const SUBTYPE_PHOTO_TAG = 'PhotoTag';

	/**
	 * What only Bluesky tells: a like or a repost of your repost, Bluesky
	 * verifying or unverifying your account, somebody joining with your
	 * starter pack. A client that does not know a `bluesky:…` type leaves it out.
	 */
	public const SUBTYPE_BLUESKY_REPOST_LIKED = 'BlueskyRepostLiked';
	public const SUBTYPE_BLUESKY_REPOST_REPOSTED = 'BlueskyRepostReposted';
	public const SUBTYPE_BLUESKY_VERIFIED = 'BlueskyVerified';
	public const SUBTYPE_BLUESKY_UNVERIFIED = 'BlueskyUnverified';
	public const SUBTYPE_BLUESKY_STARTER_PACK = 'BlueskyStarterPackJoined';

	/**
	 * Mastodon's notification type for each notification sub-type, shared by
	 * the `types`/`exclude_types` filter and the exported entity.
	 */
	private const NOTIFICATION_TYPES = [
		Like::TYPE => 'favourite',
		Announce::TYPE => 'reblog',
		Mention::TYPE => 'mention',
		Update::TYPE => 'update',
		Follow::TYPE => 'follow',
		Follow::TYPE_REQUEST => 'follow_request',
		self::SUBTYPE_POLL => 'poll',
		self::SUBTYPE_STATUS => 'status',
		self::SUBTYPE_WARNING => 'moderation_warning',
		self::SUBTYPE_SEVERED => 'severed_relationships',
		self::SUBTYPE_STORY_REACT => 'story:react',
		self::SUBTYPE_STORY_REPLY => 'story:comment',
		self::SUBTYPE_PHOTO_TAG => 'tagged',
		self::SUBTYPE_BLUESKY_REPOST_LIKED => 'bluesky:repost_liked',
		self::SUBTYPE_BLUESKY_REPOST_REPOSTED => 'bluesky:repost_reposted',
		self::SUBTYPE_BLUESKY_VERIFIED => 'bluesky:verified',
		self::SUBTYPE_BLUESKY_UNVERIFIED => 'bluesky:unverified',
		self::SUBTYPE_BLUESKY_STARTER_PACK => 'bluesky:starterpack_joined',
	];

	/**
	 * The notification types Mastodon's API does not define. Served to the web
	 * app and to a client that names them in `types[]`; left out for every
	 * other client, which may fail to decode an unknown type.
	 */
	public const FIRST_PARTY_NOTIFICATION_TYPES = [
		'story:react',
		'story:comment',
		'tagged',
		'bluesky:repost_liked',
		'bluesky:repost_reposted',
		'bluesky:verified',
		'bluesky:unverified',
		'bluesky:starterpack_joined',
	];

	private string $activityId = '';
	private string $content = '';
	private string $visibility = '';
	/** BCP 47, normalised; empty when nobody said — never a guess, see normalizeLanguage() */
	private string $language = '';
	/** when the author last edited this, ISO-8601 — the ActivityPub `updated` */
	private string $updated = '';
	private string $attributedTo = '';
	private string $inReplyTo = '';
	private string $activityPubReplyLink = '';
	/** FEP-044f: the id of the object this post quotes, empty when it quotes none */
	private string $quote = '';
	/** FEP-044f: the quoted author's stamp of approval, once one has been granted */
	private string $quoteAuthorization = '';
	private ?bool $hiddenByAuthor = null;
	private ?bool $readerMayHide = null;
	/** who the author said may quote this post; '' means the visibility rule decides */
	private string $quotePolicy = '';
	private array $attachments = [];
	/**
	 * Stores the attachments a post arrived with and answers them as
	 * `MediaAttachment`s; run once, by the first `getAttachments()` that is
	 * not held. Parsing a post writes nothing and fetches nothing.
	 */
	private ?\Closure $pendingAttachments = null;
	/** whether the pending attachments wait for `releaseAttachments()` */
	private bool $attachmentsHeld = false;
	private array $mentions = [];
	private array $emojis = [];
	private bool $sensitive = false;

	/**
	 * How many accounts have opened this post's own page, counted from
	 * `social_stream_view` for the author alone (`ViewCountService`). `null`
	 * on everybody else's copy.
	 */
	private ?int $viewCount = null;

	/**
	 * The people named in this post's photos, filled in by `MediaTagService`
	 * for a client read. A post read for the wire carries them as `Mention` tags.
	 *
	 * @var Person[]
	 */
	private array $taggedPeople = [];

	/**
	 * Why For you showed this post: the hashtags it matched and why. Null on
	 * every other timeline.
	 *
	 * @var array{tags: string[], reason: string, topics?: list<array{id: string, labels: array{en: string, de: string}}>, selection_stage?: string, explanation?: array<string, mixed>}|null
	 */
	private ?array $interest = null;

	/** @var array{position: int, duration: int, updated_at: ?string}|null */
	private ?array $watchProgress = null;

	/**
	 * How many times this video, posted here, was watched (`VideoViewService`).
	 * `null` for posts from elsewhere and posts that are not videos.
	 */
	private ?int $localVideoViews = null;

	/**
	 * Whether the author archived this post. Local only: the post stays on
	 * every server that received it; archiving only hides it from the profile here.
	 */
	private bool $archived = false;

	/**
	 * Where the post was taken, when its author said so. Zero is "nowhere";
	 * a place is never inferred.
	 */
	private int $placeId = 0;
	private ?Place $place = null;
	private string $conversation = '';
	private ?Cache $cache = null;
	private int $publishedTime = 0;
	/** when the post's own network was last asked for its counts (`counts_at`), null for never */
	private ?int $countsAt = null;
	private ?StreamAction $action = null;
	private string $timeline = '';
	private bool $filterDuplicate = false;
	private bool $pinned = false;
	/** whether the viewer muted the thread this post is in; attached per read, see `StreamRequestBuilder::markMutedConversations()` */
	private bool $mutedConversation = false;
	private ?StreamCard $card = null;
	/** null until asked; see isDeliveryHeld() */
	private ?bool $deliveryHeld = null;
	/** whether this copy was read without its `source`; see setSourceOmitted() */
	private bool $sourceOmitted = false;

	/**
	 * The emoji reactions on this post, attached by ReactionService.
	 *
	 * @var list<array{name: string, count: int, me: bool, url?: string}>
	 */
	private array $reactions = [];

	/**
	 * The parents already looked up in this request, keyed by their
	 * ActivityPub id: `{'https://…' => [status nid, author nid]}`.
	 *
	 * @var array<string, array{int, int}>
	 */
	private static array $replyParents = [];

	/**
	 * The quoted statuses already exported, keyed by ActivityPub id and the
	 * viewer they were resolved for; `null` for one not held or not visible to
	 * that viewer. The viewer is part of the key because a worker exports
	 * timelines for several actors in one process.
	 *
	 * @var array<string, ?array>
	 */
	private static array $quotedStatuses = [];

	/** @var array<string, bool> whether the reader follows an author, for this request */
	private static array $followChecks = [];

	/** @var array<string, array{0: string, 1: string}> an author's followers and following collections, for this request */
	private static array $authorCollections = [];

	/**
	 * How deep the export is inside a chain of quotes. Like Mastodon, only the
	 * first level is expanded.
	 */
	private static int $quoteDepth = 0;

	/** @var list<string> ATProto blob URLs explicitly allowed to remain streamed for this import */
	private array $atprotoStreamedBlobs = [];

	public function __construct(?ACore $parent = null) {
		parent::__construct($parent);
	}

	/** Mark only mapper-produced ATProto blob URLs as stream-only attachments. */
	public function setAtprotoStreamedBlobs(array $urls): self {
		$this->atprotoStreamedBlobs = array_values(array_unique(array_filter($urls, static fn (mixed $url): bool => is_string($url) && str_starts_with($url, 'https://'))));

		return $this;
	}

	/**
	 * @return string
	 */
	public function getActivityId(): string {
		return $this->activityId;
	}

	/**
	 * @param string $activityId
	 *
	 * @return Stream
	 */
	public function setActivityId(string $activityId): Stream {
		$this->activityId = $activityId;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getContent(): string {
		return $this->content;
	}

	/**
	 * @param string $content
	 *
	 * @return Stream
	 */
	public function setContent(string $content): Stream {
		$this->content = $content;

		return $this;
	}

	/**
	 * @param string $visibility
	 *
	 * @return Stream
	 */
	public function setVisibility(string $visibility): self {
		$this->visibility = $visibility;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getVisibility(): string {
		return $this->visibility;
	}

	/**
	 * The content warning. On ActivityPub this is the object's `summary`
	 * (also the database column), so the two accessors share one field.
	 *
	 * @return string
	 */
	public function getSpoilerText(): string {
		return $this->getSummary();
	}

	/**
	 * @param string $text
	 *
	 * @return Stream
	 */
	public function setSpoilerText(string $text): self {
		$this->setSummary($text);

		return $this;
	}

	/**
	 * The language of the content as a BCP 47 tag, or an empty string when
	 * nobody said; empty becomes `null` in the client format.
	 *
	 * @return string
	 */
	public function getLanguage(): string {
		return $this->language;
	}

	/**
	 * Anything that is not a language tag is dropped rather than federated:
	 * the value ends up as a key of `contentMap` on every other server.
	 *
	 * @param string $language
	 *
	 * @return $this
	 */
	public function setLanguage(string $language): self {
		$this->language = self::normalizeLanguage($language);

		return $this;
	}

	/**
	 * A language tag as this app will federate it: a 2–3 letter primary
	 * subtag, optionally a script and a region, cased as BCP 47 does
	 * (`pt-BR`, `zh-Hant-TW`). Anything else becomes an empty string. Loose on
	 * purpose, so no registry is needed.
	 */
	public static function normalizeLanguage(string $language): string {
		$language = str_replace('_', '-', trim($language));
		if (!preg_match('/^([a-z]{2,3})(?:-([a-z]{4}))?(?:-([a-z]{2}|\d{3}))?$/i', $language, $m)) {
			return '';
		}

		$tag = strtolower($m[1]);
		if (($m[2] ?? '') !== '') {
			$tag .= '-' . ucfirst(strtolower($m[2]));
		}
		if (($m[3] ?? '') !== '') {
			$tag .= '-' . strtoupper($m[3]);
		}

		return $tag;
	}

	/**
	 * When the author last edited the post, as the ActivityPub `updated`
	 * property; empty for a post never edited. Mastodon treats an
	 * `Update{Note}` without `updated` as a counter refresh and drops the
	 * content change, so every edit carries one and `published` stays as is.
	 *
	 * @return string
	 */
	public function getUpdated(): string {
		return $this->updated;
	}

	public function setUpdated(string $updated): self {
		$this->updated = $updated;

		return $this;
	}

	/**
	 * The `updated` column of a stream row, as the ActivityPub property. The
	 * column holds UTC, so the instant survives but not the peer's exact text.
	 * Empty when the column is NULL (never edited).
	 */
	private static function updatedFromRow(string $stored): string {
		if ($stored === '') {
			return '';
		}

		try {
			return (new DateTime($stored, new DateTimeZone('UTC')))
				->setTimezone(new DateTimeZone('UTC'))
				->format('Y-m-d\TH:i:s\Z');
		} catch (Exception) {
			return '';
		}
	}

	/**
	 * @return string
	 */
	public function getAttributedTo(): string {
		return $this->attributedTo;
	}

	/**
	 * @param string $attributedTo
	 *
	 * @return Stream
	 */
	public function setAttributedTo(string $attributedTo): Stream {
		$this->attributedTo = $attributedTo;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getInReplyTo(): string {
		return $this->inReplyTo;
	}

	/**
	 * @param string $inReplyTo
	 *
	 * @return Stream
	 */
	public function setInReplyTo(string $inReplyTo): Stream {
		$this->inReplyTo = $inReplyTo;

		return $this;
	}

	/** Keep an AT Protocol parent as a link when this post is sent over ActivityPub. */
	public function setActivityPubReplyLink(string $replyId): self {
		$this->activityPubReplyLink = BlueskyIds::isPostId($replyId) ? $replyId : '';

		return $this;
	}

	/**
	 * The id of the post this one quotes, empty when it quotes none. FEP-044f
	 * calls this `quote`; Mastodon also reads and writes `quoteUrl` and
	 * `_misskey_quote`. The quoted post is always a row of its own.
	 */
	public function getQuote(): string {
		return $this->quote;
	}

	public function setQuote(string $quote): self {
		$this->quote = $quote;

		return $this;
	}

	/**
	 * The quoted author's approval of this quote, as the URI their server
	 * returned in `Accept{QuoteRequest}`. Mastodon renders a quote without one
	 * as a bare link.
	 */
	public function getQuoteAuthorization(): string {
		return $this->quoteAuthorization;
	}

	public function setQuoteAuthorization(string $quoteAuthorization): self {
		$this->quoteAuthorization = $quoteAuthorization;

		return $this;
	}

	/**
	 * Who may quote this post: one of QUOTE_POLICIES, or '' where the author
	 * never said and the visibility rule decides. Only meaningful on a local
	 * post; a remote post's policy arrives as `interactionPolicy`.
	 */
	public function getQuotePolicy(): string {
		return $this->quotePolicy;
	}

	public function setQuotePolicy(string $quotePolicy): self {
		$this->quotePolicy = in_array($quotePolicy, self::QUOTE_POLICIES, true) ? $quotePolicy : '';

		return $this;
	}

	/**
	 * The policy with the default filled in: a post nobody was asked about is
	 * quotable if it was addressed to the public collection.
	 */
	public function effectiveQuotePolicy(): string {
		if ($this->quotePolicy !== '') {
			return $this->quotePolicy;
		}

		return $this->isQuotable() ? self::QUOTE_POLICY_PUBLIC : self::QUOTE_POLICY_NOBODY;
	}

	/**
	 * A quote state that was decided elsewhere — a refusal or a withdrawal —
	 * or an empty string when nothing was. Anything else is derived at export
	 * time from what this instance actually holds; see exportQuoteAsLocal().
	 */
	public function getQuoteState(): string {
		$state = $this->getDetailsAll()[Details::QUOTE_STATE] ?? '';

		return is_string($state) ? $state : '';
	}

	public function setQuoteState(string $state): self {
		$this->setDetail(Details::QUOTE_STATE, $state);

		return $this;
	}

	/**
	 * What kind of media a post carries, from its stored attachment JSON.
	 * Static and raw so the backfill migration can ask it of rows without
	 * building a `Stream`. A PeerTube `Video` always counts as a video, the
	 * same rule as `SocialLimitsQueryBuilder::limitToVideo()`. Types outside
	 * `MEDIA_KINDS` are skipped.
	 *
	 * @param string $attachments the stored JSON list
	 * @param string $subType the post's ActivityPub subtype
	 */
	public static function mediaKindOf(string $attachments, string $subType = ''): string {
		$kinds = [];

		if ($subType === 'Video') {
			$kinds['video'] = true;
		}

		$decoded = ($attachments === '') ? [] : json_decode($attachments, true);
		if (is_array($decoded)) {
			foreach ($decoded as $attachment) {
				if (!is_array($attachment)) {
					continue;
				}

				$type = (string)($attachment['type'] ?? '');
				if (in_array($type, self::MEDIA_KINDS, true)) {
					$kinds[$type] = true;
				}
			}
		}

		if ($kinds === []) {
			return self::MEDIA_KIND_NONE;
		}

		if (count($kinds) > 1) {
			return self::MEDIA_KIND_MIXED;
		}

		// cast because `array_key_first()` is nullable and psalm cannot see
		// that the returns above have already dealt with an empty list
		return (string)array_key_first($kinds);
	}

	/**
	 * Whether a post is a short video, from its stored attachment JSON: the
	 * rule `only_short`, the Shorts stack and `/api/v2/config` share. A short
	 * has a single `video` attachment of at most `SHORT_MAX_SECONDS` and no
	 * wider than `SHORT_MAX_ASPECT`, both read from its `meta`. A remote video
	 * is not a short until it has been cached and measured.
	 *
	 * @param string $attachments the stored JSON list
	 */
	public static function shortKindOf(string $attachments): string {
		$decoded = ($attachments === '') ? null : json_decode($attachments, true);
		if (!is_array($decoded) || count($decoded) !== 1) {
			return self::SHORT_KIND_NONE;
		}

		$attachment = reset($decoded);
		if (!is_array($attachment) || ($attachment['type'] ?? '') !== 'video') {
			return self::SHORT_KIND_NONE;
		}

		$meta = is_array($attachment['meta'] ?? null) ? $attachment['meta'] : [];
		$original = is_array($meta['original'] ?? null) ? $meta['original'] : [];

		$duration = self::positiveNumber($meta['duration'] ?? $original['duration'] ?? null);
		$width = self::positiveNumber($original['width'] ?? $meta['width'] ?? null);
		$height = self::positiveNumber($original['height'] ?? $meta['height'] ?? null);
		$aspect = ($width > 0 && $height > 0)
			? $width / $height
			: self::positiveNumber($original['aspect'] ?? $meta['aspect'] ?? null);

		if ($duration <= 0 || $duration > self::SHORT_MAX_SECONDS
			|| $aspect <= 0 || $aspect > self::SHORT_MAX_ASPECT) {
			return self::SHORT_KIND_NONE;
		}

		return self::SHORT_KIND_SHORT;
	}

	/** A number from decoded JSON, or 0 for anything that is not a positive one. */
	private static function positiveNumber(mixed $value): float {
		if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
			return 0.0;
		}

		$number = (float)$value;

		return (is_finite($number) && $number > 0) ? $number : 0.0;
	}

	/**
	 * Whether a post is news, and in which of the two senses. Static and raw
	 * for the backfill migration, as `mediaKindOf()`. The link half calls
	 * `firstLinkIn()`, the same check `LinkPreviewService` uses. Whether a card
	 * was fetched is not asked, so a post does not drop out of News because a
	 * linked site was down.
	 *
	 * @param string $content the post's stored content, markup and all
	 * @param string $subType the post's ActivityPub subtype
	 */
	public static function newsKindOf(string $content, string $subType = ''): string {
		if (in_array($subType, self::ARTICLE_SUBTYPES, true)) {
			return self::NEWS_KIND_ARTICLE;
		}

		return (self::firstLinkIn($content) === '')
			? self::NEWS_KIND_NONE
			: self::NEWS_KIND_LINK;
	}

	/**
	 * The first link in a post's content that points somewhere else, or ''.
	 * Mentions and hashtags are dropped first: the class saying which is which
	 * sits on the anchor (this app) or on a wrapping span (Mastodon). Static so
	 * a migration reading raw rows can use it.
	 */
	public static function firstLinkIn(string $content): string {
		if ($content === '') {
			return '';
		}

		$plain = preg_replace(
			[
				'/<span\b[^>]*class=["\'][^"\']*\b(?:mention|hashtag)\b[^"\']*["\'][^>]*>.*?<\/span>/is',
				'/<a\b[^>]*class=["\'][^"\']*\b(?:mention|hashtag|u-url)\b[^"\']*["\'][^>]*>.*?<\/a>/is',
			],
			' ',
			$content
		) ?? $content;

		// an anchor the composer or a remote server built
		if (preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $plain, $anchors, PREG_SET_ORDER)) {
			foreach ($anchors as $anchor) {
				$url = html_entity_decode($anchor[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
				if (self::isExternalLink($url)) {
					return $url;
				}
			}

			return '';
		}

		// plain text (a post written through the API without markup)
		if (preg_match('/https?:\/\/[^\s<>"\']+/i', strip_tags($plain), $match) === 1) {
			$url = rtrim($match[0], '.,;:!?)');

			return self::isExternalLink($url) ? $url : '';
		}

		return '';
	}

	/**
	 * A link counts when it is a plain http(s) URL to a host. Everything else
	 * (local addresses, redirects, blocked hosts) is `CurlService`'s job on the
	 * path that fetches. Public for `LinkPreviewService`'s preview image check.
	 */
	public static function isExternalLink(string $url): bool {
		$scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
		if (!in_array($scheme, ['http', 'https'], true)) {
			return false;
		}

		return (string)parse_url($url, PHP_URL_HOST) !== '';
	}

	/** What a `Video` said beyond what a post can hold, or `[]` for any other post. */
	public function getVideoMeta(): array {
		$meta = $this->getDetailsAll()[Details::VIDEO] ?? [];

		return is_array($meta) ? $meta : [];
	}

	/**
	 * The numbers a status entity carries about this post, under the same keys
	 * and with the same values its local export gives them.
	 *
	 * @return array{replies_count: int, reblogs_count: int, favourites_count: int, quotes_count: int, dislikes_count: ?int}
	 */
	public function exportCounts(): array {
		return [
			'replies_count' => $this->getDetailInt(Details::REPLIES),
			'reblogs_count' => $this->getDetailInt(Details::BOOSTS),
			'favourites_count' => $this->getDetailInt(Details::LIKES),
			'quotes_count' => $this->getDetailInt(Details::QUOTES),
			'dislikes_count' => $this->isVideo() ? $this->getDetailInt(Details::DISLIKES) : null,
		];
	}

	/**
	 * Whether this post is a video: it arrived as a `Video`, or its only kind
	 * of attachment is video.
	 */
	public function isVideo(): bool {
		if ($this->getVideoMeta() !== []) {
			return true;
		}

		foreach ($this->getAttachments() as $attachment) {
			if (str_starts_with($attachment->getMediaType(), 'video/')) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The facts a video card in a grid draws, the same for every video. Values
	 * the source did not give are null, never invented. Null for a post that
	 * is not a video; the channel is the status's own `account`.
	 *
	 * @return array{title: string, duration: ?int, thumbnail: ?string, views: ?int, live: bool, category: ?string, published: string}|null
	 */
	private function exportVideoCard(): ?array {
		if (!$this->isVideo()) {
			return null;
		}

		$meta = $this->getVideoMeta();
		$attachment = $this->videoAttachment();
		$media = $attachment?->asLocal() ?? [];

		$duration = is_numeric($meta['duration'] ?? null) ? (int)$meta['duration'] : 0;
		if ($duration <= 0) {
			$duration = (int)round((float)($attachment?->getMeta()?->getDuration() ?? 0));
		}

		// the preview of a video with no poster frame is the video itself,
		// which no `<img>` can draw
		$thumbnail = $media['preview_url'] ?? null;
		if (!is_string($thumbnail) || $thumbnail === '' || $thumbnail === ($media['url'] ?? null)) {
			$thumbnail = null;
		}

		$category = trim((string)($meta['category'] ?? ''));

		return [
			'title' => $this->videoTitle(),
			'duration' => ($duration > 0) ? $duration : null,
			'thumbnail' => $thumbnail,
			'views' => $this->videoViews($meta),
			'live' => ($meta['live'] ?? false) === true,
			'category' => ($category === '') ? null : $category,
			'published' => $this->videoPublished((string)($meta['originally_published_at'] ?? '')),
		];
	}

	/**
	 * How many times it was watched: for a video posted here, the count this
	 * server keeps (`VideoViewService`), and for one from elsewhere what its
	 * origin said. Null when neither is known.
	 *
	 * @param array<string, mixed> $meta the video block
	 */
	private function videoViews(array $meta): ?int {
		if ($this->isLocal()) {
			return $this->getLocalVideoViews();
		}

		return is_numeric($meta['views'] ?? null) ? max(0, (int)$meta['views']) : null;
	}

	/**
	 * The video block as a client gets it, with this server's own count as
	 * `views` on a local video and each subtitle track served from this server
	 * (`MediaApiController::mediaCaption()`), since the page may not load one
	 * from another host and the origin should not see who turns subtitles on.
	 *
	 * @return array<string, mixed>|null null for a post that is not a video
	 */
	private function exportVideoMeta(): ?array {
		$video = $this->getVideoMeta();
		if ($video === []) {
			return null;
		}

		if ($this->isLocal() && $this->getLocalVideoViews() !== null) {
			$video['views'] = $this->getLocalVideoViews();
		}

		if (is_array($video['captions'] ?? null)) {
			$video['captions'] = $this->exportCaptions($video['captions']);
		}

		return $video;
	}

	/**
	 * @param array<mixed> $captions as stored: `{language, url}` with the origin's url
	 *
	 * @return list<array{language: string, url: string}> the same, each url this server's
	 */
	private function exportCaptions(array $captions): array {
		$exported = [];
		foreach (array_values($captions) as $index => $caption) {
			if (!is_array($caption) || !is_string($caption['url'] ?? null) || $caption['url'] === '') {
				continue;
			}

			$url = '';
			if (!in_array((string)$this->getNid(), ['', '0'], true)) {
				try {
					$url = Server::get(IURLGenerator::class)->linkToRouteAbsolute(
						'social.MediaApi.mediaCaption', ['nid' => (string)$this->getNid(), 'index' => $index]
					);
				} catch (Throwable $e) {
				}
			}
			if ($url === '') {
				continue;
			}

			$exported[] = ['language' => (string)($caption['language'] ?? ''), 'url' => $url];
		}

		return $exported;
	}

	/**
	 * The attachment that is the video: the first of type `video`, else the
	 * first file served as one (a GIF a peer converted).
	 */
	private function videoAttachment(): ?MediaAttachment {
		$fallback = null;
		foreach ($this->getAttachments() as $attachment) {
			if ($attachment->getType() === 'video') {
				return $attachment;
			}
			if ($fallback === null && str_starts_with($attachment->getMediaType(), 'video/')) {
				$fallback = $attachment;
			}
		}

		return $fallback;
	}

	/**
	 * What the video is called: its own title, else the content warning, else
	 * the first line of the post as plain text. '' for a post with no words.
	 */
	private function videoTitle(): string {
		$title = trim((string)($this->getVideoMeta()['title'] ?? ''));
		if ($title === '') {
			$title = trim($this->getSpoilerText());
		}
		if ($title === '') {
			$text = (string)preg_replace('/<br\s*\/?>|<\/p>|<\/li>|<\/h[1-6]>/i', "\n", $this->getContent());
			$text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
			foreach (preg_split('/\R/u', $text) ?: [] as $line) {
				$line = trim((string)preg_replace('/\s+/u', ' ', $line));
				if ($line !== '') {
					$title = $line;
					break;
				}
			}
		}

		return mb_substr($title, 0, self::VIDEO_CARD_TITLE);
	}

	/**
	 * When the video was published, in `created_at`'s format: the date its
	 * author gave it where there is one (PeerTube's `originallyPublishedAt`),
	 * else when the post was made.
	 */
	private function videoPublished(string $original): string {
		if ($original !== '') {
			try {
				return gmdate('Y-m-d\TH:i:s', (new DateTime($original))->getTimestamp()) . '.000Z';
			} catch (Exception $e) {
			}
		}

		return gmdate('Y-m-d\TH:i:s', $this->getPublishedTime()) . '.000Z';
	}

	/**
	 * @param array<string, mixed> $meta
	 */
	public function setVideoMeta(array $meta): self {
		$this->setDetailArray(Details::VIDEO, $meta);

		return $this;
	}

	/**
	 * Whether replies to this post have to be approved — `approval`, or '' for
	 * the ordinary case where they do not.
	 */
	public function getReplyPolicy(): string {
		$policy = $this->getDetailsAll()[Details::REPLY_POLICY] ?? '';

		return is_string($policy) ? $policy : '';
	}

	public function setReplyPolicy(string $policy): self {
		$this->setDetail(Details::REPLY_POLICY, $policy);

		return $this;
	}

	/**
	 * Who may reply to this post (`normalizeReplyRule()`); everybody unless
	 * the author of one of this instance's own posts said otherwise.
	 */
	public function getReplyRule(): string {
		$rule = $this->getDetailsAll()[Details::REPLY_RULE] ?? '';

		return self::normalizeReplyRule(is_string($rule) ? $rule : '');
	}

	/**
	 * The parts of who may reply: none for everybody, `nobody` alone, or any
	 * of the followers, the accounts followed, the accounts mentioned and the
	 * members of one of the author's lists (`list:<id>`).
	 *
	 * @return list<string>
	 */
	public function getReplyRuleParts(): array {
		$rule = $this->getReplyRule();

		return $rule === self::REPLY_RULE_EVERYONE ? [] : explode(',', $rule);
	}

	/**
	 * A reply rule as it is kept: `everyone`, `nobody`, or a comma-separated
	 * combination of `followers`, `following`, `mentioned` and up to five
	 * `list:<id>` in that order (a Bluesky threadgate takes five rules at most).
	 * Anything else is everybody.
	 */
	public static function normalizeReplyRule(string $rule): string {
		$parts = array_values(array_unique(array_filter(array_map('trim', explode(',', strtolower($rule))))));
		if ($parts === [self::REPLY_RULE_NOBODY]) {
			return self::REPLY_RULE_NOBODY;
		}
		$kept = array_values(array_intersect([self::REPLY_RULE_FOLLOWERS, self::REPLY_RULE_FOLLOWING, self::REPLY_RULE_MENTIONED], $parts));
		foreach ($parts as $part) {
			if (preg_match('/^' . self::REPLY_RULE_LIST . '\\d{1,18}$/', $part) === 1) {
				$kept[] = $part;
			}
		}
		$kept = array_slice($kept, 0, 5);

		return $kept === [] ? self::REPLY_RULE_EVERYONE : implode(',', $kept);
	}

	/**
	 * The members of the lists a reply rule names, as they were when the rule
	 * was set: what `interactionPolicy.canReply` names for them.
	 *
	 * @param string[] $actorIds
	 */
	public function setReplyListMembers(array $actorIds): self {
		$this->setDetailArray(Details::REPLY_LIST_MEMBERS, array_slice(array_values(array_unique($actorIds)), 0, 200));

		return $this;
	}

	/**
	 * @return list<string>
	 */
	public function getReplyListMembers(): array {
		$ids = $this->getDetailsAll()[Details::REPLY_LIST_MEMBERS] ?? [];

		return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
	}

	/**
	 * The ids of the replies in this thread that its author hid, the newest
	 * 300 (as many as a Bluesky threadgate lists). Kept on the thread's first post.
	 *
	 * @return list<string>
	 */
	public function getHiddenReplies(): array {
		$ids = $this->getDetailsAll()[Details::HIDDEN_REPLIES] ?? [];

		return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
	}

	/**
	 * @param string[] $ids
	 */
	public function setHiddenReplies(array $ids): self {
		$this->setDetailArray(Details::HIDDEN_REPLIES, array_slice(array_values(array_unique($ids)), -self::HIDDEN_REPLIES_KEPT));

		return $this;
	}

	/**
	 * Whether this reply was hidden by the thread's author and whether the
	 * reader is that author, set by `StreamService::getContextByNid()`;
	 * `null` everywhere else.
	 */
	public function setThreadVisibility(bool $hiddenByAuthor, bool $readerMayHide): self {
		$this->hiddenByAuthor = $hiddenByAuthor;
		$this->readerMayHide = $readerMayHide;

		return $this;
	}

	/**
	 * The Bluesky posts quoting this one that its author detached, as their
	 * `at://` URIs: what the post's postgate lists, so every AppView shows
	 * those quotes without it. The newest fifty, as many as a postgate holds.
	 *
	 * @return list<string>
	 */
	public function getDetachedQuotes(): array {
		$uris = $this->getDetailsAll()[Details::DETACHED_QUOTES] ?? [];

		return is_array($uris) ? array_values(array_filter($uris, 'is_string')) : [];
	}

	public function addDetachedQuote(string $uri): self {
		$uris = array_values(array_diff($this->getDetachedQuotes(), [$uri]));
		$uris[] = $uri;
		$this->setDetailArray(Details::DETACHED_QUOTES, array_slice($uris, -self::DETACHED_QUOTES_KEPT));

		return $this;
	}

	public function setReplyRule(string $rule): self {
		$this->setDetail(Details::REPLY_RULE, self::normalizeReplyRule($rule));

		return $this;
	}

	/** Whether replies here need approving before anybody sees them. */
	public function repliesNeedApproval(): bool {
		return $this->getReplyPolicy() === self::REPLY_POLICY_APPROVAL;
	}

	/**
	 * Where this reply stands with the server it was sent to: `pending`,
	 * `approved`, `rejected`, or '' for a reply nobody had to approve.
	 */
	public function getReplyState(): string {
		$state = $this->getDetailsAll()[Details::REPLY_STATE] ?? '';

		return is_string($state) ? $state : '';
	}

	public function setReplyState(string $state): self {
		$this->setDetail(Details::REPLY_STATE, $state);

		return $this;
	}

	/**
	 * @return MediaAttachment[]
	 */
	public function getAttachments(): array {
		if ($this->pendingAttachments !== null && !$this->attachmentsHeld) {
			$store = $this->pendingAttachments;
			$this->pendingAttachments = null;
			$this->attachments = $store();
		}

		return $this->attachments;
	}

	/**
	 * @param MediaAttachment[] $attachments
	 *
	 * @return self
	 */
	public function setAttachments(array $attachments): self {
		$this->attachments = $attachments;
		$this->pendingAttachments = null;

		return $this;
	}

	/**
	 * Takes the attachments from `$source` when they are first read, keeping
	 * the stored ones when it returns nothing.
	 *
	 * @param \Closure(): MediaAttachment[] $source
	 */
	public function preferAttachments(\Closure $source): void {
		$previous = $this->pendingAttachments;
		$this->pendingAttachments = function () use ($source, $previous): array {
			$preferred = $source();
			if ($preferred !== []) {
				return $preferred;
			}

			return ($previous !== null) ? $previous() : $this->attachments;
		};
	}

	/**
	 * Keeps the attachments of this activity and every object inside it
	 * unstored until `releaseAttachments()`: a request whose sender is not yet
	 * held to the activity must not have this instance write rows or fetch
	 * files on its behalf. Read while held, they are empty.
	 */
	public static function holdAttachments(ACore $item, bool $held = true): void {
		for ($level = 0; $level < AP::REDUNDANCY_LIMIT; $level++) {
			if ($item instanceof Stream) {
				$item->attachmentsHeld = $held;
			}
			if (!$item->hasObject()) {
				return;
			}
			$item = $item->getObject();
		}
	}

	public static function releaseAttachments(ACore $item): void {
		self::holdAttachments($item, false);
	}

	/**
	 * @return array[] Mastodon CustomEmoji entries used in this status
	 */
	public function getEmojis(): array {
		return $this->emojis;
	}

	public function setEmojis(array $emojis): self {
		$this->emojis = $emojis;

		return $this;
	}

	public function getMentions(): array {
		return $this->mentions;
	}

	public function setMentions(array $mentions): self {
		$this->mentions = $mentions;

		return $this;
	}

	/**
	 * A post with a content warning is sensitive whether or not the flag was set.
	 *
	 * @return bool
	 */
	public function isSensitive(): bool {
		return $this->sensitive || $this->getSpoilerText() !== '';
	}

	/**
	 * Whether the author pinned this post to their profile; attached by
	 * PinService where a pin state is known.
	 */
	public function isPinned(): bool {
		return $this->pinned;
	}

	public function setPinned(bool $pinned): Stream {
		$this->pinned = $pinned;

		return $this;
	}

	/**
	 * Whether the viewer muted this post's conversation; attached by the read
	 * that knows who is reading.
	 */
	public function isMutedConversation(): bool {
		return $this->mutedConversation;
	}

	public function setMutedConversation(bool $muted): Stream {
		$this->mutedConversation = $muted;

		return $this;
	}

	/**
	 * The link preview of this post: local data attached by LinkPreviewService,
	 * never part of the wire object.
	 */
	public function getCard(): ?StreamCard {
		return $this->card;
	}

	public function setCard(?StreamCard $card): Stream {
		$this->card = $card;

		return $this;
	}

	/**
	 * Whether the post's deliveries are still waiting for its video to be
	 * converted; the author's business and nobody else's. Set by a caller
	 * that knows, or looked up once for the author's own local post.
	 */
	public function setDeliveryHeld(bool $held): Stream {
		$this->deliveryHeld = $held;

		return $this;
	}

	public function isDeliveryHeld(): bool {
		if ($this->deliveryHeld === null) {
			$this->deliveryHeld = $this->lookupDeliveryHeld();
		}

		return $this->deliveryHeld;
	}

	/**
	 * Only for the reader's own local post. `VideoDeliveryHold::isHeld()` checks
	 * the attachments before the queue, so a post without a video costs no query.
	 */
	private function lookupDeliveryHeld(): bool {
		$author = $this->getAttributedTo();
		if (!$this->isLocal() || $author === '' || $author !== self::currentViewerId()) {
			return false;
		}

		try {
			return Server::get(VideoDeliveryHold::class)->isHeld($this);
		} catch (\Throwable $e) {
			return false;
		}
	}

	/**
	 * The emoji reactions on this post: `{name, count, me}` each, most used
	 * first; local custom emoji also carry their `url`. Attached by
	 * ReactionService and never part of the wire object. Always an array, `[]`
	 * when nobody reacted.
	 *
	 * @return list<array{name: string, count: int, me: bool, url?: string}>
	 */
	public function getReactions(): array {
		return $this->reactions;
	}

	/**
	 * @param list<array{name: string, count: int, me: bool, url?: string}> $reactions
	 */
	public function setReactions(array $reactions): Stream {
		$this->reactions = $reactions;

		return $this;
	}

	public function getPlaceId(): int {
		return $this->placeId;
	}

	public function setPlaceId(int $placeId): self {
		$this->placeId = max(0, $placeId);

		return $this;
	}

	/** Filled in per page by `PlaceService::attachPlaces()`, not per post. */
	public function getPlace(): ?Place {
		return $this->place;
	}

	public function setPlace(?Place $place): self {
		$this->place = $place;
		$this->placeId = $place?->getId() ?? $this->placeId;

		return $this;
	}

	/**
	 * How many people opened this post's own page; `null` on every copy but
	 * the author's.
	 */
	public function getViewCount(): ?int {
		return $this->viewCount;
	}

	/** @return Person[] */
	public function getTaggedPeople(): array {
		return $this->taggedPeople;
	}

	/** @return array{tags: string[], reason: string, topics?: list<array{id: string, labels: array{en: string, de: string}}>, selection_stage?: string, explanation?: array<string, mixed>}|null */
	public function getInterest(): ?array {
		return $this->interest;
	}

	/** @param array{tags: string[], reason: string, topics?: list<array{id: string, labels: array{en: string, de: string}}>, selection_stage?: string, explanation?: array<string, mixed>}|null $interest */
	public function setInterest(?array $interest): self {
		$this->interest = $interest;

		return $this;
	}

	/** @return array{position: int, duration: int, updated_at: ?string}|null */
	public function getWatchProgress(): ?array {
		return $this->watchProgress;
	}

	/**
	 * Where the reader got to in this video, on the two reads that say so:
	 * "continue watching" (`WatchService::unfinished()`) and the video opened
	 * on its own (`WatchService::attachProgress()`).
	 *
	 * @param int $updated when it was last moved, as a timestamp; 0 when not known
	 */
	public function setWatchProgress(int $position, int $duration, int $updated): self {
		$this->watchProgress = [
			'position' => max(0, $position),
			'duration' => max(0, $duration),
			'updated_at' => ($updated > 0) ? gmdate('Y-m-d\TH:i:s', $updated) . '.000Z' : null,
		];

		return $this;
	}

	public function getLocalVideoViews(): ?int {
		return $this->localVideoViews;
	}

	public function setLocalVideoViews(?int $views): self {
		$this->localVideoViews = ($views === null) ? null : max(0, $views);

		return $this;
	}

	/** @param Person[] $taggedPeople */
	public function setTaggedPeople(array $taggedPeople): self {
		$this->taggedPeople = $taggedPeople;

		return $this;
	}

	public function setViewCount(?int $viewCount): self {
		$this->viewCount = $viewCount;

		return $this;
	}

	public function isArchived(): bool {
		return $this->archived;
	}

	public function setArchived(bool $archived): self {
		$this->archived = $archived;

		return $this;
	}

	public function setSensitive(bool $sensitive): Stream {
		$this->sensitive = $sensitive;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getConversation(): string {
		return $this->conversation;
	}

	/**
	 * @param string $conversation
	 *
	 * @return Stream
	 */
	public function setConversation(string $conversation): Stream {
		$this->conversation = $conversation;

		return $this;
	}

	/**
	 * @return int
	 */
	public function getPublishedTime(): int {
		return $this->publishedTime;
	}

	/**
	 * @param int $time
	 *
	 * @return Stream
	 */
	public function setPublishedTime(int $time): Stream {
		$this->publishedTime = $time;

		return $this;
	}

	public function getCountsAt(): ?int {
		return $this->countsAt;
	}

	public function setCountsAt(?int $time): Stream {
		$this->countsAt = $time;

		return $this;
	}

	/**
	 */
	public function convertPublished() {
		try {
			$dTime = new DateTime($this->getPublished());
			$this->setPublishedTime($dTime->getTimestamp());
		} catch (Exception $e) {
		}
	}

	/**
	 * @return bool
	 */
	public function hasCache(): bool {
		return ($this->cache !== null);
	}

	/**
	 * @return Cache
	 */
	public function getCache(): ?Cache {
		return $this->cache;
	}

	/**
	 * @param Cache $cache
	 *
	 * @return Stream
	 */
	public function setCache(Cache $cache): Stream {
		$this->cache = $cache;

		return $this;
	}

	public function addCacheItem(string $url): Stream {
		$cacheItem = new CacheItem($url);

		if (!$this->hasCache()) {
			$this->setCache(new Cache());
		}

		$this->getCache()
			->addItem($cacheItem);

		return $this;
	}

	public function getAction(): ?StreamAction {
		return $this->action;
	}

	public function setAction(StreamAction $action): Stream {
		$this->action = $action;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function hasAction(): bool {
		return ($this->action !== null);
	}

	/**
	 * @return string
	 */
	public function getTimeline(): string {
		return $this->timeline;
	}

	/**
	 * @param string $timeline
	 *
	 * @return Stream
	 */
	public function setTimeline(string $timeline): self {
		$this->timeline = $timeline;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isFilterDuplicate(): bool {
		return $this->filterDuplicate;
	}

	/**
	 * @param bool $filterDuplicate
	 *
	 * @return Stream
	 */
	public function setFilterDuplicate(bool $filterDuplicate): Stream {
		$this->filterDuplicate = $filterDuplicate;

		return $this;
	}

	/**
	 * @param array $data
	 */
	#[\Override]
	public function import(array $data) {
		parent::import($data);

		$this->setEmojis($this->extractEmojisFromTag($data));

		// the post replied to may be embedded rather than given by id
		$inReplyTo = $data['inReplyTo'] ?? '';
		if (is_array($inReplyTo) && !array_is_list($inReplyTo)) {
			$inReplyTo = $inReplyTo['id'] ?? '';
		}
		$this->setInReplyTo(
			$this->validate(self::AS_ID, 'inReplyTo', ['inReplyTo' => is_string($inReplyTo) ? $inReplyTo : ''], '')
		);
		$this->setQuote($this->quoteIdOf($data));
		$this->setQuoteAuthorization($this->validate(self::AS_ID, 'quoteAuthorization', $data, ''));
		$this->setQuotePolicy(self::quotePolicyOf($data));
		$this->importInteractionPolicies($data);
		// `social_stream` has no column for it, and `details` survives the round
		// trip. Only stored when it differs from the id.
		if ($this->getUrl() !== '' && $this->getUrl() !== $this->getId()) {
			$this->setDetail(Details::PAGE, $this->getUrl());
		}
		$this->setAttributedTo($this->validate(self::AS_ID, 'attributedTo', $data, ''));
		$this->setSensitive($this->getBool('sensitive', $data, false));
		$this->setObjectId($this->get('object', $data, ''));
		$this->setConversation($this->validate(self::AS_ID, 'conversation', $data, ''));
		$this->setContent($this->validate(self::AS_CONTENT, 'content', $data, ''));
		$this->setLanguage(self::languageOf($data));
		$this->setUpdated($this->validate(self::AS_DATE, 'updated', $data, ''));
		$this->importAttachments(self::listOf('attachment', $data));
		$this->convertPublished();

		$remoteLikes = self::statedCount($data, 'likes');
		if ($remoteLikes !== null) {
			$this->setDetailInt(Details::LIKES, $remoteLikes);
			$this->setDetailInt(Details::REMOTE_LIKES, $remoteLikes);
		}
		$remoteShares = self::statedCount($data, 'shares');
		if ($remoteShares !== null) {
			$this->setDetailInt(Details::BOOSTS, $remoteShares);
			$this->setDetailInt(Details::REMOTE_BOOSTS, $remoteShares);
		}
		$remoteReplies = self::statedCount($data, 'replies');
		if ($remoteReplies !== null) {
			$this->setDetailInt(Details::REPLIES, $remoteReplies);
			// the origin's own count, which `recountReplies()` adds local replies to
			$this->setDetailInt(Details::REMOTE_REPLIES, $remoteReplies);
		}
	}

	/**
	 * The three policies that are not about quoting, off the wire object. Only
	 * stored where the author said something.
	 *
	 * @param array<string, mixed> $data
	 */
	private function importInteractionPolicies(array $data): void {
		$policies = [];
		$grantees = [];
		foreach (self::INTERACTION_CLAUSES as $interaction => $clause) {
			$policy = self::policyOf($data, $clause);
			if ($policy !== '') {
				$policies[$interaction] = $policy;
			}
			if ($policy === self::QUOTE_POLICY_NOBODY) {
				$grantees[$interaction] = self::granteesOf($data, $clause);
			}
		}

		if ($policies !== []) {
			$this->setDetailArray(Details::POLICIES, $policies);
		}
		if ($grantees !== []) {
			$this->setDetailArray(Details::POLICY_GRANTEES, $grantees);
		}
	}

	/**
	 * The ids one clause's `automaticApproval` names.
	 *
	 * @param array<string, mixed> $data the wire object
	 * @return string[]
	 */
	private static function granteesOf(array $data, string $clause): array {
		$automatic = $data['interactionPolicy'][$clause]['automaticApproval'] ?? [];
		$automatic = is_array($automatic) ? $automatic : [$automatic];

		return array_values(array_filter($automatic, static fn ($id): bool => is_string($id) && $id !== ''));
	}

	/**
	 * A counter another server states, where it states a believable one: a
	 * positive number no larger than `REMOTE_COUNT_CEILING`. Anything over it
	 * is refused rather than clamped, since a clamped figure would be made up.
	 *
	 * @param array<string, mixed> $data the wire object
	 *
	 * @return int|null null where the sender said nothing believable
	 */
	public static function statedCount(array $data, string $key): ?int {
		$stated = $data[$key]['totalItems'] ?? null;
		if (!is_int($stated) && !(is_string($stated) && ctype_digit($stated))) {
			return null;
		}

		$count = (int)$stated;

		return ($count >= 0 && $count <= self::REMOTE_COUNT_CEILING) ? $count : null;
	}

	/**
	 * The language a wire object declares: a top-level `language` where a
	 * server sends one, otherwise the key of its `contentMap` — Mastodon
	 * sends nothing else. Empty when it declares none.
	 */
	private static function languageOf(array $data): string {
		// some peers send `language` as an array, which is refused rather than cast
		$declared = $data['language'] ?? '';
		$language = self::normalizeLanguage(is_string($declared) ? $declared : '');
		if ($language !== '') {
			return $language;
		}

		foreach (['contentMap', 'summaryMap'] as $map) {
			$translations = $data[$map] ?? null;
			if (!is_array($translations)) {
				continue;
			}

			// the keys are language tags; PHP turns a numeric one into an int,
			// and no language tag is a number
			foreach (array_keys($translations) as $tag) {
				if (!is_string($tag)) {
					continue;
				}

				$language = self::normalizeLanguage($tag);
				if ($language !== '') {
					return $language;
				}
			}
		}

		return '';
	}

	/**
	 * The attachments a post arrived with; none of them can take the post down.
	 * Only read here: rows are written and files fetched on the first
	 * `getAttachments()`, which for an inbox request is after the sender has
	 * been held to the activity.
	 */
	public function importAttachments(array $list): void {
		/** why each attachment that did not make it was left out */
		$dropped = [];

		$documents = [];
		$seen = 0;
		foreach ($list as $item) {
			// A peer may list any number of attachments: every entry counts
			// against the ceiling, kept or not, and the rest is not read.
			if (++$seen > self::MAX_ATTACHMENTS) {
				$dropped[] = ['reason' => 'over the ceiling', 'left out' => count($list) - self::MAX_ATTACHMENTS];
				break;
			}

			if (!is_array($item)) {
				$dropped[] = ['reason' => 'not an object'];
				continue;
			}

			// the type is checked before parsing, so an attachment that is
			// itself a post does not import attachments of its own
			$type = $this->get('type', $item);
			if ($type !== Document::TYPE && $type !== Image::TYPE) {
				$dropped[] = ['reason' => 'not a document or image', 'type' => $type];
				continue;
			}

			try {
				/** @var Document $attachment */
				$attachment = AP::instance()->getItemFromData($item, $this);
			} catch (Exception|\TypeError $e) {
				// the exception for an unknown type has no message, so log the
				// declared type
				$dropped[] = [
					'reason' => 'unreadable',
					'type' => $type,
					'url' => $this->get('url', $item, $this->get('href', $item)),
					'error' => $e->getMessage(),
				];
				continue;
			}

			if ($attachment->getType() !== Document::TYPE
				&& $attachment->getType() !== Image::TYPE) {
				$dropped[] = ['reason' => 'not a document or image', 'type' => $attachment->getType()];
				continue;
			}

			try {
				$attachment->setUrl(
					$this->validateEntryString(ACore::AS_URL, $attachment->getUrl())
				);
			} catch (InvalidResourceEntryException $e) {
				$dropped[] = ['reason' => 'url refused', 'url' => $attachment->getUrl()];
				continue;
			}

			if ($attachment->getUrl() === '') {
				$dropped[] = ['reason' => 'no url'];
				continue;
			}
			if (in_array($attachment->getUrl(), $this->atprotoStreamedBlobs, true)) {
				$attachment->setLocalCopy(Document::COPY_STREAMED);
			}

			$documents[] = $attachment;
		}

		$this->atprotoStreamedBlobs = [];
		$arrived = count($list);
		$defer = $this->deferAttachmentDownloads;
		$this->attachments = [];
		$this->pendingAttachments = function () use ($documents, $dropped, $arrived, $defer): array {
			return $this->storeAttachments($documents, $dropped, $arrived, $defer);
		};
	}

	/**
	 * Writes the documents `importAttachments()` kept and answers them as
	 * the post's attachments.
	 *
	 * @param Document[] $documents
	 * @param list<array<string, mixed>> $dropped
	 *
	 * @return MediaAttachment[]
	 */
	private function storeAttachments(array $documents, array $dropped, int $arrived, bool $deferDownloads): array {
		$urlGenerator = Server::get(IURLGenerator::class);
		$logger = Server::get(LoggerInterface::class);

		$new = [];
		foreach ($documents as $attachment) {
			try {
				$interface = AP::instance()->getInterfaceFromType($attachment->getType());
			} catch (ItemUnknownException $e) {
				$dropped[] = ['reason' => 'no interface for the type', 'type' => $attachment->getType()];
				continue;
			}

			try {
				if ($deferDownloads && $interface instanceof DocumentInterface) {
					$interface->save($attachment, true);
				} else {
					$interface->save($attachment);
				}
			} catch (Throwable $e) {
				// A failed attachment must not drop the whole post: it is left
				// out and logged, since the post then shows fewer photos than
				// it has.
				$dropped[] = [
					'reason' => 'could not be stored',
					'url' => $attachment->getUrl(),
					'error' => $e->getMessage(),
				];
				continue;
			}

			$new[] = $attachment->convertToMediaAttachment($urlGenerator);
		}

		// Log what was dropped: nothing else records why a post shows fewer
		// photos than it arrived with.
		if ($dropped !== []) {
			$logger->warning('dropped attachments while importing a post', [
				'status' => $this->getId(),
				'arrived' => $arrived,
				'kept' => count($new),
				'dropped' => $dropped,
			]);
		}

		return $new;
	}

	/**
	 * @param array $data
	 */
	#[\Override]
	public function importFromDatabase(array $data) {
		parent::importFromDatabase($data);

		try {
			$dTime = new DateTime($this->get('published_time', $data, 'yesterday'), new DateTimeZone('UTC'));
			$this->setPublishedTime($dTime->getTimestamp());
		} catch (Exception $e) {
		}
		$countsAt = $this->get('counts_at', $data, '');
		if ($countsAt !== '') {
			try {
				$this->setCountsAt((new DateTime($countsAt))->getTimestamp());
			} catch (Exception $e) {
			}
		}

		$this->setActivityId($this->validate(self::AS_ID, 'activity_id', $data, ''));
		// stored sanitized and stamped (StreamRequest); only a row under
		// another stamp, or with none, goes through the sanitizer again
		if ($this->getInt('sanitized', $data, 0) === HtmlSanitizer::version()) {
			$this->setContent($this->get('content', $data, ''));
		} else {
			$this->setContent($this->validate(self::AS_CONTENT, 'content', $data, ''));
		}
		$this->setSensitive($this->getBool('sensitive', $data, false));
		$this->setArchived($this->getBool('archived', $data, false));
		$this->setPlaceId($this->getInt('place_id', $data, 0));
		$this->setObjectId($this->validate(self::AS_ID, 'object_id', $data, ''));
		$this->setAttributedTo($this->validate(self::AS_ID, 'attributed_to', $data, ''));
		$this->setInReplyTo($this->validate(self::AS_ID, 'in_reply_to', $data));
		$this->setDetailsAll($this->getArray('details', $data, []));
		$this->overlayCounters($data);

		// These five fields have their own columns
		// (Version1000Date20260912000007); the wire object is the fallback
		// below for older rows.
		//
		// `tag`, the mentions and hashtags a post names: re-exported on every
		// Update and every outbox entry, so a post that loses it un-names them.
		$this->setTags($this->validateArray(self::AS_TAGS, 'tags', $data, []));
		// the language and the edit stamp, both rewritten by an Update — a
		// remote edit through NoteInterface as much as a local one through
		// PostService::editPost()
		$this->setLanguage($this->get('language', $data, ''));
		$this->setUpdated(self::updatedFromRow($this->get('updated', $data, '')));
		// the quote and its approval, the same kind of thing: a property of the
		// wire object, rewritten whenever the wire object is
		$this->setQuote($this->validate(self::AS_ID, 'quote', $data, ''));
		$this->setQuoteAuthorization($this->validate(self::AS_ID, 'quote_authorization', $data, ''));
		$this->setQuotePolicy($this->get('quote_policy', $data, ''));

		// What a read takes from the wire object beside the columns — the
		// custom emoji and the totals the origin stated — is kept digested in
		// `source_digest` by every write of `source`, so the whole document is
		// decoded only for a row the digest has not reached yet.
		$digest = self::storedSourceDigest($data);
		if ($digest === null) {
			$source = $this->get('source', $data, '');
			$sourceData = ($source !== '') ? json_decode($source, true) : null;
			if (is_array($sourceData)) {
				$digest = $this->digestSource($sourceData);

				// Fallback for a row stored before the columns existed and not
				// yet reached by the BackfillStreamPostFields repair step, which
				// runs after `occ upgrade`. Each field falls back on its own; a
				// row with a digest needs none of this.
				if ($this->getTags() === []) {
					$this->setTags($this->validateArray(self::AS_TAGS, 'tag', ['tag' => self::listOf('tag', $sourceData)], []));
				}
				if ($this->getLanguage() === '') {
					$this->setLanguage(self::languageOf($sourceData));
				}
				if ($this->getUpdated() === '') {
					$this->setUpdated($this->validate(self::AS_DATE, 'updated', $sourceData, ''));
				}
				if ($this->getQuote() === '') {
					$this->setQuote($this->quoteIdOf($sourceData));
				}
				if ($this->getQuoteAuthorization() === '') {
					$this->setQuoteAuthorization(
						$this->validate(self::AS_ID, 'quoteAuthorization', $sourceData, '')
					);
				}
			}
		}
		if ($digest !== null) {
			$this->applySourceDigest($digest);
		}

		$this->setFilterDuplicate($this->getBool('filter_duplicate', $data, false));
		// hydrated rather than handed on as the stored arrays, so that the one
		// place that knows how a media link is shaped gets to rebuild it: the
		// stored link is absolute and may name an address we no longer answer on
		$attachments = [];
		foreach ($this->getArray('attachments', $data, []) as $attachment) {
			$attachments[] = (new MediaAttachment())->import($attachment);
		}
		$this->setAttachments($attachments);
		$this->setMentions($this->getDetails(Details::MENTIONS));
		$this->setVisibility($this->get('visibility', $data));

		$cache = new Cache();
		$cache->import($this->getArray('cache', $data, []));
		$this->setCache($cache);
	}

	/**
	 * What `importFromDatabase()` reads out of a wire object beside the
	 * columns, as `source_digest` keeps it: the custom emoji, which come from
	 * the raw `tag` because what `AS_TAGS` validation keeps of a tag is its
	 * type, href and name and not the `icon`, and the totals the origin
	 * stated for likes, shares and replies.
	 *
	 * @param array<string, mixed> $wire the stored wire object
	 *
	 * @return array{emojis: list<array<string, mixed>>, likes?: int, shares?: int, replies?: int}
	 */
	private function digestSource(array $wire): array {
		$digest = ['emojis' => $this->extractEmojisFromTag($wire)];
		if (isset($wire['likes']['totalItems'])) {
			$digest['likes'] = (int)$wire['likes']['totalItems'];
		}
		if (isset($wire['shares']['totalItems'])) {
			$digest['shares'] = (int)$wire['shares']['totalItems'];
		}
		$replies = self::statedCount($wire, 'replies');
		if ($replies !== null) {
			$digest['replies'] = $replies;
		}

		return $digest;
	}

	/**
	 * The `source_digest` of a stored wire object, as written beside it: a
	 * JSON object, `{}` for a source that is empty or not a JSON object.
	 */
	public static function sourceDigestOf(string $source): string {
		$wire = ($source !== '') ? json_decode($source, true) : null;
		$digest = is_array($wire) ? (new self())->digestSource($wire) : [];

		return (string)json_encode((object)$digest, JSON_UNESCAPED_SLASHES);
	}

	/**
	 * The digest a row carries, or null for a row the digest has not
	 * reached, whose `source` is then read instead.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function storedSourceDigest(array $data): ?array {
		$stored = $data['source_digest'] ?? null;
		if (!is_string($stored) || $stored === '') {
			return null;
		}
		$digest = json_decode($stored, true);

		return is_array($digest) ? $digest : null;
	}

	/**
	 * Lays a digest over the post: the emoji, and the origin's totals where
	 * `details` holds none of its own (replies always, as the origin states
	 * them).
	 *
	 * @param array<string, mixed> $digest
	 */
	private function applySourceDigest(array $digest): void {
		if (array_key_exists('emojis', $digest)) {
			$this->setEmojis(is_array($digest['emojis']) ? array_values(array_filter($digest['emojis'], 'is_array')) : []);
		}

		$details = $this->getDetailsAll();
		if (!array_key_exists('remote_likes', $details) && is_int($digest['likes'] ?? null)) {
			$remoteLikes = $digest['likes'];
			$this->setDetailInt(Details::REMOTE_LIKES, $remoteLikes);
			if (!array_key_exists('likes', $details) || $details['likes'] === 0) {
				$this->setDetailInt(Details::LIKES, $remoteLikes);
			}
		}
		if (!array_key_exists('remote_boosts', $details) && is_int($digest['shares'] ?? null)) {
			$remoteBoosts = $digest['shares'];
			$this->setDetailInt(Details::REMOTE_BOOSTS, $remoteBoosts);
			if (!array_key_exists('boosts', $details) || $details['boosts'] === 0) {
				$this->setDetailInt(Details::BOOSTS, $remoteBoosts);
			}
		}
		if (is_int($digest['replies'] ?? null)) {
			$this->setDetailInt(Details::REPLIES, $digest['replies']);
			$this->setDetailInt(Details::REMOTE_REPLIES, $digest['replies']);
		}
	}

	/**
	 * Marks a copy read without its `source` (`StreamRequestBuilder`'s
	 * narrow reads): exported as a client sees it, it is complete, but it
	 * must never be written back, or the stored wire object would go with it.
	 */
	public function setSourceOmitted(bool $omitted): self {
		$this->sourceOmitted = $omitted;

		return $this;
	}

	public function isSourceOmitted(): bool {
		return $this->sourceOmitted;
	}

	/**
	 * The counter columns, laid over the `details` keys every reader of a
	 * count asks for. The column wins wherever the row has one; a column at
	 * zero adds no key.
	 */
	private function overlayCounters(array $data): void {
		$details = $this->getDetailsAll();
		foreach (self::COUNTER_COLUMNS as $key => $column) {
			if (!array_key_exists($column, $data) || !is_numeric($data[$column])) {
				continue;
			}

			$value = max(0, (int)$data[$column]);
			if ($value > 0 || array_key_exists($key, $details)) {
				$this->setDetailInt($key, $value);
			}
		}
	}

	#[\Override]
	public function importFromLocal(array $data) {
		parent::importFromLocal($data);

		$this->setId($this->get('url', $data));
		$this->setUrl($this->get('url', $data));
		$this->setLocal($this->getBool('local', $data));
		$this->setContent($this->get('content', $data));
		$this->setSensitive($this->getBool('sensitive', $data));
		$this->setSpoilerText($this->get('spoiler_text', $data));
		$this->setVisibility(self::visibilityFromClient($this->get('visibility', $data)));
		$this->setLanguage($this->get('language', $data));

		$action = new StreamAction();
		$action->updateValueBool(StreamAction::LIKED, $this->getBool('favourited', $data));
		$action->updateValueBool(StreamAction::BOOSTED, $this->getBool('reblogged', $data));
		$this->setAction($action);

		try {
			$dTime = new DateTime($this->get('created_at', $data, 'yesterday'));
			$this->setPublishedTime($dTime->getTimestamp());
		} catch (Exception $e) {
		}

		$attachments = [];
		foreach ($this->getArray('media_attachments', $data) as $dataAttachment) {
			$attachment = new MediaAttachment();
			$attachment->import($dataAttachment);
			$attachments[] = $attachment;
		}
		$this->setAttachments($attachments);

		$this->setMentions($this->getArray('mentions', $data));

		$actor = new Person();
		$actor->importFromLocal($this->getArray('account', $data));
		$actor->setExportFormat(ACore::FORMAT_LOCAL);
		$this->setActor($actor);
	}

	/**
	 * @return array
	 */
	#[\Override]
	public function exportAsActivityPub(): array {
		$result = array_merge(
			parent::exportAsActivityPub(),
			[
				'content' => $this->getContent(),
				// `attributedTo` is always a full actor URI (see PostService
				// and PollService), so it is emitted as-is.
				'attributedTo' => $this->getAttributedTo(),
				'inReplyTo' => $this->getInReplyTo(),
				'sensitive' => $this->isSensitive(),
				'conversation' => $this->getConversation(),
				'updated' => $this->getUpdated(),
			],
			$this->exportQuoteAsActivityPub(),
			$this->exportRepliesAsActivityPub(),
			$this->exportInteractionPolicy(),
			$this->exportLanguageMaps(),
			$this->exportPageAsActivityPub()
		);

		if ($this->activityPubReplyLink !== '') {
			unset($result['inReplyTo']);
			$authorId = BlueskyIds::actorId(BlueskyIds::didOf($this->activityPubReplyLink));
			$result['cc'] = array_values(array_diff($result['cc'] ?? [], [$authorId]));
			$url = htmlspecialchars($this->activityPubReplyLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
			$result['content'] = rtrim($result['content'] ?? '') . '<p><a href="' . $url . '">' . $url . '</a></p>';
		}

		// Local bookkeeping (counts seen, last action, pending fetches)
		// carried in the document; no other implementation reads it.
		if ($this->isCompleteDetails()) {
			$result = array_merge(
				$result,
				[
					'details' => $this->getDetailsAll(),
					'action' => ($this->hasAction()) ? $this->getAction() : [],
					'cache' => ($this->hasCache()) ? $this->getCache() : '',
					'publishedTime' => $this->getPublishedTime()
				]
			);
		}

		$this->cleanArray($result);

		return $result;
	}

	/**
	 * The `replies` collection, which is how a peer discovers a thread: a
	 * reply reaches only the servers holding the post it answers. Only for a
	 * local post, since a remote one's replies live under its server's ids.
	 * `first` is a URL because the export has no database to build a page.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function exportRepliesAsActivityPub(): array {
		if (!$this->isLocal() || $this->getId() === '') {
			return [];
		}

		$id = $this->getId() . self::REPLIES_PATH;

		return [
			'replies' => [
				'id' => $id,
				'type' => OrderedCollection::TYPE,
				'first' => $id . '?page=1',
			],
		];
	}

	/**
	 * `contentMap`/`summaryMap`: the text keyed by its language, the only place
	 * Mastodon looks for it. Nothing when the language is unknown, since a
	 * wrong key is worse than none, and no map for an empty text.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function exportLanguageMaps(): array {
		$language = $this->getLanguage();
		if ($language === '') {
			return [];
		}

		$maps = [];
		if ($this->getContent() !== '') {
			$maps['contentMap'] = [$language => $this->getContent()];
		}
		if ($this->getSummary() !== '') {
			$maps['summaryMap'] = [$language => $this->getSummary()];
		}

		return $maps;
	}

	/**
	 * @return array
	 */
	#[\Override]
	public function exportAsLocal(): array {
		$actions = ($this->hasAction()) ? $this->getAction()->getValues() : [];
		$favorited = false;
		$reblogged = false;
		$bookmarked = false;
		$disliked = false;
		foreach ($actions as $action => $value) {
			if ($value) {
				switch ($action) {
					case StreamAction::BOOSTED:
						$reblogged = true;
						break;
					case StreamAction::LIKED:
						$favorited = true;
						break;
					case StreamAction::DISLIKED:
						$disliked = true;
						break;
					case StreamAction::BOOKMARKED:
						$bookmarked = true;
						break;
				}
			}
		}
		[$inReplyToId, $inReplyToAccountId] = $this->resolveInReplyTo();

		$result = [
			'local' => $this->isLocal(),
			// the author's own; no list this server builds contains an archived post
			'archived' => $this->isArchived(),
			// null on every copy but the author's
			'view_count' => $this->getViewCount(),
			// Pixelfed's key, because its own app reads it
			'tagged_people' => $this->getTaggedPeople(),
			// on For you only, for the "why am I seeing this" line
			'interest' => $this->getInterest(),
			'content' => $this->getContent(),
			'sensitive' => $this->isSensitive(),
			'spoiler_text' => $this->getSpoilerText(),
			'visibility' => self::visibilityForClient($this->getVisibility()),
			// nullable in Mastodon's entity: null is "nobody said"
			'language' => ($this->getLanguage() === '') ? null : $this->getLanguage(),
			// null is "nowhere"; a place is never inferred
			'place' => $this->getPlace(),
			'in_reply_to_id' => $inReplyToId,
			'in_reply_to_account_id' => $inReplyToAccountId,
			'quote' => $this->exportQuoteAsLocal(),
			// only on our own posts: a remote post's policy is its server's
			// `interactionPolicy`
			'quote_approval' => $this->isLocal() ? $this->exportQuoteApproval() : null,
			// an extension, null on anybody else's post
			'reply_policy' => $this->isLocal() ? $this->getReplyRule() : null,
			// in a conversation: whether its author hid this reply, and
			// whether the reader is that author; null elsewhere
			'hidden_by_author' => $this->hiddenByAuthor,
			'can_hide' => $this->readerMayHide,
			// where a reply of ours stands with the server it was sent to, and
			// whether replies here need approval; null for an ordinary post
			'reply_approval' => $this->exportReplyApproval(),
			// what the author's server allows, so a client can hide a button
			// rather than offer an action that will be refused; absent on a
			// local post
			'interaction_policy' => $this->exportAllowedInteractions(),
			// PeerTube's other counter; null for anything that is not a video,
			// so no client draws a dislike button on a post
			'dislikes_count' => $this->isVideo() ? $this->getDetailInt(Details::DISLIKES) : null,
			// and whether this reader is one of them; null on non-videos
			'disliked' => $this->isVideo() ? $disliked : null,
			// null for every post that is not a video
			'video' => $this->exportVideoMeta(),
			// Aloha Social's own, see `exportVideoCard()`; null for non-videos
			'video_card' => $this->exportVideoCard(),
			// only on "continue watching" (`/api/v1/videos/continue`) and on
			// the video read on its own, null everywhere else
			'watch_progress' => $this->getWatchProgress(),
			'mentions' => $this->exportMentionsAsLocal(),
			'emojis' => $this->getEmojis(),
			'tags' => $this->exportTagsAsLocal(),
			// Aloha Social's own: whether the post says it was made with AI, by
			// a hashtag or by a photo's metadata. A label, not a judgement.
			'ai_generated' => $this->exportAiGenerated(),
			// a post read from Bluesky: its location there and its labels with
			// their labeler; null for every other post
			'bluesky' => $this->exportBluesky(),
			'replies_count' => $this->getDetailInt(Details::REPLIES),
			'reblogs_count' => $this->getDetailInt(Details::BOOSTS),
			'favourites_count' => $this->getDetailInt(Details::LIKES),
			// Mastodon 4.5's: the posts quoting it, here and where it lives
			'quotes_count' => $this->getDetailInt(Details::QUOTES),
			'favourited' => $favorited,
			'reblogged' => $reblogged,
			'muted' => $this->isMutedConversation(),
			'bookmarked' => $bookmarked,
			'pinned' => $this->isPinned(),
			'card' => $this->card?->jsonSerialize(),
			// Pleroma and Akkoma call this `emoji_reactions` and Misskey has its
			// own shape; `reactions` with `{name, count, me}` is what
			// Mastodon-family clients and this app's frontend read
			'reactions' => $this->getReactions(),
			// null, not absent: a Question overwrites it, and a client never has
			// to test for a missing key
			'poll' => null,
			'uri' => $this->getId(),
			// always sent by Mastodon: `application` is not recorded, `filtered`
			// is filled by `FilterService`, `text` only by `/statuses/{id}/source`
			// and a delete. Sent as constants because masto.js types `filtered`
			// as required and Elk and Phanpy read its length.
			'application' => null,
			'filtered' => [],
			'text' => null,
			// the page a person can open, which is not always the id: Loops,
			// Pixelfed and PeerTube give an object a separate `url`
			'url' => $this->pageUrl(),
			'reblog' => null,
			'media_attachments' => $this->getAttachments(),
			// whole seconds, as `published_time` keeps them; clients order a
			// timeline by the snowflake `id`, not by this
			'created_at' => gmdate('Y-m-d\TH:i:s', $this->getPublishedTime()) . '.000Z',
			'edited_at' => $this->editedAt(),
			'noindex' => false
		];

		// Aloha Social's own: `held` while the post's deliveries wait for its
		// video to be converted, on the author's copy only. The one exception
		// to the entity's no-missing-keys rule, because it is transient.
		if ($this->isDeliveryHeld()) {
			$result['delivery'] = 'held';
		}
		if ($this->getSubType() === 'Event') {
			// only when the remote object is an Event
			$result['event'] = ActivityPubEvent::fromStream($this);
		}
		if (in_array($this->getSubType(), self::ARTICLE_SUBTYPES, true)) {
			$title = $this->getDetailsAll()[Details::ARTICLE_TITLE] ?? '';
			if (is_string($title) && $title !== '') {
				$result['article'] = ['title' => $title, 'type' => $this->getSubType()];
			}
		}

		if ($this->hasActor()) {
			$actor = $this->getActor();
			$result['account'] = $actor->exportAsLocal();
		}

		return array_merge(parent::exportAsLocal(), $result);
	}

	/**
	 * The parent of a reply, as the two numeric ids Mastodon addresses it by;
	 * without them clients show a reply as a top-level post. Looked up from
	 * the parent's ActivityPub id and memoised for the request. Null for a
	 * parent this instance has never seen.
	 *
	 * @return array{?string, ?string}
	 */
	private function resolveInReplyTo(): array {
		$parentId = $this->getInReplyTo();
		if ($parentId === '') {
			return [null, null];
		}

		if (!array_key_exists($parentId, self::$replyParents)) {
			self::$replyParents[$parentId] = $this->lookupParent($parentId);
		}

		[$nid, $accountNid] = self::$replyParents[$parentId];

		return [
			($nid > 0) ? (string)$nid : null,
			($accountNid > 0) ? (string)$accountNid : null,
		];
	}

	/**
	 * @return array{string, string}
	 */
	private function lookupParent(string $parentId): array {
		try {
			$parent = Server::get(StreamRequest::class)->getStreamById($parentId);
			$author = $parent->hasActor() ? $parent->getActor()->getNid() : 0;

			return [(string)$parent->getNid(), (string)$author];
		} catch (\Throwable $e) {
			return ['0', '0'];
		}
	}

	/** Forgets the memoised parents; for tests, which share one process. */
	public static function resetReplyParentCache(): void {
		self::$replyParents = [];
	}

	/**
	 * The wire object as the `source` column keeps it: the ActivityPub
	 * document, whatever format this copy was read in. A copy read in the
	 * client format must not be stored as is, since the Mastodon entity has
	 * no `tag` for custom emoji and no `oneOf` for a poll.
	 */
	public function wireSource(): string {
		$format = $this->getExportFormat();
		$this->setExportFormat(self::FORMAT_ACTIVITYPUB);
		try {
			return (string)json_encode($this, JSON_UNESCAPED_SLASHES);
		} finally {
			$this->setExportFormat($format);
		}
	}

	/** Forgets the memoised quoted statuses; for tests, which share one process. */
	public static function resetQuoteCache(): void {
		self::$quotedStatuses = [];
		self::$quoteDepth = 0;
		self::$followChecks = [];
		self::$authorCollections = [];
	}

	/**
	 * The id of the quoted object, whichever of the four names a peer used and
	 * whether it is a bare id, an embedded object or a Link. `quote` is
	 * FEP-044f, `quoteUri` Fedibird's, `quoteUrl` Mastodon's alias and
	 * `_misskey_quote` Misskey's; older servers send only the aliases.
	 */
	private function quoteIdOf(array $data): string {
		foreach (['quote', 'quoteUri', 'quoteUrl', '_misskey_quote'] as $key) {
			$value = $data[$key] ?? null;
			if (is_array($value)) {
				// an embedded object names itself in `id`, a Link in `href`
				$value = $value['id'] ?? $value['href'] ?? null;
			}

			if (!is_string($value) || trim($value) === '') {
				continue;
			}

			$id = $this->validateEntryString(self::AS_ID, trim($value), false);
			if ($id !== '') {
				return $id;
			}
		}

		return '';
	}

	/**
	 * The quote as it goes onto the wire: FEP-044f's `quote`, the two aliases
	 * Mastodon emits for older readers, and the approval once there is one.
	 * Empty keys are dropped by cleanArray().
	 *
	 * @return array<string, string>
	 */
	private function exportQuoteAsActivityPub(): array {
		$quote = $this->getQuote();
		if ($quote === '') {
			return [];
		}

		return [
			'quote' => $quote,
			'quoteUrl' => $quote,
			'_misskey_quote' => $quote,
			'quoteAuthorization' => $this->getQuoteAuthorization(),
		];
	}

	/**
	 * Who may quote this post, for our own posts only. Mastodon 4.5 offers no
	 * quote button for a post without `interactionPolicy.canQuote`. Mirrors
	 * what QuoteRequestInterface answers.
	 *
	 * @return array<string, array<string, array<string, string[]>>>
	 */
	private function exportInteractionPolicy(): array {
		if (!$this->isLocal()) {
			return [];
		}

		$author = array_filter([$this->getAttributedTo()]);
		$allowed = match ($this->effectiveQuotePolicy()) {
			// the author is always allowed; naming them lets a peer see that
			self::QUOTE_POLICY_PUBLIC => array_merge([self::CONTEXT_PUBLIC], $author),
			// the followers collection, the address FEP-044f expects here.
			// Derived from the author's id, as `ActorsRequestBuilder` does,
			// because a post read from the database carries no actor object.
			self::QUOTE_POLICY_FOLLOWERS => array_merge(
				array_filter([$this->followersOfAuthor()]), $author
			),
			default => $author,
		};

		$policy = ['canQuote' => ['automaticApproval' => $allowed]];
		$canReply = $this->replyApproval();
		if ($canReply !== null) {
			$policy['canReply'] = ['automaticApproval' => $canReply];
		}

		return ['interactionPolicy' => $policy];
	}

	/**
	 * Who the author lets reply, as GoToSocial's `interactionPolicy.canReply`:
	 * the author always, beside the collection or the accounts the rule names.
	 * Null for everybody.
	 *
	 * @return string[]|null
	 */
	private function replyApproval(): ?array {
		$author = $this->getAttributedTo();
		$parts = $this->getReplyRuleParts();
		if ($parts === []) {
			return null;
		}
		$named = [];
		foreach ($parts as $part) {
			$named = array_merge($named, match (true) {
				$part === self::REPLY_RULE_FOLLOWERS => [$this->followersOfAuthor()],
				$part === self::REPLY_RULE_FOLLOWING => [$author === '' ? '' : $author . '/following'],
				$part === self::REPLY_RULE_MENTIONED => $this->addressed(),
				str_starts_with($part, self::REPLY_RULE_LIST) => $this->getReplyListMembers(),
				default => [],
			});
		}

		return array_values(array_unique(array_filter(array_merge($named, [$author]))));
	}

	/**
	 * The accounts a post is addressed to by name — whom it mentions — as
	 * against the collections it goes to.
	 *
	 * @return string[]
	 */
	public function addressed(): array {
		return array_values(array_filter(
			array_merge($this->getToArray(), $this->getCcArray()),
			fn (string $id): bool => $id !== self::CONTEXT_PUBLIC && $id !== $this->followersOfAuthor() && !str_ends_with($id, '/followers'),
		));
	}

	/**
	 * What a client needs to say "waiting to be approved", or null.
	 *
	 * @return array{policy: string, state: string}|null
	 */
	private function exportReplyApproval(): ?array {
		$policy = $this->getReplyPolicy();
		$state = $this->getReplyState();

		if ($policy === '' && $state === '') {
			return null;
		}

		return ['policy' => $policy, 'state' => $state];
	}

	/** The author's followers collection, as every local actor publishes it. */
	private function followersOfAuthor(): string {
		$author = $this->getAttributedTo();

		return ($author === '') ? '' : $author . '/followers';
	}

	/**
	 * Mastodon's `quote_approval` on a status of ours: who is approved
	 * automatically, who would have to be asked, and where the reader stands.
	 * `manual` is always empty: a `QuoteRequest` is answered on arrival, so
	 * no quote ever waits.
	 *
	 * @param string $viewerId the reader's actor id, or '' for nobody
	 * @param bool $viewerFollows whether that reader follows the author
	 *
	 * @return array{automatic: string[], manual: string[], current_user: string}
	 */
	public function exportQuoteApproval(?string $viewerId = null, ?bool $viewerFollows = null): array {
		$policy = $this->effectiveQuotePolicy();
		$automatic = ($policy === self::QUOTE_POLICY_NOBODY) ? [] : [$policy];

		$viewerId ??= self::currentViewerId();
		// the follow is only looked up for the `followers` policy, so a
		// timeline does not cost a query per post
		$viewerFollows ??= ($policy === self::QUOTE_POLICY_FOLLOWERS)
			&& self::viewerFollows($viewerId, $this->getAttributedTo());

		return [
			'automatic' => $automatic,
			'manual' => [],
			'current_user' => $this->mayBeQuotedBy($viewerId, $viewerFollows) ? 'automatic' : 'denied',
		];
	}

	/**
	 * What may be done with somebody else's post, in the shape a client reads.
	 * Null for a local post or a remote one whose server publishes no policies.
	 *
	 * @return array<string, bool>|null
	 */
	private function exportAllowedInteractions(): ?array {
		if ($this->isLocal()) {
			return null;
		}

		$policy = [];
		foreach (array_keys(self::INTERACTION_CLAUSES) as $interaction) {
			if ($this->getInteractionPolicy($interaction) !== '') {
				$policy[$interaction] = $this->allowsInteraction($interaction);
			}
		}

		return ($policy === []) ? null : $policy;
	}

	/**
	 * Who is reading, as the stream reads were scoped for, or '' where there
	 * is nobody or no container (a unit test, a command).
	 */
	private static function currentViewerId(): string {
		try {
			return Server::get(StreamRequest::class)->getViewerId();
		} catch (\Throwable $e) {
			return '';
		}
	}

	/**
	 * Whether the reader follows an author, memoised for the request, since a
	 * timeline repeats the same authors.
	 */
	private static function viewerFollows(string $viewerId, string $authorId): bool {
		if ($viewerId === '' || $authorId === '' || $viewerId === $authorId) {
			return false;
		}

		$key = $viewerId . "\0" . $authorId;
		if (array_key_exists($key, self::$followChecks)) {
			return self::$followChecks[$key];
		}

		try {
			$follow = Server::get(FollowsRequest::class)->getByPersons($viewerId, $authorId);
			self::$followChecks[$key] = $follow->isAccepted();
		} catch (\Throwable $e) {
			self::$followChecks[$key] = false;
		}

		return self::$followChecks[$key];
	}

	/**
	 * Whether one account may quote this post without being asked: the author
	 * always, beyond that the policy. The same rule `QuoteRequestInterface`
	 * applies, so `quote_approval.current_user` and a peer's `QuoteRequest`
	 * get the same answer.
	 */
	public function mayBeQuotedBy(string $askerId, bool $askerFollows = false): bool {
		if ($askerId !== '' && $askerId === $this->getAttributedTo()) {
			return true;
		}

		return match ($this->effectiveQuotePolicy()) {
			self::QUOTE_POLICY_PUBLIC => true,
			self::QUOTE_POLICY_FOLLOWERS => $askerFollows,
			default => false,
		};
	}

	/**
	 * The page of a post as `url`, which Mastodon always sends and Loops
	 * needs: it keeps a remote video's address only when `url` is a string,
	 * and later interactions name the video by it. A boost is not a page of
	 * its own.
	 *
	 * @return array{url?: string}
	 */
	private function exportPageAsActivityPub(): array {
		if ($this->getType() === Announce::TYPE) {
			return [];
		}
		$page = $this->pageUrl();

		return ($page === '') ? [] : ['url' => $page];
	}

	/**
	 * The page a person can open for this post: on the object when freshly
	 * imported, in `details` when read from the database, else the id.
	 */
	public function pageUrl(): string {
		if ($this->getUrl() !== '') {
			return $this->getUrl();
		}

		$stored = $this->getDetailsAll()[Details::PAGE] ?? '';

		return is_string($stored) && $stored !== '' ? $stored : $this->getId();
	}

	/**
	 * Whether anyone may read the post: public or unlisted, as against
	 * followers-only and direct.
	 */
	public function isOpenToAnyone(): bool {
		return in_array($this->getVisibility(), [self::TYPE_PUBLIC, self::TYPE_UNLISTED], true);
	}

	/**
	 * Whether this post is open enough to be quoted: addressed to the public
	 * collection, as `PinService::pin()` and `BoostService::create()` require,
	 * since a quote carries it to the quoter's audience.
	 */
	public function isQuotable(): bool {
		// a remote post whose `canQuote` says nobody would be refused by its
		// server after the quote was already shown to its writer
		if (!$this->isLocal() && $this->getQuotePolicy() === self::QUOTE_POLICY_NOBODY) {
			return false;
		}

		return $this->isPublic() || $this->isOpenToAnyone();
	}

	/**
	 * Who the author says may quote their post, from `interactionPolicy`
	 * (GoToSocial, Mastodon 4.5, Loops, FEP-044f). Only `automaticApproval`
	 * naming the public collection means anyone; anything narrower means ask
	 * the author, and `manualApproval` is not a yes because this app cannot
	 * wait for the answer. No policy leaves the visibility rule to decide.
	 */
	private static function quotePolicyOf(array $data): string {
		return self::policyOf($data, 'canQuote');
	}

	/**
	 * One clause of an `interactionPolicy`, as this app understands it.
	 * `automaticApproval` naming the public collection is the only blanket
	 * "yes" (see `quotePolicyOf()`); whom a narrower list names is kept
	 * (`granteesOf()`) and decided per reader by `allowsInteraction()`.
	 *
	 * @param array<string, mixed> $data the wire object
	 * @param string $clause `canReply`, `canAnnounce`, `canLike` or `canQuote`
	 *
	 * @return string one of the policy constants, or '' where the author said
	 *                nothing at all — which is not the same as "nobody"
	 */
	private static function policyOf(array $data, string $clause): string {
		$stated = $data['interactionPolicy'][$clause] ?? null;
		if (!is_array($stated)) {
			return '';
		}

		$automatic = $stated['automaticApproval'] ?? [];
		$automatic = is_array($automatic) ? $automatic : [$automatic];
		foreach ($automatic as $allowed) {
			if (is_string($allowed) && $allowed === self::CONTEXT_PUBLIC) {
				return self::QUOTE_POLICY_PUBLIC;
			}
		}

		return self::QUOTE_POLICY_NOBODY;
	}

	/**
	 * What the author allows, for an interaction other than quoting. Kept in
	 * `details` because few posts carry it and nothing queries by it.
	 *
	 * @return string '' where the author said nothing, which is every post
	 *                from a server that does not publish policies
	 */
	public function getInteractionPolicy(string $interaction): string {
		$stored = $this->getDetailsAll()[Details::POLICIES][$interaction] ?? '';

		return is_string($stored) ? $stored : '';
	}

	/**
	 * Whether this instance should offer an interaction to one reader. True
	 * where the author said nothing and for anything local, where the policy
	 * is applied on arrival. A narrower policy is still a yes for whom it
	 * names: the reader by id, the author's followers collection when the
	 * reader follows, the following collection when the author follows the
	 * reader (how GoToSocial writes followers-only and direct posts).
	 *
	 * @param string|null $viewerId the reader's actor id; the reader the
	 *                              stream reads were scoped for when null
	 */
	public function allowsInteraction(string $interaction, ?string $viewerId = null): bool {
		if ($this->isLocal() || $this->getInteractionPolicy($interaction) !== self::QUOTE_POLICY_NOBODY) {
			return true;
		}

		$viewerId ??= self::currentViewerId();
		$author = $this->getAttributedTo();
		if ($viewerId === '' || $author === '') {
			return false;
		}
		if ($viewerId === $author
			|| ($interaction === self::INTERACTION_REPLY && in_array($viewerId, $this->addressed(), true))) {
			return true;
		}

		$grantees = $this->getDetailsAll()[Details::POLICY_GRANTEES][$interaction] ?? [];
		$grantees = is_array($grantees) ? $grantees : [];
		if (in_array($viewerId, $grantees, true)) {
			return true;
		}

		[$followers, $following] = $this->authorCollections();

		return ($followers !== '' && in_array($followers, $grantees, true) && self::viewerFollows($viewerId, $author))
			|| ($following !== '' && in_array($following, $grantees, true) && self::viewerFollows($author, $viewerId));
	}

	/**
	 * The author's followers and following collections, from the actor the
	 * post was read with or else from the actor cache.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function authorCollections(): array {
		$authorId = $this->getAttributedTo();
		if (array_key_exists($authorId, self::$authorCollections)) {
			return self::$authorCollections[$authorId];
		}

		$author = $this->hasActor() ? $this->getActor() : null;
		if ($author === null || $author->getId() !== $authorId) {
			try {
				$author = Server::get(CacheActorsRequest::class)->getFromId($authorId);
			} catch (\Throwable $e) {
				return ['', ''];
			}
		}

		return self::$authorCollections[$authorId] = [$author->getFollowers(), $author->getFollowing()];
	}

	/**
	 * Mastodon's Quote entity, or null for a post that quotes nothing. The
	 * lookup runs as the viewer, so a quote cannot be used to read somebody's
	 * followers-only post.
	 *
	 * @return ?array{state: string, quoted_status: ?array}
	 */
	private function exportQuoteAsLocal(): ?array {
		if ($this->getQuote() === '') {
			return null;
		}

		$state = $this->getQuoteState();
		if ($state === self::QUOTE_REJECTED || $state === self::QUOTE_REVOKED) {
			return ['state' => $state, 'quoted_status' => null];
		}

		// The approval, not holding the quoted post, makes a quote accepted:
		// FEP-044f renders a quote without one as a link. Bluesky asks nobody's
		// permission, so a quote there or of a post there stands until detached.
		if ($this->getQuoteAuthorization() === '' && !self::isBlueskyPost($this->getId()) && !self::isBlueskyPost($this->getQuote())) {
			return ['state' => self::QUOTE_PENDING, 'quoted_status' => null];
		}

		// Approved, but the post may be missing here or closed to this reader;
		// still not `pending`, since the author has answered.
		return ['state' => self::QUOTE_ACCEPTED, 'quoted_status' => $this->resolveQuoted()];
	}

	/**
	 * The quoted post as a status entity, or null when this instance does not
	 * hold it or the viewer may not see it.
	 */
	private function resolveQuoted(): ?array {
		if (self::$quoteDepth > 0) {
			return null;
		}

		$streamRequest = Server::get(StreamRequest::class);
		$key = $streamRequest->getViewerId() . "\0" . $this->getQuote();
		if (array_key_exists($key, self::$quotedStatuses)) {
			return self::$quotedStatuses[$key];
		}

		self::$quoteDepth++;
		try {
			$quoted = $streamRequest->getStreamById($this->getQuote(), true, ACore::FORMAT_LOCAL);
			self::$quotedStatuses[$key] = $quoted->exportAsLocal();
		} catch (\Throwable $e) {
			self::$quotedStatuses[$key] = null;
		} finally {
			self::$quoteDepth--;
		}

		return self::$quotedStatuses[$key];
	}

	/**
	 * @return array{uri: string, url: string, labels: list<array{src: string, val: string}>}|null
	 */
	private function exportBluesky(): ?array {
		$details = $this->getDetails(Details::ATPROTO);
		$uri = (string)($details['uri'] ?? '');
		if ($uri === '' || !str_starts_with($this->getId(), 'https://bsky.app/profile/')) {
			return null;
		}
		$labels = [];
		foreach (is_array($details['label_sources'] ?? null) ? $details['label_sources'] : [] as $label) {
			if (is_array($label) && is_string($label['src'] ?? null) && is_string($label['val'] ?? null)) {
				$labels[] = ['src' => $label['src'], 'val' => $label['val']];
			}
		}

		return ['uri' => $uri, 'url' => $this->pageUrl(), 'labels' => $labels];
	}

	private static function isBlueskyPost(string $id): bool {
		return str_starts_with($id, 'https://bsky.app/profile/') && str_contains($id, '/post/');
	}

	/**
	 * Whether this post is labelled as made with AI, using the built-in
	 * defaults where there is no container to ask.
	 */
	private function exportAiGenerated(): bool {
		try {
			return Server::get(AiContentService::class)->labelsPost($this);
		} catch (\Throwable $e) {
			return AiContentService::labels($this, AiContentService::DEFAULT_TAGS);
		}
	}

	/** The hashtags as Mastodon's Tag entities. */
	private function exportTagsAsLocal(): array {
		$tags = [];
		foreach ($this->getHashtags() as $hashtag) {
			$hashtag = ltrim(trim((string)$hashtag), '#');
			if ($hashtag === '') {
				continue;
			}

			$tags[] = ['name' => $hashtag, 'url' => $this->hashtagUrl($hashtag)];
		}

		return $tags;
	}

	private function hashtagUrl(string $hashtag): string {
		try {
			return Server::get(IURLGenerator::class)->linkToRouteAbsolute(
				'social.Navigation.timeline', ['path' => 'tags/' . $hashtag]
			);
		} catch (\Throwable $e) {
			return '';
		}
	}

	/**
	 * The mentions, with every id a string: an unresolvable mention is stored
	 * with an integer `0`, which a client typing the field as a string fails
	 * to decode, and the whole status with it.
	 */
	private function exportMentionsAsLocal(): array {
		return array_map(
			static function (array $mention): array {
				$mention['id'] = (string)($mention['id'] ?? '0');

				return $mention;
			},
			array_values(array_filter($this->getMentions(), 'is_array'))
		);
	}

	/**
	 * When this status was last edited, or null if it never was: the
	 * ActivityPub `updated`, or for older rows a `published` that differs from
	 * `published_time`.
	 */
	private function editedAt(): ?string {
		if ($this->getUpdated() !== '') {
			try {
				return gmdate('Y-m-d\TH:i:s', (new DateTime($this->getUpdated()))->getTimestamp()) . '.000Z';
			} catch (Exception $e) {
				return null;
			}
		}

		$published = $this->getPublished();
		if ($published === '' || $this->getPublishedTime() === 0) {
			return null;
		}

		try {
			$edited = (new DateTime($published))->getTimestamp();
		} catch (Exception $e) {
			return null;
		}

		// a second of slack: creating a post writes both from the same moment,
		// but not from the same value
		if ($edited - $this->getPublishedTime() < 2) {
			return null;
		}

		return gmdate('Y-m-d\TH:i:s', $edited) . '.000Z';
	}

	/**
	 * The first-party notification types a third-party client did not ask
	 * for, to be excluded from its page.
	 *
	 * @param string[] $types the client's `types[]`
	 *
	 * @return string[]
	 */
	public static function notificationTypesKeptFromClients(array $types): array {
		return array_values(array_diff(self::FIRST_PARTY_NOTIFICATION_TYPES, $types));
	}

	/**
	 * Mastodon's name for a notification sub-type, or an empty string for a
	 * sub-type Mastodon has no notification for.
	 */
	public static function notificationTypeOfSubType(string $subType): string {
		return self::NOTIFICATION_TYPES[$subType] ?? '';
	}

	/**
	 * The notification sub-types that the given Mastodon notification types
	 * select. Unrecognised types select nothing, so a caller that asked only
	 * for those gets an empty list back and must not query at all.
	 *
	 * @param string[] $types
	 *
	 * @return string[]
	 */
	public static function subTypesOfNotificationTypes(array $types): array {
		$subTypes = array_flip(self::NOTIFICATION_TYPES);

		return array_values(array_intersect_key($subTypes, array_flip($types)));
	}

	#[\Override]
	public function exportAsNotification(): array {
		$type = self::notificationTypeOfSubType($this->getSubType());

		$result = [
			'id' => (string)$this->getNid(),
			'type' => $type,
			'created_at' => gmdate('Y-m-d\TH:i:s', $this->getPublishedTime()) . '.000Z',
			'status' => $this->getObject(),
		];

		if ($this->hasActor()) {
			$actor = $this->getActor();
			$result['account'] = $actor->exportAsLocal();
		}

		return array_merge(parent::exportAsNotification(), $result);
	}

	#[\Override]
	public function jsonSerialize(): array {
		$result = parent::jsonSerialize();

		// `attachment` is the ActivityPub name; the client format carries them
		// as `media_attachments`
		if ($this->getExportFormat() !== self::FORMAT_LOCAL) {
			// always as Documents: a post read from the database has them in
			// the local format, which a peer cannot read
			$result['attachment'] = array_map(
				static fn (MediaAttachment|array $attachment): array => self::asWireDocument($attachment),
				$this->getAttachments()
			);
		}

		return $result;
	}

	/**
	 * One attachment as the wire carries it. A caller may hand the list over
	 * as arrays already shaped for the wire; those go through as they are.
	 *
	 * @param MediaAttachment|array<string, mixed> $attachment
	 * @return array<string, mixed>
	 */
	private static function asWireDocument(MediaAttachment|array $attachment): array {
		if ($attachment instanceof MediaAttachment) {
			return $attachment->asDocument();
		}

		return $attachment;
	}

	/**
	 * The client format is a fixed key set: unlike an ActivityPub document,
	 * nothing is dropped by `cleanArray()`, because clients treat Mastodon's
	 * keys as required and fail to decode a status missing one.
	 */
	#[\Override]
	protected function cleanArray(array &$arr) {
		if ($this->getExportFormat() === self::FORMAT_LOCAL) {
			return;
		}

		parent::cleanArray($arr);
	}
}
