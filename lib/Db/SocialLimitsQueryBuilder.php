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
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Tools\Exceptions\DateTimeException;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Class SocialLimitsQueryBuilder
 *
 * @package OCA\Social\Db
 */
class SocialLimitsQueryBuilder extends SocialCrossQueryBuilder {
	public function limitToType(string $type, string $alias = ''): self {
		$this->limitToDBField('type', $type, true, $alias);

		return $this;
	}

	/**
	 * Limit to the types that are statuses to a reader: Notes and polls.
	 */
	public function limitToStatusTypes(string $alias = ''): self {
		$pf = ($alias === '') ? $this->getDefaultSelectAlias() . '.' : $alias . '.';
		$this->andWhere($this->expr()->in(
			$pf . 'type',
			$this->createNamedParameter(
				[Note::TYPE, Question::TYPE],
				IQueryBuilder::PARAM_STR_ARRAY
			)
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
	 * Limit the request to the Id (string)
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
	 * Limit the request to the ActorId
	 */
	public function limitToPublic() {
		$this->limitToDBFieldInt('public', 1);
	}

	/**
	 * Limit the request to the ActorId
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
	 * Limit the request to the ActorId
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
	 * Limit the request to the FollowId
	 *
	 * @param bool $accepted
	 * @param string $alias
	 */
	public function limitToAccepted(bool $accepted, string $alias = '') {
		$this->limitToDBField('accepted', ($accepted) ? '1' : '0', true, $alias);
	}

	/**
	 * Limit the request to the ServiceId
	 *
	 * @param string $objectId
	 */
	public function limitToObjectId(string $objectId) {
		$this->limitToDBField('object_id', $objectId, false);
	}

	/**
	 * Limit the request to the ActorId
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
	 * Limit the request to the creation
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
	 * Limit the request to the url
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
	 * Limit the request to the instance
	 *
	 * @param string $address
	 */
	public function limitToAddress(string $address) {
		$this->limitToDBField('address', $address);
	}

	/**
	 * Limit the request to the instance
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
	 * Bounds and orders a page on a nid.
	 *
	 * On the post's own by default. A timeline whose recipient join fixes the
	 * collection and the type — public, notifications, direct — passes the
	 * recipient row's alias instead: that row carries the post's nid too, and
	 * `social_sd_atn` (actor_id, type, nid) then serves the filter and the
	 * order as one descending range. Ordered on `s.nid`, MariaDB drove from the
	 * same recipient rows anyway, joined every one of them to its post and
	 * sorted the lot in a temporary table to keep twenty.
	 *
	 * @param string $alias the table whose nid pages; '' for the post's
	 */
	public function paginate(ProbeOptions $options, string $alias = '') {
		$expr = $this->expr();
		$pf = ($alias === '') ? $this->getDefaultSelectAlias() : $alias;

		if ($options->getSince() > 0) {
			$this->andWhere($expr->gt($pf . '.nid', $this->createNamedParameter($options->getSince())));
		}

		if ($options->getMaxId() > 0) {
			$this->andWhere($expr->lt($pf . '.nid', $this->createNamedParameter($options->getMaxId())));
		}

		if ($options->getMinId() > 0) {
			$options->setInverted(true);
			$this->andWhere($expr->gt($pf . '.nid', $this->createNamedParameter($options->getMinId())));
		}

		$this->setMaxResults($options->getLimit());
		$this->orderBy($pf . '.nid', ($options->isInverted()) ? 'asc' : 'desc');
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
				$dTime = new DateTime();
				$dTime->setTimestamp($since);
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
	 * @param string $aliasFollowing
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
			// No viewer means no follows are consulted, so the follows table
			// must not be in the FROM list at all: a plain `FROM social_follow`
			// with nothing to join it against is a cartesian product, and on an
			// instance with no follows at all it collapses every row. Passing
			// '' suppresses it, exactly as the viewer branch below does.
			$this->selectDestFollowing($aliasDest, '');
			$this->innerJoinStreamDest('recipient', 'id_prim', 'sd', 's');
			$this->limitToDest(ACore::CONTEXT_PUBLIC, 'recipient', $publicSubType, $aliasDest);

			return;
		}

		$this->selectDestFollowing($aliasDest, '');
		$this->leftJoinFollowing($aliasFollowing);
		$expr = $this->expr();
		$actor = $this->getViewer();

		$conditions = [
			$this->exprInnerJoinStreamDestFollowing(
				$actor->getId(), 'recipient', 'id_prim', $aliasDest, $aliasFollowing
			)
		];

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
	 * Limit to posts that carry at least one attachment.
	 *
	 * `attachments` is the JSON list the post was stored with, and "no media"
	 * has three spellings in it — NULL for a row written before the column
	 * existed, `''` and `'[]'` — so all three are excluded rather than the one
	 * that happens to be commonest. Plain string comparison, not a JSON
	 * function: the same predicate has to run on MySQL, PostgreSQL and SQLite.
	 */
	public function limitToMedia(): self {
		$expr = $this->expr();
		$pf = $this->getDefaultSelectAlias();

		// `media_kind` says this in one indexed column, and is what answers
		// wherever it has been filled in. The three string comparisons below
		// are what a row written before that column existed is judged by, and
		// they are the reason this predicate could never use an index: they are
		// applied *after* the join, so a Photos timeline on a ten-million-row
		// table read every candidate row and then decided.
		$this->andWhere($expr->orX(
			$expr->andX(
				$expr->isNotNull($pf . '.media_kind'),
				$expr->neq($pf . '.media_kind', $this->createNamedParameter(Stream::MEDIA_KIND_NONE))
			),
			$expr->andX(
				$expr->isNull($pf . '.media_kind'),
				$expr->isNotNull($pf . '.attachments'),
				$expr->neq($pf . '.attachments', $this->createNamedParameter('')),
				$expr->neq($pf . '.attachments', $this->createNamedParameter('[]'))
			)
		));

		return $this;
	}

	/**
	 * Limit to posts that are a video.
	 *
	 * Two ways of being one, because there are two ways a video reaches this
	 * app. Somebody here -- or on Mastodon, or on Pixelfed -- posts a file,
	 * and it is an attachment whose Mastodon type is `video`. PeerTube
	 * publishes a `Video` object instead, whose whole existence is the video;
	 * that arrives as a `Note` carrying `Video` in `subtype` (see
	 * `AP::NOTE_LIKE_TYPES`), and it counts whether or not this instance found
	 * a file in it that a browser can play.
	 *
	 * The attachment half is a substring test rather than a JSON function, for
	 * the same reason `limitToMedia()` is one: the predicate has to run on
	 * MySQL, PostgreSQL and SQLite alike. It is exact rather than approximate
	 * -- `json_encode` writes no spaces, and a `"` inside a *value* is escaped
	 * as `\"`, so the seven characters `"video"` preceded by `"type":` cannot
	 * occur anywhere but in the field this is asking about.
	 */
	public function limitToVideo(): self {
		$expr = $this->expr();
		$pf = $this->getDefaultSelectAlias();

		$this->andWhere($expr->orX(
			// the indexed answer, wherever the column has been filled in.
			// `mixed` counts: a post with a photograph and a video in it is a
			// video post, and the column had to choose one word for it.
			$expr->in($pf . '.media_kind', $this->createNamedParameter(
				['video', Stream::MEDIA_KIND_MIXED], IQueryBuilder::PARAM_STR_ARRAY
			)),
			// and the old question, for a row the backfill has not reached
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
	 * Narrows the media to one kind of attachment, for the kinds that have no
	 * method of their own.
	 *
	 * `video` is not one of them: a video can also arrive as a PeerTube
	 * `Video` object with no attachment at all, so that question is
	 * `limitToVideo()` and `StreamRequest::filterMedia()` sends it there.
	 * What is left is `image` and `audio`, where the attachment is the only
	 * way the post can be one.
	 *
	 * The column holds the attachments as the client sees them
	 * (`MediaAttachment::asLocal()`), so each one carries `"type":"image"` —
	 * the first half of its MIME type. There is no column to compare and no
	 * JSON support to rely on across the three databases this app supports, so
	 * it is a `LIKE` on that pair, exactly as `limitToVideo()` does it.
	 *
	 * Unindexed, like the silenced-instance filter and for the same reason:
	 * it runs on a list that something else has already narrowed — a profile,
	 * which is one account's posts.
	 */
	public function limitToMediaType(string $type): self {
		$expr = $this->expr();
		$pf = $this->getDefaultSelectAlias();

		$this->andWhere($expr->orX(
			$expr->in($pf . '.media_kind', $this->createNamedParameter(
				[$type, Stream::MEDIA_KIND_MIXED], IQueryBuilder::PARAM_STR_ARRAY
			)),
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
	 * Limit to posts that are news: an article, or a post linking to one.
	 *
	 * One indexed column, unlike its media siblings, and with no `LIKE`
	 * fallback for a row the backfill has not reached — see
	 * `Version1000Date20260917000005`. The question this answers cannot be put
	 * to a database at all ("is there a link in this markup that is not a
	 * mention"), so there is nothing to fall back *to*: an unclassified row is
	 * not news yet, and the backfill is what makes it so.
	 *
	 * `IN` rather than `!= ''` because the column is nullable and `NULL` must
	 * not match, and because naming the two values is what lets a caller later
	 * ask for only one of them.
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
	 * Limit to posts carrying a hashtag the viewer follows.
	 *
	 * Two inner joins: the post's tags, and the ones this account follows. It
	 * says nothing about *visibility* — a followed hashtag is not a
	 * relationship with the author — so the caller adds that; see
	 * `StreamRequest::followedTagNids()`, which limits to public.
	 *
	 * Both sides hold the tag in one form — `FollowedTagsRequest::normalise()`,
	 * which `social_stream_tag` is written in as well (see
	 * `Version1000Date20260925000001`) — so they are compared as they stand.
	 * It is the same comparison `hashtagTimelineNids()` makes on the same
	 * column, which is what keeps "posts tagged #x" and "I follow #x" meaning
	 * one thing. With no function over either column the join can be driven
	 * from the account's followed tags (`social_followed_tag`'s
	 * `(actor_id_prim, hashtag)` index) to the posts carrying them
	 * (`social_st_ht`), as well as from the stream through the
	 * `(stream_id, hashtag)` unique index.
	 *
	 * A post carrying two followed tags matches twice; the caller selects
	 * DISTINCT over the one column it pages by, which is the cheap place to
	 * fold that back into one row.
	 *
	 * No viewer, no followed tags, and this adds nothing — which would leave
	 * an unconstrained query, so callers must only use it with a viewer.
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
				// a mute whose expiry has passed is not a mute: it stops
				// applying on the read, with nothing deleting the row. The
				// expiry belongs in this ON clause and not in the WHERE —
				// there, a second matching relation row would let the post
				// through whenever one of them satisfied the relaxed predicate
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

		// an instance the viewer blocked for themselves, which is not a row in
		// social_actor_relation and cannot be one: it applies to accounts that
		// do not exist yet
		DomainBlocksRequestBuilder::filterDomainBlocked($this);
	}

	/**
	 * Drops the boosts of accounts whose boosts this viewer has turned off.
	 *
	 * Mastodon's `reblogs: false` on a follow: "I want to read you, not what
	 * you pass on." Only the boost rows go — the account's own posts are why
	 * the viewer follows them, and hiding those would be a mute under another
	 * name.
	 *
	 * The type predicate is in the ON clause rather than the WHERE for the
	 * reason the mute expiry is: in the WHERE it would apply to every row, and
	 * a post that is not a boost would be dropped whenever the viewer had a
	 * row of this type about its author.
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
