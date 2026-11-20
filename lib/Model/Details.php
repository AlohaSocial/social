<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

/**
 * Every key a `details` blob may hold, and the only place one is spelled, so
 * a misspelling cannot silently become a new key. The strings are stored in
 * the database and must not change.
 *
 * @see \OCA\Social\Traits\TDetails for the accessors
 */
final class Details {
	/**
	 * Counts on a post, each pair "what this server can see" and "what the
	 * origin claimed"; the larger is shown, so a remote total is not
	 * overwritten by a local count of one.
	 */
	public const REPLIES = 'replies';
	public const BOOSTS = 'boosts';
	public const LIKES = 'likes';
	public const DISLIKES = 'dislikes';
	/** the posts quoting it, as `REPLIES` counts its replies */
	public const QUOTES = 'quotes';
	public const REMOTE_REPLIES = 'remote_replies';
	public const REMOTE_BOOSTS = 'remote_boosts';
	public const REMOTE_LIKES = 'remote_likes';
	public const REMOTE_QUOTES = 'remote_quotes';

	/** Handles mentioned in a post, as an array of strings. */
	public const MENTIONS = 'mentions';
	/** a local account's Bluesky handle, DID and profile link, for the client */
	public const BLUESKY = 'bluesky';

	/** a Bluesky account or post read here: its DID or `at://` URI, CID, counts and labels */
	public const ATPROTO = 'atproto';
	/** a local post's non-post AT Protocol record embed, as its original strong reference */
	public const ATPROTO_RECORD_EMBED = 'record_embed';
	/** the self-label values received with an AT Protocol post record */
	public const ATPROTO_SELF_LABELS = 'self_labels';

	/**
	 * The page a post is at on the server it came from.
	 *
	 * ActivityPub's `id` is a document address and `url` is a page a person can
	 * open; `social_stream` has no column for the second, so it is kept here.
	 */
	public const PAGE = 'page_url';

	/**
	 * What the author allows besides quoting, as `interaction => policy`.
	 *
	 * Three more columns on the largest table in the app, for three fields
	 * almost no post carries and nothing queries by, would be the wrong trade.
	 */
	public const POLICIES = 'policies';

	/**
	 * Whom a narrower-than-public policy names, as `interaction => [ids]`:
	 * the actors and the author's collections listed in its
	 * `automaticApproval`, which are a "yes" for those they name.
	 */
	public const POLICY_GRANTEES = 'policy_grantees';

	/**
	 * Whether this post may be quoted, and whether a quote was let through. A
	 * refusal leaves no trace in the quoting post's wire object, which is
	 * somebody else's document, so it is kept here.
	 */
	public const QUOTE_STATE = 'quote_state';

	/**
	 * Whether replies to this post have to be approved before anybody sees
	 * them and, on a reply of ours, whether it has been: PeerTube ≥ 6.2
	 * (FEP-5624) holds replies to a video whose `commentsPolicy` is 3 and
	 * sends an `ApproveReply` once a human approves.
	 */
	public const REPLY_POLICY = 'reply_policy';
	/** who may reply to one of this instance's own posts (`Stream::normalizeReplyRule()`) */
	public const REPLY_RULE = 'reply_rule';
	/** the `at://` URIs of the Bluesky quotes the author of one of this instance's own posts detached */
	public const DETACHED_QUOTES = 'detached_quotes';
	/** the replies the author of a thread's first post hid, by their ids, kept on that post */
	public const HIDDEN_REPLIES = 'hidden_replies';
	/** the members of the lists a post's reply rule names, when it was set */
	public const REPLY_LIST_MEMBERS = 'reply_list_members';
	public const REPLY_STATE = 'reply_state';

	/**
	 * Everything a `Video` says that a `Note` has nowhere to put: category,
	 * licence, language, chapters, captions, counters and the author's support
	 * line, in one block, as a watch page wants all or none of it.
	 */
	public const VIDEO = 'video';

	/** The public schedule and venue stated on an ActivityStreams Event. */
	public const EVENT = 'event';

	/** The original heading of an ActivityStreams Article or Page. */
	public const ARTICLE_TITLE = 'article_title';

	/**
	 * How many levels up the climb to this post's ancestors already is:
	 * `NoteInterface::save()` stops queueing parents at
	 * `StreamQueueService::MAX_ANCESTOR_DEPTH`.
	 */
	public const ANCESTOR_DEPTH = 'ancestor_depth';

	/** An actor's followers/following/post counts, as an array. */
	public const COUNT = 'count';

	/** Whether the viewer follows this actor, and whether they follow back. */
	public const FOLLOWING = 'following';
	public const FOLLOWED = 'followed';

	/** When this actor last posted, as a date, for pruning what nobody uses. */
	public const LAST_POST = 'last_post_creation';

	/** Profile links this actor's own pages vouched for, and when that was checked. */
	public const FIELDS_VERIFIED = 'fields_verified';
	public const FIELDS_CHECKED = 'fields_checked';

	/** The post a notification is about, handed along in memory and never stored. */
	public const POST = 'post';

	/** The actor a notification is about, handed along in memory and never stored. */
	public const ACTOR = 'actor';

	/**
	 * Who a notification is about. `ACCOUNTS` is a list, appended to as more
	 * people like or boost the same post. `ACCOUNT` is written both as a
	 * single handle (a follow) and as a one-element list (a mention), which
	 * readers handle both ways.
	 */
	public const ACCOUNT = 'account';
	public const ACCOUNTS = 'accounts';

	/** Where a notification points. */
	public const URL = 'url';

	/** What a moderation or short notification says, and about what. */
	public const ACTION = 'action';
	public const TEXT = 'text';
	public const CONTENT = 'content';
	public const STORY_ID = 'story_id';
	public const TARGET_NAME = 'target_name';
	public const RELATIONSHIPS_COUNT = 'relationships_count';
}
