<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

/**
 * Every key a `details` blob may hold.
 *
 * `social_stream.details` and `social_cache_actors.details` are JSON columns
 * that anything may write anything into, and for a long time everything did:
 * the keys were spelled as string literals at some seventy call sites, with a
 * handful promoted to constants on whichever class happened to need one. A
 * misspelling was not an error but a new key, silently holding the value the
 * reader was looking for under a name it would never look up, and there was no
 * place to read to find out what a row might contain.
 *
 * So this is the vocabulary, and the only place a details key is spelled. The
 * strings are what is already in the database and must not be changed: they
 * are stored, not derived, and every row written since 2018 uses them.
 *
 * @see \OCA\Social\Traits\TDetails for the accessors
 */
final class Details {
	/**
	 * Counts on a post.
	 *
	 * Each pair is "what this server can see" and "what the origin claimed":
	 * a post's own server counts the whole network's likes, this one counts
	 * only the likes it stores, and the larger of the two is what a reader is
	 * shown. Keeping both is what stops a remote total from being overwritten
	 * by a local count of one.
	 */
	public const REPLIES = 'replies';
	public const BOOSTS = 'boosts';
	public const LIKES = 'likes';
	public const DISLIKES = 'dislikes';
	public const REMOTE_REPLIES = 'remote_replies';
	public const REMOTE_BOOSTS = 'remote_boosts';
	public const REMOTE_LIKES = 'remote_likes';

	/** Handles mentioned in a post, as an array of strings. */
	public const MENTIONS = 'mentions';

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
	 * Whether this post may be quoted, and whether a quote was let through.
	 *
	 * `quote` and `quoteAuthorization` are properties of the stored wire object
	 * and come back with it, but a refusal leaves no trace there: the quoting
	 * post is somebody else's document and this instance may not rewrite it.
	 * A refusal is local, derived data — exactly what this column is for.
	 */
	public const QUOTE_STATE = 'quote_state';

	/**
	 * Whether replies to this post have to be approved before anybody sees
	 * them, and — on a reply of ours — whether this one has been.
	 *
	 * PeerTube ≥ 6.2 moderates comments (FEP-5624): a video whose
	 * `commentsPolicy` is 3 takes a reply in and shows it to nobody until a
	 * human approves it, and says so by sending an `ApproveReply` back to the
	 * server the reply came from. Without reading any of that, a reply written
	 * here looked posted, sat in a queue on the other side, and either appeared
	 * a day later or never — with nothing anywhere to say which.
	 *
	 * Neither fact is a property of the wire object this instance holds, and
	 * the second one is about somebody else's document entirely.
	 */
	public const REPLY_POLICY = 'reply_policy';
	public const REPLY_STATE = 'reply_state';

	/**
	 * Everything a `Video` says that a `Note` has nowhere to put: the category,
	 * the licence, the language, the chapters, the captions, the counters and
	 * the author's support line.
	 *
	 * One block rather than a dozen columns, because it is local derived data
	 * about somebody else's document — which is what this column is for — and
	 * because a watch page wants all of it or none of it.
	 */
	public const VIDEO = 'video';

	/**
	 * How many levels up the climb to this post's ancestors already is.
	 *
	 * `NoteInterface::save()` stops queueing the next parent once it reaches
	 * `StreamQueueService::MAX_ANCESTOR_DEPTH`, so a thread of a thousand
	 * messages costs eight fetches rather than a thousand.
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

	/** The post a notification is about, as a serialised item. */
	public const POST = 'post';

	/** The actor a notification is about, as a serialised item. */
	public const ACTOR = 'actor';

	/**
	 * Who a notification is about.
	 *
	 * `ACCOUNTS` is a list, appended to as more people like or boost the same
	 * post, so one notification says "and four others". `ACCOUNT` is written
	 * both as a single handle (a follow) and as a one-element list (a mention),
	 * which is a wart the readers work around rather than a distinction.
	 */
	public const ACCOUNT = 'account';
	public const ACCOUNTS = 'accounts';

	/** Where a notification points. */
	public const URL = 'url';

	/** What a moderation or story notification says, and about what. */
	public const ACTION = 'action';
	public const TEXT = 'text';
	public const CONTENT = 'content';
	public const STORY_ID = 'story_id';
	public const TARGET_NAME = 'target_name';
	public const RELATIONSHIPS_COUNT = 'relationships_count';
}
