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
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidResourceEntryException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Mention;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\Place;
use OCA\Social\Model\Details;
use OCA\Social\Model\StreamAction;
use OCA\Social\Model\StreamCard;
use OCA\Social\Service\VideoDeliveryHold;
use OCA\Social\Tools\IQueryRow;
use OCA\Social\Tools\Model\Cache;
use OCA\Social\Tools\Model\CacheItem;
use OCA\Social\Traits\TDetails;
use OCP\IURLGenerator;
use OCP\Server;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Class Stream
 *
 * @package OCA\Social\Model\ActivityPub
 */
class Stream extends ACore implements IQueryRow, JsonSerializable {
	/**
	 * The six values `social_stream.media_kind` holds.
	 *
	 * The four `MediaAttachment` types this app stores, plus `''` for a post
	 * carrying nothing and `mixed` for one carrying more than one kind — a post
	 * with a photograph and a video is both, and a column has to choose.
	 *
	 * **Closed, and it has to be.** The column is seven characters wide, and
	 * what fills it is read out of the stored attachment JSON — which was
	 * written by older versions of this app and by every server it federates
	 * with. `Document::convertToMediaAttachment()` has narrowed an attachment's
	 * type to these four since "Files as attachments", but a row written before
	 * that kept the whole first half of the MIME type, so a PDF attached in
	 * 0.23.0 is stored as `"type":"application"` — eleven characters, and on
	 * MySQL in strict mode an upgrade that ends in *Data too long for column
	 * media_kind*.
	 */
	public const MEDIA_KIND_NONE = '';
	public const MEDIA_KIND_MIXED = 'mixed';

	/**
	 * The attachment types that are a kind of their own. Everything else is a
	 * file a client cannot render, which is what `unknown` has meant since the
	 * conversion learnt to say it.
	 */
	public const MEDIA_KINDS = ['image', 'video', 'audio', 'gifv'];

	/**
	 * The three values `social_stream.news_kind` holds.
	 *
	 * `article` is a post that *is* an article: a `Article` or `Page` object,
	 * which is what Plume, WriteFreely, Ghost and the WordPress plugin publish
	 * and which arrives here as a `Note` carrying that word in `subtype` (see
	 * `AP::NOTE_LIKE_TYPES`). `link` is a post that *points at* one — an
	 * ordinary note whose text carries an external link, which is how most
	 * news actually travels on the fediverse. `''` is neither.
	 *
	 * Two values rather than one because they are not the same thing and a
	 * reader may well want only the first; the News timeline asks for both.
	 */
	public const NEWS_KIND_NONE = '';
	public const NEWS_KIND_LINK = 'link';
	public const NEWS_KIND_ARTICLE = 'article';

	/** The subtypes that make a post an article in its own right. */
	public const ARTICLE_SUBTYPES = ['Article', 'Page'];

	use TDetails;

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
	 * The states Mastodon's Quote entity can be in. A quote is `accepted` once
	 * the quoted author's server has approved it — and, here, once the quoted
	 * post is one this instance holds and the reader may see; `pending` while
	 * the approval or the post itself is still being waited for; `rejected`
	 * when the author refused and `revoked` when they took the approval back.
	 */
	public const QUOTE_ACCEPTED = 'accepted';
	public const QUOTE_PENDING = 'pending';
	public const QUOTE_REJECTED = 'rejected';
	public const QUOTE_REVOKED = 'revoked';

	/**
	 * Who the author has said may quote this post, as Mastodon 4.5 names the
	 * three choices its composer offers.
	 *
	 * `''` is the fourth state and the one almost every stored post is in:
	 * nobody was ever asked, so the answer is the one this app gave before the
	 * question existed — the visibility rule, public and unlisted posts being
	 * quotable and nothing else.
	 */
	/**
	 * The largest counter this app will repeat from another server: ten
	 * million, which is some five orders of magnitude above the most-boosted
	 * post the fediverse has produced.
	 */
	public const REMOTE_COUNT_CEILING = 10000000;

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

	/** This reply is waiting to be approved, has been, or was refused. */
	public const REPLY_PENDING = 'pending';
	public const REPLY_APPROVED = 'approved';
	public const REPLY_REJECTED = 'rejected';

	/**
	 * How many attachments a single post may bring in. Twice what Mastodon
	 * lets an author attach, so nothing real is ever cut.
	 */
	// Ten, which is Pixelfed's album ceiling: a ten-picture album federated
	// from there used to arrive here two pictures short.
	public const MAX_ATTACHMENTS = 10;

	/**
	 * Mastodon calls a followers-only post `private`; this app has always
	 * called it `followers`. The two vocabularies have to be translated in
	 * both directions: without it a client's followers-only post arrives as a
	 * value this app does not know, and `StreamService::setRecipient()` used
	 * to address exactly those to the public collection.
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
	 * vocabulary. Anything unrecognised becomes `direct` — the most
	 * restrictive option. Guessing wrong in the other direction publishes
	 * somebody's private post to the whole Fediverse.
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
	 * The hashtags on this item.
	 *
	 * Only a Note actually stores any — it overrides this — but the tags belong
	 * to the exported status entity, which is built here, and `StreamRequest`
	 * asks any Stream for them when it writes one.
	 *
	 * @return string[]
	 */
	public function getHashtags(): array {
		return [];
	}

	/**
	 * Translate this app's vocabulary back into what a client expects.
	 *
	 * An empty one is answered `public`. Mastodon's `Status` entity has four
	 * legal visibilities and `''` is not among them, so a row written without
	 * one -- an import, a seed, anything that reached `social_stream` by a
	 * path that did not set it -- handed every client a value it could not
	 * read. This one stopped offering Boost on the post, because the composer
	 * and the timeline both ask whether the post is public before they offer
	 * to republish it; a post with nine boosts on it was not boostable here.
	 *
	 * `public` rather than the cautious `direct` because this is a *label*,
	 * not a permission: what may actually be read, forwarded or boosted is
	 * decided against the stored value everywhere it matters --
	 * `ForwardService::shouldForward()`, the visibility filter every timeline
	 * query carries -- and none of that goes through here. Two other places
	 * had already made the same call for the same reason: `PixelfedService`'s
	 * scope mapping and `AccountService`'s default posting privacy.
	 */
	public static function visibilityForClient(string $visibility): string {
		return match ($visibility) {
			self::TYPE_FOLLOWERS => 'private',
			'' => self::TYPE_PUBLIC,
			default => $visibility,
		};
	}

	/**
	 * Mastodon's notification type for each notification sub-type. Kept as one
	 * map so the `types`/`exclude_types` API filter and the exported entity
	 * cannot drift apart.
	 */
	/**
	 * A notification this instance raises that is not an activity anybody
	 * sent: a poll of yours closing, an account you asked about posting, a
	 * moderator warning you, or a block cutting your follows. Mastodon
	 * documents a type for each; there is no ActivityPub verb for any of them,
	 * so the subtype is this app's own name for the event.
	 */
	public const SUBTYPE_POLL = 'PollClosed';
	public const SUBTYPE_STATUS = 'NewStatus';
	public const SUBTYPE_WARNING = 'ModerationWarning';
	public const SUBTYPE_SEVERED = 'SeveredRelationships';

	/**
	 * Somebody answered one of your stories. Pixelfed's own two names, because
	 * these arrive from Pixelfed and a client that knows them already draws
	 * them the way their sender meant.
	 */
	public const SUBTYPE_STORY_REACT = 'StoryReaction';
	public const SUBTYPE_STORY_REPLY = 'StoryReply';

	/** Somebody named you in a photograph. */
	public const SUBTYPE_PHOTO_TAG = 'PhotoTag';

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
	/** FEP-044f: the id of the object this post quotes, empty when it quotes none */
	private string $quote = '';
	/** FEP-044f: the quoted author's stamp of approval, once one has been granted */
	private string $quoteAuthorization = '';
	/** who the author said may quote this post; '' means the visibility rule decides */
	private string $quotePolicy = '';
	private array $attachments = [];
	private array $mentions = [];
	private array $emojis = [];
	private bool $sensitive = false;

	/**
	 * How many accounts have opened this post's own page.
	 *
	 * Not stored on the row and not federated: it is counted from
	 * `social_stream_view` and put here for the author alone — see
	 * `ViewCountService`. `null` on everybody else's copy, which is how the
	 * client entity tells "nobody has read it" from "this is not yours to
	 * know".
	 */
	private ?int $viewCount = null;

	/**
	 * The people named in this post's pictures.
	 *
	 * Filled in by `MediaTagService` where a post is read for a client, and
	 * empty otherwise — a post read for the wire carries its names as
	 * `Mention` tags, which is where a peer looks for them.
	 *
	 * @var Person[]
	 */
	private array $taggedPeople = [];

	/**
	 * Why For you put this post in front of the reader: the hashtags it
	 * matched and whether they were learned, followed, related or trending.
	 * Null everywhere else, which is every other timeline.
	 *
	 * @var array{tags: string[], reason: string}|null
	 */
	private ?array $interest = null;

	/**
	 * Whether the author has put this post away.
	 *
	 * Local and never federated: an archived post is still on every server
	 * that received it, because taking it back from them is what `Delete` is
	 * for and is a different decision. What archiving says is "not on my
	 * profile here any more".
	 */
	private bool $archived = false;

	/**
	 * Where the post was taken, when its author said so. Zero is "nowhere",
	 * which is what almost every post is: a place is never inferred.
	 */
	private int $placeId = 0;
	private ?Place $place = null;
	private string $conversation = '';
	private ?Cache $cache = null;
	private int $publishedTime = 0;
	private ?StreamAction $action = null;
	private string $timeline = '';
	private bool $filterDuplicate = false;
	private bool $pinned = false;
	private ?StreamCard $card = null;
	/** null until asked; see isDeliveryHeld() */
	private ?bool $deliveryHeld = null;

	/**
	 * The emoji reactions on this post, attached by ReactionService.
	 *
	 * @var list<array{name: string, count: int, me: bool}>
	 */
	private array $reactions = [];

	/**
	 * The parents already looked up in this request, keyed by their
	 * ActivityPub id: `{'https://…' => [status nid, author nid]}`.
	 *
	 * A timeline of replies to the same thread would otherwise ask the database
	 * for the same parent once per reply.
	 *
	 * @var array<string, array{int, int}>
	 */
	private static array $replyParents = [];

	/**
	 * The quoted statuses already exported in this request, keyed by their
	 * ActivityPub id **and the viewer it was resolved for**; `null` for one this
	 * instance does not hold, or that the viewer may not see. A quote that goes
	 * round tends to be quoted by several of the posts on one page.
	 *
	 * The viewer is half the key because the value is a filtered copy: one
	 * request serves one reader under PHP-FPM, but a worker process or a queued
	 * job exports timelines for several actors in a row, and a memo keyed by
	 * post alone would hand the first reader's copy to the second.
	 *
	 * @var array<string, ?array>
	 */
	private static array $quotedStatuses = [];

	/** @var array<string, bool> whether the reader follows an author, for this request */
	private static array $followChecks = [];

	/**
	 * How deep the export currently is inside a chain of quotes. A quote of a
	 * quote of a quote is a lookup and a nested entity per level; Mastodon
	 * stops after the first and so does this.
	 */
	private static int $quoteDepth = 0;

	public function __construct(?ACore $parent = null) {
		parent::__construct($parent);
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
	 * (which is also the database column), so the two accessors share one field —
	 * a remote CW survives the AP import and a local one survives the save.
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
	 * nobody said. This used to default to `'en'`, so every local post
	 * federated as English and every remote post was shown as English
	 * whatever it was written in; an empty value is the honest answer and
	 * becomes `null` in the client format, which Mastodon allows.
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
	 * A language tag as this app is willing to federate it: a 2–3 letter
	 * primary subtag, optionally a script and a region, cased the way BCP 47
	 * does (`pt-BR`, `zh-Hant-TW`). Anything else — a language name, markup,
	 * a variant this app does not understand — becomes an empty string, so
	 * the caller's default applies. Loose on purpose: Mastodon's own list is
	 * mostly ISO 639-1 with a handful of regional variants, and a stricter
	 * check would need a registry that ages.
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
	 * property; empty for a post that was never edited.
	 *
	 * Mastodon reads an `Update{Note}` without `updated` as an implicit
	 * update — it refreshes poll counters and discards the content change —
	 * so every edit has to carry one, and `published` has to stay what it
	 * was: it is the creation time, and moving it is not an edit to anybody.
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
	 * The `updated` column of a stream row, as the ActivityPub property.
	 *
	 * The column is a datetime and holds UTC — `StreamRequest` converts before
	 * it binds — so the instant survives, while the exact text a peer sent does
	 * not: an `updated` of `2026-09-12T10:00:00+02:00` reads back as
	 * `2026-09-12T08:00:00Z`. That is the same moment written the way this app
	 * writes its own edits (`PostService::editPost()`), and nothing anywhere
	 * compares the two as strings.
	 *
	 * Empty for a row with no edit time, which is `NULL` in the column and the
	 * ordinary case: most posts are never edited.
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

	/**
	 * The id of the post this one quotes, empty when it quotes none.
	 *
	 * FEP-044f calls this `quote`; Mastodon 4.5 emits that name and keeps
	 * emitting the older `quoteUrl`/`_misskey_quote` aliases beside it, and
	 * reads any of them. Only ever an id here: the quoted post is a row of its
	 * own, fetched like a reply's parent, never a copy embedded in this one.
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
	 * handed back in `Accept{QuoteRequest}`. Mastodon dereferences it before it
	 * renders the quoted post inline, so a quote that has one is shown as a
	 * card and one that has none is shown as a bare link.
	 */
	public function getQuoteAuthorization(): string {
		return $this->quoteAuthorization;
	}

	public function setQuoteAuthorization(string $quoteAuthorization): self {
		$this->quoteAuthorization = $quoteAuthorization;

		return $this;
	}

	/**
	 * Who may quote this post — one of QUOTE_POLICIES, or '' where the author
	 * never said and the visibility rule decides.
	 *
	 * Only meaningful on a post of ours: somebody else's server says who may
	 * quote theirs, and what it says arrives as `interactionPolicy` on their
	 * document rather than here.
	 */
	public function getQuotePolicy(): string {
		return $this->quotePolicy;
	}

	public function setQuotePolicy(string $quotePolicy): self {
		$this->quotePolicy = in_array($quotePolicy, self::QUOTE_POLICIES, true) ? $quotePolicy : '';

		return $this;
	}

	/**
	 * The policy as it stands, with the default filled in.
	 *
	 * A post nobody was asked about keeps the answer this app gave before the
	 * question existed: quotable if it was addressed to the public collection,
	 * and not otherwise.
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
	 * What a `Video` said beyond what a post can hold, or `[]` for every post
	 * that is not one.
	 *
	 * @return array<string, mixed>
	 */
	/**
	 * What kind of media a post carries, from the attachment list it was
	 * stored with.
	 *
	 * Static, and taking the raw JSON, because the backfill migration asks the
	 * same question of rows it reads straight out of the database without
	 * building a `Stream` for each of ten million of them.
	 *
	 * A PeerTube `Video` counts as a video whether or not this instance found
	 * a file in it a browser can play: its whole existence is the video, and it
	 * arrives as a `Note` carrying `Video` in `subtype`. That is the same rule
	 * `SocialLimitsQueryBuilder::limitToVideo()` applies, kept in step here
	 * because the two must not come to disagree about what a video is.
	 *
	 * Only `MEDIA_KINDS` are kinds. An attachment of any other type is
	 * skipped, exactly as `unknown` is — which is what this app already
	 * decides for a PDF attached *today*, so an old row and a new one now
	 * agree about the same file. Before this, the whole of whatever an older
	 * version happened to store went into a seven-character column, and an
	 * instance with a PDF in its history could not be upgraded at all.
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
		// that the two returns above have already dealt with an empty list
		return (string)array_key_first($kinds);
	}

	/**
	 * Whether a post is news, and in which of the two senses.
	 *
	 * Static and taking the raw columns, for the same reason `mediaKindOf()`
	 * is: the backfill migration asks this of rows it reads straight out of
	 * the database without building a `Stream` for each of ten million of
	 * them.
	 *
	 * The article half is the post's own type. The link half is "does the text
	 * carry a link to somewhere else", which is exactly the question
	 * `LinkPreviewService` asks before it fetches a preview — so it is asked
	 * here by calling the same code (`firstLinkIn()`), and the News timeline
	 * and the card under a post cannot come to disagree about whether a post
	 * links anywhere.
	 *
	 * Deliberately *not* asked: whether a card was fetched successfully. Cards
	 * are read from the linked page after the post is stored and may never
	 * arrive — the page may be slow, private, or refuse this instance — and a
	 * post that dropped out of the News timeline hours after being posted,
	 * because somebody else's web server was down, would be worse than one
	 * that is there without a picture.
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
	 * The first link in a post's content that points somewhere else, or '' when
	 * it carries none.
	 *
	 * A mention and a hashtag are links too, so both are dropped whole before
	 * anything is looked for: the class that says which is which sits on the
	 * anchor (this app) or on a wrapping span (Mastodon).
	 *
	 * This lived in `LinkPreviewService`, which still asks it and is still the
	 * only thing that *fetches* anything. It is here because two things now
	 * need the answer and one of them is a migration reading raw rows, which
	 * cannot build a service.
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
	 * A link counts when it is a plain http(s) URL to a host. Everything
	 * beyond that — local addresses, redirects to other protocols, oversized
	 * bodies, blocked hosts — is `CurlService`'s job on the one path that
	 * fetches the page.
	 *
	 * Public because `LinkPreviewService` asks the same of the picture a page
	 * offers, which is a URL from the same untrusted source.
	 */
	public static function isExternalLink(string $url): bool {
		$scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
		if (!in_array($scheme, ['http', 'https'], true)) {
			return false;
		}

		return (string)parse_url($url, PHP_URL_HOST) !== '';
	}

	public function getVideoMeta(): array {
		$meta = $this->getDetailsAll()[Details::VIDEO] ?? [];

		return is_array($meta) ? $meta : [];
	}

	/**
	 * Whether this post is a video, in the sense the watch page means.
	 *
	 * Either it arrived as one — a `Video`, which leaves the metadata block
	 * behind — or it carries a video as its only kind of attachment, which is
	 * what an upload here produces.
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
		return $this->attachments;
	}

	/**
	 * @param MediaAttachment[] $attachments
	 *
	 * @return self
	 */
	public function setAttachments(array $attachments): self {
		$this->attachments = $attachments;

		return $this;
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
	 * A content warning is itself a statement that the body should not be
	 * shown unasked, which is what `sensitive` means to a client — so a post
	 * carrying one is sensitive whether or not the flag was set as well.
	 *
	 * @return bool
	 */
	public function isSensitive(): bool {
		return $this->sensitive || $this->getSpoilerText() !== '';
	}

	/**
	 * Whether the author pinned this post to their profile. Only the author
	 * can pin, so the flag is a property of the post rather than of the
	 * viewer; it is attached by PinService where a pin state is known.
	 */
	public function isPinned(): bool {
		return $this->pinned;
	}

	public function setPinned(bool $pinned): Stream {
		$this->pinned = $pinned;

		return $this;
	}

	/**
	 * The link preview of this post. Cards are local, derived data attached
	 * by LinkPreviewService where one is known — never part of the wire
	 * object.
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
	 * Only for the reader's own local post, which is the one case the answer
	 * is anybody's to see: `VideoDeliveryHold::isHeld()` looks at the
	 * attachments before it asks the queue, so a post without a video costs
	 * no query at all.
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
	 * first.
	 *
	 * Local, derived data attached by ReactionService the way a card is, and
	 * never part of the wire object — an `EmojiReact` is its own activity, and
	 * a peer counts the ones it has received itself.
	 *
	 * The key is always present, as an array: a client should never have to
	 * tell "no reactions" from "this server does not do reactions", and a post
	 * nobody has reacted to answers `[]`.
	 *
	 * @return list<array{name: string, count: int, me: bool}>
	 */
	public function getReactions(): array {
		return $this->reactions;
	}

	/**
	 * @param list<array{name: string, count: int, me: bool}> $reactions
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
	 * How many people opened this post's own page.
	 *
	 * `null` on everybody else's copy: how many people read a post is the
	 * author's business.
	 */
	public function getViewCount(): ?int {
		return $this->viewCount;
	}

	/** @return Person[] */
	public function getTaggedPeople(): array {
		return $this->taggedPeople;
	}

	/** @return array{tags: string[], reason: string}|null */
	public function getInterest(): ?array {
		return $this->interest;
	}

	/** @param array{tags: string[], reason: string}|null $interest */
	public function setInterest(?array $interest): self {
		$this->interest = $interest;

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

		// the post replied to may come embedded rather than by id — both are
		// the vocabulary's — and read as a string the thread link was lost
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
		// `social_stream` has no column for it, and `details` is the one thing
		// on the row that survives the round trip and is already read back
		// with it. Only stored when it says something the id does not.
		if ($this->getUrl() !== '' && $this->getUrl() !== $this->getId()) {
			$this->setDetail(Details::PAGE, $this->getUrl());
		}
		$this->setAttributedTo($this->validate(self::AS_ID, 'attributedTo', $data, ''));
		$this->setSensitive($this->getBool('sensitive', $data, false));
		$this->setObjectId($this->get('object', $data, ''));
		$this->setConversation($this->validate(self::AS_ID, 'conversation', $data, ''));
		$this->setContent($this->get('content', $data, ''));
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
			// and the origin's own half of the pair, the way likes and shares
			// keep theirs: `recountReplies()` adds this instance's replies to
			// what the origin said, and with the origin's number never stored
			// it added them to nothing — the total the origin had published
			// was replaced by the count of the replies held here
			$this->setDetailInt(Details::REMOTE_REPLIES, $remoteReplies);
		}
	}

	/**
	 * The three policies that are not about quoting, off the wire object.
	 *
	 * Only stored where the author said something: an empty `policies` blob on
	 * every post from every server that publishes none would be a row larger
	 * for no reason.
	 *
	 * @param array<string, mixed> $data
	 */
	private function importInteractionPolicies(array $data): void {
		$policies = [];
		foreach (self::INTERACTION_CLAUSES as $interaction => $clause) {
			$policy = self::policyOf($data, $clause);
			if ($policy !== '') {
				$policies[$interaction] = $policy;
			}
		}

		if ($policies !== []) {
			$this->setDetailArray(Details::POLICIES, $policies);
		}
	}

	/**
	 * A counter another server states, where it states a believable one.
	 *
	 * `likes`, `shares` and `replies` arrive as collections with a
	 * `totalItems`, and whatever is in there is what every reader on this
	 * instance is shown — there is no way to verify it and no attempt to. That
	 * is fine for a number that is roughly right and useless for one that is
	 * not: a server that states four billion favourites is not describing a
	 * post, it is writing in somebody else's timeline, and the figure sits in
	 * the database until the post is deleted.
	 *
	 * So a count has to be a number, it has to be positive, and it has to be
	 * small enough to be a count of something. The ceiling is far above
	 * anything the fediverse has produced — the most-boosted post in its
	 * history is five orders of magnitude below it — and deliberately not a
	 * judgement about what is plausible for *this* post. What is over it is
	 * refused rather than clamped: a number nobody can believe is worse than
	 * no number, because clamping would state a figure this instance made up.
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
		// a peer is free to send anything here, and some send `language` as an
		// array; casting that to a string is a PHP warning and an empty
		// language either way, so it is refused rather than cast
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
	 * The attachments a post arrived with, each one stored and none of them
	 * able to take the post down with it.
	 */
	public function importAttachments(array $list): void {
		$urlGenerator = Server::get(IURLGenerator::class);
		$logger = Server::get(LoggerInterface::class);
		/** why each attachment that did not make it was left out */
		$dropped = [];

		$new = [];
		foreach ($list as $item) {
			// A signed Create is authenticated, not trusted: a peer may list as
			// many attachments as it likes, and each one is a row written and a
			// file queued for download inside the inbox request. Real posts carry
			// a handful (Mastodon allows four), so the rest of a list of fifty
			// thousand is dropped rather than imported.
			if (count($new) >= self::MAX_ATTACHMENTS) {
				break;
			}

			if (!is_array($item)) {
				$dropped[] = ['reason' => 'not an object'];
				continue;
			}

			try {
				/** @var Document $attachment */
				$attachment = AP::instance()->getItemFromData($item, $this);
			} catch (Exception|\TypeError $e) {
				// the exception for an unknown type carries no message, so the
				// type the sender declared is what makes this line worth
				// having at all
				$dropped[] = [
					'reason' => 'unreadable',
					'type' => (string)($item['type'] ?? ''),
					'url' => (string)($item['url'] ?? $item['href'] ?? ''),
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

			try {
				$interface = AP::instance()->getInterfaceFromType($attachment->getType());
			} catch (ItemUnknownException $e) {
				$dropped[] = ['reason' => 'no interface for the type', 'type' => $attachment->getType()];
				continue;
			}

			try {
				$interface->save($attachment);
			} catch (Throwable $e) {
				// A post is not its attachments. Storing one means fetching a
				// file from another server, which fails in every way a request
				// can, and letting that out of here dropped the whole Create:
				// the text, the thread it belongs to and the notification with
				// it, over one picture. The attachment that could not be
				// stored is left out and the post is kept -- but said out loud,
				// because the post then shows fewer pictures than it has and
				// nothing else anywhere says why.
				$dropped[] = [
					'reason' => 'could not be stored',
					'url' => $attachment->getUrl(),
					'error' => $e->getMessage(),
				];
				continue;
			}

			$new[] = $attachment->convertToMediaAttachment($urlGenerator);
		}

		// A post that arrived with pictures and is stored with fewer is a
		// thing readers report and nobody can diagnose: every way out of the
		// loop above is silent, and the post keeps its own frozen copy of what
		// survived, so there is nothing to go back to afterwards either.
		if ($dropped !== []) {
			$logger->warning('dropped attachments while importing a post', [
				'status' => $this->getId(),
				'arrived' => count($list),
				'kept' => count($new),
				'dropped' => $dropped,
			]);
		}

		$this->setAttachments($new);
	}

	/**
	 * @param array $data
	 */
	#[\Override]
	public function importFromDatabase(array $data) {
		parent::importFromDatabase($data);

		try {
			$dTime = new DateTime($this->get('published_time', $data, 'yesterday'));
			$this->setPublishedTime($dTime->getTimestamp());
		} catch (Exception $e) {
		}

		$this->setActivityId($this->validate(self::AS_ID, 'activity_id', $data, ''));
		$this->setContent($this->validate(self::AS_CONTENT, 'content', $data, ''));
		$this->setSensitive($this->getBool('sensitive', $data, false));
		$this->setArchived($this->getBool('archived', $data, false));
		$this->setPlaceId($this->getInt('place_id', $data, 0));
		$this->setObjectId($this->validate(self::AS_ID, 'object_id', $data, ''));
		$this->setAttributedTo($this->validate(self::AS_ID, 'attributed_to', $data, ''));
		$this->setInReplyTo($this->validate(self::AS_ID, 'in_reply_to', $data));
		$this->setDetailsAll($this->getArray('details', $data, []));

		// Five fields that used to be read out of the stored wire object and
		// nowhere else, because none of them had a column;
		// Version1000Date20260912000007 gave each one its own, and the column
		// is what is read now. The reasoning that put them in the JSON is kept
		// below, at the fallback, because the fallback is still what a row
		// written before that step is read through.
		//
		// `tag`, the mentions and hashtags a post names: re-exported on every
		// Update and every outbox entry, so a post that loses it tells its
		// peers that the people it names are not named by it.
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

		$source = $this->get('source', $data, '');
		if ($source !== '') {
			$sourceData = json_decode($source, true);
			if (is_array($sourceData)) {
				// Emoji still come from the raw wire object rather than from
				// the `tags` column: an emoji tag carries an `icon`, and what
				// `AS_TAGS` validation keeps of a tag is its type, href and
				// name — the column holds the post's tags as the model holds
				// them, which is already without the icons.
				$this->setEmojis($this->extractEmojisFromTag($sourceData));

				// The fallback, for a row stored before the columns existed and
				// not yet reached by the BackfillStreamPostFields repair step:
				// the schema change lands during `occ upgrade` and the backfill
				// runs after it, so an instance serves reads in between. Each
				// field falls back on its own, because a post may legitimately
				// have four of the five empty.
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
				$details = $this->getDetailsAll();
				if (!array_key_exists('remote_likes', $details) && isset($sourceData['likes']['totalItems'])) {
					$remoteLikes = (int)$sourceData['likes']['totalItems'];
					$this->setDetailInt(Details::REMOTE_LIKES, $remoteLikes);
					if (!array_key_exists('likes', $details) || $details['likes'] === 0) {
						$this->setDetailInt(Details::LIKES, $remoteLikes);
					}
				}
				if (!array_key_exists('remote_boosts', $details) && isset($sourceData['shares']['totalItems'])) {
					$remoteBoosts = (int)$sourceData['shares']['totalItems'];
					$this->setDetailInt(Details::REMOTE_BOOSTS, $remoteBoosts);
					if (!array_key_exists('boosts', $details) || $details['boosts'] === 0) {
						$this->setDetailInt(Details::BOOSTS, $remoteBoosts);
					}
				}
				$remoteReplies = self::statedCount($sourceData, 'replies');
				if ($remoteReplies !== null) {
					$this->setDetailInt(Details::REPLIES, $remoteReplies);
					$this->setDetailInt(Details::REMOTE_REPLIES, $remoteReplies);
				}
			}
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

		//		"in_reply_to_id" => null,
		//			"in_reply_to_account_id" => null,
		//			'replies_count' => 0,
		//			'reblogs_count' => 0,
		//			'favourites_count' => 0,
		//			'muted' => false,
		//			'bookmarked' => false,
		//			"reblog" => null,
		//			'noindex' => false

		$attachments = [];
		foreach ($this->getArray('media_attachments', $data) as $dataAttachment) {
			$attachment = new MediaAttachment();
			$attachment->import($dataAttachment);
			$attachments[] = $attachment;
		}
		$this->setAttachments($attachments);

		$this->setMentions($this->getArray('mentions', $data));

		// import from cache with new format !
		$actor = new Person();
		$actor->importFromLocal($this->getArray('account', $data));
		$actor->setExportFormat(ACore::FORMAT_LOCAL);
		$this->setActor($actor);
		//		$this->setCompleteDetails(true);
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
				// `attributedTo` is always set to a full actor URI (see
				// PostService and PollService), so it is emitted as-is. It used
				// to be prefixed with `urlSocial`, which produced a valid value
				// only because nothing ever calls `setUrlSocial()` on a stream —
				// that is done on Person rows alone. The day anything did, every
				// outgoing post would have carried a doubled URL and remote
				// thread resolution would have stopped working.
				'attributedTo' => $this->getAttributedTo(),
				'inReplyTo' => $this->getInReplyTo(),
				'sensitive' => $this->isSensitive(),
				'conversation' => $this->getConversation(),
				'updated' => $this->getUpdated(),
			],
			$this->exportQuoteAsActivityPub(),
			$this->exportRepliesAsActivityPub(),
			$this->exportInteractionPolicy(),
			$this->exportLanguageMaps()
		);

		// Bookkeeping this app keeps about a post — the counts it has seen, the
		// last action taken on it, what it still has to fetch — carried in the
		// document rather than beside it. No other implementation reads any of
		// it, and taking it back out means knowing which of the callers that
		// ask for complete details are reading it, so it stays until each of
		// those has been walked.
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
	 * The `replies` collection, which is how a peer discovers a thread.
	 *
	 * Everything else publishes one and reads it: a reply reaches the instances
	 * that hold the post it answers and nowhere else, so a reader on a third
	 * instance sees a post with no replies unless there is a collection to walk.
	 * Without it, a conversation that starts here is a conversation only the
	 * participants' own servers ever see whole.
	 *
	 * Only for a local post. A remote one's replies live on the server that
	 * holds it, under an id of its choosing; naming a collection here that this
	 * instance does not serve, under an id it does not own, would send every
	 * reader to a 404.
	 *
	 * `first` is a URL rather than an inlined page: the page it names is the
	 * one the route serves, and a page written out here would have to be
	 * queried for during a model export that has no database.
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
	 * `contentMap`/`summaryMap`: the text keyed by its language, which is how
	 * ActivityPub says what language a post is in and the only place Mastodon
	 * looks. Nothing when the language is unknown — a wrong key is worse than
	 * none — and no map for an empty text.
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
			// the author's own; nobody else is ever handed an archived post,
			// because no list this server builds contains one
			'archived' => $this->isArchived(),
			// null on everybody else's copy: how many people read a post is
			// the author's business
			'view_count' => $this->getViewCount(),
			// who is in the picture. Pixelfed's `tagged_people`, and the same
			// key, because its own app reads it
			'tagged_people' => $this->getTaggedPeople(),
			// what matched, on For you only; the web interface draws the
			// "why am I seeing this" line from it
			'interest' => $this->getInterest(),
			'content' => $this->getContent(),
			'sensitive' => $this->isSensitive(),
			'spoiler_text' => $this->getSpoilerText(),
			'visibility' => self::visibilityForClient($this->getVisibility()),
			// nullable in Mastodon's entity, and null is what "nobody said" is
			'language' => ($this->getLanguage() === '') ? null : $this->getLanguage(),
			// null is "nowhere", which is what almost every post is: a place is
			// never inferred, only stated
			'place' => $this->getPlace(),
			'in_reply_to_id' => $inReplyToId,
			'in_reply_to_account_id' => $inReplyToAccountId,
			'quote' => $this->exportQuoteAsLocal(),
			// who may quote this one. Only on our own posts: somebody else's
			// server decides who may quote theirs, and what it decided rides
			// on their document as `interactionPolicy` rather than here
			'quote_approval' => $this->isLocal() ? $this->exportQuoteApproval() : null,
			// where a reply of ours stands with the server it was sent to, and
			// whether replies here have to be approved at all. Null for the
			// ordinary post, which is almost every post: a client that has to
			// test for a key it will almost never see is a client that will
			// get it wrong.
			'reply_approval' => $this->exportReplyApproval(),
			// what the author's own server says may be done with this post, so
			// a client can leave out a button rather than offer an action that
			// will be refused. Absent on a local post, where the policy is
			// this instance's to apply when the interaction arrives
			'interaction_policy' => $this->exportAllowedInteractions(),
			// PeerTube's other counter. Null for everything that is not a
			// video, which is almost every post: Mastodon has never had a
			// dislike, and a key full of zeroes would invite a client to draw
			// a button for one
			'dislikes_count' => $this->isVideo() ? $this->getDetailInt(Details::DISLIKES) : null,
			// and whether this reader is one of them; null on anything that is
			// not a video, for the same reason
			'disliked' => $this->isVideo() ? $disliked : null,
			// what a video is, beyond being a post with a file on it: null for
			// every post that is not one, which is almost all of them
			'video' => ($video = $this->getVideoMeta()) === [] ? null : $video,
			'mentions' => $this->exportMentionsAsLocal(),
			'emojis' => $this->getEmojis(),
			'tags' => $this->exportTagsAsLocal(),
			'replies_count' => $this->getDetailInt(Details::REPLIES),
			'reblogs_count' => $this->getDetailInt(Details::BOOSTS),
			'favourites_count' => $this->getDetailInt(Details::LIKES),
			'favourited' => $favorited,
			'reblogged' => $reblogged,
			'muted' => false,
			'bookmarked' => $bookmarked,
			'pinned' => $this->isPinned(),
			'card' => $this->card?->jsonSerialize(),
			// Pleroma and Akkoma call this `emoji_reactions` and Misskey sends
			// its own shape; `reactions` with `{name, count, me}` is what the
			// Mastodon-family clients that support them read, and it is what
			// this app's own frontend draws
			'reactions' => $this->getReactions(),
			// null, not absent. A Question overwrites this with the real poll;
			// every other status has to carry the key, because the entity's
			// rule here is that a client never has to test for a missing one
			'poll' => null,
			'uri' => $this->getId(),
			// the page a person can open, which is not always the id. Loops
			// posts are `.../ap/users/1/video/3268…` with a `url` of
			// `loops.video/v/i9co_4TqPk`, and Pixelfed and PeerTube do the
			// same; sending the id here pointed "open original" at a JSON
			// document. Mastodon's two fields mean two different things and
			// this app had them meaning one.
			'url' => $this->pageUrl(),
			'reblog' => null,
			'media_attachments' => $this->getAttachments(),
			// seconds, with the milliseconds Mastodon's shape asks for written
			// as zero: `published_time` is what the row keeps, and the full
			// string the sender wrote is kept beside it in `published`, so
			// nothing is lost by this being the coarser of the two. Clients
			// order a timeline by the snowflake `id`, not by this.
			'created_at' => gmdate('Y-m-d\TH:i:s', $this->getPublishedTime()) . '.000Z',
			'edited_at' => $this->editedAt(),
			'noindex' => false
		];

		// Aloha Social's own: `held` while the post's deliveries wait for its
		// video to be converted, on the author's copy only. Absent everywhere
		// else — including on the same post once it has gone out — because
		// this is a transient state of one person's post, not a property of
		// a status, and the one exception to the entity's no-missing-keys rule
		if ($this->isDeliveryHeld()) {
			$result['delivery'] = 'held';
		}

		if ($this->hasActor()) {
			$actor = $this->getActor();
			$result['account'] = $actor->exportAsLocal();
		}

		return array_merge(parent::exportAsLocal(), $result);
	}

	/**
	 * The parent of a reply, as the two numeric ids Mastodon addresses it by.
	 *
	 * These were hard-coded to null, and a reply with no `in_reply_to_id` is
	 * not a reply: Elk and Phanpy render it as a fresh top-level post, and the
	 * status handed back from `POST /statuses` lost the link the client needed
	 * to slot it under the post being answered. The row only carries the
	 * parent's ActivityPub id, so the numbers come from a lookup — memoised for
	 * the request, since a thread's replies all name the same parent.
	 *
	 * A parent this instance has never seen has no numeric id here, and null is
	 * then the honest answer.
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

	/** Forgets the memoised quoted statuses; for tests, which share one process. */
	public static function resetQuoteCache(): void {
		self::$quotedStatuses = [];
		self::$quoteDepth = 0;
	}

	/**
	 * The id of the quoted object, whichever of the four names a peer used for
	 * it, and whether it arrived as a bare id, an embedded object or a Link.
	 *
	 * `quote` is FEP-044f and what Mastodon 4.5 reads first; `quoteUri` is
	 * Fedibird's, `quoteUrl` Mastodon's own alias, `_misskey_quote` Misskey's.
	 * Mastodon emits the last two beside `quote` to this day, and a server that
	 * predates FEP-044f sends nothing else.
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
	 * Mastodon still emits for readers that predate it, and the approval once
	 * there is one. Nothing at all for a post that quotes nothing — the keys
	 * are dropped by cleanArray().
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
	 * Who may quote this post, for our own posts only — somebody else's server
	 * says who may quote theirs.
	 *
	 * Mastodon 4.5 offers no quote button at all for a post that carries no
	 * `interactionPolicy.canQuote`, so a post from here would be unquotable
	 * however open it is. The policy mirrors what QuoteRequestInterface
	 * actually answers: anyone, automatically, for a post addressed to the
	 * public collection, and nobody but the author for anything narrower.
	 *
	 * @return array<string, array<string, array<string, string[]>>>
	 */
	private function exportInteractionPolicy(): array {
		if (!$this->isLocal()) {
			return [];
		}

		$author = array_filter([$this->getAttributedTo()]);
		$allowed = match ($this->effectiveQuotePolicy()) {
			// the author is always allowed; naming them beside the collection
			// is what lets a peer see that without special-casing it
			self::QUOTE_POLICY_PUBLIC => array_merge([self::CONTEXT_PUBLIC], $author),
			// the followers collection, which is the address FEP-044f expects
			// here and the one a peer can dereference to check itself.
			// Derived from the author's id rather than read off a hydrated
			// actor — `ActorsRequestBuilder` mints it the same way, and a post
			// read out of the database carries no actor object at all
			self::QUOTE_POLICY_FOLLOWERS => array_merge(
				array_filter([$this->followersOfAuthor()]), $author
			),
			default => $author,
		};

		return ['interactionPolicy' => ['canQuote' => ['automaticApproval' => $allowed]]];
	}

	/**
	 * What a client needs to say "waiting to be approved" — or null, which is
	 * almost every post.
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
	 *
	 * `manual` is always empty, and honestly so: this app answers a
	 * `QuoteRequest` in the moment it arrives and has no queue for an author to
	 * work through, so every quote is either allowed or refused and none of
	 * them waits. Saying otherwise would put a "requested" state in a client
	 * that nothing here would ever resolve.
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
		// the follow is only looked up where the answer turns on it, which is
		// the `followers` policy and nothing else: a timeline of forty posts
		// must not cost forty queries to say who may quote them
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
	 *
	 * Null where there is nothing to say — a local post, or a remote one whose
	 * server publishes no policies, which is most of them. A client that has
	 * to test for a key it will almost never see is a client that gets it
	 * wrong, so it is absent rather than full of `true`.
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
	 * Who is reading, as the stream reads were scoped for — or '' where there
	 * is nobody, which is also what a context with no container is (a model
	 * exported in a unit test, or by a command that never opened one).
	 */
	private static function currentViewerId(): string {
		try {
			return Server::get(StreamRequest::class)->getViewerId();
		} catch (\Throwable $e) {
			return '';
		}
	}

	/**
	 * Whether the reader follows an author, memoised for the request.
	 *
	 * One timeline is one author repeated, so without the memo a page of
	 * somebody's followers-only posts would ask the same question forty times.
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
	 * Whether one account may quote this post without being asked.
	 *
	 * The author always may — quoting your own post is how a thread is picked
	 * up later — and beyond that it is the policy: anybody, the people who
	 * follow the author, or nobody.
	 *
	 * This is the rule `QuoteRequestInterface` applies when it answers, so a
	 * client that reads `quote_approval.current_user` and a peer that sends a
	 * `QuoteRequest` are told the same thing.
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
	 * Whether this post is open enough to be quoted: addressed to the public
	 * collection, which `public` and `unlisted` both are. The same rule
	 * `PinService::pin()` and `BoostService::create()` apply, and for the same
	 * reason — a quote carries the audience of the quoter, so anything
	 * narrower would be handed to readers the author never addressed.
	 */
	/**
	 * The page a person can open for this post.
	 *
	 * Freshly imported it is on the object; read back out of the database it
	 * is in `details`, because the table has no column for it. Falling back to
	 * the id keeps every local post — whose id *is* its page — exactly as it
	 * was.
	 */
	public function pageUrl(): string {
		if ($this->getUrl() !== '') {
			return $this->getUrl();
		}

		$stored = $this->getDetailsAll()[Details::PAGE] ?? '';

		return is_string($stored) && $stored !== '' ? $stored : $this->getId();
	}

	public function isQuotable(): bool {
		// what the author said, where they said anything. A remote post that
		// carries `canQuote` has answered this question itself, and quoting it
		// anyway means sending a request their server is going to refuse --
		// after this instance has already shown the quote to the person who
		// wrote it.
		if (!$this->isLocal() && $this->getQuotePolicy() === self::QUOTE_POLICY_NOBODY) {
			return false;
		}

		return $this->isPublic()
			|| in_array($this->getVisibility(), [self::TYPE_PUBLIC, self::TYPE_UNLISTED], true);
	}

	/**
	 * Who the author says may quote their post, from `interactionPolicy`.
	 *
	 * GoToSocial defined the field, Mastodon 4.5 reads it, and Loops publishes
	 * it on every video; FEP-044f is the same shape. Only `canQuote` is read
	 * here, because it is the only one this app can act on without pretending
	 * to know a remote server's follower list: `automaticApproval` naming the
	 * public collection is "anyone", anything narrower is treated as "ask the
	 * author", and an absent policy leaves the visibility rule to decide as
	 * before.
	 *
	 * `manualApproval` is deliberately not "yes": it means the author's server
	 * decides case by case, and this app has no way to wait for that answer
	 * before showing a reader the quote they just wrote.
	 */
	private static function quotePolicyOf(array $data): string {
		return self::policyOf($data, 'canQuote');
	}

	/**
	 * One clause of an `interactionPolicy`, as this app understands it.
	 *
	 * The same shape answers all four questions — may this be replied to,
	 * boosted, liked, quoted — and only `canQuote` was ever read. The other
	 * three were offered in the interface of every reader here and refused by
	 * the author's server afterwards, which is the worst of both: the reader
	 * is told their reply went out, and it did, and nothing ever shows it.
	 *
	 * `automaticApproval` naming the public collection is the only "yes" this
	 * app acts on, for the reason set out above `quotePolicyOf()`:
	 * `manualApproval` means the author's server decides case by case, and
	 * nothing here can wait for that answer.
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
	 * What the author allows, for an interaction other than quoting.
	 *
	 * Kept in `details` rather than in columns of their own: three more
	 * columns on the largest table in the app, for three fields almost no post
	 * carries and nothing queries by.
	 *
	 * @return string '' where the author said nothing, which is every post
	 *                from a server that does not publish policies
	 */
	public function getInteractionPolicy(string $interaction): string {
		$stored = $this->getDetailsAll()[Details::POLICIES][$interaction] ?? '';

		return is_string($stored) ? $stored : '';
	}

	/**
	 * Whether this instance should offer an interaction at all.
	 *
	 * True where the author said nothing — which is every post from every
	 * server that does not publish policies, and has to stay the default — and
	 * true for anything local, where the policy is this instance's to apply
	 * when the interaction arrives rather than something to refuse in advance.
	 */
	public function allowsInteraction(string $interaction): bool {
		if ($this->isLocal()) {
			return true;
		}

		return $this->getInteractionPolicy($interaction) !== self::QUOTE_POLICY_NOBODY;
	}

	/**
	 * Mastodon's Quote entity, or null for a post that quotes nothing.
	 *
	 * `accepted` with the quoted status inline is what a client renders as a
	 * card. `pending` is the honest answer while the quoted post is still being
	 * fetched — and the only answer for a post this reader may not see, which
	 * is why the lookup runs as the viewer: a quote must not become a way of
	 * reading somebody's followers-only post.
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

		// What makes a quote accepted is the approval, not the lookup. The
		// stamp is the quoted author's word that their post may be shown
		// inside this one, and FEP-044f is explicit that a quote without one
		// renders as a link. Holding the quoted post answers a different
		// question — whether we *could* show it — and reading the state off
		// that reported every quote as accepted the moment it was written,
		// including ones the author went on to refuse.
		if ($this->getQuoteAuthorization() === '') {
			return ['state' => self::QUOTE_PENDING, 'quoted_status' => null];
		}

		// Approved, but the post may still be missing here or closed to this
		// reader. That is not `pending`: pending says the author has not
		// answered, and they have.
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
	 * The hashtags as Mastodon's Tag entities.
	 *
	 * The app used to emit its own `hashtags: ["foo"]` alongside nothing a
	 * client recognises, so a post's tags were invisible in every client and
	 * there was nothing to tap through to the hashtag timeline.
	 *
	 * @return array<array{name: string, url: string}>
	 */
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
	 * The mentions, with every id a string.
	 *
	 * An unresolvable mention is stored with an integer `0` for an id, and a
	 * client that declares the field a string fails to decode the mention —
	 * and with it the status carrying it.
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
	 * When this status was last edited, or null if it never was.
	 *
	 * The edit stamp is the ActivityPub `updated`, remote or local. Rows
	 * edited before this app emitted one carry the edit differently: the
	 * edit used to move `published` and leave `published_time` — which
	 * `created_at` is built from — at the original, so for those the two
	 * disagreeing is what says the post was edited.
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

		// `attachment` is the ActivityPub name for these; the client format
		// already carries them as `media_attachments`, and a second copy under
		// a key Mastodon does not define was only ever confusing.
		if ($this->getExportFormat() !== self::FORMAT_LOCAL) {
			// as Documents, whatever format the attachments themselves are in:
			// a post read back from the database has them hydrated in the local
			// format, and served like that a peer got Mastodon's client entity
			// under an ActivityPub key -- Pixelfed dropped every picture
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
	 * The client format is a fixed key set, and nothing may be filtered out of
	 * it.
	 *
	 * `Note::jsonSerialize()` runs its result through `cleanArray()`, which
	 * drops every empty string and empty list. For an ActivityPub document that
	 * is the intent — an absent property is simply absent. For a Mastodon
	 * status entity it is silent data loss: a post with no content warning lost
	 * `spoiler_text`, one with no attachments lost `media_attachments`, one
	 * that is not a reply lost `in_reply_to_id`, and a client that declares
	 * those keys non-optional — which Mastodon's own behaviour entitles it to —
	 * failed to decode the status, and with it the page it arrived on.
	 *
	 * It went unnoticed because `ApiContractTest` asserted against
	 * `exportAsLocal()`, which is not what a client receives.
	 */
	#[\Override]
	protected function cleanArray(array &$arr) {
		if ($this->getExportFormat() === self::FORMAT_LOCAL) {
			return;
		}

		parent::cleanArray($arr);
	}
}
