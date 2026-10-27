<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

/**
 * Who may read which post, and what each read path is expected to show of it.
 *
 * One table, `mayRead()`, says whether a viewer may read a post at all; it is
 * the same for every path, and a path that shows a post to somebody the table
 * says may not read it is a leak. On top of it each read path has its own
 * listing rule, `shown()`: a public timeline lists public posts only, a home
 * timeline the posts of followed accounts, a conversation list direct posts
 * only. The rule is consulted only where the table says the viewer may read
 * the post, so no rule can widen an audience.
 *
 * The posts are written by `author`, mention `mentioned`, and carry a hashtag;
 * `quote` is a public post of a remote account quoting one of the author's
 * followers-only posts, and `boost` that account's boost of another one. What
 * is looked for is the followers-only text, so for those two rows the table
 * says who may read the post inside.
 *
 * The viewers:
 * - `author` wrote the posts;
 * - `follower` follows the author, accepted;
 * - `pending` asked to follow the author, who has not answered;
 * - `mentioned` is named in every post and follows nobody;
 * - `stranger` has no relation to the author;
 * - `anonymous` is nobody signed in;
 * - `blocked` is blocked by the author;
 * - `muter` follows the author and has muted them.
 *
 * Plain data: the unit suite reads `ROUTES` to make sure every route that
 * serves posts is either a path of the matrix or named with a reason.
 */
final class VisibilityMatrix {
	public const VIEWERS = ['author', 'follower', 'pending', 'mentioned', 'stranger', 'anonymous', 'blocked', 'muter'];
	public const POSTS = ['public', 'unlisted', 'followers', 'direct', 'quote', 'boost'];

	/** the rows a remote account wrote, which no route of this server serves as its own */
	public const REMOTE_POSTS = ['quote', 'boost'];
	/** the rows the author wrote here */
	public const LOCAL_POSTS = ['public', 'unlisted', 'followers', 'direct'];

	public const SIGNED_IN = ['author', 'follower', 'pending', 'mentioned', 'stranger', 'blocked', 'muter'];

	/** shown or hidden, as `shown()` answers; `null` is either, a choice that is the path's to make */
	public const EITHER = null;

	/**
	 * Every read path of the matrix, with the viewers and posts it is asked
	 * about. A path whose answer does not depend on who asks is asked as
	 * `anonymous` only.
	 *
	 * @var array<string, array{viewers: list<string>, posts: list<string>}>
	 */
	public const PATHS = [
		'status' => ['viewers' => self::VIEWERS, 'posts' => self::POSTS],
		'context' => ['viewers' => self::VIEWERS, 'posts' => ['public', 'unlisted', 'followers', 'direct', 'quote']],
		'thread' => ['viewers' => self::VIEWERS, 'posts' => self::LOCAL_POSTS],
		'favourited_by' => ['viewers' => self::VIEWERS, 'posts' => self::LOCAL_POSTS],
		'reblogged_by' => ['viewers' => self::VIEWERS, 'posts' => self::LOCAL_POSTS],
		'quotes' => ['viewers' => self::VIEWERS, 'posts' => self::LOCAL_POSTS],
		'account_statuses' => ['viewers' => self::VIEWERS, 'posts' => self::POSTS],
		'home' => ['viewers' => self::SIGNED_IN, 'posts' => self::POSTS],
		'app_first_page' => ['viewers' => self::SIGNED_IN, 'posts' => self::POSTS],
		'public' => ['viewers' => self::VIEWERS, 'posts' => self::POSTS],
		// the hashtag timeline asks for a token
		'tag' => ['viewers' => self::SIGNED_IN, 'posts' => ['public', 'unlisted', 'followers', 'direct', 'quote']],
		'list' => ['viewers' => self::SIGNED_IN, 'posts' => self::POSTS],
		'search_statuses' => ['viewers' => self::SIGNED_IN, 'posts' => self::POSTS],
		'search_resolve' => ['viewers' => self::SIGNED_IN, 'posts' => self::POSTS],
		'notifications' => ['viewers' => self::SIGNED_IN, 'posts' => self::POSTS],
		'notifications_v2' => ['viewers' => self::SIGNED_IN, 'posts' => self::POSTS],
		'bookmark' => ['viewers' => self::SIGNED_IN, 'posts' => self::POSTS],
		'bookmarks' => ['viewers' => self::SIGNED_IN, 'posts' => self::POSTS],
		'favourite' => ['viewers' => self::SIGNED_IN, 'posts' => self::POSTS],
		'favourites' => ['viewers' => self::SIGNED_IN, 'posts' => self::POSTS],
		'conversations' => ['viewers' => self::SIGNED_IN, 'posts' => self::POSTS],
		'activitypub_object' => ['viewers' => ['anonymous'], 'posts' => self::LOCAL_POSTS],
		'activitypub_object_signed' => ['viewers' => self::SIGNED_IN, 'posts' => self::LOCAL_POSTS],
		'activitypub_replies' => ['viewers' => self::VIEWERS, 'posts' => self::LOCAL_POSTS],
		'activitypub_likes' => ['viewers' => self::VIEWERS, 'posts' => self::LOCAL_POSTS],
		'rss' => ['viewers' => ['anonymous'], 'posts' => self::LOCAL_POSTS],
		'oembed' => ['viewers' => ['anonymous'], 'posts' => self::LOCAL_POSTS],
		'embed' => ['viewers' => ['anonymous'], 'posts' => self::LOCAL_POSTS],
		'post_page' => ['viewers' => self::VIEWERS, 'posts' => self::LOCAL_POSTS],
		'unified_search' => ['viewers' => self::SIGNED_IN, 'posts' => self::POSTS],
		'reference' => ['viewers' => self::VIEWERS, 'posts' => self::LOCAL_POSTS],
		'widget_timeline' => ['viewers' => self::SIGNED_IN, 'posts' => self::POSTS],
		'widget_mentions' => ['viewers' => self::SIGNED_IN, 'posts' => self::POSTS],
		'widget_direct' => ['viewers' => self::SIGNED_IN, 'posts' => self::POSTS],
		'widget_bookmarks' => ['viewers' => self::SIGNED_IN, 'posts' => self::POSTS],
	];

	/**
	 * The route behind each read path, `Controller::method`. A route that
	 * serves posts and is in neither this list nor `ROUTES_OUTSIDE` fails
	 * `VisibilityMatrixRoutesTest`.
	 *
	 * @var array<string, string> route => read path
	 */
	public const ROUTES = [
		'StatusApiController::statusGet' => 'status',
		'StatusApiController::statusContext' => 'context',
		'StatusApiController::statusFavouritedBy' => 'favourited_by',
		'StatusApiController::statusRebloggedBy' => 'reblogged_by',
		'StatusApiController::statusQuotes' => 'quotes',
		'AccountApiController::accountStatuses' => 'account_statuses',
		'TimelineApiController::timelines' => 'home',
		'NavigationController::navigate' => 'app_first_page',
		'NavigationController::timeline' => 'app_first_page',
		'NavigationController::personal' => 'app_first_page',
		'TimelineApiController::tag' => 'tag',
		'ListController::timeline' => 'list',
		'ApiController::searchV2' => 'search_statuses',
		'ApiController::search' => 'search_statuses',
		'TimelineApiController::notifications' => 'notifications',
		'NotificationController::indexV2' => 'notifications_v2',
		'StatusApiController::statusAction' => 'bookmark',
		'TimelineApiController::bookmarks' => 'bookmarks',
		'TimelineApiController::favourites' => 'favourites',
		'ConversationController::index' => 'conversations',
		// and, for a browser, SocialPubController::displayPost(): post_page
		'ActivityPubController::displayPost' => 'activitypub_object',
		'ActivityPubController::replies' => 'activitypub_replies',
		'ActivityPubController::likes' => 'activitypub_likes',
		'ActivityPubController::feed' => 'rss',
		'InstanceApiController::oembed' => 'oembed',
		'SocialPubController::embedPost' => 'embed',
	];

	/**
	 * The read paths that are not routes: dashboard widgets, the unified
	 * search provider and the reference provider, by class.
	 *
	 * @var array<string, string> class => read path
	 */
	public const OUTSIDE_ROUTER = [
		'SocialTimelineWidget' => 'widget_timeline',
		'SocialMentionsWidget' => 'widget_mentions',
		'SocialDirectWidget' => 'widget_direct',
		'SocialBookmarksWidget' => 'widget_bookmarks',
		'UnifiedSearchProvider' => 'unified_search',
		'PostReferenceProvider' => 'reference',
	];

	/**
	 * Routes that serve posts and are not a path of the matrix, and why.
	 *
	 * @var array<string, string>
	 */
	public const ROUTES_OUTSIDE = [
		'CalendarEventController::statusCalendar' => 'exports an event only after getStreamByNid() applies the same visibility gate as status; CalendarEventControllerTest',
		'MediaApiController::mediaCaption' => 'a subtitle track, no post: the post is read through the same getStreamByNid() gate as status, and one the viewer may not see is a 404',
		'ActivityPubController::dislikes' => 'the same openLocalPost() gate as likes, which is in the matrix',
		'ActivityPubController::displayQuoteAuthorization' => 'a stamp naming two post ids, served for public quoted posts only; QuoteAuthorizationTest',
		'ActivityPubController::displayReplyAuthorization' => 'a stamp naming one reply and its public target, no post content; ReplyAuthorizationTest',
		'ActivityPubController::outbox' => 'public posts only, built from an anonymous read; OutboundCollectionGoldenTest',
		'ActivityPubController::featured' => 'pinned posts, public only; PinnedPostsTest',
		'AdminApiController::trendStatuses' => 'an administrator reviewing trends, not a reader',
		'AnnualReportApiController::annualReport' => 'the viewer\'s own posts and counts',
		'AnnualReportApiController::annualReports' => 'the viewer\'s own posts and counts',
		'AtprotoFeedsController::timeline' => 'Bluesky posts read from the AppView, never a post of this server',
		'ConversationController::unreadCount' => 'a count of the viewer\'s conversations, no post',
		'NotificationController::unreadCountV2' => 'a count of the viewer\'s notifications, no post',
		'NotificationController::policy' => 'the viewer\'s notification policy and a count of what it holds',
		'NotificationController::group' => 'one group of the same visible() page notifications_v2 reads',
		'NotificationController::groupAccounts' => 'the accounts of one group of the page notifications_v2 reads',
		'NotificationController::requests' => 'notifications the policy holds; the matrix accepts every notification, so it holds none',
		'NotificationController::request' => 'notifications the policy holds; the matrix accepts every notification, so it holds none',
		'TimelineApiController::notificationsUnreadCount' => 'a count of the viewer\'s notifications, no post',
		'CollectionController::items' => 'collection items are posts checked one by one; CollectionItemVisibilityTest',
		'DiscoveryController::discoverPosts' => 'trending posts, public only by construction; TrendsRequestTest',
		'DiscoveryController::linkTimeline' => 'posts sharing a link, public only by construction; StreamCardsTest',
		'DiscoveryController::trendStatuses' => 'trending posts, public only by construction; TrendsRequestTest',
		'HistoryController::history' => 'the revisions of a post the viewer is shown; StatusRevisionsRequestTest',
		'MediaApiController::mediaGet' => 'the viewer\'s own upload',
		'MemoriesController::onThisDay' => 'the viewer\'s own posts',
		'PeerTubeApiController::comments' => 'replies of a public video, PeerTube\'s shape; not seeded here',
		'PeerTubeApiController::searchVideos' => 'videos only, the same searchStreamContent() as search_statuses',
		'PeerTubeApiController::video' => 'videos only, the same getStreamByNid() as status',
		'PeerTubeApiController::videos' => 'videos only, public by construction',
		'PixelfedController::discoverNetworkTrending' => 'trending posts, public only by construction',
		'PixelfedController::discoverPosts' => 'trending posts, public only by construction',
		'PixelfedController::discoverPostsTrending' => 'trending posts, public only by construction',
		'PlaceController::statuses' => 'posts tagged with a place, read as the viewer; PlacesRequestTest',
		'ScheduledStatusApiController::scheduledStatusGet' => 'the viewer\'s own scheduled post',
		'ScheduledStatusApiController::scheduledStatuses' => 'the viewer\'s own scheduled posts',
		'StatusApiController::pollGet' => 'the poll of a post, read through the same getStreamByNid() as status',
		'StatusApiController::statusCard' => 'the link card of a post, read through the same getStreamByNid() as status',
		'StatusApiController::statusDelivery' => 'the viewer\'s own post',
		'StatusApiController::statusReactions' => 'emoji reactions of a post, read through the same getStreamByNid() as status',
		'StatusApiController::statusSource' => 'the viewer\'s own post',
		'TimelineApiController::videosContinue' => 'the viewer\'s own watch history',
	];

	/** Whether the viewer may read the post at all, on any path. */
	public static function mayRead(string $post, string $viewer): bool {
		return match ($viewer) {
			'author', 'mentioned' => true,
			'follower', 'muter' => $post !== 'direct',
			'pending', 'stranger', 'anonymous' => in_array($post, ['public', 'unlisted'], true),
			'blocked' => false,
		};
	}

	/**
	 * Whether a post the viewer may read is on their home timeline: their own,
	 * of an account they follow and do not mute, or naming them. Direct posts
	 * are read in the conversations, not on home; a quote by somebody else of
	 * the muted account's post is the quoter's post.
	 */
	private static function onHome(string $post, string $viewer): ?bool {
		$muted = ($viewer === 'muter');

		return match (true) {
			$post === 'direct' => self::EITHER,
			$post === 'quote' && $muted => self::EITHER,
			$muted => false,
			default => in_array($viewer, ['author', 'follower', 'mentioned'], true) || in_array($post, self::REMOTE_POSTS, true),
		};
	}

	/**
	 * Paths whose answer is the same for whoever asks, by design: a reader
	 * the author blocked gets what an anonymous one gets, which is what it
	 * would get by not signing.
	 */
	private const SAME_FOR_EVERYBODY = ['activitypub_replies', 'activitypub_likes', 'reference'];

	/** Paths that serve one status by its id, and serve a boost without the post it repeats. */
	private const BY_ID = ['status', 'search_resolve', 'bookmark', 'bookmarks', 'favourite', 'favourites', 'account_statuses'];

	/**
	 * What the path is expected to show: `true` shown, `false` hidden,
	 * `null` either.
	 */
	public static function expected(string $path, string $post, string $viewer): ?bool {
		if (in_array($path, self::SAME_FOR_EVERYBODY, true) && $viewer === 'blocked') {
			$viewer = 'anonymous';
		}
		if (!self::mayRead($post, $viewer)) {
			return false;
		}

		$muted = ($viewer === 'muter');
		$followsOwner = in_array($viewer, ['follower', 'muter'], true) || in_array($post, self::REMOTE_POSTS, true);
		if ($post === 'boost' && in_array($path, self::BY_ID, true)) {
			// a boost asked for by its own id, or listed as a status of the
			// booster, comes without the post it repeats
			return self::EITHER;
		}

		return match ($path) {
			'status', 'context', 'search_resolve', 'bookmark', 'bookmarks',
			'favourite', 'favourites', 'favourited_by', 'activitypub_object_signed' => true,
			// the author's own direct posts are left off their profile, and
			// shown on the profile to the account they were sent to
			'account_statuses' => ($post === 'direct' && $viewer === 'author') ? self::EITHER : true,
			'thread' => $muted ? self::EITHER : true,
			'reblogged_by' => true,
			// the author's own list, or a reader's: Mastodon serves it to the author only
			'quotes' => ($viewer === 'author') ? true : self::EITHER,
			'home' => self::onHome($post, $viewer),
			// the first fifteen posts of home only, so what is further down
			// may be missing from it
			'app_first_page' => (self::onHome($post, $viewer) === false) ? false : self::EITHER,
			'public' => match ($post) {
				'public' => !$muted,
				'quote' => $muted ? self::EITHER : true,
				'boost' => self::EITHER,
				default => false,
			},
			// every post the reader may read that carries the hashtag, not
			// only the public ones Mastodon lists
			'tag' => match ($post) {
				'public' => !$muted,
				'quote' => $muted ? self::EITHER : true,
				default => self::EITHER,
			},
			'list' => match (true) {
				$post === 'quote' && $muted => self::EITHER,
				$muted => false,
				!$followsOwner => false,
				$post === 'direct' => self::EITHER,
				default => true,
			},
			'search_statuses', 'unified_search' => self::EITHER,
			'notifications', 'notifications_v2' => ($viewer === 'mentioned') ? true : self::EITHER,
			'conversations' => ($post === 'direct') ? true : false,
			'activitypub_object' => true,
			// public posts only, whoever asks or signed the fetch
			'activitypub_replies', 'activitypub_likes', 'rss', 'oembed', 'reference' => match ($post) {
				'public' => true,
				'unlisted' => self::EITHER,
				default => false,
			},
			'embed' => $post === 'public',
			'post_page' => true,
			'widget_timeline', 'widget_mentions', 'widget_bookmarks' => self::EITHER,
			'widget_direct' => ($post === 'direct') ? self::EITHER : false,
		};
	}
}
