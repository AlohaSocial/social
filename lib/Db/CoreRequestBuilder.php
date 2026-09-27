<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\Details;
use OCA\Social\Model\StreamAction;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCA\Social\Tools\IExtendedQueryBuilder;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Class CoreRequestBuilder
 *
 * @package OCA\Social\Db
 */
class CoreRequestBuilder {
	public const TABLE_ACTIONS = 'social_action';
	public const TABLE_ACTORS = 'social_actor';
	public const TABLE_ACTOR_RELATION = 'social_actor_relation';
	public const TABLE_CACHE_ACTORS = 'social_cache_actor';
	public const TABLE_CACHE_DOCUMENTS = 'social_cache_doc';
	public const TABLE_CLIENT = 'social_client';
	public const TABLE_CLIENT_AUTH = 'social_client_auth';
	public const TABLE_EMOJI = 'social_emoji';
	public const TABLE_GIFS = 'social_gif';
	public const TABLE_FOLLOWED_TAGS = 'social_followed_tag';
	public const TABLE_FOLLOWS = 'social_follow';
	public const TABLE_HASHTAGS = 'social_hashtag';
	public const TABLE_INTERESTS = 'social_interest';
	public const TABLE_INTEREST_HIDES = 'social_interest_hide';
	public const TABLE_FILTERS = 'social_filter';
	public const TABLE_FILTER_KEYWORDS = 'social_filter_kw';
	public const TABLE_FILTER_STATUSES = 'social_filter_st';
	public const TABLE_ANNOUNCEMENTS = 'social_announcement';
	public const TABLE_ANNOUNCEMENT_READS = 'social_announce_read';
	public const TABLE_ANNOUNCEMENT_REACTIONS = 'social_announce_react';
	public const TABLE_ACCESS_BLOCKS = 'social_access_block';
	public const TABLE_ACCOUNT_NOTES = 'social_account_note';
	public const TABLE_CONVERSATION_STATE = 'social_convo_state';
	public const TABLE_DOMAIN_BLOCKS = 'social_domain_block';
	public const TABLE_DURABLE_CACHE = 'social_durable_cache';
	public const TABLE_MUTE_EXPIRY = 'social_mute_expiry';
	public const TABLE_FEATURED_TAGS = 'social_featured_tag';
	public const TABLE_STATUS_REVISIONS = 'social_stream_rev';
	public const TABLE_LISTS = 'social_list';
	public const TABLE_LIST_MEMBERS = 'social_list_member';
	public const TABLE_COLLECTIONS = 'social_collection';
	public const TABLE_COLLECTION_ITEMS = 'social_collection_item';
	public const TABLE_IMPORTED_POSTS = 'social_import_post';
	public const TABLE_INSTANCE = 'social_instance';
	public const TABLE_DISCOVER_CATS = 'social_discover_cat';
	public const TABLE_MEDIA_BLOCKS = 'social_media_block';
	public const TABLE_MODERATION = 'social_moderation';
	public const TABLE_REPORTS = 'social_report';
	public const TABLE_REACTIONS = 'social_reaction';
	public const TABLE_REQUEST_QUEUE = 'social_req_queue';
	public const TABLE_HOST_BREAKER = 'social_host_breaker';
	public const TABLE_POST_HOLD = 'social_post_hold';
	public const TABLE_SCHEDULED = 'social_scheduled';
	public const TABLE_PLACES = 'social_place';
	public const TABLE_STORIES = 'social_story';
	public const TABLE_STORY_VIEWS = 'social_story_view';
	public const TABLE_STORY_REACTS = 'social_story_react';
	public const TABLE_MEDIA_TAGS = 'social_media_tag';
	public const TABLE_PORTFOLIOS = 'social_portfolio';
	public const TABLE_TEAMS = 'social_team';
	public const TABLE_TEAM_POSTS = 'social_team_post';
	public const TABLE_TREND_REVIEW = 'social_trend_review';
	public const TABLE_RELAYS = 'social_relay';
	public const TABLE_QUOTE_GRANTS = 'social_quote_grant';
	public const TABLE_CHANNELS = 'social_channel';
	public const TABLE_WATCH = 'social_watch';
	public const TABLE_RENDITIONS = 'social_video_rendition';
	public const TABLE_STREAM = 'social_stream';
	public const TABLE_STREAM_ACTIONS = 'social_stream_act';
	public const TABLE_STREAM_VIEWS = 'social_stream_view';
	public const TABLE_STREAM_CARDS = 'social_stream_card';
	public const TABLE_STREAM_DEST = 'social_stream_dest';
	public const TABLE_STRIKES = 'social_strike';
	public const TABLE_STREAM_QUEUE = 'social_stream_queue';
	public const TABLE_STREAM_TAGS = 'social_stream_tag';

	public static array $tables = [
		self::TABLE_ACTIONS => [
			'id_prim',
			'id',
			'type',
			'actor_id',
			'actor_id_prim',
			'object_id',
			'object_id_prim',
			'creation'
		],
		self::TABLE_GIFS => [
			'id',
			'slug',
			'title',
			'filename',
			'media_type',
			'creation'
		],
		self::TABLE_REACTIONS => [
			'id_prim',
			'id',
			'actor_id',
			'actor_id_prim',
			'object_id',
			'object_id_prim',
			'emoji',
			'creation'
		],
		self::TABLE_ACTOR_RELATION => [
			'id',
			'actor_id_prim',
			'object_id',
			'object_id_prim',
			'type',
			'notifications',
			'creation'
		],
		self::TABLE_ACTORS => [
			'id_prim',
			'id',
			'actor_type',
			'user_id',
			'preferred_username',
			'name',
			'summary',
			'public_key',
			'private_key',
			'avatar_version',
			'creation',
			'locked',
			'fields',
			'discoverable',
			'indexable',
			'bot',
			'also_known_as',
			'moved_to'
		],
		self::TABLE_CACHE_ACTORS => [
			'id_prim',
			'id',
			'type',
			'account',
			'account_lower',
			'host',
			'local',
			'following',
			'followers',
			'inbox',
			'outbox',
			'featured',
			'url',
			'preferred_username',
			'name',
			'icon_id',
			'summary',
			'public_key',
			'source',
			'details',
			'details_update',
			'sync_attempt',
			'sync_failures',
			'creation',
			'count_followers',
			'count_following',
			'count_posts'
		],
		self::TABLE_CACHE_DOCUMENTS => [
			'nid',
			'id_prim',
			'id',
			'type',
			'account',
			'parent_id',
			'media_type',
			'mime_type',
			'url',
			'local_copy',
			'resized_copy',
			'meta',
			'blurhash',
			'description',
			'size',
			'public',
			'error',
			'transcoded',
			'laddered',
			'creation',
			'caching'
		],
		self::TABLE_CLIENT => [
			'id',
			'app_name',
			'app_website',
			'app_redirect_uris',
			'app_client_id',
			'app_client_secret',
			'app_scopes',
			'auth_scopes',
			'auth_account',
			'auth_user_id',
			'auth_code',
			'token',
			'last_update',
			'creation'
		],
		self::TABLE_FOLLOWED_TAGS => [
			'id',
			'actor_id_prim',
			'hashtag',
			'creation'
		],
		self::TABLE_INTERESTS => [
			'id',
			'actor_id_prim',
			'hashtag',
			'score',
			'scored_at',
			'manual',
			'position',
			'score_week'
		],
		self::TABLE_INTEREST_HIDES => [
			'id',
			'actor_id_prim',
			'stream_nid',
			'creation'
		],
		self::TABLE_FILTERS => [
			'id',
			'actor_id_prim',
			'title',
			'contexts',
			'action',
			'expires_at',
			'creation'
		],
		self::TABLE_FILTER_KEYWORDS => [
			'id',
			'filter_id',
			'keyword',
			'whole_word',
			'creation'
		],
		self::TABLE_FILTER_STATUSES => [
			'id',
			'filter_id',
			'status_id',
			'creation'
		],
		self::TABLE_LISTS => [
			'id',
			'actor_id',
			'actor_id_prim',
			'title',
			'replies_policy',
			'exclusive',
			'group_id',
			'creation'
		],
		self::TABLE_LIST_MEMBERS => [
			'id',
			'list_id',
			'actor_id',
			'actor_id_prim',
			'creation'
		],
		self::TABLE_COLLECTIONS => [
			'id',
			'actor_id',
			'actor_id_prim',
			'title',
			'description',
			'visibility',
			'creation',
			'updated'
		],
		self::TABLE_COLLECTION_ITEMS => [
			'id',
			'collection_id',
			'stream_id_prim',
			'position',
			'creation'
		],
		self::TABLE_PLACES => [
			'id',
			'name',
			'name_prim',
			'country',
			'lat',
			'lon',
			'creation'
		],
		self::TABLE_IMPORTED_POSTS => [
			'id',
			'actor_id_prim',
			'source_id',
			'source_id_prim',
			'stream_id_prim',
			'creation'
		],
		self::TABLE_STORIES => [
			'id',
			'actor_id',
			'actor_id_prim',
			'document_id',
			'document_id_prim',
			'caption',
			'duration',
			'creation',
			'expires_at',
			'source_id',
			'source_id_prim',
			'local'
		],
		self::TABLE_STORY_VIEWS => [
			'id',
			'story_id',
			'actor_id_prim',
			'creation'
		],
		self::TABLE_STORY_REACTS => [
			'id',
			'story_id',
			'actor_id',
			'actor_id_prim',
			'type',
			'content',
			'source_id',
			'source_id_prim',
			'creation'
		],
		self::TABLE_MEDIA_TAGS => [
			'id',
			'stream_id',
			'stream_id_prim',
			'actor_id',
			'actor_id_prim',
			'tagger_id_prim',
			'creation'
		],
		self::TABLE_PORTFOLIOS => [
			'id',
			'actor_id',
			'actor_id_prim',
			'active',
			'title',
			'intro',
			'layout',
			'source',
			'collection_id',
			'show_captions',
			'show_places',
			'show_dates',
			'show_avatar',
			'creation'
		],
		self::TABLE_TEAMS => [
			'id',
			'actor_id',
			'actor_id_prim',
			'group_id',
			'creation'
		],
		self::TABLE_TEAM_POSTS => [
			'id',
			'stream_id_prim',
			'author_id',
			'author_id_prim',
			'creation'
		],
		self::TABLE_TREND_REVIEW => [
			'id',
			'kind',
			'ref',
			'ref_prim',
			'approved',
			'moderator',
			'creation'
		],
		self::TABLE_RELAYS => [
			'id',
			'actor_id',
			'actor_id_prim',
			'inbox',
			'status',
			'follow_id',
			'error',
			'creation',
			'last_update'
		],
		self::TABLE_WATCH => [
			'id',
			'stream_id_prim',
			'actor_id_prim',
			'position',
			'duration',
			'last_update'
		],
		self::TABLE_RENDITIONS => [
			'id',
			'doc_nid',
			'height',
			'bandwidth',
			'size',
			'local_copy',
			'playlist',
			'creation'
		],
		self::TABLE_CHANNELS => [
			'id',
			'actor_id',
			'actor_id_prim',
			'owner_id',
			'owner_id_prim',
			'name',
			'description',
			'is_default',
			'creation'
		],
		self::TABLE_QUOTE_GRANTS => [
			'id',
			'target_id',
			'target_id_prim',
			'quoting_id',
			'quoting_id_prim',
			'actor_id',
			'request_id',
			'authorization',
			'creation'
		],
		self::TABLE_CONVERSATION_STATE => [
			'id',
			'actor_id',
			'actor_id_prim',
			'root_id',
			'root_id_prim',
			'read_nid',
			'hidden_nid',
			'muted',
			'creation'
		],
		self::TABLE_ANNOUNCEMENTS => [
			'id',
			'content',
			'starts_at',
			'ends_at',
			'all_day',
			'creation',
			'last_update'
		],
		self::TABLE_ANNOUNCEMENT_READS => [
			'id',
			'announcement_id',
			'actor_id_prim',
			'creation'
		],
		self::TABLE_DOMAIN_BLOCKS => [
			'id',
			'actor_id_prim',
			'domain',
			'creation'
		],
		self::TABLE_ACCOUNT_NOTES => [
			'id',
			'actor_id_prim',
			'object_id',
			'object_id_prim',
			'note',
			'creation'
		],
		self::TABLE_MUTE_EXPIRY => [
			'id',
			'actor_id_prim',
			'object_id_prim',
			'expires_at',
			'creation'
		],
		self::TABLE_STATUS_REVISIONS => [
			'id',
			'stream_id_prim',
			'content',
			'spoiler_text',
			'sensitive',
			'published',
			'creation'
		],
		self::TABLE_FEATURED_TAGS => [
			'id',
			'actor_id',
			'actor_id_prim',
			'hashtag',
			'creation'
		],
		self::TABLE_FOLLOWS => [
			'id_prim',
			'id',
			'type',
			'actor_id',
			'actor_id_prim',
			'object_id',
			'object_id_prim',
			'follow_id',
			'follow_id_prim',
			'accepted',
			'creation'
		],
		self::TABLE_HASHTAGS => [
			'hashtag',
			'trend',
			'trend_1h',
			'trend_12h',
			'trend_1d',
			'trend_3d',
			'trend_10d'
		],
		self::TABLE_INSTANCE => [
			'uri',
			'local',
			'title',
			'version',
			'short_description',
			'description',
			'email',
			'urls',
			'stats',
			'usage',
			'image',
			'languages',
			'contact',
			'account_prim',
			'creation'
		],
		self::TABLE_DISCOVER_CATS => [
			'id',
			'name',
			'hashtags',
			'position',
			'creation'
		],
		self::TABLE_MEDIA_BLOCKS => [
			'id',
			'hash',
			'reason',
			'moderator',
			'blocked',
			'creation'
		],
		self::TABLE_MODERATION => [
			'actor_id_prim',
			'actor_id',
			'level',
			'comment',
			'force_sensitive',
			'creation'
		],
		self::TABLE_CLIENT_AUTH => [
			'id',
			'client_id',
			'user_id',
			'account',
			'scopes',
			'code',
			'token',
			'creation',
			'last_update'
		],
		self::TABLE_ACCESS_BLOCKS => [
			'id',
			'type',
			'value',
			'severity',
			'comment',
			'expires',
			'creation'
		],
		self::TABLE_ANNOUNCEMENT_REACTIONS => [
			'id',
			'announcement_id',
			'actor_id_prim',
			'name',
			'creation'
		],
		self::TABLE_EMOJI => [
			'id',
			'shortcode',
			'category',
			'filename',
			'media_type',
			'visible',
			'creation'
		],
		self::TABLE_STRIKES => [
			'id',
			'actor_id_prim',
			'actor_id',
			'action',
			'text',
			'moderator',
			'report_id',
			'creation'
		],
		self::TABLE_REQUEST_QUEUE => [
			'id',
			'token',
			'author',
			'activity',
			'object_id_prim',
			'instance',
			'priority',
			'status',
			'tries',
			'last'
		],
		self::TABLE_POST_HOLD => [
			'id',
			'actor_id',
			'actor_id_prim',
			'params',
			'reason',
			'digest',
			'creation'
		],
		self::TABLE_SCHEDULED => [
			'id',
			'actor_id',
			'actor_id_prim',
			'scheduled_at',
			'params',
			'creation'
		],
		self::TABLE_DURABLE_CACHE => [
			'cache_key',
			'cache_value',
			'expires'
		],
		self::TABLE_HOST_BREAKER => [
			'host_prim',
			'host',
			'strikes',
			'open_until',
			'last_failure'
		],
		self::TABLE_STREAM => [
			'nid',
			'id',
			'id_prim',
			'visibility',
			'sensitive',
			'archived',
			'type',
			'subtype',
			'to',
			'to_array',
			'cc',
			'bcc',
			'content',
			'summary',
			'published',
			'published_time',
			'attributed_to',
			'attributed_to_prim',
			'in_reply_to',
			'in_reply_to_prim',
			'activity_id',
			'object_id',
			'object_id_prim',
			'hashtags',
			'details',
			'source',
			'instances',
			'attachments',
			'media_kind',
			'news_kind',
			'cache',
			'creation',
			'local',
			'filter_duplicate',
			'tags',
			'language',
			'updated',
			'quote',
			'quote_authorization',
			'quote_policy',
			'place_id'
		],
		self::TABLE_STREAM_VIEWS => [
			'id',
			'stream_id_prim',
			'actor_id_prim',
			'creation'
		],
		self::TABLE_STREAM_ACTIONS => [
			'id',
			'actor_id',
			'actor_id_prim',
			'stream_id',
			'stream_id_prim',
			'liked',
			'boosted',
			'replied',
			'bookmarked',
			'disliked',
			'values'
		],
		self::TABLE_STREAM_DEST => [
			'stream_id',
			'actor_id',
			'type',
			'subtype',
			'nid'
		],
		self::TABLE_STREAM_QUEUE => [
			'id',
			'token',
			'stream_id',
			'type',
			'status',
			'tries',
			'last'
		],
		self::TABLE_STREAM_TAGS => [
			'stream_id',
			'hashtag'
		],
		self::TABLE_REPORTS => [
			'id',
			'actor_id',
			'account_id',
			'status_ids',
			'comment',
			'category',
			'local',
			'resolved',
			'creation',
			'assigned_to',
			'action_taken_by',
			'action_taken_at'
		],
		self::TABLE_STREAM_CARDS => [
			'stream_id_prim',
			'url',
			'title',
			'description',
			'image',
			'provider_name',
			'creation'
		],
	];

	protected IDBConnection $dbConnection;
	protected ?Person $viewer = null;
	protected ?string $defaultSelectAlias = null;

	public function __construct(
		IDBConnection $connection,
		protected LoggerInterface $logger,
		protected IURLGenerator $urlGenerator,
		protected ConfigService $configService,
		protected MiscService $miscService,
	) {
		$this->dbConnection = $connection;
	}

	/**
	 * How many blocked instances a read filter will carry.
	 *
	 * Each one becomes four `LIKE` patterns on the timeline query — two
	 * schemes, the author and the boosted author — so this is the point past
	 * which filtering in SQL stops being the cheaper answer.
	 */
	public const BLOCKED_DOMAINS_IN_A_QUERY = 100;

	/**
	 * The instances each viewer has blocked, read once per request.
	 *
	 * The domain-block filter compares these as constants rather than joining
	 * the table on every row (see
	 * `DomainBlocksRequestBuilder::filterDomainBlocked()`), so every filtered
	 * query needs the list. A request reads several timelines — a page, its
	 * thread, a notification poll — and the list cannot change between them
	 * except through this process, which clears the memo when it does.
	 *
	 * @var array<string, string[]> viewer id_prim => domains
	 */
	private static array $blockedDomains = [];

	/** Blocking or unblocking an instance makes the memo wrong; drop it. */
	public static function forgetBlockedDomains(): void {
		self::$blockedDomains = [];
	}

	/**
	 * @return SocialQueryBuilder
	 */
	public function getQueryBuilder(): SocialQueryBuilder {
		$qb = new SocialQueryBuilder(
			$this->dbConnection->getQueryBuilder(),
			$this->urlGenerator
		);

		if ($this->viewer !== null) {
			$qb->setViewer($this->viewer);
			$qb->setBlockedDomains($this->blockedDomainsOf($qb->prim($this->viewer->getId())));
		}

		return $qb;
	}

	/**
	 * @param string $viewerPrim the viewer, as the block rows key them
	 * @return string[] the domains that viewer has blocked
	 */
	private function blockedDomainsOf(string $viewerPrim): array {
		if ($viewerPrim === '') {
			return [];
		}

		if (array_key_exists($viewerPrim, self::$blockedDomains)) {
			return self::$blockedDomains[$viewerPrim];
		}

		$domains = [];
		try {
			$qb = $this->dbConnection->getQueryBuilder();
			$qb->select('domain')
				->from(self::TABLE_DOMAIN_BLOCKS)
				->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($viewerPrim)))
				// a read filter, not the block list itself: a viewer with more
				// blocks than this has a timeline query with more LIKEs than
				// is worth running, and the full list is still what
				// `/api/v1/domain_blocks` serves
				->setMaxResults(self::BLOCKED_DOMAINS_IN_A_QUERY);

			$cursor = $qb->executeQuery();
			while ($row = $cursor->fetch()) {
				$domain = (string)($row['domain'] ?? '');
				if ($domain !== '') {
					$domains[] = $domain;
				}
			}
			$cursor->closeCursor();
		} catch (Exception $e) {
			// a read that cannot see the block table must not take the
			// timeline with it; the filter then adds nothing, which is what it
			// did for every account before this table existed
			$this->miscService->log('could not read the blocked instances: ' . $e->getMessage(), 1);
		}

		self::$blockedDomains[$viewerPrim] = $domains;

		return $domains;
	}

	/**
	 * @return IDBConnection
	 */
	public function getConnection(): IDBConnection {
		return $this->dbConnection;
	}

	/**
	 * @param Person $viewer
	 */
	public function setViewer(Person $viewer) {
		$this->viewer = $viewer;
	}

	/**
	 * Reads what follows for nobody in particular.
	 *
	 * The request object outlives one read — it is a service — so a reader set
	 * for an earlier query is still set for the next one. A path that has to
	 * answer as the anonymous internet does (the portfolio page, which is
	 * public and must only ever show public posts) says so rather than hoping
	 * nothing before it set a viewer.
	 */
	public function resetViewer(): void {
		$this->viewer = null;
	}

	/**
	 * Who the rows now being read are being read for, or '' for nobody.
	 *
	 * Anything that caches a row after this has filtered it has to say which
	 * reader the cached copy belongs to — see `Stream::$quotedStatuses`.
	 */
	public function getViewerId(): string {
		return ($this->viewer === null) ? '' : $this->viewer->getId();
	}

	/**
	 * Limit the request to the prim (md5) form of an id on one of the indexed
	 * `_prim` columns, matched case-sensitively — see limitToIdPrimString().
	 */
	protected function limitToPrim(
		SocialQueryBuilder $qb, string $field, string $id, string $alias = '',
	): void {
		$qb->limitToDBField($field, $qb->prim($id), true, $alias);
	}

	/**
	 * Limit the request to an id, matched on its indexed `_prim` column.
	 *
	 * A `_prim` column holds the md5 of the id, so it is already
	 * case-normalised and is matched case-sensitively: wrapping it in LOWER()
	 * — which is what limitToIdString() does to the unindexed TEXT column —
	 * makes the index unusable, and these are the hottest lookups in the app.
	 *
	 * An id that is not an http(s) uri has no prim form (prim() returns '');
	 * that falls back to the plain column, so no caller can silently start
	 * matching nothing at all.
	 */
	protected function limitToIdPrimString(
		SocialQueryBuilder $qb, string $id, string $field = 'id_prim', string $fallback = 'id',
	): void {
		$prim = $qb->prim($id);
		if ($prim === '') {
			$qb->limitToDBField($fallback, $id, false);

			return;
		}

		$qb->limitToDBField($field, $prim);
	}

	/**
	 * Limit a queue drain to the rows that are actually due: the retry backoff
	 * and the give-up threshold, in SQL.
	 *
	 * Both queues used to take the oldest N rows and then drop most of them in
	 * PHP on exactly these two conditions, which means the rows of one dead
	 * instance permanently occupy the window and nothing behind them is ever
	 * delivered.
	 *
	 * The delay grows as tries^4/3, which is not something a portable query
	 * can compute from the column, so it is unrolled into one branch per try
	 * count — $maxTries of them, and the give-up threshold falls out of the
	 * same expression. A row that has never been attempted has a NULL `last`.
	 *
	 * @param int $maxTries the try count at which a row is abandoned
	 */
	protected function limitToQueueDue(IExtendedQueryBuilder $qb, int $maxTries): void {
		$expr = $qb->expr();
		$pf = ($qb->getType() === IExtendedQueryBuilder::SELECT) ? $this->defaultSelectAlias . '.' : '';
		$now = time();

		// built first and passed in one go: an empty orX() is deprecated and
		// will throw
		$due = [];
		for ($tries = 0; $tries < $maxTries; $tries++) {
			$delay = (int)floor($tries ** 4 / 3);
			$cutoff = new DateTime('@' . ($now - $delay));

			$due[] = $expr->andX(
				$expr->eq($pf . 'tries', $qb->createNamedParameter($tries, IQueryBuilder::PARAM_INT)),
				$expr->orX(
					$expr->isNull($pf . 'last'),
					$expr->lte($pf . 'last', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_DATE))
				)
			);
		}

		$qb->andWhere($expr->orX(...$due));
	}

	//
	//

	/**
	 * The cached actor a row names, as a LEFT JOIN on the prim columns.
	 *
	 * The id columns themselves carry no index and a `LOWER()` over them would
	 * defeat one anyway, so matching on them was a scan of every cached actor
	 * for each row of the page. A prim is the md5 of the id exactly as it was
	 * written, which makes this an exact match where the old join folded case:
	 * an id is a URI, and two that differ in case name different resources.
	 *
	 * @param string $fieldActorId the id column of the row, whose `_prim`
	 *                             sibling is what is compared
	 *
	 * @deprecated - use SocialCrossQueryBuilder:leftJoinCacheActor
	 */
	protected function leftJoinCacheActors(
		IExtendedQueryBuilder $qb, string $fieldActorId, string $alias = '',
	) {
		if ($qb->getType() !== IExtendedQueryBuilder::SELECT) {
			return;
		}

		$pf = ($alias === '') ? $this->defaultSelectAlias : $alias;

		$qb->selectAlias('ca.id', 'ca_id')
			->selectAlias('ca.type', 'ca_type')
			->selectAlias('ca.account', 'ca_account')
			->selectAlias('ca.following', 'ca_following')
			->selectAlias('ca.followers', 'ca_followers')
			->selectAlias('ca.inbox', 'ca_inbox')
			->selectAlias('ca.shared_inbox', 'ca_shared_inbox')
			->selectAlias('ca.outbox', 'ca_outbox')
			->selectAlias('ca.featured', 'ca_featured')
			->selectAlias('ca.url', 'ca_url')
			->selectAlias('ca.preferred_username', 'ca_preferred_username')
			->selectAlias('ca.name', 'ca_name')
			->selectAlias('ca.summary', 'ca_summary')
			->selectAlias('ca.public_key', 'ca_public_key')
			->selectAlias('ca.source', 'ca_source')
			->selectAlias('ca.creation', 'ca_creation')
			->selectAlias('ca.local', 'ca_local');

		$qb->leftJoin(
			$this->defaultSelectAlias, CoreRequestBuilder::TABLE_CACHE_ACTORS, 'ca',
			$qb->expr()->eq('ca.id_prim', $pf . '.' . $fieldActorId . '_prim')
		);
	}

	/**
	 * The local account a row names, joined on the prim columns for the reason
	 * leftJoinCacheActors() gives.
	 *
	 * @param IExtendedQueryBuilder $qb
	 * @param string $fieldActorId
	 * @param string $alias
	 */
	protected function leftJoinAccounts(IExtendedQueryBuilder $qb, string $fieldActorId, string $alias = '',
	) {
		if ($qb->getType() !== IExtendedQueryBuilder::SELECT) {
			return;
		}

		$pf = ($alias === '') ? $this->defaultSelectAlias : $alias;

		$qb->selectAlias('lja.id', 'accounts_id')
			->selectAlias('lja.user_id', 'accounts_user_id')
			->selectAlias('lja.preferred_username', 'accounts_preferred_username')
			->selectAlias('lja.name', 'accounts_name')
			->selectAlias('lja.summary', 'accounts_summary')
			->selectAlias('lja.public_key', 'accounts_public_key');

		$qb->leftJoin(
			$this->defaultSelectAlias, CoreRequestBuilder::TABLE_ACTORS, 'lja',
			$qb->expr()->eq('lja.id_prim', $pf . '.' . $fieldActorId . '_prim')
		);
	}

	/**
	 * @param array $data
	 *
	 * @return Person
	 * @throws InvalidResourceException
	 */
	protected function parseAccountsLeftJoin(array $data): Person {
		$new = [];
		foreach ($data as $k => $v) {
			if (substr($k, 0, 9) === 'accounts_') {
				$new[substr($k, 9)] = $v;
			}
		}

		$actor = new Person();
		$actor->importFromDatabase($new);

		if (!$actor->getUserId()) {
			throw new InvalidResourceException();
		}

		return $actor;
	}

	/**
	 * @param SocialQueryBuilder $qb
	 *
	 * @deprecated
	 */
	protected function leftJoinStreamAction(SocialQueryBuilder &$qb) {
		if ($qb->getType() !== IExtendedQueryBuilder::SELECT || $this->viewer === null) {
			return;
		}

		$pf = $this->defaultSelectAlias;
		$expr = $qb->expr();

		$qb->selectAlias('sa.id', 'streamaction_id')
			->selectAlias('sa.actor_id', 'streamaction_actor_id')
			->selectAlias('sa.stream_id', 'streamaction_stream_id')
			->selectAlias('sa.liked', 'streamaction_liked')
			->selectAlias('sa.boosted', 'streamaction_boosted')
			->selectAlias('sa.replied', 'streamaction_replied')
			->selectAlias('sa.bookmarked', 'streamaction_bookmarked')
			->selectAlias('sa.values', 'streamaction_values');

		$orX = $expr->orX(
			$expr->eq('sa.stream_id_prim', $pf . '.id_prim'),
			$expr->eq('sa.stream_id_prim', $pf . '.object_id_prim')
		);

		$on = $expr->andX(
			$expr->eq(
				'sa.actor_id_prim', $qb->createNamedParameter($qb->prim($this->viewer->getId()))
			),
			$orX
		);

		$qb->leftJoin(
			$this->defaultSelectAlias, CoreRequestBuilder::TABLE_STREAM_ACTIONS, 'sa',
			$on
		);
	}

	/**
	 * @param array $data
	 *
	 * @return StreamAction
	 */
	protected function parseStreamActionsLeftJoin(array $data): StreamAction {
		$new = [];
		foreach ($data as $k => $v) {
			if (substr($k, 0, 13) === 'streamaction_') {
				$new[substr($k, 13)] = $v;
			}
		}

		$action = new StreamAction();
		$action->importFromDatabase($new);
		$action->setDefaultValues(
			[
				'boosted' => false
			]
		);

		return $action;
	}

	/**
	 * @param SocialQueryBuilder $qb
	 * @param string $fieldActorId
	 * @param bool $asFollower
	 * @param string $prefix
	 * @param string $pf
	 */
	protected function leftJoinFollowAsViewer(
		SocialQueryBuilder $qb, string $fieldActorId, bool $asFollower = true,
		string $prefix = 'follow', string $pf = '',
	) {
		if ($qb->getType() !== IExtendedQueryBuilder::SELECT) {
			return;
		}

		if ($this->viewer === null) {
			return;
		}

		$expr = $qb->expr();
		if ($pf === '') {
			$pf = $this->defaultSelectAlias;
		}

		// on the prims, which `social_f_oa_u` (object, actor) answers from
		// either side; see leftJoinCacheActors() for why not on the ids
		$viewer = $qb->createNamedParameter($qb->prim($this->viewer->getId()));
		$row = $pf . '.' . $fieldActorId . '_prim';
		$conditions = [];
		$conditions[] = $qb->exprLimitToDBFieldInt('accepted', 1, $prefix . '_f');

		if ($asFollower === true) {
			$conditions[] = $expr->eq($prefix . '_f.object_id_prim', $row);
			$conditions[] = $expr->eq($prefix . '_f.actor_id_prim', $viewer);
		} else {
			$conditions[] = $expr->eq($prefix . '_f.actor_id_prim', $row);
			$conditions[] = $expr->eq($prefix . '_f.object_id_prim', $viewer);
		}

		$qb->selectAlias($prefix . '_f.id', $prefix . '_id')
			->selectAlias($prefix . '_f.type', $prefix . '_type')
			->selectAlias($prefix . '_f.actor_id', $prefix . '_actor_id')
			->selectAlias($prefix . '_f.object_id', $prefix . '_object_id')
			->selectAlias($prefix . '_f.follow_id', $prefix . '_follow_id')
			->selectAlias($prefix . '_f.creation', $prefix . '_creation')
			->leftJoin(
				$this->defaultSelectAlias, CoreRequestBuilder::TABLE_FOLLOWS, $prefix . '_f',
				$expr->andX(...$conditions)
			);
	}

	/**
	 * @param array $data
	 * @param string $prefix
	 *
	 * @return Follow
	 * @throws InvalidResourceException
	 */
	protected function parseFollowLeftJoin(array $data, string $prefix): Follow {
		$new = [];

		$length = strlen($prefix) + 1;
		foreach ($data as $k => $v) {
			if (substr($k, 0, $length) === $prefix . '_') {
				$new[substr($k, $length)] = $v;
			}
		}

		$follow = new Follow();
		$follow->importFromDatabase($new);

		if ($follow->getType() !== Follow::TYPE) {
			throw new InvalidResourceException();
		}

		return $follow;
	}

	/**
	 * @param SocialQueryBuilder $qb
	 * @param string $fieldActorId
	 * @param string $pf
	 */
	protected function leftJoinDetails(SocialQueryBuilder $qb, string $fieldActorId = 'id', string $pf = '') {
		$this->leftJoinFollowAsViewer($qb, $fieldActorId, true, 'as_follower', $pf);
		$this->leftJoinFollowAsViewer($qb, $fieldActorId, false, 'as_followed', $pf);
	}

	/**
	 * @param Person $actor
	 * @param array $data
	 */
	protected function assignDetails(Person $actor, array $data) {
		if ($this->viewer === null) {
			return;
		}

		try {
			$this->parseFollowLeftJoin($data, 'as_follower');
			$actor->setDetailBool(Details::FOLLOWING, true);
		} catch (InvalidResourceException $e) {
			$actor->setDetailBool(Details::FOLLOWING, false);
		}

		try {
			$this->parseFollowLeftJoin($data, 'as_followed');
			$actor->setDetailBool(Details::FOLLOWED, true);
		} catch (InvalidResourceException $e) {
			$actor->setDetailBool(Details::FOLLOWED, false);
		}

		$actor->setCompleteDetails(true);
	}

	/**
	 * this just empty all tables from the app.
	 */
	public function emptyAll(): void {
		foreach (array_keys(self::$tables) as $table) {
			if ($this->dbConnection->tableExists($table)) {
				$qb = $this->getQueryBuilder();
				$qb->delete($table);
				$qb->executeStatement();
			}
		}
	}

	/**
	 * this just empty all tables from the app.
	 */
	public function uninstallSocialTables(): void {
		foreach (array_keys(self::$tables) as $table) {
			if ($this->dbConnection->tableExists($table)) {
				$this->dbConnection->dropTable($table);
			}
		}
	}

	/**
	 *
	 */
	public function uninstallFromMigrations() {
		$qb = $this->getQueryBuilder();
		$qb->delete('migrations');
		$qb->where($qb->exprLimitToDBField('app', 'social', true, true));

		$qb->executeStatement();
	}

	/**
	 *
	 */
	public function uninstallFromJobs() {
		$qb = $this->getQueryBuilder();
		$qb->delete('jobs');
		$qb->where($qb->exprLimitToDBField('class', 'OCA\Social\Cron\Cache', true, true));
		$qb->executeStatement();

		$qb = $this->getQueryBuilder();
		$qb->delete('jobs');
		$qb->where($qb->exprLimitToDBField('class', 'OCA\Social\Cron\Queue', true, true));
		$qb->executeStatement();
	}
}
