<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateInterval;
use DateTime;
use Exception;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Tools\Exceptions\DateTimeException;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IQueryBuilder;

class SocialLimitsQueryBuilder extends SocialCrossQueryBuilder {
	public function limitToType(string $type, string $alias = ''): self {
		$this->limitToDBField('type', $type, true, $alias);

		return $this;
	}

	/**
	 * Limit to the types that are statuses to a reader: Notes and polls, and
	 * with `$withBoosts` the boosts, which a reader is shown as statuses too.
	 */
	public function limitToStatusTypes(string $alias = '', bool $withBoosts = false): self {
		$pf = ($alias === '') ? $this->getDefaultSelectAlias() . '.' : $alias . '.';
		$types = Stream::STATUS_TYPES;
		if ($withBoosts) {
			$types[] = Announce::TYPE;
		}
		$this->andWhere($this->expr()->in(
			$pf . 'type',
			$this->createNamedParameter($types, IQueryBuilder::PARAM_STR_ARRAY)
		));

		return $this;
	}

	/**
	 * Leaves out the replies to other accounts' posts and keeps the posts that
	 * answer their author's own, a thread, as Mastodon's `exclude_replies`
	 * does. A reply whose parent is not stored here counts as one to somebody
	 * else, since nothing says it is not.
	 */
	public function filterRepliesToOthers(): self {
		$expr = $this->expr();
		$pf = $this->getDefaultSelectAlias();

		$this->leftJoin($pf, CoreRequestBuilder::TABLE_STREAM, 'rp', $expr->eq('rp.id_prim', $pf . '.in_reply_to_prim'));
		$this->andWhere($expr->orX(
			$expr->isNull($pf . '.in_reply_to_prim'),
			$expr->eq($pf . '.in_reply_to_prim', $this->createNamedParameter('')),
			$expr->eq('rp.attributed_to_prim', $pf . '.attributed_to_prim')
		));

		return $this;
	}

	/**
	 * Limit the request to the ActivityId
	 *
	 * @param string $activityId
	 */
	public function limitToActivityId(string $activityId) {
		$this->limitToDBField('activity_id', $activityId, false);
	}

	/**
	 * Limit the request to replies to the given post id
	 *
	 * @param string $id
	 * @param bool $prim
	 */
	public function limitToInReplyTo(string $id, bool $prim = false) {
		if ($prim) {
			$this->limitToDBField('in_reply_to_prim', $this->prim($id), false);

			return;
		}

		$this->limitToDBField('in_reply_to', $id, false);
	}

	/**
	 * Limit the request to the sub-type
	 *
	 * @param string $subType
	 */
	public function limitToSubType(string $subType) {
		$this->limitToDBField('subtype', $subType);
	}

	/**
	 * Limit the request to a set of sub-types, or to everything but them.
	 * An empty set is no constraint at all.
	 *
	 * @param string[] $subTypes
	 */
	public function limitToSubTypes(array $subTypes, bool $exclude = false): self {
		if ($subTypes === []) {
			return $this;
		}

		$field = $this->getDefaultSelectAlias() . '.subtype';
		$param = $this->createNamedParameter($subTypes, IQueryBuilder::PARAM_STR_ARRAY);

		$this->andWhere(
			$exclude
				? $this->expr()->notIn($field, $param)
				: $this->expr()->in($field, $param)
		);

		return $this;
	}

	/**
	 * Limit the request to clientId
	 *
	 * @param string $clientId
	 */
	public function limitToAppClientId(string $clientId) {
		$this->limitToDBField('app_client_id', $clientId);
	}

	/**
	 * @param string $type
	 */
	public function filterType(string $type) {
		$this->filterDBField('type', $type);
	}

	/**
	 * Limit the request to the Preferred Username
	 *
	 * @param string $username
	 */
	public function limitToPreferredUsername(string $username) {
		$this->limitToDBField('preferred_username', $username, false);
	}

	/**
	 * Limit the request to public rows
	 */
	public function limitToPublic() {
		$this->limitToDBFieldInt('public', 1);
	}

	/**
	 * Limit the request to the id_prim
	 */
	public function limitToIdPrim(string $id) {
		$this->limitToDBField('id_prim', $id);
	}

	/**
	 * Limit the request to the token
	 *
	 * @param string $token
	 * @param string $alias
	 */
	public function limitToToken(string $token, string $alias = '') {
		$this->limitToDBField('token', $token, true, $alias);
	}

	/**
	 * Limit the results to a given number
	 *
	 * @param int $limit
	 */
	public function limitResults(int $limit) {
		$this->setMaxResults($limit);
	}

	/**
	 * Limit the request to the hashtag
	 *
	 * @param string $hashtag
	 */
	public function limitToHashtag(string $hashtag) {
		$this->limitToDBField('hashtag', $hashtag, false);
	}

	/**
	 * Limit the request to the ActorId
	 *
	 * @param string $actorId
	 * @param string $alias
	 */
	public function limitToActorId(string $actorId, string $alias = '') {
		$this->limitToDBField('actor_id', $actorId, false, $alias);
	}

	/**
	 * Limit the request to the ActorId
	 *
	 * @param string $actorId
	 * @param string $alias
	 */
	public function limitToActorIdPrim(string $actorId, string $alias = '') {
		$this->limitToDBField('actor_id_prim', $actorId, false, $alias);
	}

	/**
	 * @param string $streamId
	 * @param string $alias
	 *
	 * @return void
	 */
	public function limitToStreamIdPrim(string $streamId, string $alias = '') {
		$this->limitToDBField('stream_id_prim', $streamId, false, $alias);
	}

	/**
	 * Limit the request to the FollowId
	 *
	 * @param string $followId
	 */
	public function limitToFollowId(string $followId) {
		$this->limitToDBField('follow_id', $followId, false);
	}

	/**
	 * Limit the request to accepted (or pending) rows
	 *
	 * @param bool $accepted
	 * @param string $alias
	 */
	public function limitToAccepted(bool $accepted, string $alias = '') {
		$this->limitToDBField('accepted', ($accepted) ? '1' : '0', true, $alias);
	}

	/**
	 * Limit the request to the ObjectId
	 *
	 * @param string $objectId
	 */
	public function limitToObjectId(string $objectId) {
		$this->limitToDBField('object_id', $objectId, false);
	}

	/**
	 * Limit the request to the ObjectId (prim)
	 *
	 * @param string $actorId
	 * @param string $alias
	 */
	public function limitToObjectIdPrim(string $actorId, string $alias = '') {
		$this->limitToDBField('object_id_prim', $actorId, false, $alias);
	}

	/**
	 * Limit the request to the account
	 *
	 * @param string $account
	 */
	public function limitToAccount(string $account) {
		$this->limitToDBField('account', $account, false);
	}

	/**
	 * Limit the request to rows whose caching started at least `$delay` minutes ago
	 *
	 * @param int $delay
	 *
	 * @throws Exception
	 */
	public function limitToCaching(int $delay = 0) {
		$date = new DateTime('now');
		$date->sub(new DateInterval('PT' . $delay . 'M'));

		$this->limitToDBFieldDateTime('caching', $date, true);
	}

	/**
	 * Limit the request to the url
	 *
	 * @param string $url
	 */
	public function limitToUrl(string $url) {
		$this->limitToDBField('url', $url);
	}

	/**
	 * Limit the request to the author
	 *
	 * @param string $actorId
	 * @param bool $prim
	 */
	public function limitToAttributedTo(string $actorId, bool $prim = false) {
		if ($prim) {
			$this->limitToDBField('attributed_to_prim', $this->prim($actorId));

			return;
		}

		$this->limitToDBField('attributed_to', $actorId, false);
	}

	/**
	 * Limit the request to the status
	 *
	 * @param int $status
	 */
	public function limitToStatus(int $status) {
		$this->limitToDBFieldInt('status', $status);
	}

	/**
	 * Limit the request to the address
	 *
	 * @param string $address
	 */
	public function limitToAddress(string $address) {
		$this->limitToDBField('address', $address);
	}

	/**
	 * Limit the request to local or remote rows
	 *
	 * @param bool $local
	 */
	public function limitToLocal(bool $local) {
		$this->limitToDBFieldInt('local', ($local) ? 1 : 0);
	}

	/**
	 * Limit the request to the parent_id
	 *
	 * @param string $parentId
	 */
	public function limitToParentId(string $parentId) {
		$this->limitToDBField('parent_id', $parentId);
	}

	/**
	 * Bounds and orders a page on a nid: the post's own by default. A
	 * timeline whose recipient join fixes the collection and type passes the
	 * recipient row's alias instead, so `social_sd_atn` (actor_id, type, nid)
	 * serves filter and order as one range instead of a temporary-table sort.
	 *
	 * @param string $alias the table whose nid pages; '' for the post's
	 * @param string $column the id column of that table the page is cut and
	 *                       ordered on, where it is not its `nid` -- the
	 *                       favourites list pages on the mark's own id
	 */
	public function paginate(ProbeOptions $options, string $alias = '', string $column = 'nid') {
		$expr = $this->expr();
		$pf = ($alias === '') ? $this->getDefaultSelectAlias() : $alias;
		$field = $pf . '.' . $column;

		if ($options->getSince() > 0) {
			$this->andWhere($expr->gt($field, $this->createNamedParameter($options->getSince())));
		}

		if ($options->getMaxId() > 0) {
			$this->andWhere($expr->lt($field, $this->createNamedParameter($options->getMaxId())));
		}

		if ($options->getMinId() > 0) {
			$options->setInverted(true);
			$this->andWhere($expr->gt($field, $this->createNamedParameter($options->getMinId())));
		}

		$this->setMaxResults($options->getLimit());
		$this->orderBy($field, ($options->isInverted()) ? 'asc' : 'desc');
	}

	/**
	 * @param int $since
	 * @param int $limit
	 *
	 * @throws DateTimeException
	 * @deprecated - use paginate()
	 */
	public function limitPaginate(int $since = 0, int $limit = 5) {
		$limit = max(1, min(ProbeOptions::MAX_LIMIT, $limit));
		try {
			if ($since > 0) {
				$dTime = new DateTime('@' . $since);
				$this->limitToDBFieldDateTime('published_time', $dTime);
			}
		} catch (Exception $e) {
			throw new DateTimeException();
		}

		$this->setMaxResults($limit);
		$pf = $this->getDefaultSelectAlias();
		$this->orderBy($pf . '.published_time', 'desc');
	}

	/**
	 * @param string $recipient
	 */
	public function filterDest(string $recipient) {
		$expr = $this->expr();

		$this->andWhere($expr->neq('actor_id', $this->createNamedParameter($this->prim($recipient))));
	}

	/**
	 * @param string $actorId
	 * @param string $type
	 * @param string $subType
	 * @param string $alias
	 */
	public function limitToDest(string $actorId, string $type, string $subType = '', string $alias = 'sd') {
		$this->andWhere($this->exprLimitToDest($actorId, $type, $subType, $alias));
	}

	/**
	 * @param string $actorId
	 * @param string $type
	 * @param string $subType
	 * @param string $alias
	 *
	 * @return ICompositeExpression
	 */
	public function exprLimitToDest(string $actorId, string $type, string $subType = '', string $alias = 'sd',
	): ICompositeExpression {
		$expr = $this->expr();

		$conditions = [$expr->eq($alias . '.stream_id', $this->getDefaultSelectAlias() . '.id_prim')];
		if ($actorId) {
			$conditions[] = $this->exprLimitToDBField('actor_id', $this->prim($actorId), true, true, $alias);
		}
		$conditions[] = $this->exprLimitToDBField('type', $type, true, true, $alias);

		if ($subType !== '') {
			$conditions[] = $this->exprLimitToDBField('subtype', $subType, true, true, $alias);
		}

		$andX = $expr->andX(...$conditions);

		return $andX;
	}

	/**
	 * @param string $aliasDest
	 * @param string $aliasFollowing the alias of `social_follow`: joined to the
	 *                               request when the follow is the only branch,
	 *                               which is when a caller may constrain on it,
	 *                               and inside an `EXISTS` otherwise
	 * @param bool $allowPublic
	 * @param bool $allowDirect
	 * @param string $publicSubType which recipient row counts as public: '' for
	 *                              any, `to` for a post addressed *to* the public
	 *                              collection -- which leaves out an unlisted
	 *                              post, where the public collection is only in `cc`
	 */
	public function limitToViewer(
		string $aliasDest = 'sd', string $aliasFollowing = 'f', bool $allowPublic = false,
		bool $allowDirect = false, string $hiddenLevel = self::HIDDEN_TIMELINE, string $publicSubType = '',
	) {
		if (!$this->hasViewer()) {
			// no viewer: the follows table must not be in the FROM list at
			// all, or it becomes a cartesian product; '' suppresses it
			$this->selectDestFollowing($aliasDest, '');
			$this->innerJoinStreamDest('recipient', 'id_prim', 'sd', 's');
			$this->limitToDest(ACore::CONTEXT_PUBLIC, 'recipient', $publicSubType, $aliasDest);

			return;
		}

		$this->selectDestFollowing($aliasDest, '');
		$expr = $this->expr();
		$actor = $this->getViewer();

		if ($allowPublic || $allowDirect) {
			// the follow is one branch of several, and a joined follows table
			// would repeat each candidate row once per account followed
			$conditions = [$this->exprStreamDestFollowed($actor->getId(), $aliasDest, $aliasFollowing)];
		} else {
			// the follow is the only way in: the join drives from the viewer's
			// follows, and the caller constrains on the follow's columns
			$this->leftJoinFollowing($aliasFollowing);
			$conditions = [
				$this->exprInnerJoinStreamDestFollowing(
					$actor->getId(), 'recipient', 'id_prim', $aliasDest, $aliasFollowing
				)
			];
		}

		if ($allowPublic) {
			$conditions[] = $this->exprLimitToDest(ACore::CONTEXT_PUBLIC, 'recipient', $publicSubType, $aliasDest);
		}

		if ($allowDirect) {
			$conditions[] = $this->exprLimitToDest($actor->getId(), 'dm', '', $aliasDest);
		}

		$orX = $expr->orX(...$conditions);

		$this->andWhere($orX);

		$this->filterHiddenActors($hiddenLevel);
	}

	/**
	 * Limit to posts that carry at least one attachment. "No media" has three
	 * spellings in the stored JSON (NULL, `''`, `'[]'`), all excluded with
	 * plain string comparison, which runs on MySQL, PostgreSQL and SQLite.
	 */
	public function limitToMedia(): self {
		$expr = $this->expr();
		$pf = $this->getDefaultSelectAlias();

		// `media_kind` answers in one indexed column where filled in; the
		// string comparisons cover rows not yet backfilled and are left out
		// once the flag says every row has its kind, since the `OR` keeps the
		// planner off the index
		$byKind = $expr->andX(
			$expr->isNotNull($pf . '.media_kind'),
			$expr->neq($pf . '.media_kind', $this->createNamedParameter(Stream::MEDIA_KIND_NONE))
		);
		if ($this->mediaKindIsFilled()) {
			$this->andWhere($byKind);

			return $this;
		}

		$this->andWhere($expr->orX(
			$byKind,
			$expr->andX(
				$expr->isNull($pf . '.media_kind'),
				$expr->isNotNull($pf . '.attachments'),
				$expr->neq($pf . '.attachments', $this->createNamedParameter('')),
				$expr->neq($pf . '.attachments', $this->createNamedParameter('[]'))
			)
		));

		return $this;
	}

	/** Photos feed: images, videos and animated images, excluding audio-only posts. */
	public function limitToVisualMedia(): self {
		$expr = $this->expr();
		$pf = $this->getDefaultSelectAlias();
		$byKind = $expr->in($pf . '.media_kind', $this->createNamedParameter(
			['image', 'video', 'gifv', Stream::MEDIA_KIND_MIXED], IQueryBuilder::PARAM_STR_ARRAY
		));
		if ($this->mediaKindIsFilled()) {
			$this->andWhere($byKind);
			return $this;
		}
		$this->andWhere($expr->orX(
			$byKind,
			$expr->andX(
				$expr->isNull($pf . '.media_kind'),
				$expr->orX(
					$expr->eq($pf . '.subtype', $this->createNamedParameter('Video')),
					...array_map(fn (string $type) => $expr->like(
						$pf . '.attachments', $this->createNamedParameter('%"type":"' . $type . '"%')
					), ['image', 'video', 'gifv'])
				)
			)
		));
		return $this;
	}

	/**
	 * Limit to posts that are a video: an attachment of Mastodon type `video`,
	 * or a PeerTube `Video` object, which arrives as a `Note` with `Video` in
	 * `subtype` (see `AP::NOTE_LIKE_TYPES`) and counts even without a
	 * playable file. The attachment test is an exact substring match on
	 * `"type":"video"`, since `json_encode` writes no spaces and escapes `"`
	 * in values, and it runs on all three databases.
	 */
	public function limitToVideo(): self {
		$expr = $this->expr();
		$pf = $this->getDefaultSelectAlias();

		// the indexed answer, wherever the column has been filled in; `mixed`
		// (a photo and a video) counts as video
		$byKind = $expr->in($pf . '.media_kind', $this->createNamedParameter(
			['video', Stream::MEDIA_KIND_MIXED], IQueryBuilder::PARAM_STR_ARRAY
		));
		if ($this->mediaKindIsFilled()) {
			$this->andWhere($byKind);

			return $this;
		}

		$this->andWhere($expr->orX(
			$byKind,
			// and the string test, for a row the backfill has not reached
			$expr->andX(
				$expr->isNull($pf . '.media_kind'),
				$expr->orX(
					$expr->like(
						$pf . '.attachments',
						$this->createNamedParameter('%"type":"video"%')
					),
					$expr->eq($pf . '.subtype', $this->createNamedParameter('Video'))
				)
			)
		));

		return $this;
	}

	/**
	 * Narrows the media to one kind of attachment (`image` or `audio`);
	 * `video` goes to `limitToVideo()` via `StreamRequest::filterMedia()`,
	 * since a PeerTube `Video` has no attachment. A `LIKE` on `"type":"…"` in
	 * the client-format JSON, as in `limitToVideo()`. Unindexed, but only run
	 * on an already narrowed list such as a profile.
	 */
	public function limitToMediaType(string $type): self {
		$expr = $this->expr();
		$pf = $this->getDefaultSelectAlias();

		$byKind = $expr->in($pf . '.media_kind', $this->createNamedParameter(
			[$type, Stream::MEDIA_KIND_MIXED], IQueryBuilder::PARAM_STR_ARRAY
		));
		if ($this->mediaKindIsFilled()) {
			$this->andWhere($byKind);

			return $this;
		}

		$this->andWhere($expr->orX(
			$byKind,
			$expr->andX(
				$expr->isNull($pf . '.media_kind'),
				$expr->like(
					$pf . '.attachments',
					$this->createNamedParameter('%"type":"' . $type . '"%')
				)
			)
		));

		return $this;
	}

	/**
	 * Limit to posts that are a short video: one indexed column,
	 * `(short_kind, nid)`, with no fallback, since shortness is read from the
	 * attachment's meta in PHP (`Stream::shortKindOf()`).
	 */
	public function limitToShort(): self {
		$pf = $this->getDefaultSelectAlias();

		$this->andWhere($this->expr()->eq(
			$pf . '.short_kind', $this->createNamedParameter(Stream::SHORT_KIND_SHORT)
		));

		return $this;
	}

	/**
	 * Limit to posts that are news: an article, or a post linking to one. One
	 * indexed column with no fallback, since "is there a non-mention link"
	 * cannot be asked of a database; an unclassified row is not news until the
	 * backfill (`Version1000Date20260917000005`) reaches it. `IN` rather than
	 * `!= ''` so that `NULL` does not match.
	 */
	public function limitToNews(): self {
		$expr = $this->expr();
		$pf = $this->getDefaultSelectAlias();

		$this->andWhere($expr->in($pf . '.news_kind', $this->createNamedParameter(
			[Stream::NEWS_KIND_LINK, Stream::NEWS_KIND_ARTICLE], IQueryBuilder::PARAM_STR_ARRAY
		)));

		return $this;
	}

	/**
	 * Limit to posts carrying a hashtag the viewer follows, by two inner
	 * joins. Says nothing about visibility, which the caller adds (see
	 * `StreamRequest::followedTagNids()`). Both sides hold the tag normalised
	 * (`FollowedTagsRequest::normalise()`), so they compare as they stand and
	 * either index can drive the join. A post with two followed tags matches
	 * twice; the caller selects DISTINCT. Without a viewer this adds nothing,
	 * so only call it with one.
	 */
	public function limitToFollowedTags(string $aliasTags = 'ft_st', string $aliasFollowed = 'ft'): self {
		if (!$this->hasViewer()) {
			return $this;
		}

		$expr = $this->expr();
		$pf = $this->getDefaultSelectAlias();

		$this->innerJoin(
			$pf, CoreRequestBuilder::TABLE_STREAM_TAGS, $aliasTags,
			$expr->eq($aliasTags . '.stream_id', $pf . '.id_prim')
		);
		$this->innerJoin(
			$aliasTags, CoreRequestBuilder::TABLE_FOLLOWED_TAGS, $aliasFollowed,
			$expr->andX(
				$expr->eq(
					$aliasFollowed . '.actor_id_prim',
					$this->createNamedParameter($this->prim($this->getViewer()->getId()))
				),
				$expr->eq($aliasFollowed . '.hashtag', $aliasTags . '.hashtag')
			)
		);

		return $this;
	}

	/**
	 * Hide streams involving actors the viewer has blocked or muted (and actors who
	 * blocked the viewer). One LEFT JOIN anti-join against social_actor_relation,
	 * on the row's author and — for boosts — the boosted post's author. Levels:
	 * see the HIDDEN_* constants. No viewer, no filtering.
	 */
	public function filterHiddenActors(string $level = self::HIDDEN_TIMELINE): void {
		if (!$this->hasViewer()) {
			return;
		}

		$expr = $this->expr();
		$pf = $this->getDefaultSelectAlias();
		$viewerPrim = $this->prim($this->getViewer()->getId());

		// a boost row's attributed_to is the booster; the boosted author sits on the
		// announced object's row
		$this->leftJoin(
			$pf, CoreRequestBuilder::TABLE_STREAM, 'hd_o',
			$expr->andX(
				$expr->eq('hd_o.id_prim', $pf . '.object_id_prim'),
				$expr->eq($pf . '.type', $this->createNamedParameter(Announce::TYPE))
			)
		);

		if ($level !== self::HIDDEN_DIRECT) {
			// the expiry of a timed mute is a condition of the join below, and
			// a join may only name an alias that already exists
			MuteExpiryRequestBuilder::joinExpiredMutes($this);
		}

		if ($level === self::HIDDEN_NOTIFICATIONS) {
			// a mute hides notifications only when it was created with notifications=true
			$onTypes = $expr->orX(
				$expr->in(
					'hd_r.type',
					$this->createNamedParameter(
						[ActorRelation::TYPE_BLOCK, ActorRelation::TYPE_BLOCKED_BY],
						IQueryBuilder::PARAM_STR_ARRAY
					)
				),
				$expr->andX(
					$expr->eq('hd_r.type', $this->createNamedParameter(ActorRelation::TYPE_MUTE)),
					$expr->eq('hd_r.notifications', $this->createNamedParameter(1)),
					MuteExpiryRequestBuilder::unexpired($expr)
				)
			);
		} else {
			$onTypes = $expr->in(
				'hd_r.type',
				$this->createNamedParameter(
					[ActorRelation::TYPE_BLOCK, ActorRelation::TYPE_BLOCKED_BY],
					IQueryBuilder::PARAM_STR_ARRAY
				)
			);
			if ($level === self::HIDDEN_TIMELINE) {
				// an expired mute no longer applies; the expiry is in the ON
				// clause, since in the WHERE a second matching relation row
				// could let the post through
				$onTypes = $expr->orX(
					$onTypes,
					$expr->andX(
						$expr->eq('hd_r.type', $this->createNamedParameter(ActorRelation::TYPE_MUTE)),
						MuteExpiryRequestBuilder::unexpired($expr)
					)
				);
			}
		}

		$this->leftJoin(
			$pf, CoreRequestBuilder::TABLE_ACTOR_RELATION, 'hd_r',
			$expr->andX(
				$expr->eq('hd_r.actor_id_prim', $this->createNamedParameter($viewerPrim)),
				$onTypes,
				$expr->orX(
					$expr->eq('hd_r.object_id_prim', $pf . '.attributed_to_prim'),
					$expr->eq('hd_r.object_id_prim', 'hd_o.attributed_to_prim')
				)
			)
		);
		$this->andWhere($expr->isNull('hd_r.id'));

		// an instance the viewer blocked, which applies to accounts that do
		// not exist yet and so is not a social_actor_relation row
		DomainBlocksRequestBuilder::filterDomainBlocked($this);
	}

	/**
	 * Drops the boosts of accounts whose boosts this viewer has turned off
	 * (Mastodon's `reblogs: false`); their own posts stay. The type predicate
	 * is in the ON clause, as the mute expiry is, so non-boosts are not dropped.
	 */
	public function filterHiddenBoosts(): void {
		if (!$this->hasViewer()) {
			return;
		}

		$expr = $this->expr();
		$pf = $this->getDefaultSelectAlias();

		$this->leftJoin(
			$pf, CoreRequestBuilder::TABLE_ACTOR_RELATION, 'hb_r',
			$expr->andX(
				$expr->eq(
					'hb_r.actor_id_prim',
					$this->createNamedParameter($this->prim($this->getViewer()->getId()))
				),
				$expr->eq(
					'hb_r.type', $this->createNamedParameter(ActorRelation::TYPE_HIDE_REBLOGS)
				),
				$expr->eq('hb_r.object_id_prim', $pf . '.attributed_to_prim'),
				$expr->eq($pf . '.type', $this->createNamedParameter(Announce::TYPE))
			)
		);
		$this->andWhere($expr->isNull('hb_r.id'));
	}
}
