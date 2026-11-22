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
	 * Whether the client API answered at the domain root when last probed: '1',
	 * '0', or unset. Stored because the discovery document, a public route, is
	 * built from it (`CheckService::clientApiRootIsKnownGood()`).
	 */
	public const CLIENT_API_ROOT = 'client_api_root';

	/**
	 * Whether the last root probe answered in the scheme it was asked over: '1',
	 * '0', or unset. See `CheckService::answeredInScheme()`.
	 */
	public const CLIENT_API_SCHEME = 'client_api_scheme';

	public const SOCIAL_SERVICE = 'service';
	public const SOCIAL_MAX_SIZE = 'max_size';
	/** The size limit for a video, separate from the one for a photo. */
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
	 * How far today's retention walk over old remote posts got, as
	 * `day|creation|id_prim`; the first run of a new day starts from the oldest row.
	 */
	public const SOCIAL_RETENTION_CHECKED = 'retention_checked';

	/**
	 * Days to keep a cached remote actor nobody here refers to before the cache
	 * cron evicts it with its avatar; 0 disables the sweep.
	 */
	public const SOCIAL_CACHE_ACTOR_DAYS = 'cache_actor_days';

	/**
	 * Days an OAuth access token lives from issue until its client refreshes it
	 * once, however much it is used; 0 lets a token in use live for ever.
	 */
	public const SOCIAL_TOKEN_MAX_DAYS = 'token_max_days';

	/**
	 * Days an OAuth authorization may go unused (no request, no refresh) before
	 * it is removed; at least 1.
	 */
	public const SOCIAL_TOKEN_IDLE_DAYS = 'token_idle_days';

	/**
	 * Hours an access token issued by a refresh lives; 0 counts its lifetime by
	 * `token_max_days` like any other.
	 */
	public const SOCIAL_REFRESHED_TOKEN_HOURS = 'refreshed_token_hours';

	/**
	 * Which of `Cron\Cache`'s steps the next pass begins with (bookkeeping), so
	 * the steps at the end still run when a pass runs out of budget.
	 */
	public const SOCIAL_CACHE_CRON_START = 'cache_cron_start';

	/**
	 * The nid of the last stored video `Cron\VideoPosters` looked at for a poster
	 * frame (bookkeeping). `occ social:media:posters` retries every video without one.
	 */
	public const SOCIAL_POSTER_CURSOR = 'poster_backfill_cursor';

	/**
	 * The nid of the last cached actor icon `AvatarThumbnailBackfill` handled, or
	 * `done` once finished (bookkeeping).
	 */
	public const SOCIAL_AVATAR_THUMB_CURSOR = 'avatar_thumb_cursor';

	/**
	 * Instances whose accounts are silenced rather than blocked: out of the
	 * public and global timelines, still readable by whoever follows them.
	 */
	public const SOCIAL_SILENCED_LIST = 'silenced_list';

	/**
	 * The published block lists this instance subscribes to, as JSON: one object
	 * per source with its id, URL, format, enabled state and last run. Empty
	 * until an admin turns one on.
	 */
	public const SOCIAL_BLOCKLIST_SOURCES = 'blocklist_sources';

	/**
	 * Whether an ActivityPub GET must be signed to be answered (Mastodon's secure
	 * mode). Off by default: it hides this instance from peers that do not sign
	 * their fetches.
	 */
	public const SOCIAL_SECURE_MODE = 'secure_mode';

	/**
	 * Whether `/api/v1/instance/domain_blocks` publishes the deny list. Off by
	 * default; publishing it is the admin's disclosure decision.
	 */
	public const SOCIAL_PUBLISH_BLOCKS = 'publish_blocks';

	/** Independent, instance-wide kill switch for ranked For You recommendations. */
	public const REC_V4_ENABLED = 'rec_v4_enabled';
	/** Pause only the For You background requests to remote discovery providers. */
	public const REC_REMOTE_DISCOVERY_PAUSED = 'rec_remote_discovery_paused';
	/** Stop For You behavioural learning regardless of individual consent. */
	public const REC_LEARNING_PAUSED = 'rec_learning_paused';
	/** Serve the established hashtag-interest feed in place of the ranked web feed. */
	public const REC_FORCE_LEGACY_FEED = 'rec_force_legacy_feed';

	/**
	 * How far the closed-poll sweep has got, as a timestamp.
	 */
	public const SOCIAL_POLLS_SWEPT = 'polls_swept';

	/**
	 * Whether the first post of an account that has published nothing here yet
	 * is held for a moderator. Off by default: accounts here are provisioned by
	 * an administrator, and holding their first post makes it look lost.
	 */
	public const SOCIAL_REVIEW_FIRST_POST = 'review_first_post';

	/**
	 * Whether a post that trips the spam rules (`PostReviewService`) waits for a
	 * moderator. On by default; nothing is refused, only queued for review.
	 */
	public const SOCIAL_AUTOSPAM = 'autospam';

	/**
	 * Hashtags that label a post as made with AI, comma-separated, added to
	 * `AiContentService::DEFAULT_TAGS`. Compared case-insensitively, with or
	 * without the `#`. Empty by default: the defaults are the list.
	 */
	public const SOCIAL_AI_TAGS = 'ai_tags';

	/**
	 * Whether the composer offers the 881 animated emoji. Only the list ships;
	 * see `GifPackService` for what is fetched. 0 leaves only the ones added
	 * with `occ social:gif add`.
	 */
	public const SOCIAL_GIF_PACK = 'gif_pack';

	/**
	 * Whether this instance offers shorts (`stories` in the code): photos or
	 * videos that are gone in a day. Off hides the bar and refuses new ones.
	 */
	public const SOCIAL_STORIES = 'stories';

	/**
	 * Whether the Photos, Videos and News timelines are offered. Each is a filter
	 * over the same posts, so turning one off hides it without touching posts.
	 */
	public const SOCIAL_SECTION_PHOTOS = 'section_photos';
	public const SOCIAL_SECTION_VIDEOS = 'section_videos';

	/**
	 * Which Nextcloud groups become Social lists, as a JSON array of group ids.
	 * Empty by default: a group list reveals the group's members, which is the
	 * administrator's choice to make.
	 */
	public const SOCIAL_GROUP_LISTS = 'group_lists';

	/**
	 * How many posts an account must have published here before its posts stop
	 * being held. The default `1` holds the first one only.
	 */
	public const SOCIAL_REVIEW_POSTS = 'review_posts';

	/**
	 * The longest edge a stored photo may have, in pixels; 0 (the default) keeps
	 * every upload as it arrived, re-encoded only when it has to be.
	 */
	public const SOCIAL_IMAGE_MAX_EDGE = 'image_max_edge';

	/**
	 * The JPEG quality a re-encoded photo is stored at; only used when
	 * `image_max_edge` is set.
	 */
	public const SOCIAL_IMAGE_QUALITY = 'image_quality';

	/**
	 * Whether stored videos are re-encoded to H.264 in an MP4 by a background
	 * job, where ffmpeg is installed. Pixelfed's default `media_types` accepts
	 * only `video/mp4`, so iPhone `video/quicktime` is otherwise dropped by its
	 * inbox. The converted file replaces the original.
	 */
	public const SOCIAL_VIDEO_TRANSCODE = 'video_transcode';

	/**
	 * The tallest a converted video is written; only used when `video_transcode`
	 * is on.
	 */
	public const SOCIAL_VIDEO_MAX_HEIGHT = 'video_max_height';

	/**
	 * Whether a stored video is also written as HLS at a ladder of smaller
	 * heights, for slow connections. Off by default: it costs several encodes
	 * per video.
	 */
	public const SOCIAL_VIDEO_LADDER = 'video_ladder';

	/**
	 * Which heights, comma-separated. Rungs at or above a video's own height are
	 * skipped rather than upscaled.
	 */
	public const SOCIAL_VIDEO_LADDER_HEIGHTS = 'video_ladder_heights';

	/**
	 * How many megabytes of video one account may keep here, `0` for no limit.
	 * Off by default so existing heavy users are not suddenly refused.
	 */
	public const SOCIAL_VIDEO_QUOTA = 'video_quota';

	/**
	 * Where the local-account refresh walk got to, as an `id_prim`
	 * (bookkeeping); `''` starts at the top again.
	 */
	public const SOCIAL_LOCAL_ACTOR_CURSOR = 'local_actor_cursor';

	/**
	 * Where the profile-link verification walk over the local accounts got
	 * to, as an `id_prim`; bookkeeping like `SOCIAL_LOCAL_ACTOR_CURSOR`.
	 */
	public const SOCIAL_PROFILE_LINK_CURSOR = 'profile_link_cursor';

	/**
	 * Whether every recipient row carries its post's sort key yet; set by
	 * `Version1000Date20260917000001` and read on every home timeline to choose
	 * the fast page query. A flag because checking for a zero nid is a full scan.
	 */
	public const SOCIAL_DEST_NID_FILLED = 'dest_nid_filled';

	/**
	 * Whether every `social_stream` row carries a value in `archived`; set by
	 * `Version1000Date20261013100500`. Until then post lists must also admit NULL.
	 */
	public const SOCIAL_STREAM_ARCHIVED_FILLED = 'stream_archived_filled';

	/**
	 * Whether every `social_stream` row carries a value in `media_kind`; set by
	 * `Version1000Date20261013100500`. Until then the media filters also judge a
	 * row by its attachments, which the `(media_kind, nid)` index cannot answer.
	 */
	public const SOCIAL_STREAM_MEDIA_KIND_FILLED = 'stream_media_kind_filled';

	/**
	 * Whether every post carries its author's host in `author_host` yet; set by
	 * `Cron\StreamAuthorHosts`. Until then the domain filters also match a row
	 * with no host by its actor id.
	 */
	public const SOCIAL_STREAM_AUTHOR_HOSTS_FILLED = 'stream_author_hosts_filled';

	/**
	 * How far back a content search looks, in days; `0` searches everything.
	 * `content ILIKE '%term%'` cannot use an index, so an unbounded search scans
	 * every stored post.
	 */
	public const SOCIAL_SEARCH_WINDOW_DAYS = 'search_window_days';

	/**
	 * What this instance does with a post somebody marked sensitive, for
	 * readers who have not chosen for themselves: `show_all`, `default`
	 * (covered, one press away) or `hide_all`. PeerTube's three NSFW policies
	 * under Mastodon's names for them — see `SensitiveMediaService`.
	 */
	public const SOCIAL_NSFW_POLICY = 'nsfw_policy';

	/**
	 * Whether a post with a video on it waits for a moderator. Off by default.
	 */
	public const SOCIAL_REVIEW_VIDEOS = 'review_videos';

	/**
	 * The last measurement of this app's disk use, as JSON with its time
	 * (bookkeeping, written by the cron). See `MediaUsageService`.
	 */
	public const SOCIAL_MEDIA_USAGE = 'media_usage';

	/**
	 * Days a cached copy of another server's media is kept before the daily
	 * purge removes it; it is fetched again when somebody next looks at it.
	 * 0 disables the age limit. See `MediaPurgeService::purgeCache()`.
	 */
	public const SOCIAL_MEDIA_CACHE_DAYS = 'media_cache_days';

	/**
	 * Megabytes the cached copies of other servers' media may take up in all;
	 * the daily purge removes the oldest until they fit. 0 disables the size
	 * limit.
	 */
	public const SOCIAL_MEDIA_CACHE_MB = 'media_cache_mb';

	/**
	 * What the last purge of the media cache removed, as JSON, with the
	 * moment it ran. Bookkeeping for the administration page.
	 */
	public const SOCIAL_MEDIA_CACHE_PURGED = 'media_cache_purged';

	/**
	 * The secret a short's fetch capability is derived from. See `getStorySecret()`.
	 */
	public const SOCIAL_STORY_SECRET = 'story_secret';

	/**
	 * Per account: the Pixelfed app's own switches, stored so a reinstall finds
	 * them again. Read and written by `PixelfedService` only.
	 */
	public const APP_SETTINGS = 'app_settings';

	/** The long form of what this instance is, for `instance/extended_description`. */
	public const SOCIAL_EXTENDED_DESCRIPTION = 'extended_description';

	/**
	 * Who to write to about this instance: Mastodon's `instance.email`; empty
	 * until an administrator fills it in.
	 */
	public const CONTACT_EMAIL = 'contact_email';
	public const SOCIAL_CONTACT_ACCOUNT = 'contact_account';

	/**
	 * Whether the public instance metadata leaves out how many accounts and
	 * posts this instance has (`/api/v1/instance` stats, NodeInfo usage).
	 */
	public const SOCIAL_HIDE_INSTANCE_COUNTS = 'hide_instance_counts';

	/** When the administrators were told the app is waiting to be set up. */
	public const SETUP_ADMINS_NOTIFIED = 'setup_admins_notified';

	/**
	 * For you: the feed built from the hashtags a reader lingers on.
	 *
	 * `interests` switches the whole feature; off learns nothing and hides the
	 * feed, keeping what was learned. `interests_default` is what a reader who
	 * never chose gets (off). The other four are the score half-life in days,
	 * the score a tag needs to be listed, how many are listed, and how many
	 * days back the feed looks.
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
	 * (a JSON list), whether existing users may send invitations, and whether
	 * the form asks for a proof of work and of how many bits
	 * (`SignupChallengeService`).
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
	public const SOCIAL_EXTERNAL_PROOF_OF_WORK = 'external_signup_pow';
	public const SOCIAL_EXTERNAL_PROOF_OF_WORK_BITS = 'external_signup_pow_bits';
	public const USER_EXTERNAL_SIGNUP_NOTICE_VERSION = 'external_signup_notice_version';
	public const USER_EXTERNAL_SIGNUP_NOTICE_ACCEPTED = 'external_signup_notice_accepted';
	public const USER_EXTERNAL_SIGNUP_NOTICE_SNAPSHOT = 'external_signup_notice_snapshot';
	/** when writing the handle into the profile's `fediverse` field last failed, as a unix time */
	public const USER_HANDLE_PUBLISH_FAILED = 'handle_publish_failed';
	/** whether the web app's one-key shortcuts are on: `'0'` turns them off, anything else leaves them on */
	public const USER_KEYBOARD_SHORTCUTS = 'keyboard_shortcuts';

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
	/** Bluesky's chat service, which direct messages in a Bluesky app go through; empty for none */
	public const ATPROTO_CHAT = 'atproto_chat';
	public const ATPROTO_SYNC_CEILING = 'atproto_sync_ceiling';
	/** the firehose daemon's last report (JSON), written by `occ social:atproto:serve` */
	public const ATPROTO_FIREHOSE_STATUS = 'atproto_firehose_status';
	/** last time a relay accepted a crawl request for this PDS */
	public const ATPROTO_CRAWL_ACCEPTED = 'atproto_crawl_accepted';
	/** last time a crawl request was attempted, including failed attempts */
	public const ATPROTO_CRAWL_ATTEMPTED = 'atproto_crawl_attempted';
	/** per-relay result of the last automatic crawl request, as JSON */
	public const ATPROTO_CRAWL_RESULTS = 'atproto_crawl_results';
	/** where older versions kept the Jetstream listener's report and cursor; JetstreamState moves them out */
	public const ATPROTO_JETSTREAM_STATUS = 'atproto_jetstream_status';
	public const ATPROTO_JETSTREAM_CURSOR = 'atproto_jetstream_cursor';
	public const ATPROTO_DELETE_CURSOR = 'atproto_delete_cursor';
	/** upper NID captured when one deletion-sweep cycle starts */
	public const ATPROTO_DELETE_MAX_NID = 'atproto_delete_max_nid';
	/** where the deletion sweep's fast pass over the last week's Bluesky posts got to */
	public const ATPROTO_DELETE_RECENT_CURSOR = 'atproto_delete_recent_cursor';
	public const ATPROTO_DETACH_CURSOR = 'atproto_detach_cursor';
	public const ATPROTO_MODERATION_DID = 'atproto_moderation_did';
	/** where reports to that service are sent; empty for the endpoint its DID document names */
	public const ATPROTO_MODERATION_ENDPOINT = 'atproto_moderation_endpoint';
	public const ATPROTO_VIDEO_SERVICE = 'atproto_video_service';
	public const ATPROTO_VIDEO_SERVICE_DID = 'atproto_video_service_did';
	/** the Bluesky apps (client IDs) whose own name and logo the consent page shows */
	public const ATPROTO_OAUTH_TRUSTED = 'atproto_oauth_trusted';
	/** the most records one account keeps in collections Social has no role for; 0 is no limit */
	public const ATPROTO_OPAQUE_RECORDS = 'atproto_opaque_records';
	/** the most megabytes those records take; 0 is no limit */
	public const ATPROTO_OPAQUE_MB = 'atproto_opaque_mb';

	/**
	 * The local account this instance verifies people in the name of
	 * (`VerificationService`), by actor id; empty for none.
	 */
	public const VERIFICATION_ACCOUNT = 'verification_account';

	public array $defaults = [
		self::ATPROTO_ENABLED => '0',
		self::ATPROTO_RELAYS => '["https://bsky.network"]',
		self::ATPROTO_PLC_DIRECTORY => 'https://plc.directory',
		self::ATPROTO_APPVIEW => 'https://public.api.bsky.app',
		self::ATPROTO_APPVIEW_AUTH => 'https://api.bsky.app',
		self::ATPROTO_APPVIEW_DID => 'did:web:api.bsky.app',
		self::ATPROTO_JETSTREAM => '',
		self::ATPROTO_CHAT => 'https://api.bsky.chat',
		self::ATPROTO_SYNC_CEILING => '200',
		self::ATPROTO_FIREHOSE_STATUS => '',
		self::ATPROTO_CRAWL_ACCEPTED => '0',
		self::ATPROTO_CRAWL_ATTEMPTED => '0',
		self::ATPROTO_CRAWL_RESULTS => '[]',
		self::ATPROTO_DELETE_CURSOR => '0',
		self::ATPROTO_DELETE_MAX_NID => '0',
		self::ATPROTO_DELETE_RECENT_CURSOR => '0',
		self::ATPROTO_DETACH_CURSOR => '0',
		self::ATPROTO_MODERATION_DID => 'did:plc:ar7c4by46qjdydhdevvrndac',
		self::ATPROTO_MODERATION_ENDPOINT => '',
		self::ATPROTO_VIDEO_SERVICE => 'https://video.bsky.app',
		self::ATPROTO_VIDEO_SERVICE_DID => 'did:web:video.bsky.app',
		self::ATPROTO_OAUTH_TRUSTED => '[]',
		self::ATPROTO_OPAQUE_RECORDS => '10000',
		self::ATPROTO_OPAQUE_MB => '50',
		self::VERIFICATION_ACCOUNT => '',
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
		self::SOCIAL_MEDIA_CACHE_DAYS => '30',
		self::SOCIAL_MEDIA_CACHE_MB => '5120',
		self::SOCIAL_TOKEN_MAX_DAYS => '365',
		self::SOCIAL_TOKEN_IDLE_DAYS => '90',
		self::SOCIAL_REFRESHED_TOKEN_HOURS => '24',
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
		self::SOCIAL_STREAM_ARCHIVED_FILLED => '0',
		self::SOCIAL_STREAM_MEDIA_KIND_FILLED => '0',
		self::SOCIAL_STREAM_AUTHOR_HOSTS_FILLED => '0',
		self::SOCIAL_SEARCH_WINDOW_DAYS => '365',
		self::SOCIAL_NSFW_POLICY => 'default',
		self::SOCIAL_REVIEW_VIDEOS => '0',
		self::SOCIAL_EXTENDED_DESCRIPTION => '',
		self::CONTACT_EMAIL => '',
		self::SOCIAL_HIDE_INSTANCE_COUNTS => '0',
		self::SETUP_ADMINS_NOTIFIED => '',
		self::SOCIAL_CONTACT_ACCOUNT => '',
		self::SOCIAL_POLLS_SWEPT => '0',
		self::SOCIAL_INTERESTS => '1',
		self::SOCIAL_INTERESTS_DEFAULT => '0',
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
		self::SOCIAL_EXTERNAL_PROOF_OF_WORK => '1',
		self::SOCIAL_EXTERNAL_PROOF_OF_WORK_BITS => '20',
	];

	public array $accessTypeList = [
		'BLACKLIST' => 'all_but',
		'WHITELIST' => 'none_but'
	];

	private ?string $userId = null;

	/** Seconds a federation request may take when nobody asks for anything else. */
	public const DEFAULT_REQUEST_TIMEOUT = 10;

	/**
	 * Seconds allowed for reaching a peer (DNS, TCP, TLS) unless asked otherwise;
	 * never more than the request's own timeout. Kept short so a dead host fails
	 * fast; a connected slow peer still gets the full timeout.
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
	 * A switch: anything but the literal `0` is on.
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
	 * A value Nextcloud loads only on request, for bookkeeping too large to load
	 * on every request (Nextcloud warns above 128 bytes). A value stored non-lazy
	 * is migrated first, since a lazy read does not return it.
	 */
	public function getLazyAppValue(string $key): string {
		$this->moveToLazy($key);

		return $this->appConfig->getValueString(
			Application::APP_ID, $key, (string)($this->defaults[$key] ?? ''), lazy: true
		);
	}

	/** Stores a value the way `getLazyAppValue()` reads it. */
	public function setLazyAppValue(string $key, string $value): void {
		$this->moveToLazy($key);
		$this->appConfig->setValueString(Application::APP_ID, $key, $value, lazy: true);
	}

	private function moveToLazy(string $key): void {
		if ($this->appConfig->hasKey(Application::APP_ID, $key, false)) {
			$this->appConfig->updateLazy(Application::APP_ID, $key, true);
		}
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

		// A bearer-token request has no session user, and the backend fatals on a
		// null user id.
		if ($userId === '') {
			return (string)$defaultValue;
		}

		return $this->userConfig->getValueString($userId, $app, $key, (string)$defaultValue);
	}

	/**
	 * A user setting another app owns, as the number it holds.
	 *
	 * Nextcloud 32 throws when a typed value is read as another type, and the
	 * owning app may store it as text or int (the avatar app's `version`), so
	 * this falls back to reading the text and converting it.
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
	 * Drops this process's cached settings of a user, so a long-running process
	 * sees values another process wrote.
	 */
	public function forgetUserValues(string $userId): void {
		if ($userId !== '') {
			$this->userConfig->clearCache($userId);
		}
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
	 * Whether this server may reach the internet at all: Nextcloud's own
	 * `has_internet_connection`, true unless an administrator says otherwise.
	 */
	public function hasInternetConnection(): bool {
		return $this->config->getSystemValueBool('has_internet_connection', true);
	}

	/**
	 * Whether outbound requests may reach the instance's own network (the
	 * standard Nextcloud setting, off by default).
	 */
	public function isLocalNetworkAllowed(): bool {
		return $this->config->getSystemValueBool('allow_local_remote_servers', false);
	}

	/**
	 * The configured host as a peer addresses it: the hostname, plus the port
	 * when it is not the scheme's default. Peers sign `Host`, so `:8443` matters.
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

		// parse_url() returns null for a URL with no host and false for one it
		// cannot parse.
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
	 * Stores the address every id is built from. Callers check it against
	 * `CloudAddressRules` first; an address given without a scheme is taken
	 * as https.
	 */
	public function setCloudUrl(string $cloudAddress) {
		$cloudAddress = CloudAddressRules::normalize($cloudAddress);
		if (parse_url($cloudAddress, PHP_URL_SCHEME) === null) {
			$cloudAddress = 'https://' . $cloudAddress;
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
	 * What `social_url` should be for the stored `cloud_url`: that address with
	 * the app's route path from `/apps/` on, or '' while there is none.
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
	 * The secret the short capabilities are derived from, generated on first use.
	 * Changing it revokes every capability at once.
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
	 * Stores the app's own base URL, which every id is minted from. Without an
	 * argument it is derived from `cloud_url`, and nothing is stored while that
	 * is not set.
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
			// random_int() for the random half; the timestamp-plus-ten-digits shape keeps
			// stored ids valid.
			$id .= time() . str_pad((string)random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
		}

		return $id;
	}

	/**
	 * Runs $action with every federation request it makes bounded to $timeout
	 * seconds, so work done before authentication cannot hold a PHP worker for
	 * the full federation timeout.
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
	 * `OCP\Http\Client\IClient` takes them: timeout, certificate verification,
	 * and whether local network targets are allowed (admin opt-in only).
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
	 * The ActivityPub content negotiation headers a federation request carries.
	 * WebFinger, host-meta and cached media pass `json_headers: false`.
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
