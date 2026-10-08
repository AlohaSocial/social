<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Tools\Traits\TArrayTools;
use OCA\Social\Tools\Traits\TPathTools;
use OCP\Config\Exceptions\TypeConflictException;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IURLGenerator;

/**
 * Class ConfigService
 *
 * @package OCA\Social\Service
 */
class ConfigService {
	use TPathTools;
	use TArrayTools;

	public const CLOUD_URL = 'cloud_url';
	public const SOCIAL_URL = 'social_url';
	public const SOCIAL_ADDRESS = 'social_address';

	/**
	 * Whether the client API answered at the domain root the last time it was
	 * probed: '1', '0', or unset where nothing has asked yet.
	 *
	 * Written down rather than only cached because the discovery document is
	 * built from it, and that is a route any client may call -- see
	 * `CheckService::clientApiRootIsKnownGood()`.
	 */
	public const CLIENT_API_ROOT = 'client_api_root';

	/**
	 * Whether the last root probe came back with addresses in the scheme it was
	 * asked over: '1', '0', or unset where nothing has asked yet. A proxy that
	 * drops the scheme leaves every absolute URL saying `http` on an `https`
	 * site — see `CheckService::answeredInScheme()`.
	 */
	public const CLIENT_API_SCHEME = 'client_api_scheme';

	public const SOCIAL_SERVICE = 'service';
	public const SOCIAL_MAX_SIZE = 'max_size';
	/** The ceiling for a video, which is not the ceiling for a picture. */
	public const SOCIAL_MAX_VIDEO_SIZE = 'max_video_size';
	/** Whether a post that is a video is federated as a `Video` object. */
	public const SOCIAL_PUBLISH_VIDEO = 'publish_video_objects';
	public const SOCIAL_ACCESS_TYPE = 'access_type';
	public const SOCIAL_ACCESS_LIST = 'access_list';

	public const SOCIAL_SELF_SIGNED = 'allow_self_signed';

	/** incoming inbox requests allowed per origin host per minute; 0 disables */
	public const SOCIAL_INBOX_THROTTLE = 'inbox_throttle';

	/**
	 * The budget a client API route with no rate limit of its own gets, per
	 * window; `0` on either switches the default off.
	 */
	public const SOCIAL_RATE_LIMIT_USER = 'rate_limit_user';
	public const SOCIAL_RATE_LIMIT_ANON = 'rate_limit_anon';
	public const SOCIAL_RATE_LIMIT_WINDOW = 'rate_limit_window';

	/** How many follows one account may send in an hour; `0` is no limit. */
	public const SOCIAL_FOLLOW_LIMIT = 'follow_limit';

	/** How many megabytes of media one other server may keep here; `0` is no limit. */
	public const SOCIAL_DOMAIN_MEDIA_QUOTA = 'domain_media_quota';

	/** days to keep remote statuses nobody local cares about; 0 disables */
	public const SOCIAL_RETENTION_DAYS = 'retention_days';

	/**
	 * Days to keep an in-app notification row before the prune deletes it;
	 * applied whether or not `retention_days` is set. 0 disables.
	 */
	public const SOCIAL_NOTIFICATION_RETENTION_DAYS = 'notification_retention_days';

	/**
	 * Days to keep a cached remote actor nobody here refers to any more —
	 * not followed, not following, no post stored, no relation pending — before
	 * the cache cron evicts it with its avatar; 0 disables the sweep.
	 */
	public const SOCIAL_CACHE_ACTOR_DAYS = 'cache_actor_days';

	/**
	 * Days an OAuth token lives from the moment it was granted, however much
	 * it is used; 0 lets a token in use live for ever, as it used to. Use
	 * keeps a token from ageing out inside this, never past it.
	 */
	public const SOCIAL_TOKEN_MAX_DAYS = 'token_max_days';

	/**
	 * Which of `Cron\Cache`'s steps the next pass begins with — bookkeeping,
	 * not a setting. Written by the job when it runs out of its budget, so the
	 * steps at the end of the list are not the ones that never run.
	 */
	public const SOCIAL_CACHE_CRON_START = 'cache_cron_start';

	/**
	 * Instances whose accounts are silenced rather than blocked: out of the
	 * public and global timelines, still readable by whoever follows them.
	 *
	 * The middle tier a domain block did not have. Without it the only answer
	 * to an instance that is a nuisance rather than a menace was to cut it off
	 * entirely, which also cuts off the local users who deliberately follow
	 * somebody there.
	 */
	public const SOCIAL_SILENCED_LIST = 'silenced_list';

	/**
	 * The published block lists this instance subscribes to, as JSON.
	 *
	 * One object per source: its id, where it is fetched from, which format it
	 * is in, whether it is on, and what the last run did. Empty until an admin
	 * turns one on — a server's moderation is its own, and adopting somebody
	 * else's is a decision that has to be made rather than inherited.
	 */
	public const SOCIAL_BLOCKLIST_SOURCES = 'blocklist_sources';

	/**
	 * Whether an ActivityPub GET must be signed to be answered.
	 *
	 * Mastodon's secure mode. Off by default, and deliberately: turning it on
	 * makes this instance invisible to every peer that does not sign its
	 * fetches, which is a decision about who an instance federates with rather
	 * than something to arrive at by upgrading.
	 */
	public const SOCIAL_SECURE_MODE = 'secure_mode';

	/**
	 * Whether `/api/v1/instance/domain_blocks` publishes the deny list.
	 *
	 * Off by default. Mastodon publishes the list so somebody choosing a
	 * server can see who it will not talk to; whether *this* server wants that
	 * read by anybody is a disclosure decision its admin makes, not a default.
	 */
	public const SOCIAL_PUBLISH_BLOCKS = 'publish_blocks';

	/**
	 * How far the closed-poll sweep has got, as a timestamp.
	 *
	 * A poll closes by its end time passing, so nothing happens at the moment
	 * it does and something has to look. This is where that look left off.
	 */
	public const SOCIAL_POLLS_SWEPT = 'polls_swept';

	/**
	 * Whether the first post of an account that has published nothing here yet
	 * is held for a moderator.
	 *
	 * **Off by default**, and it was on until it was clear what that meant: an
	 * account on this server is a Nextcloud account that an administrator
	 * provisioned, and holding its first post made every new person's first
	 * five minutes look broken — the composer closed, the post did not appear,
	 * and the only place it existed was a panel their administrator had not
	 * opened yet. The end-to-end suite failed on exactly that, twice per run,
	 * on a clean install. An instance that hands out accounts to strangers
	 * turns it on; the spam rules (`autospam`) stay on for everybody.
	 */
	public const SOCIAL_REVIEW_FIRST_POST = 'review_first_post';

	/**
	 * Whether a post that trips the spam rules waits for a moderator.
	 *
	 * On by default, and a very short list of rules — see `PostReviewService`.
	 * What this is not is a filter: nothing is ever refused by it, only put in
	 * front of a person.
	 */
	public const SOCIAL_AUTOSPAM = 'autospam';

	/**
	 * Hashtags that label a post as made with AI, comma-separated, added to
	 * `AiContentService::DEFAULT_TAGS`. Compared case-insensitively, with or
	 * without the `#`. Empty by default: the defaults are the list.
	 */
	public const SOCIAL_AI_TAGS = 'ai_tags';

	/**
	 * Whether the composer offers the 881 animated emoji.
	 *
	 * On by default. What ships is the list, not the pictures — see
	 * `GifPackService` for why, and for what the instance fetches and when.
	 * An administrator who wants nothing fetched sets this to 0 and is left
	 * with whatever they added with `occ social:gif add`.
	 */
	public const SOCIAL_GIF_PACK = 'gif_pack';

	/**
	 * Whether this instance offers stories.
	 *
	 * On by default. A story is a picture or a video that is gone in a day,
	 * and an instance that would rather not carry something it cannot show a
	 * moderator afterwards turns the whole thing off here: the bar goes from
	 * the timeline, and nothing new is taken.
	 */
	public const SOCIAL_STORIES = 'stories';

	/**
	 * Whether the Photos, Videos and News timelines are offered.
	 *
	 * All on by default. Each is the same stream of posts read through one
	 * filter -- pictures or videos -- and an instance
	 * that does not want a section in the sidebar turns that one off. The
	 * posts are not touched: a video posted while Videos was off is still a
	 * video, and shows up again the moment it is turned back on.
	 */
	public const SOCIAL_SECTION_PHOTOS = 'section_photos';
	public const SOCIAL_SECTION_VIDEOS = 'section_videos';

	/**
	 * Which Nextcloud groups become Social lists, as a JSON array of group ids.
	 *
	 * Empty by default, which is no groups at all. Every group a person is in
	 * used to become a list of theirs, which on an instance whose groups are
	 * departments is useful and on one whose groups are licence pools is a
	 * sidebar full of noise -- and it tells everybody in a group who else is
	 * in it, which is not the administrator's to give away by default. So:
	 * nothing until somebody chooses.
	 */
	public const SOCIAL_GROUP_LISTS = 'group_lists';

	/**
	 * How many posts an account must have published here before its posts stop
	 * being held.
	 *
	 * `1` — hold the first one only — is the default and is what "first-post
	 * review" means. An instance that has had trouble can ask for more, and an
	 * account that has had that many posts approved is one a person has
	 * already looked at that many times.
	 */
	public const SOCIAL_REVIEW_POSTS = 'review_posts';

	/**
	 * The longest edge a stored picture may have, in pixels; 0 keeps every
	 * upload exactly as it arrived.
	 *
	 * Off by default, because this app's promise has been that nothing loses a
	 * generation of quality: the metadata is stripped losslessly and a picture
	 * is re-encoded only when it has to be. An instance where storage costs
	 * money, or whose people post straight from a 48-megapixel phone, wants
	 * the other trade, and until now had no way to ask for it.
	 */
	public const SOCIAL_IMAGE_MAX_EDGE = 'image_max_edge';

	/**
	 * The JPEG quality a re-encoded picture is stored at. Only consulted when
	 * `image_max_edge` is set, because otherwise nothing is re-encoded.
	 */
	public const SOCIAL_IMAGE_QUALITY = 'image_quality';

	/**
	 * Whether stored videos are re-encoded to H.264 in an MP4.
	 *
	 * On by default, and it only does anything where ffmpeg is installed. The
	 * trade is a lossy re-encode of somebody's file against that file being
	 * seen at all: **Pixelfed's default `media_types` accepts `video/mp4` and
	 * nothing else**, so every `video/quicktime` posted from here, which is
	 * every video straight off an iPhone, is dropped by its inbox without a
	 * word to anybody, and HEVC plays in Safari alone. The converted file
	 * replaces the original.
	 *
	 * The work is done by a background job, never during an upload: converting
	 * a video is minutes rather than the seconds a poster frame takes.
	 */
	public const SOCIAL_VIDEO_TRANSCODE = 'video_transcode';

	/**
	 * The tallest a converted video is written. Only consulted when
	 * `video_transcode` is on, because otherwise nothing is re-encoded.
	 */
	public const SOCIAL_VIDEO_MAX_HEIGHT = 'video_max_height';

	/**
	 * Whether a stored video is also written at a ladder of smaller heights,
	 * as HLS.
	 *
	 * A different question from `video_transcode`, which is about a video
	 * being *playable at all* elsewhere. This is about it being watchable on a
	 * connection that cannot carry the original: the same video two or three
	 * more times, smaller, and a playlist that lets a player move between
	 * them. Off by default, because it is several encodes per video on
	 * somebody's server.
	 */
	public const SOCIAL_VIDEO_LADDER = 'video_ladder';

	/**
	 * Which heights, as a comma-separated list. Rungs at or above a video's
	 * own height are skipped rather than upscaled, so this is a ceiling on
	 * what may be written and not a promise about what will be.
	 */
	public const SOCIAL_VIDEO_LADDER_HEIGHTS = 'video_ladder_heights';

	/**
	 * How many megabytes of video one account may keep here, `0` for no
	 * limit.
	 *
	 * A different question from `max_video_size`, which is a ceiling on one
	 * file: that one is about a single request, this one about a year of
	 * them. Off by default, because an instance that has been running without
	 * a quota and acquires one on upgrade would start refusing uploads from
	 * exactly the accounts that use it most.
	 */
	public const SOCIAL_VIDEO_QUOTA = 'video_quota';

	/**
	 * What this instance does with a post somebody marked sensitive, for
	 * readers who have not chosen for themselves: `show_all`, `default`
	 * (covered, one press away) or `hide_all`. PeerTube's three NSFW policies
	 * under Mastodon's names for them — see `SensitiveMediaService`.
	 */
	/**
	 * Where the local-account refresh walk got to, as an `id_prim`.
	 *
	 * Bookkeeping rather than a setting. The walk used to read every local
	 * account into memory on every cron pass; it pages now, and this is what
	 * makes the next pass carry on rather than start again. `''` is "begin at
	 * the top", which is also what it is set back to when the walk reaches the
	 * end.
	 */
	public const SOCIAL_LOCAL_ACTOR_CURSOR = 'local_actor_cursor';

	/**
	 * Where the profile-link verification walk over the local accounts got
	 * to, as an `id_prim`; bookkeeping like `SOCIAL_LOCAL_ACTOR_CURSOR`.
	 */
	public const SOCIAL_PROFILE_LINK_CURSOR = 'profile_link_cursor';

	/**
	 * How far back a content search looks, in days; `0` searches everything.
	 *
	 * `content ILIKE '%term%'` cannot use an index — a leading wildcard never
	 * can — so an unbounded search reads every post the instance has ever
	 * stored, joined to seven other tables, on every keystroke. At ten million
	 * rows that is a table scan the rate limit is the only defence against.
	 * A year covers what anybody is looking for; an instance small enough to
	 * search all of it can say so.
	 */
	/**
	 * Whether every recipient row carries its post's sort key yet.
	 *
	 * Set by `Version1000Date20260917000001` when its backfill finishes, and
	 * read on every home timeline to decide whether the fast page query may be
	 * used. A *flag*, because the obvious check — "is there a row with a zero
	 * nid" — has no index to answer it and is a full scan of the largest table
	 * this app has: measured at 427 ms on 800,000 rows, on every request, which
	 * would have made the thing it guards slower than what it replaced.
	 */
	public const SOCIAL_DEST_NID_FILLED = 'dest_nid_filled';

	/**
	 * Whether every post carries the host of its author in `author_host` yet.
	 *
	 * Set by `Cron\StreamAuthorHosts` when its backfill reaches the end of
	 * the table. Until then the domain-block and silenced-instance filters
	 * also match a row with no host by its actor id, which is the slow form
	 * the column replaces; a flag rather than a check for NULL rows for the
	 * reason `dest_nid_filled` gives.
	 */
	public const SOCIAL_STREAM_AUTHOR_HOSTS_FILLED = 'stream_author_hosts_filled';

	public const SOCIAL_SEARCH_WINDOW_DAYS = 'search_window_days';

	public const SOCIAL_NSFW_POLICY = 'nsfw_policy';

	/**
	 * Whether a post with a video on it waits for a moderator.
	 *
	 * The review queue already holds a first post and a post the spam rules
	 * do not like; this is the third rule, and the one an instance that hosts
	 * video wants: a video is minutes of somebody's attention and a great deal
	 * of somebody else's disk, and an instance may reasonably want to see one
	 * before it is published. Off by default.
	 */
	public const SOCIAL_REVIEW_VIDEOS = 'review_videos';

	/**
	 * The last measurement of how much disk this app is using, as JSON, with
	 * the moment it was taken.
	 *
	 * Bookkeeping rather than a setting: the walk is a `stat` per file and
	 * belongs in the cron, so the administration page reads what the cron left
	 * here and says when it was measured. See `MediaUsageService`.
	 */
	public const SOCIAL_MEDIA_USAGE = 'media_usage';

	/**
	 * The secret a story's fetch capability is derived from, generated the
	 * first time a story is published. See `getStorySecret()`.
	 */
	public const SOCIAL_STORY_SECRET = 'story_secret';

	/**
	 * Per account, not per instance: the Pixelfed app's own switches, kept
	 * here because that app keeps them on its server so a reinstall finds the
	 * app as it was left. Written and read by `PixelfedService`, and
	 * interpreted by nothing here.
	 */
	public const APP_SETTINGS = 'app_settings';

	/** The long form of what this instance is, for `instance/extended_description`. */
	public const SOCIAL_EXTENDED_DESCRIPTION = 'extended_description';

	/**
	 * Who to write to about this instance: Mastodon's `instance.email`.
	 *
	 * Read by every client on its first request and shown on the server's
	 * about page; empty until an administrator fills it in.
	 */
	public const CONTACT_EMAIL = 'contact_email';
	public const SOCIAL_CONTACT_ACCOUNT = 'contact_account';

	/**
	 * For you: the feed built from the hashtags a reader lingers on.
	 *
	 * `interests` switches the whole feature, and off means off: nothing is
	 * learned, the feed and its settings are gone, and what was learned is
	 * kept for the day it is switched back on. `interests_default` is what a
	 * reader who never touched their own switch gets — on out of the box, and
	 * the one to turn off where learning from reading has to be opted into.
	 * The other four tune the arithmetic: the half-life of a score in days,
	 * the score a learned tag needs to be listed, how many are listed, and how
	 * many days back the feed looks for posts.
	 */
	public const SOCIAL_INTERESTS = 'interests';
	public const SOCIAL_INTERESTS_DEFAULT = 'interests_default';
	public const SOCIAL_INTERESTS_HALF_LIFE = 'interests_half_life';
	public const SOCIAL_INTERESTS_THRESHOLD = 'interests_threshold';
	public const SOCIAL_INTERESTS_CAP = 'interests_cap';
	public const SOCIAL_INTERESTS_WINDOW = 'interests_window';

	/**
	 * Self-registered external users (`ExternalUserService`): whether people
	 * without a Nextcloud account may create one that reaches Social only, how
	 * many such accounts may exist, the megabytes of media each may upload
	 * (0 is no quota), how a registration is admitted (`open`, `invite` or
	 * `approval`), whether its email has to be confirmed first, the minimum
	 * age a person must confirm (0 asks nothing), handles nobody may register
	 * (a JSON list) and whether existing users may send invitations.
	 */
	public const SOCIAL_EXTERNAL_ENABLED = 'external_enabled';
	public const SOCIAL_EXTERNAL_MAX = 'external_max';
	public const SOCIAL_EXTERNAL_QUOTA = 'external_media_quota';
	public const SOCIAL_EXTERNAL_MODE = 'external_signup_mode';
	public const SOCIAL_EXTERNAL_VERIFY = 'external_verify_email';
	public const SOCIAL_EXTERNAL_MIN_AGE = 'external_min_age';
	public const SOCIAL_EXTERNAL_RESERVED = 'external_reserved';
	public const SOCIAL_EXTERNAL_USER_INVITES = 'external_user_invites';
	public const SOCIAL_EXTERNAL_SIGNUP_NOTICE = 'external_signup_notice';
	public const SOCIAL_EXTERNAL_SIGNUP_NOTICE_REQUIRED = 'external_signup_notice_required';
	public const USER_EXTERNAL_SIGNUP_NOTICE_VERSION = 'external_signup_notice_version';
	public const USER_EXTERNAL_SIGNUP_NOTICE_ACCEPTED = 'external_signup_notice_accepted';
	public const USER_EXTERNAL_SIGNUP_NOTICE_SNAPSHOT = 'external_signup_notice_snapshot';

	/**
	 * Bluesky (`Atproto\Service\AtprotoConfig`): whether this instance is a
	 * PDS at all, the relays told to crawl it (a JSON list), the PLC
	 * directory that registers its DIDs, the public AppView it reads, the
	 * Jetstream endpoint an optional listener uses, and how many AppView
	 * requests one sync pass may make.
	 */
	public const ATPROTO_ENABLED = 'atproto_enabled';
	public const ATPROTO_RELAYS = 'atproto_relays';
	public const ATPROTO_PLC_DIRECTORY = 'atproto_plc_directory';
	public const ATPROTO_APPVIEW = 'atproto_appview';
	public const ATPROTO_APPVIEW_AUTH = 'atproto_appview_auth';
	public const ATPROTO_APPVIEW_DID = 'atproto_appview_did';
	public const ATPROTO_JETSTREAM = 'atproto_jetstream';
	public const ATPROTO_SYNC_CEILING = 'atproto_sync_ceiling';
	/** the firehose daemon's last report (JSON), written by `occ social:atproto:serve` */
	public const ATPROTO_FIREHOSE_STATUS = 'atproto_firehose_status';
	public const ATPROTO_DELETE_CURSOR = 'atproto_delete_cursor';
	public const ATPROTO_MODERATION_DID = 'atproto_moderation_did';

	public array $defaults = [
		self::ATPROTO_ENABLED => '0',
		self::ATPROTO_RELAYS => '["https://bsky.network"]',
		self::ATPROTO_PLC_DIRECTORY => 'https://plc.directory',
		self::ATPROTO_APPVIEW => 'https://public.api.bsky.app',
		self::ATPROTO_APPVIEW_AUTH => 'https://api.bsky.app',
		self::ATPROTO_APPVIEW_DID => 'did:web:api.bsky.app',
		self::ATPROTO_JETSTREAM => '',
		self::ATPROTO_SYNC_CEILING => '200',
		self::ATPROTO_FIREHOSE_STATUS => '',
		self::ATPROTO_DELETE_CURSOR => '0',
		self::ATPROTO_MODERATION_DID => 'did:plc:ar7c4by46qjdydhdevvrndac',
		self::CLOUD_URL => '',
		self::SOCIAL_URL => '',
		self::SOCIAL_ADDRESS => '',
		self::SOCIAL_SERVICE => 1,
		self::SOCIAL_MAX_SIZE => 10,
		self::SOCIAL_MAX_VIDEO_SIZE => 2048,
		self::SOCIAL_PUBLISH_VIDEO => '0',
		self::SOCIAL_ACCESS_TYPE => 'all_but',
		self::SOCIAL_ACCESS_LIST => '[]',
		self::SOCIAL_SELF_SIGNED => '0',
		self::SOCIAL_INBOX_THROTTLE => '300',
		self::SOCIAL_RATE_LIMIT_USER => '900',
		self::SOCIAL_RATE_LIMIT_ANON => '300',
		self::SOCIAL_RATE_LIMIT_WINDOW => '300',
		self::SOCIAL_FOLLOW_LIMIT => '100',
		self::SOCIAL_DOMAIN_MEDIA_QUOTA => '0',
		self::SOCIAL_RETENTION_DAYS => '0',
		self::SOCIAL_NOTIFICATION_RETENTION_DAYS => '90',
		self::SOCIAL_CACHE_ACTOR_DAYS => '180',
		self::SOCIAL_TOKEN_MAX_DAYS => '365',
		self::SOCIAL_SILENCED_LIST => '[]',
		self::SOCIAL_BLOCKLIST_SOURCES => '[]',
		self::SOCIAL_SECURE_MODE => '0',
		self::SOCIAL_PUBLISH_BLOCKS => '0',
		self::SOCIAL_REVIEW_FIRST_POST => '0',
		self::SOCIAL_AUTOSPAM => '1',
		self::SOCIAL_AI_TAGS => '',
		self::SOCIAL_GIF_PACK => '1',
		self::SOCIAL_STORIES => '1',
		self::SOCIAL_SECTION_PHOTOS => '1',
		self::SOCIAL_SECTION_VIDEOS => '1',
		self::SOCIAL_GROUP_LISTS => '[]',
		self::SOCIAL_REVIEW_POSTS => '1',
		self::SOCIAL_IMAGE_MAX_EDGE => '0',
		self::SOCIAL_IMAGE_QUALITY => '85',
		self::SOCIAL_VIDEO_TRANSCODE => '1',
		self::SOCIAL_VIDEO_MAX_HEIGHT => '1080',
		self::SOCIAL_VIDEO_LADDER => '0',
		self::SOCIAL_VIDEO_LADDER_HEIGHTS => '360,720,1080',
		self::SOCIAL_VIDEO_QUOTA => '0',
		self::SOCIAL_LOCAL_ACTOR_CURSOR => '',
		self::SOCIAL_PROFILE_LINK_CURSOR => '',
		self::SOCIAL_DEST_NID_FILLED => '0',
		self::SOCIAL_STREAM_AUTHOR_HOSTS_FILLED => '0',
		self::SOCIAL_SEARCH_WINDOW_DAYS => '365',
		self::SOCIAL_NSFW_POLICY => 'default',
		self::SOCIAL_REVIEW_VIDEOS => '0',
		self::SOCIAL_EXTENDED_DESCRIPTION => '',
		self::CONTACT_EMAIL => '',
		self::SOCIAL_CONTACT_ACCOUNT => '',
		self::SOCIAL_POLLS_SWEPT => '0',
		self::SOCIAL_INTERESTS => '1',
		self::SOCIAL_INTERESTS_DEFAULT => '1',
		self::SOCIAL_INTERESTS_HALF_LIFE => '30',
		self::SOCIAL_INTERESTS_THRESHOLD => '3',
		self::SOCIAL_INTERESTS_CAP => '30',
		self::SOCIAL_INTERESTS_WINDOW => '7',
		self::SOCIAL_EXTERNAL_ENABLED => '0',
		self::SOCIAL_EXTERNAL_MAX => '100',
		self::SOCIAL_EXTERNAL_QUOTA => '1024',
		self::SOCIAL_EXTERNAL_MODE => 'approval',
		self::SOCIAL_EXTERNAL_VERIFY => '1',
		self::SOCIAL_EXTERNAL_MIN_AGE => '16',
		self::SOCIAL_EXTERNAL_RESERVED => '[]',
		self::SOCIAL_EXTERNAL_USER_INVITES => '0',
		self::SOCIAL_EXTERNAL_SIGNUP_NOTICE => '',
		self::SOCIAL_EXTERNAL_SIGNUP_NOTICE_REQUIRED => '0',
	];

	public array $accessTypeList = [
		'BLACKLIST' => 'all_but',
		'WHITELIST' => 'none_but'
	];

	private ?string $userId = null;

	/** Seconds a federation request may take when nobody asks for anything else. */
	public const DEFAULT_REQUEST_TIMEOUT = 10;

	/**
	 * Seconds allowed for reaching a peer — DNS, TCP and TLS — when nobody
	 * asks for anything else; never more than the request's own timeout.
	 *
	 * A live peer is reached in well under a second, and what takes longer is
	 * almost always a host that is gone. Allowing it the whole read timeout
	 * made every dead host cost ten seconds, or thirty from the queue, before
	 * the breaker could hear about it; a slow peer that is there still gets
	 * the full timeout to answer once connected.
	 */
	public const DEFAULT_CONNECT_TIMEOUT = 4;

	/** Seconds; 0 leaves each request its own default. See withRequestTimeout(). */
	private int $requestTimeout = 0;

	/** Seconds allowed for reaching the peer alone; 0 means DEFAULT_CONNECT_TIMEOUT. */
	private int $requestConnectTimeout = 0;

	public function __construct(
		?string $userId,
		private IAppConfig $appConfig,
		private IUserConfig $userConfig,
		private IConfig $config,
		private IURLGenerator $urlGenerator,
	) {
		$this->userId = $userId;
	}

	/**
	 * @return array
	 */
	public function getConfig(): array {
		$keys = array_keys($this->defaults);
		$data = [];

		foreach ($keys as $k) {
			$data[$k] = $this->getAppValue($k);
		}

		return $data;
	}

	/**
	 * /**
	 * Get a value by key
	 *
	 * @param string $key
	 *
	 * @return string
	 */
	/**
	 * Whether a user's block is also sent to the blocked account's server as a
	 * Block activity (Mastodon behaviour, the default). Disable to keep blocks
	 * strictly local:  occ config:app:set social federate_blocks --value 0
	 */
	public function isBlockFederationEnabled(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 'federate_blocks', '1') !== '0';
	}

	public function getAppValue($key) {
		$defaultValue = null;
		if (array_key_exists($key, $this->defaults)) {
			$defaultValue = $this->defaults[$key];
		}

		return $this->appConfig->getValueString(Application::APP_ID, $key, (string)$defaultValue);
	}

	/**
	 * Get a value by key
	 *
	 * @param string $key
	 *
	 * @return int
	 */
	public function getAppValueInt(string $key): int {
		$defaultValue = null;
		if (array_key_exists($key, $this->defaults)) {
			$defaultValue = $this->defaults[$key];
		}

		return (int)$this->appConfig->getValueString(Application::APP_ID, $key, (string)$defaultValue);
	}

	/**
	 * A switch, read the way this app reads every other one: anything but the
	 * literal `0` is on, so an admin who writes `false`, `no` or `off` does not
	 * silently get the opposite of what they meant.
	 */
	public function getAppValueBool(string $key): bool {
		return !in_array(strtolower(trim((string)$this->getAppValue($key))), ['0', 'false', 'no', 'off', ''], true);
	}

	/**
	 * Set a value by key
	 *
	 * @param string $key
	 * @param string $value
	 *
	 * @return void
	 */
	public function setAppValue($key, $value) {
		$this->appConfig->setValueString(Application::APP_ID, $key, (string)$value);
	}

	/**
	 * remove a key
	 *
	 * @param string $key
	 */
	public function deleteAppValue($key): void {
		$this->appConfig->deleteKey(Application::APP_ID, $key);
	}

	/**
	 * Get a user value by key
	 *
	 * @param string $key
	 * @param string $userId
	 * @param string $app
	 *
	 * @return string
	 */
	public function getUserValue(string $key, string $userId = '', string $app = '') {
		if ($userId === '') {
			$userId = (string)$this->userId;
		}

		$defaultValue = '';
		if ($app === '') {
			$app = Application::APP_ID;
			if (array_key_exists($key, $this->defaults)) {
				$defaultValue = $this->defaults[$key];
			}
		}

		// A request authenticated by a bearer token has no Nextcloud session,
		// so there is no user to read a per-user value for unless the caller
		// names one. The backend takes a string and fatals on null, which
		// turned every such read into a 500 for OAuth clients.
		if ($userId === '') {
			return (string)$defaultValue;
		}

		return $this->userConfig->getValueString($userId, $app, $key, (string)$defaultValue);
	}

	/**
	 * A user setting another app owns, as the number it holds.
	 *
	 * Nextcloud 32 gave stored settings a type, and reading one back as
	 * anything else throws. Which type a value has is the owning app's
	 * business and changes between versions — the avatar app's `version` is a
	 * number on a current server and was text on an older one — so this asks
	 * for the number, and falls back to reading the text and converting it.
	 *
	 * Without it, reading that one value threw out of `createActor()`, so
	 * making an account answered 500, and every request that needed an actor —
	 * which is all of them — answered 401 after it.
	 *
	 * @param string $key the setting
	 * @param string $userId whose
	 * @param string $app which app owns it
	 *
	 * @return int the value, or 0 where there is none
	 */
	public function getUserValueInt(string $key, string $userId, string $app): int {
		if ($userId === '') {
			return 0;
		}

		try {
			return $this->userConfig->getValueInt($userId, $app, $key);
		} catch (TypeConflictException) {
			// stored as text on this version
		}

		try {
			return (int)$this->userConfig->getValueString($userId, $app, $key);
		} catch (TypeConflictException) {
			// stored as something this cannot be read as at all
			return 0;
		}
	}

	/**
	 * Set a user value by key
	 *
	 * @param string $key
	 * @param string $value
	 */
	public function setUserValue($key, $value): void {
		$this->userConfig->setValueString($this->userId, Application::APP_ID, $key, (string)$value);
	}

	/**
	 * Get a user value by key and user
	 *
	 * @param string $userId
	 * @param string $key
	 *
	 * @return string
	 */
	public function getValueForUser($userId, $key) {
		return $this->userConfig->getValueString($userId, Application::APP_ID, $key);
	}

	/**
	 * Set a user value by key
	 *
	 * @param string $userId
	 * @param string $key
	 * @param string $value
	 *
	 */
	public function setValueForUser($userId, $key, $value): void {
		$this->userConfig->setValueString($userId, Application::APP_ID, $key, (string)$value);
	}

	/**
	 * @param string $key
	 * @param string $value
	 */
	public function setCoreValue(string $key, string $value) {
		$this->appConfig->setValueString('core', $key, $value);
	}

	/**
	 * @param string $key
	 *
	 * @return string
	 */
	public function getCoreValue(string $key): string {
		return $this->appConfig->getValueString('core', $key, '');
	}

	/**
	 * @param string $key
	 */
	public function unsetCoreValue(string $key) {
		$this->appConfig->deleteKey('core', $key);
	}

	/**
	 *
	 */
	public function unsetAppConfig() {
		$this->appConfig->deleteApp(Application::APP_ID);
	}

	/**
	 * @param $key
	 *
	 * @return mixed
	 *
	 * @psalm-param string $key
	 */
	public function getSystemValue(string $key) {
		return $this->config->getSystemValue($key, '');
	}

	/**
	 * Whether outbound requests may reach the instance's own network.
	 *
	 * The standard Nextcloud setting, off by default; the single switch every
	 * outbound path consults before contacting a local address.
	 */
	public function isLocalNetworkAllowed(): bool {
		return $this->config->getSystemValueBool('allow_local_remote_servers', false);
	}

	/**
	 * The configured host as a peer addresses it: the hostname, plus the port
	 * when the cloud URL names one that is not its scheme's default. It is
	 * what a peer puts in `Host` and signs, so an instance on `:8443` has to
	 * verify against `example.org:8443` and not `example.org`.
	 *
	 * @throws SocialAppConfigException
	 */
	public function getCloudAuthority(): string {
		// asked first for its refusal of a URL with no host
		$this->getCloudHost();

		return self::authorityOf($this->getCloudUrl());
	}

	/**
	 * `host[:port]` of a URL, the port only when it is not the scheme's
	 * default; '' for a URL with no host.
	 */
	public static function authorityOf(string $url): string {
		$parts = parse_url($url);
		$host = is_array($parts) ? ($parts['host'] ?? '') : '';
		if ($host === '') {
			return '';
		}

		$port = $parts['port'] ?? null;
		$default = (strtolower($parts['scheme'] ?? '') === 'https') ? 443 : 80;

		return ($port === null || $port === $default) ? $host : $host . ':' . $port;
	}

	/**
	 * getCloudHost - cloud.example.com
	 *
	 * @return string
	 * @throws SocialAppConfigException
	 */
	public function getCloudHost(): string {
		$url = $this->getCloudUrl();
		$host = parse_url($url, PHP_URL_HOST);

		// parse_url() answers null for a URL with no host and false for one it
		// cannot parse at all; the declared string return then made either a
		// TypeError, thrown from whichever caller happened to be first.
		if (!is_string($host) || $host === '') {
			throw new SocialAppConfigException(
				'the configured cloud address has no host: ' . $url
			);
		}

		return $host;
	}

	/**
	 * getCloudUrl - https://cloud.example.com/index.php
	 *             - https://cloud.example.com
	 *
	 * @param bool $noPhp
	 *
	 * @return string
	 * @throws SocialAppConfigException
	 */
	public function getCloudUrl(bool $noPhp = false) {
		$address = $this->getAppValue(self::CLOUD_URL);
		if ($address === '') {
			throw new SocialAppConfigException();
		}

		if ($noPhp) {
			$pos = strpos($address, '/index.php');
			if ($pos) {
				$address = substr($address, 0, $pos);
			}
		}

		return $this->withoutEndSlash($address, false, false);
	}

	/**
	 * @param string $cloudAddress
	 */
	public function setCloudUrl(string $cloudAddress) {
		if (parse_url($cloudAddress, PHP_URL_SCHEME) === null) {
			$cloudAddress = 'http://' . $cloudAddress;
		}

		$this->setAppValue(self::CLOUD_URL, $cloudAddress);
	}

	/**
	 * getSocialAddress - example.com
	 *
	 * @return string
	 * @throws SocialAppConfigException
	 */
	public function getSocialAddress(): string {
		$address = $this->getAppValue(self::SOCIAL_ADDRESS);

		if ($address === '') {
			return $this->getCloudHost();
		}

		return $address;
	}

	/**
	 * @param string $address
	 */
	public function setSocialAddress(string $address) {
		$this->setAppValue(self::SOCIAL_ADDRESS, $address);
	}

	/**
	 * getSocialUrl - https://cloud.example.com/apps/social/
	 *
	 * @return string
	 * @throws SocialAppConfigException
	 */
	public function getSocialUrl(): string {
		$socialUrl = $this->getAppValue(self::SOCIAL_URL);
		if ($socialUrl === '') {
			throw new SocialAppConfigException();
		}

		return $socialUrl;
	}

	/**
	 * What `social_url` should be for the stored `cloud_url`: that address
	 * with the app's route path after it, or '' while there is none.
	 *
	 * The path is read off the route rather than written out, and taken from
	 * `/apps/` on, so the web root and `index.php` come from `cloud_url`
	 * alone and the two cannot disagree about either.
	 */
	public function derivedSocialUrl(): string {
		$cloudUrl = $this->getAppValue(self::CLOUD_URL);
		if ($cloudUrl === '') {
			return '';
		}

		$route = $this->urlGenerator->linkToRoute('social.Navigation.navigate');
		$pos = strpos($route, '/apps/');
		$path = ($pos === false) ? '/apps/' . Application::APP_ID . '/' : substr($route, $pos);

		return rtrim($cloudUrl, '/') . $path;
	}

	/**
	 * The secret the story capabilities are derived from, made the first time
	 * one is needed.
	 *
	 * Generated rather than configured: nobody should have to set this, and an
	 * instance that has never published a story does not need one. Changing it
	 * invalidates every capability at once, which is the only revocation this
	 * needs — a story lives a day.
	 */
	public function getStorySecret(): string {
		$secret = $this->getAppValue(self::SOCIAL_STORY_SECRET);
		if ($secret === '') {
			$secret = bin2hex(random_bytes(32));
			$this->setAppValue(self::SOCIAL_STORY_SECRET, $secret);
		}

		return $secret;
	}

	/**
	 * Stores the app's own base URL, which every id is minted from.
	 *
	 * Without an argument it is derived from `cloud_url`, and nothing is
	 * stored while that is not set. It used to be the absolute URL of the
	 * request that happened to find it empty — the host and scheme that
	 * request arrived with — so an instance first opened through an internal
	 * name, or behind a proxy that does not pass the scheme on, federated
	 * every id under that address for good while `cloud_url` was right.
	 *
	 * @param string $url
	 */
	public function setSocialUrl(string $url = '') {
		if ($url === '') {
			$url = $this->derivedSocialUrl();
			if ($url === '') {
				return;
			}
		}

		if (parse_url($url, PHP_URL_SCHEME) === null) {
			$url = 'http://' . $url;
		}

		$this->setAppValue(self::SOCIAL_URL, $url);
	}

	/**
	 * @param string $path
	 * @param bool $generateId
	 *
	 * @return string
	 * @throws SocialAppConfigException
	 */
	public function generateId(string $path = '', $generateId = true): string {
		$path = $this->withoutBeginSlash($this->withEndSlash($path));

		$id = $this->getSocialUrl() . $path;
		if ($generateId === true) {
			// The random half comes from the system's random source rather than
			// from crc32(uniqid()): uniqid() is the clock, and its checksum left
			// roughly a million candidates per second to enumerate offline. Same
			// shape as before — the timestamp followed by ten digits — so ids
			// already stored stay valid.
			$id .= time() . str_pad((string)random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
		}

		return $id;
	}

	/**
	 * Runs $action with every federation request it makes bounded to $timeout
	 * seconds instead of the default.
	 *
	 * Work that happens before a caller is authenticated must not be able to
	 * hold a PHP worker for the full federation timeout — a few dozen
	 * concurrent requests naming a host that never answers would otherwise take
	 * the whole instance down, not just this app. The override lasts exactly as
	 * long as the call.
	 *
	 * @return mixed whatever $action returns
	 */
	public function withRequestTimeout(int $timeout, callable $action, int $connectTimeout = 0) {
		$previous = $this->requestTimeout;
		$previousConnect = $this->requestConnectTimeout;
		$this->requestTimeout = max(1, $timeout);
		$this->requestConnectTimeout = max(0, $connectTimeout);
		try {
			return $action();
		} finally {
			$this->requestTimeout = $previous;
			$this->requestConnectTimeout = $previousConnect;
		}
	}

	/**
	 * The transport options every federation request goes out with, as
	 * `OCP\Http\Client\IClient` takes them: how long it may take, whether the
	 * peer's certificate has to check out, and whether it may be on this
	 * instance's own network.
	 *
	 * Federation reaches arbitrary public hosts, but must not be pointed at the
	 * instance's own network. Local targets are permitted only where the admin
	 * has opted in through the standard Nextcloud setting (default off).
	 *
	 * @param int $timeout what the caller asks for; a bounded call
	 *                     (withRequestTimeout()) overrides it
	 *
	 * @return array<string, mixed>
	 */
	public function requestOptions(int $timeout = self::DEFAULT_REQUEST_TIMEOUT): array {
		if ($this->requestTimeout > 0) {
			$timeout = $this->requestTimeout;
		}

		$options = [
			'timeout' => $timeout,
			'connect_timeout' => min(
				$timeout,
				($this->requestConnectTimeout > 0) ? $this->requestConnectTimeout : self::DEFAULT_CONNECT_TIMEOUT
			),
			'nextcloud' => ['allow_local_address' => $this->isLocalNetworkAllowed()],
		];

		if ($this->getAppValue(self::SOCIAL_SELF_SIGNED) === '1') {
			$options['verify'] = false;
		}

		return $options;
	}

	/**
	 * The ActivityPub content negotiation a federation request carries: what
	 * this app is willing to read back, and what it is sending.
	 *
	 * WebFinger, host-meta and cached media are not ActivityPub and ask for
	 * none of it — those callers pass `json_headers: false`.
	 *
	 * @return array<string, string>
	 */
	public function activityPubHeaders(string $method): array {
		return match (strtolower($method)) {
			'get' => [
				'Accept' => 'application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
			],
			'post' => ['Content-Type' => 'application/activity+json'],
			default => [],
		};
	}
}
