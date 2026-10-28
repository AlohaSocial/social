<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use OCA\Social\AP;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Mention;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\Details;
use OCA\Social\Model\Moderation;
use OCA\Social\Service\ConfigService;
use OCA\Social\Tools\Exceptions\DateTimeException;
use OCA\Social\Tools\Nid;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Every timeline `StreamRequest` can be asked for, and nothing else.
 *
 * A timeline is answered in two steps — choose the nids a page is made of,
 * then read those posts — because the choosing is where the filters and the
 * indexes are and the reading is the same query every time. Each `getTimeline*`
 * pairs with a `*TimelineNids`, and the private helpers between them are the
 * filters those share.
 *
 * It is a trait rather than a class of its own: every one of these reaches
 * into `StreamRequest` for the query builders, the viewer and half its
 * injected requests, so a separate object would be one holding a reference
 * back to the object it came from. What this separates is the file, which is
 * the thing that was actually too big to read — a third of `StreamRequest` was
 * this one subject.
 */
trait StreamTimelines {
	/**
	 * @param ProbeOptions $options
	 *
	 * @return Stream[]
	 */
	public function getTimeline(ProbeOptions $options): array {
		switch (strtolower($options->getProbe())) {
			case ProbeOptions::ACCOUNT:
				$result = $this->getTimelineAccount($options);
				break;
			case ProbeOptions::HOME:
				$result = $this->getTimelineHome($options);
				break;
			case ProbeOptions::DIRECT:
				$result = $this->getTimelineDirect($options);
				break;
			case ProbeOptions::FAVOURITES:
				$result = $this->getTimelineFavourites($options);
				break;
			case ProbeOptions::BOOKMARKS:
				$result = $this->getTimelineBookmarks($options);
				break;
			case ProbeOptions::HASHTAG:
				$result = $this->getTimelineHashtag($options);
				break;
			case ProbeOptions::NOTIFICATIONS:
				$options->setFormat(ACore::FORMAT_NOTIFICATION);
				$result = $this->getTimelineNotifications($options);
				break;
			case ProbeOptions::PUBLIC:
				$result = $this->getTimelinePublic($options);
				break;
			default:
				return [];
		}

		if ($options->isInverted()) {
			// in case we inverted the order during the request, we revert the results
			$result = array_reverse($result);
		}

		return $result;
	}

	/**
	 * Should return:
	 *  * Own posts,
	 *  * Followed accounts,
	 *  * Public posts carrying a hashtag the viewer follows
	 *
	 * Two pages of ids, not one query. A post belongs here because of its
	 * author *or* because of its tags, and written as one query that is an OR
	 * across two different joins: no index can serve both sides of it, so the
	 * database falls back to reading the stream table. Each half on its own is
	 * a query over one indexed column, which is the shape the rest of this
	 * method depends on.
	 *
	 * Merging them here is exact rather than approximate. Both halves are
	 * ordered and cut to the same limit, so anything that belongs in the top
	 * `limit` of the union is in the top `limit` of the half it came from —
	 * there can be at most `limit - 1` ids above it in the union, hence at
	 * most that many in its own half. Sorting the two and cutting is therefore
	 * the same page one query would have produced.
	 *
	 * @param ProbeOptions $options
	 *
	 * @return Stream[]
	 */
	private function getTimelineHome(ProbeOptions $options): array {
		// which posts, decided over one column, then what they say
		$this->homeServedFromRecipients = false;
		$nids = $this->homeTimelineNids($options);
		$fast = $this->homeServedFromRecipients;

		if ($this->followsAnyTag()) {
			$nids = $this->mergeNidPages($nids, $this->followedTagNids($options), $options);
		}

		if ($nids === []) {
			return [];
		}

		// The fast page query narrows on one column and carries none of the
		// per-viewer filters; they are applied here, to the rows it chose,
		// where each is a lookup against twenty ids rather than a join across
		// everything the viewer follows.
		$posts = $this->streamsByNids($nids, $options, $fast);
		if (!$fast) {
			return $posts;
		}

		return array_slice(
			$this->refilledHomePage($posts, $nids, $options), 0, $options->getLimit()
		);
	}

	/**
	 * Fetches further windows when filtering emptied the one that was read.
	 *
	 * The fast page query reads three times the limit and the per-viewer
	 * filters are applied to the rows afterwards, which covers a page that
	 * loses a few posts to a block. It does not cover a page that loses *all*
	 * of them: a muted account that has just posted sixty times, or a blocked
	 * instance that dominates the window, and the page comes back empty while
	 * older posts the reader can see sit further down. Empty is how both
	 * clients read "there is nothing more" — the web app sets `allLoaded` on a
	 * page of zero and the `Link: rel="next"` header is not sent — so the
	 * timeline ended, in the middle.
	 *
	 * So a short page reads on, from below the oldest id this page has already
	 * *considered* rather than from the oldest it kept: everything between the
	 * two was looked at and dropped. Bounded, because a reader who has muted
	 * everything they follow must not turn one request into a walk of the
	 * table; four more windows is twelve times the limit, and a page still
	 * short after that is one where the join path would be reading the same
	 * rows to no better end.
	 *
	 * @param Stream[] $posts what the first window came back with
	 * @param string[] $window the ids that window was chosen from
	 *
	 * @return Stream[]
	 */
	private function refilledHomePage(array $posts, array $window, ProbeOptions $options): array {
		return $this->refilledPage(
			$posts, $window, $options,
			function (ProbeOptions $next): array {
				$window = $this->homeTimelineNidsFromRecipients($next) ?? [];
				if ($window !== [] && $this->followsAnyTag()) {
					$window = $this->mergeNidPages($window, $this->followedTagNids($next), $next);
				}

				return $window;
			},
			fn (array $window, ProbeOptions $next): array => $this->streamsByNids($window, $next, true)
		);
	}

	/**
	 * The rounds `refilledHomePage()` describes, for any page chosen off the
	 * recipient rows and filtered afterwards: posts, or the ids of them.
	 *
	 * @template T
	 *
	 * @param list<T> $posts what the first window came back with
	 * @param string[] $window the ids that window was chosen from
	 * @param callable(ProbeOptions): string[] $nextWindow the ids below (or
	 *                                                     above) the cursor the options carry
	 * @param callable(string[], ProbeOptions): list<T> $hydrate those ids filtered, and read if
	 *                                                           that is what the page holds
	 *
	 * @return list<T>
	 */
	private function refilledPage(
		array $posts, array $window, ProbeOptions $options, callable $nextWindow, callable $hydrate,
	): array {
		for ($round = 0; $round < self::HOME_REFILL_ROUNDS; $round++) {
			if (count($posts) >= $options->getLimit() || $window === []) {
				break;
			}

			$next = clone $options;
			if ($options->isInverted()) {
				$next->setMinId(max($window));
			} else {
				$next->setMaxId(min($window));
			}

			$window = $nextWindow($next);
			if ($window === []) {
				break;
			}

			$posts = array_merge($posts, $hydrate($window, $next));
		}

		return $posts;
	}

	/**
	 * Narrows a timeline to one kind of post, when the caller asked for one.
	 *
	 * The one seam every timeline query goes through, so that a question a
	 * client may put to any of them is answered the same way by all of them.
	 * Each filter below does nothing unless it was asked for.
	 */
	private function filterKind(SocialQueryBuilder $qb, ProbeOptions $options): void {
		$this->filterMedia($qb, $options);
		$this->filterNews($qb, $options);
	}

	/**
	 * Applies `only_news` when the caller asked for it.
	 *
	 * Independent of the media filters rather than a narrowing of them: an
	 * article with no picture is news, and a holiday photograph with a link in
	 * the caption is both. A client that sends `only_media` and `only_news`
	 * gets the posts that are both, which is what the two words together say.
	 */
	private function filterNews(SocialQueryBuilder $qb, ProbeOptions $options): void {
		if ($options->isOnlyNews()) {
			$qb->limitToNews();
		}
	}

	/**
	 * Applies `only_media`, `only_video` and `only_short` when the caller
	 * asked for them.
	 *
	 * The first has been parsed off the request since the hashtag timeline
	 * gained it and was never applied to a query, so `only_media=true` quietly
	 * returned everything. Every timeline that can carry media runs it through
	 * here, so the dedicated photo timeline and a client asking Mastodon's
	 * question of any other list get the same answer.
	 *
	 * `only_video` is this app's own and narrower; the video timeline sends
	 * it. Both may be sent at once -- the narrower one then decides, since
	 * every video is media.
	 *
	 * `media_type` is narrower still and names the kind, which is what a
	 * profile's Photos and Videos tabs ask; `media_type=video` is the same
	 * question `only_video` asks and is answered by the same predicate.
	 *
	 * `only_short` is the narrowest, a stored column the write fills in
	 * (`Stream::shortKindOf()`), and decides whatever else was sent.
	 */
	private function filterMedia(SocialQueryBuilder $qb, ProbeOptions $options): void {
		// the narrowest: every short is a video, and every video is media
		if ($options->isOnlyShort()) {
			$qb->limitToShort();

			return;
		}

		if ($options->getMediaType() === 'visual') {
			$qb->limitToVisualMedia();

			return;
		}

		// `media_type=video` and `only_video` are the same question, so they
		// are one predicate: a profile's Videos tab and the Videos timeline
		// cannot come to disagree about whether a PeerTube `Video` is a video.
		if ($options->isOnlyVideo() || $options->getMediaType() === 'video') {
			$qb->limitToVideo();

			return;
		}

		// any other kind narrows `only_media` to attachments of that type,
		// which is what a profile's Photos tab asks. It implies `only_media`:
		// a post with no attachments cannot be one carrying a picture, and
		// saying so here means a caller that sends only `media_type` gets what
		// they asked for rather than everything.
		if ($options->getMediaType() !== '') {
			$qb->limitToMedia();
			$qb->limitToMediaType($options->getMediaType());

			return;
		}

		if ($options->isOnlyMedia()) {
			$qb->limitToMedia();
		}
	}

	/**
	 * The page of the home timeline that the viewer's follows put there.
	 *
	 * @return string[]
	 */
	protected function homeTimelineNids(ProbeOptions $options): array {
		$fast = $this->homeTimelineNidsFromRecipients($options);
		if ($fast !== null) {
			// which half answered decides what `streamsByNids()` has left to
			// do, and it is recorded rather than returned so that this stays
			// one overridable seam returning one thing
			$this->homeServedFromRecipients = true;

			return $fast;
		}

		// a media or news narrowing: the newest posts of the kind, then the
		// join query asked about those few dozen alone
		if ($this->recipientNidsAreFilled() && $this->narrowedToAKind($options)) {
			$byKind = $this->narrowedNidsByKind(
				$options, fn (array $window, ProbeOptions $next): array => $this->homeTimelineNidsByJoin($next, $window)
			);
			if ($byKind !== null) {
				return $byKind;
			}
		}

		return $this->homeTimelineNidsByJoin($options);
	}

	/**
	 * The old page query: correct everywhere, slow at scale.
	 *
	 * Kept as the fallback for an instance whose backfill has not finished and
	 * for a viewer the fast path cannot serve. See
	 * `homeTimelineNidsFromRecipients()` for what is wrong with it.
	 *
	 * @param string[]|null $within the posts to choose among, or null for all
	 *
	 * @return string[]
	 */
	private function homeTimelineNidsByJoin(ProbeOptions $options, ?array $within = null): array {
		$page = $this->getStreamNidsSelectSql();
		$this->homeTimelineFilters($page, $options, false);
		if ($within !== null) {
			$page->andWhere($page->expr()->in('s.nid', $page->createNamedParameter($within, IQueryBuilder::PARAM_STR_ARRAY)));
		}
		$followed = $this->getNidsFromRequest($page);
		if (!$page->hasViewer()) {
			return $followed;
		}

		// The viewer's own posts are addressed to their follower collection,
		// which they do not follow. Read that one indexed range separately:
		// OR-ing it with the followed range makes the database scan `s`.
		$own = $this->getStreamNidsSelectSql();
		$own->filterType(SocialAppNotification::TYPE);
		$own->paginate($options);
		$this->filterKind($own, $options);
		$own->selectDestFollowing('sd', '');
		$own->leftJoinFollowing('f');
		$viewer = $own->getViewer();
		$own->andWhere($own->expr()->andX(
			$own->expr()->eq('s.attributed_to_prim', $own->createNamedParameter(md5($viewer->getId()))),
			$own->expr()->eq('sd.stream_id', 's.id_prim'),
			$own->expr()->eq('sd.actor_id', $own->createNamedParameter(md5($viewer->getFollowers()))),
			$own->expr()->eq('sd.type', $own->createNamedParameter('recipient')),
		));
		if ($within !== null) {
			$own->andWhere($own->expr()->in('s.nid', $own->createNamedParameter($within, IQueryBuilder::PARAM_STR_ARRAY)));
		}
		$own->filterHiddenActors(SocialCoreQueryBuilder::HIDDEN_TIMELINE);
		$own->filterHiddenBoosts();
		$this->timelineHomeLinkCacheActor($own, 'ca', 'f', false);
		$own->filterDuplicate();

		return $this->mergeNidPages($followed, $this->getNidsFromRequest($own), $options);
	}

	/**
	 * The home page, read off the recipient rows' own sort key.
	 *
	 * This is the query the home timeline is supposed to be, and the one the
	 * `nid` column on `social_stream_dest` exists for. The old shape — kept
	 * below as the fallback — drives from the viewer's follows, fetches
	 * **every recipient row every followed account ever produced**, joins each
	 * to `social_stream` to find out when it was published, and sorts the lot
	 * in a temporary table to keep twenty. `EXPLAIN` named it exactly:
	 * `Using temporary; Using filesort`. One page load therefore costs
	 * Σ(all posts of everyone you follow), and a client asks for one every
	 * thirty seconds.
	 *
	 * Here the sort key is *on the row being filtered*, so
	 * `(actor_id, type, nid)` answers the page from the index, without a row
	 * lookup. It is not a merge that stops at the limit: across several
	 * collections the database reads every entry the predicate admits and
	 * sorts them, so the predicate is what bounds the read. Two things bound
	 * it. The collections come from `FollowsRequest::limitToHomeCollections()`,
	 * which names up to five hundred of them and leaves a longer list to the
	 * database. And the page is read within a window of publication time —
	 * the nid is the publication time with a random suffix — first the last
	 * day before the cursor, widened only when that comes back short; see
	 * `homeRecipientNids()`.
	 *
	 * The filters that used to ride along in that query — blocks, mutes,
	 * hidden boosts, the media narrowing — are **not** applied here. They are
	 * per-viewer predicates over joined tables, and putting them back would
	 * put the join back. They are applied to the twenty rows instead, in
	 * `streamsByNids()`, where they cost twenty lookups rather than three
	 * million. `withoutHidden()` reads a page slightly larger than the limit
	 * so that a page which loses rows to a block still fills.
	 *
	 * Returns **null** where this path cannot be used — no viewer, nothing
	 * followed, or an instance whose backfill has not finished — and the
	 * caller falls back to the old query, which is slower and always correct.
	 *
	 * @return string[]|null
	 */
	protected function homeTimelineNidsFromRecipients(ProbeOptions $options): ?array {
		if ($this->viewer === null || !$this->recipientNidsAreFilled()) {
			return null;
		}

		// A media or news narrowing is a question about the **post**, and this
		// query reads only the recipient rows — which carry no such column.
		// Asking it anyway would read the newest rows and then throw most of
		// them away: on an instance where a small fraction of posts carry a
		// picture, a Photos or Videos timeline would come back empty while the
		// pictures sat a few thousand rows further down. News is the same
		// shape of question and the same trap, and a rarer kind of post than a
		// photograph at that. So the narrowed timelines take the join path,
		// which has the predicate *in* the query and finds them wherever they
		// are. They are also read far less often than the home timeline, which
		// is what makes that the right trade rather than a concession.
		if ($this->narrowedToAKind($options)) {
			return null;
		}

		$own = $this->viewer->getFollowers();
		$qb = $this->getQueryBuilder();
		$followed = $this->followsRequest->limitToHomeCollections($qb, 'sd.actor_id', $this->viewer->getId());
		if ($followed === '' && $own === '') {
			return null;
		}

		if ($options->getMinId() > 0) {
			$options->setInverted(true);
		}
		$ascending = $options->isInverted();
		// read wider than the page: the filters this query no longer carries
		// are applied to the rows afterwards, and a page that lost three posts
		// to a block should still come back with twenty
		$wanted = min(self::HOME_OVERREAD_MAX, $options->getLimit() * self::HOME_OVERREAD);

		$nids = [];
		if ($followed !== '') {
			$this->selectHomeRecipients($qb, $followed, $options, $wanted);

			if ($ascending) {
				$anchor = Nid::publishedTimeOf($options->getMinId(), self::NID_LIMIT);
			} elseif ($options->getMaxId() > 0) {
				$anchor = Nid::publishedTimeOf($options->getMaxId(), self::NID_LIMIT);
			} else {
				$anchor = time();
			}
			$nids = $this->homeRecipientNids($qb, $wanted, $ascending, $anchor, $options->getSince());
		}

		// An account's own posts reach its own timeline through the recipient
		// row addressed to its own follower collection, which it is not a
		// follower of. A query of its own rather than one more name in the
		// list: past the cap the list is a sub-select, and `OR` beside it keeps
		// MariaDB from turning it into a semi-join — it scanned every recipient
		// row instead. One collection is one index range, read in order and
		// stopping at the limit, so it needs no window either.
		if ($own !== '') {
			$mine = $this->getQueryBuilder();
			$this->selectHomeRecipients(
				$mine, $mine->expr()->eq('sd.actor_id', $mine->createNamedParameter(md5($own))), $options, $wanted
			);
			$nids = $this->mergeNidPages($nids, $this->getNidsFromRequest($mine), $options, $wanted);
		}

		return $nids;
	}

	/** Whether the caller asked for one kind of post; see `filterKind()`. */
	private function narrowedToAKind(ProbeOptions $options): bool {
		return $options->isOnlyMedia() || $options->isOnlyVideo() || $options->isOnlyShort()
			|| $options->getMediaType() !== '' || $options->isOnlyNews();
	}

	/**
	 * The page query over recipient rows, for the collections `$collections`
	 * names.
	 */
	private function selectHomeRecipients(
		SocialQueryBuilder $qb, string $collections, ProbeOptions $options, int $wanted,
	): void {
		$expr = $qb->expr();
		// Not `DISTINCT`. A post reaches this set through its author's
		// follower collection and no other, so a duplicate needs a post
		// addressed to two collections the viewer follows — which this app's
		// writer never produces and a remote one could. It would cost nothing
		// if it did: the hydration below selects `WHERE nid IN (…)`, which
		// returns one row per nid however many times the id was listed. The
		// `DISTINCT` bought that guarantee a second time and paid for it with
		// a temporary table over every index entry the page scanned — 27.7 ms
		// against 20.8 on the seeded instance.
		$qb->select('sd.nid')
			->from(self::TABLE_STREAM_DEST, 'sd')
			->where($collections)
			->andWhere($expr->eq('sd.type', $qb->createNamedParameter('recipient')))
			// a row written before the column existed carries 0 and would sort
			// to the bottom for ever; it is excluded rather than shown last
			->andWhere($expr->gt('sd.nid', $qb->createNamedParameter('0')));

		$this->boundNidPage($qb, 'sd', $options, $wanted);
	}

	/**
	 * The cursor, the order and the size of a page of nids read off one
	 * table: what every page query chosen before its rows are read shares.
	 */
	private function boundNidPage(SocialQueryBuilder $qb, string $alias, ProbeOptions $options, int $wanted): void {
		$expr = $qb->expr();
		if ($options->getSince() > 0) {
			$qb->andWhere($expr->gt($alias . '.nid', $qb->createNamedParameter($options->getSince())));
		}
		if ($options->getMaxId() > 0) {
			$qb->andWhere($expr->lt($alias . '.nid', $qb->createNamedParameter($options->getMaxId())));
		}
		if ($options->getMinId() > 0) {
			$qb->andWhere($expr->gt($alias . '.nid', $qb->createNamedParameter($options->getMinId())));
		}

		$qb->orderBy($alias . '.nid', $options->isInverted() ? 'asc' : 'desc');
		$qb->setMaxResults($wanted);
	}

	/**
	 * The newest nid on the viewer's home timeline, or '0': what its entity
	 * tag is built from.
	 *
	 * The same collections and the same windows as the page, so a probe the
	 * client repeats every thirty seconds reads the last day of what the
	 * viewer follows rather than all of it.
	 */
	public function newestHomeNid(Person $viewer): string {
		$newest = '0';

		$qb = $this->getQueryBuilder();
		$followed = $this->followsRequest->limitToHomeCollections($qb, 'sd.actor_id', $viewer->getId());
		if ($followed !== '') {
			$qb->select('sd.nid')
				->from(self::TABLE_STREAM_DEST, 'sd')
				->where($followed)
				->andWhere($qb->expr()->eq('sd.type', $qb->createNamedParameter('recipient')))
				->orderBy('sd.nid', 'desc')
				->setMaxResults(1);
			$newest = $this->homeRecipientNids($qb, 1, false, time(), 0)[0] ?? '0';
		}

		// the account's own posts, apart for the reason the page gives
		$own = $viewer->getFollowers();
		if ($own !== '') {
			$mine = $this->newestNidFor([md5($own)]);
			if (Nid::compare($mine, $newest) > 0) {
				$newest = $mine;
			}
		}

		return $newest;
	}

	/**
	 * Runs a home recipient query within a window of publication time, widened
	 * until the page is full.
	 *
	 * A nid is `published_time * NID_LIMIT + random`, so "published within
	 * the last day before the cursor" is a range on the column the query is
	 * ordered by, and it turns the sort over everything the viewer follows
	 * into a sort over that day. The answer is exact rather than approximate:
	 * every row outside a window is further from the cursor than every row
	 * inside it, so a window that yields `$wanted` rows yields the same rows
	 * the unbounded query would. Only a short page is read again, wider, and
	 * the last attempt has no bound at all. The query is built once and each
	 * attempt rebinds the one parameter.
	 *
	 * @param SocialQueryBuilder $qb ordered on `sd.nid` and limited to `$wanted`
	 * @param bool $ascending whether the page reads forward from its cursor
	 * @param int $anchor the publication time the window is measured from
	 * @param int|string $since a lower bound the query already carries, if any
	 *
	 * @return string[]
	 */
	private function homeRecipientNids(
		SocialQueryBuilder $qb, int $wanted, bool $ascending, int $anchor, int|string $since,
	): array {
		$qb->andWhere(
			$ascending
				? $qb->expr()->lt('sd.nid', $qb->createNamedParameter((string)PHP_INT_MAX, IQueryBuilder::PARAM_STR, ':home_window'))
				: $qb->expr()->gt('sd.nid', $qb->createNamedParameter('0', IQueryBuilder::PARAM_STR, ':home_window'))
		);

		$windows = self::HOME_WINDOWS;
		$windows[] = 0;
		$nids = [];
		foreach ($windows as $seconds) {
			$bound = $this->homeWindowBound($anchor, $seconds, $ascending);
			$qb->setParameter('home_window', $bound);

			$nids = [];
			$cursor = $qb->executeQuery();
			while ($data = $cursor->fetch()) {
				$nids[] = (string)$data['nid'];
			}
			$cursor->closeCursor();

			// a full page is the page; so is one whose window already reaches
			// past the lower bound the query carries anyway
			if (count($nids) >= $wanted || $seconds === 0
				|| (!$ascending && Nid::compare($since, '0') > 0 && Nid::compare($bound, $since) <= 0)) {
				break;
			}
		}

		return array_values(array_unique($nids));
	}

	/**
	 * The nid `$seconds` of publication time away from `$anchor`: below it
	 * for a page read backwards, above it for one read forwards. No window —
	 * `$seconds` of 0, or one reaching before 1970 — is the widest bound the
	 * column allows.
	 */
	private function homeWindowBound(int $anchor, int $seconds, bool $ascending): string {
		if ($seconds === 0 || $anchor <= 0 || (!$ascending && $anchor - $seconds < 0)) {
			return $ascending ? (string)PHP_INT_MAX : '0';
		}

		$time = $ascending ? $anchor + $seconds : $anchor - $seconds;

		return Nid::fromPublishedTime($time, 0, self::NID_LIMIT);
	}

	/**
	 * Pages a timeline on the recipient row's nid rather than the post's.
	 *
	 * For a query whose recipient join, aliased `sd`, fixes the collection and
	 * the type; see `SocialLimitsQueryBuilder::paginate()`. The two nids are
	 * the same number — the row is written with its post's — except on an
	 * instance whose backfill has not finished, where a row may still carry 0
	 * and would sort to the bottom for ever, so until the flag says so the
	 * page stays on the post's. A zero is excluded rather than shown last, as
	 * on the home timeline.
	 */
	private function paginateOnRecipient(SocialQueryBuilder $qb, ProbeOptions $options): void {
		if (!$this->recipientNidsAreFilled()) {
			$qb->paginate($options);

			return;
		}

		$qb->paginate($options, 'sd');
		$qb->andWhere($qb->expr()->gt('sd.nid', $qb->createNamedParameter('0')));
	}

	/**
	 * Whether the recipient rows carry their post's sort key yet.
	 *
	 * A flag written by the migration when its backfill finishes, not a
	 * question asked of the table. The obvious check — "is there a row with a
	 * zero nid" — has no index that can answer it, so it is a full scan of the
	 * largest table this app has: **427 ms on 800,000 rows**, on every request.
	 * A guard that costs more than the query it guards is worse than no guard,
	 * and this one was measured rather than assumed.
	 */
	private function recipientNidsAreFilled(): bool {
		$this->recipientNidsFilled ??= $this->configService->getAppValueBool(
			ConfigService::SOCIAL_DEST_NID_FILLED
		);

		return $this->recipientNidsFilled;
	}

	/**
	 * The page of the home timeline that the viewer's followed hashtags put
	 * there: public posts carrying one of them.
	 *
	 * Public only — a followed hashtag is not a relationship with the author,
	 * so it may not reach past what any stranger can read, and not even every
	 * post a stranger can read: an unlisted post is kept off the tag timelines,
	 * as on Mastodon. Every other filter
	 * the follows half applies holds here too: notifications are not posts,
	 * the viewer's own boosts are not shown back to them, blocked and muted
	 * accounts stay hidden, and a silenced account is out of the public square
	 * this half reads from, exactly as it is out of the hashtag timeline.
	 *
	 * @return string[]
	 */
	protected function followedTagNids(ProbeOptions $options): array {
		$page = $this->getStreamNidsSelectSql();

		$page->filterType(SocialAppNotification::TYPE);
		$page->paginate($options);
		$this->filterKind($page, $options);
		$page->limitToFollowedTags('ft_st', 'ft');
		$page->selectDestFollowing('ft_sd', '');
		$page->innerJoinStreamDest('recipient', 'id_prim', 'ft_sd', 's');
		// addressed *to* the public collection: an unlisted post carries the
		// tag and is readable, and is still kept off every tag timeline, as
		// its author asked
		$page->limitToDest(ACore::CONTEXT_PUBLIC, 'recipient', 'to', 'ft_sd');
		$page->filterHiddenActors();
		$page->filterDuplicate();
		$this->filterSilencedActors($page);

		return $this->getNidsFromRequest($page);
	}

	/**
	 * Whether the second half is worth asking for at all.
	 *
	 * One count answered out of the `(actor_id_prim, hashtag)` index without
	 * reading a row, and it is the whole cost of this feature to the accounts
	 * that follow no tag — which is all of them until they say otherwise.
	 */
	private function followsAnyTag(): bool {
		return $this->viewer !== null
			&& $this->followedTagsRequest->countByActor($this->viewer->getId()) > 0;
	}

	/**
	 * One page out of two, in the order the page is asked for, with nothing
	 * twice: a post by somebody the viewer follows that also carries a tag
	 * they follow is in both halves and is one post.
	 *
	 * @param string[] $first
	 * @param string[] $second
	 * @param int|null $limit where to cut, when not at the page's own limit
	 *
	 * @return string[]
	 */
	private function mergeNidPages(array $first, array $second, ProbeOptions $options, ?int $limit = null): array {
		if ($second === []) {
			return $first;
		}

		$nids = array_values(array_unique(array_merge($first, $second)));
		if ($options->isInverted()) {
			usort($nids, static fn (string $a, string $b): int => \OCA\Social\Tools\Nid::compare($a, $b));
		} else {
			usort($nids, static fn (string $a, string $b): int => \OCA\Social\Tools\Nid::compare($b, $a));
		}

		return array_slice($nids, 0, $limit ?? $options->getLimit());
	}

	/**
	 * The rows of a page that has already been decided, in its order.
	 *
	 * @param string[] $nids
	 *
	 * @return Stream[]
	 */
	protected function streamsByNids(array $nids, ProbeOptions $options, bool $homeFilters = false): array {
		$qb = $this->getStreamSelectSql($options->getFormat());
		$qb->andWhere(
			$qb->expr()->in('s.nid', $qb->createNamedParameter($nids, IQueryBuilder::PARAM_STR_ARRAY))
		);
		$qb->orderBy('s.nid', $options->isInverted() ? 'asc' : 'desc');

		// the author, for the row; the follows table stays out of this query
		// because the page has already decided what belongs in it
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$qb->leftJoinStreamAction('sa');
		$qb->leftJoinObjectStatus();

		if ($homeFilters) {
			// what the fast page query deliberately left out. Every one of
			// these is a per-viewer predicate over a joined table, and in the
			// page query it forced the join that made the page cost
			// Σ(everything the viewer follows). Here the driving set is the
			// page itself.
			$qb->filterType(SocialAppNotification::TYPE);
			$this->filterKind($qb, $options);
			$qb->filterHiddenBoosts();
			$qb->filterHiddenActors();
			$qb->filterDuplicate();
		}

		return $this->getStreamsFromRequest($qb);
	}

	/**
	 * Everything that decides whether a post belongs in the viewer's home
	 * timeline. Applied to the query that picks the page; the query that reads
	 * the rows afterwards needs none of it, because the page already said which.
	 */
	/**
	 * @param SocialQueryBuilder $qb the query being built
	 * @param ProbeOptions $options what was asked for
	 * @param bool $select whether the joined author's columns are wanted; the
	 *                     page-selection query projects nids and does not want
	 *                     several kilobytes of actor per row inside its
	 *                     `SELECT DISTINCT`
	 */
	private function homeTimelineFilters(
		SocialQueryBuilder $qb, ProbeOptions $options, bool $select = true,
	): void {
		$qb->filterType(SocialAppNotification::TYPE);
		$qb->paginate($options);
		$this->filterKind($qb, $options);
		$qb->limitToViewer('sd', 'f', false);
		// "show me this account, not what they pass on"
		$qb->filterHiddenBoosts();
		// a filter, not a join: it constrains on the follow's type
		$this->timelineHomeLinkCacheActor($qb, 'ca', 'f', $select);
		$qb->filterDuplicate();
	}

	/**
	 * Should return:
	 *  * Private message.
	 *  - group messages. (not yet)
	 *
	 * @param ProbeOptions $options
	 *
	 * @return Stream[]
	 */
	private function getTimelineDirect(ProbeOptions $options): array {
		// two queries, as every other timeline: which posts, decided over one
		// indexed column, then what they say
		$nids = $this->directTimelineNids($options);
		if ($nids === []) {
			return [];
		}

		return $this->streamsByNids($nids, $options);
	}

	/**
	 * The direct timeline's page, each message reduced to its place in a
	 * conversation: its id, its nid, the post it answers and its author,
	 * newest first as `getTimeline()` answers them.
	 *
	 * The same page, chosen by the same query, without reading the posts
	 * themselves: what a count over the page needs, and nothing a page that
	 * is drawn needs.
	 *
	 * @return list<array{id: string, nid: string, inReplyTo: string, attributedTo: string}>
	 */
	public function getDirectTimelineLinks(ProbeOptions $options): array {
		$nids = $this->directTimelineNids($options);
		if ($nids === []) {
			return [];
		}

		$qb = $this->getQueryBuilder();
		$qb->select('s.id', 's.nid', 's.in_reply_to', 's.attributed_to')
			->from(self::TABLE_STREAM, 's')
			->where($qb->expr()->in('s.nid', $qb->createNamedParameter($nids, IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('s.nid', 'desc')
			->setMaxResults(count($nids));

		$links = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$links[] = [
				'id' => (string)$row['id'],
				'nid' => (string)$row['nid'],
				'inReplyTo' => (string)($row['in_reply_to'] ?? ''),
				'attributedTo' => (string)($row['attributed_to'] ?? ''),
			];
		}
		$cursor->closeCursor();

		return $links;
	}

	/**
	 * The page of direct messages addressed to the viewer.
	 *
	 * The recipient join fixes the viewer and the type `dm`, which the unique
	 * index on the recipient rows makes at most one row per post, so the page
	 * needs no `DISTINCT`.
	 *
	 * @return string[]
	 */
	protected function directTimelineNids(ProbeOptions $options): array {
		$page = $this->getStreamNidsSelectSql(false);
		$page->filterType(SocialAppNotification::TYPE);
		$this->paginateOnRecipient($page, $options);
		$this->filterKind($page, $options);

		// the author is joined for the filters below, not for its columns
		$page->linkToCacheActors('ca', 's.attributed_to_prim', true, false);
		$page->selectDestFollowing('sd', '');
		$page->limitToDest($page->getViewer()->getId(), 'dm', '', 'sd');
		$page->filterHiddenActors();

		return $this->getNidsFromRequest($page);
	}

	/**
	 * Should returns:
	 *  - public message from actorId.
	 *  - followers-only if logged and follower.
	 *
	 * @param ProbeOptions $options
	 *
	 * @return Stream[]
	 */
	private function getTimelineAccount(ProbeOptions $options): array {
		if ($options->getAccountId() === '') {
			return [];
		}

		$nids = $this->accountTimelineNids($options);
		if ($nids === []) {
			return [];
		}

		return $this->streamsByNids($nids, $options);
	}

	/**
	 * The page of one account's posts the viewer may read.
	 *
	 * For the account reading its own profile, everything it wrote. For anybody
	 * else, exactly what `getStreamById()` would hand them post by post: the
	 * public and unlisted ones, the followers-only ones once the follow is
	 * accepted, and what was addressed to them. Forcing the public collection
	 * here instead meant a follower read a followers-only post in their home
	 * timeline while the author's profile denied it existed.
	 *
	 * A post names several recipients — the public collection, its author, the
	 * author's followers collection — and each is a row, so any page with a
	 * reader behind it can match one post more than once and has to be
	 * `DISTINCT`. The anonymous page matches only the public row and does not.
	 *
	 * With `withBoosts` the account's boosts are on the page too, by when they
	 * were made, under the same rule: a boost is addressed like a post. Whether
	 * the reader may see the post a boost repeats is decided where the rows
	 * are read, which joins it only when they may.
	 *
	 * `excludeReplies` and `tagged` narrow the page itself, as Mastodon does:
	 * no replies to other accounts, and only the posts carrying the hashtag.
	 *
	 * @return string[]
	 */
	protected function accountTimelineNids(ProbeOptions $options): array {
		$actorId = $options->getAccountId();
		$accountIsViewer = ($this->viewer !== null && $this->viewer->getId() === $actorId);
		$page = $this->getStreamNidsSelectSql($this->viewer !== null);

		$page->limitToStatusTypes('', $options->isWithBoosts());
		$page->paginate($options);
		$this->filterKind($page, $options);
		$page->limitToAttributedTo($actorId, true);
		if ($options->isExcludeReplies()) {
			$page->filterRepliesToOthers();
		}
		if ($options->getTagged() !== '') {
			$page->linkToStreamTags('st', 's.id_prim');
			$page->andWhere($page->expr()->eq(
				'st.hashtag', $page->createNamedParameter(FollowedTagsRequest::normalise($options->getTagged()))
			));
		}

		if ($accountIsViewer) {
			$page->selectDestFollowing('sd', '');
			$page->innerJoinStreamDest('recipient', 'id_prim', 'sd', 's');
			$page->limitToDest('', 'recipient', '', 'sd');
			$page->filterHiddenActors(SocialCoreQueryBuilder::HIDDEN_DIRECT);
		} else {
			$page->limitToViewer('sd', 'f', true, true, SocialCoreQueryBuilder::HIDDEN_DIRECT);
		}

		$page->linkToCacheActors('ca', 's.attributed_to_prim', true, false);

		return $this->getNidsFromRequest($page);
	}

	/**
	 * @param ProbeOptions $options
	 *
	 * @return Stream[]
	 */
	private function getTimelineFavourites(ProbeOptions $options): array {
		return $this->getTimelineMarked($options, 'liked');
	}

	/**
	 * The viewer's own marks — liked or bookmarked — newest first.
	 *
	 * Two queries, as the home timeline does it, and for the same reason. Asked
	 * as one, the database drives from the action rows, joins the whole post and
	 * its author to each, and only then sorts the result by post id to take a
	 * page of fifteen: EXPLAIN says "Using temporary; Using filesort" over the
	 * joined rows. Somebody with ten thousand likes therefore pays for ten
	 * thousand wide rows to see the newest fifteen. Deciding the page over one
	 * indexed column first leaves the wide read with exactly the rows it returns.
	 *
	 * A mark does not make a post readable: the page is limited to what the
	 * viewer may read now, as `getStreamById()` would answer it, so a
	 * followers-only post marked while following its author is not served
	 * once the follow has ended. That join can match a post once per
	 * recipient row, hence `DISTINCT`.
	 *
	 * Ordered and cut on the mark's own id (`liked_nid`, `bookmarked_nid`:
	 * when it was set), not the post's, as Mastodon orders its favourites: a
	 * post from last month marked today is first, and `max_id`/`min_id` name
	 * a mark. `social_sa_aln`/`social_sa_abn` serve the filter and the order
	 * as one descending range.
	 *
	 * @param string $mark the action column that has to be set
	 *
	 * @return Stream[]
	 */
	private function getTimelineMarked(ProbeOptions $options, string $mark): array {
		if (!in_array($mark, ['liked', 'bookmarked'], true)) {
			throw new InvalidArgumentException('unknown mark: ' . $mark);
		}

		$page = $this->getStreamNidsSelectSql();
		$viewer = $page->createNamedParameter($page->prim($page->getViewer()->getId()));
		$page->limitToStatusTypes();
		// the sort column has to be selected for a DISTINCT page to be
		// ordered on it; one mark row per post, so it does not widen the page
		$page->addSelect('sa.' . $mark . '_nid');
		$page->paginate($options, 'sa', $mark . '_nid');
		$this->filterKind($page, $options);
		$page->innerJoin(
			's', CoreRequestBuilder::TABLE_STREAM_ACTIONS, 'sa',
			$page->expr()->andX(
				$page->expr()->eq('sa.stream_id_prim', 's.id_prim'),
				$page->expr()->eq('sa.actor_id_prim', $viewer),
				$page->expr()->eq('sa.' . $mark, $page->createNamedParameter(1))
			)
		);
		$page->limitToViewer('sd', 'f', true, true, SocialCoreQueryBuilder::HIDDEN_DIRECT);
		// the rows are read with their author, so a post without a cached
		// author is left out here, where it does not take a place on the page
		$page->joinCacheActors('ca', 's.attributed_to_prim');

		$nids = $this->getNidsFromRequest($page);
		if ($nids === []) {
			return [];
		}

		$qb = $this->getStreamSelectSql($options->getFormat());
		$qb->andWhere(
			$qb->expr()->in('s.nid', $qb->createNamedParameter($nids, IQueryBuilder::PARAM_STR_ARRAY))
		);
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$qb->leftJoinStreamAction('sa');

		// in the order the page query chose them, which the hydrating read
		// does not keep
		$order = array_flip($nids);
		$streams = $this->getStreamsFromRequest($qb);
		usort($streams, static fn (Stream $a, Stream $b): int => $order[$a->getNid()] <=> $order[$b->getNid()]);

		return $streams;
	}

	/**
	 * @param ProbeOptions $options
	 *
	 * @return Stream[]
	 */
	private function getTimelineBookmarks(ProbeOptions $options): array {
		return $this->getTimelineMarked($options, 'bookmarked');
	}

	/**
	 * @param ProbeOptions $options
	 *
	 * @return Stream[]
	 */
	private function getTimelineHashtag(ProbeOptions $options): array {
		$nids = $this->hashtagNidsByWindow($options) ?? $this->hashtagTimelineNids($options);
		if ($nids === []) {
			return [];
		}

		return $this->streamsByNids($nids, $options);
	}

	/**
	 * The hashtag page in two steps, as the public page is decided: the tag's
	 * newest posts first, read off the tag's index and the stream's key alone
	 * (`hashtagWindowNids()`), then the visibility filters applied to those
	 * few ids by their key (`hashtagTimelineNids()` with `$among`), with
	 * further windows when the filters empty one.
	 *
	 * Asked as one join, the plan was the optimizer's to pick, and with the
	 * viewer's recipient condition an `OR` no index answers, MySQL 8.4 could
	 * start from `social_stream_dest` — every recipient row of the instance —
	 * rather than from the tag: 770 ms against 20 for the same page on
	 * 100,000 posts. Neither step here leaves it that choice.
	 *
	 * Returns **null** for the join query where the windows ran out before
	 * the page filled while more of the tag's posts remained, as
	 * `narrowedNidsByKind()` does: a tag whose posts the viewer mostly may
	 * not read is one an empty page would wrongly call finished.
	 *
	 * @return string[]|null
	 */
	private function hashtagNidsByWindow(ProbeOptions $options): ?array {
		if ($options->getMinId() > 0) {
			$options->setInverted(true);
		}
		$wanted = min(self::HOME_OVERREAD_MAX, $options->getLimit() * self::HOME_OVERREAD);
		$window = $this->hashtagWindowNids($options, $wanted);
		$exhausted = count($window) < $wanted;
		$filter = fn (array $window, ProbeOptions $next): array => $this->hashtagTimelineNids($next, $window);

		$nids = $this->refilledPage(
			($window === []) ? [] : $filter($window, $options), $window, $options,
			function (ProbeOptions $next) use (&$exhausted, $wanted): array {
				// a window shorter than asked for held the rest of the tag:
				// there is nothing below it to read
				if ($exhausted) {
					return [];
				}
				$window = $this->hashtagWindowNids($next, $wanted);
				$exhausted = count($window) < $wanted;

				return $window;
			},
			$filter
		);

		if (count($nids) < $options->getLimit() && !$exhausted) {
			return null;
		}

		return array_slice($nids, 0, $options->getLimit());
	}

	/**
	 * The newest posts carrying the tag, before any visibility filter: the
	 * tag's rows and the posts they name, nothing else joined, so the only
	 * way in is the tag's index (`social_st_ht`).
	 *
	 * @return string[]
	 */
	protected function hashtagWindowNids(ProbeOptions $options, int $wanted): array {
		$qb = $this->getStreamNidsSelectSql(false, true);
		$this->hideArchived($qb, false, true);
		$qb->limitToStatusTypes();
		$this->filterKind($qb, $options);
		$qb->linkToStreamTags('st', 's.id_prim');
		$qb->andWhere($qb->expr()->eq(
			'st.hashtag', $qb->createNamedParameter(FollowedTagsRequest::normalise($options->getArgument()))
		));
		$this->boundNidPage($qb, 's', $options, $wanted);

		return $this->getNidsFromRequest($qb);
	}

	/**
	 * The page of posts carrying a hashtag that the viewer may read.
	 *
	 * The tag join and the viewer's recipient join can each match a post more
	 * than once, so the page is `DISTINCT` -- over one decimal identifier, which
	 * is what this is for: it used to be over the whole post.
	 *
	 * @param string[]|null $among the window to choose from, by key; null for the whole tag
	 *
	 * @return string[]
	 */
	protected function hashtagTimelineNids(ProbeOptions $options, ?array $among = null): array {
		// the archived filter in the form that admits a row the backfill has
		// not reached, filled or not: with the bare `archived = 0` MySQL walks
		// `social_stream` in nid order looking for the tag, which on a large
		// table and a rare tag is a read of the whole table, where with the
		// `OR` it starts from the tag's index. `QueryPlanTest` pins the plan.
		$page = $this->getStreamNidsSelectSql(true, true);
		$this->hideArchived($page, false, true);
		$page->limitToStatusTypes();
		$page->paginate($options);
		$this->filterKind($page, $options);
		if ($among !== null) {
			$page->andWhere($page->expr()->in('s.nid', $page->createNamedParameter($among, IQueryBuilder::PARAM_STR_ARRAY)));
		}

		$page->linkToCacheActors('ca', 's.attributed_to_prim', true, false);
		$page->linkToStreamTags('st', 's.id_prim');
		// the stored form, compared as it stands, so `social_st_ht` answers it
		$page->andWhere($page->expr()->eq(
			'st.hashtag', $page->createNamedParameter(FollowedTagsRequest::normalise($options->getArgument()))
		));

		// `to`: an unlisted post names the public collection only in `cc`, and
		// its author chose to keep it off the tag timelines (Mastodon's rule)
		$page->limitToViewer('sd', 'f', true, false, SocialCoreQueryBuilder::HIDDEN_TIMELINE, 'to');
		$page->andWhere($page->expr()->eq('s.attributed_to_prim', 'ca.id_prim'));
		// a hashtag timeline is part of the public square a silenced account loses
		$this->filterSilencedActors($page);

		return $this->getNidsFromRequest($page);
	}

	/**
	 * @param ProbeOptions $options
	 *
	 * @return Stream[]
	 */
	private function getTimelineNotifications(ProbeOptions $options): array {
		$wanted = Stream::subTypesOfNotificationTypes($options->getTypes());
		if ($options->getTypes() !== [] && $wanted === []) {
			// every requested type is one we have no notification for, so
			// nothing can match and there is no point in asking the database
			return [];
		}

		// two queries, as the home and public timelines do it and for the same
		// reason. Asked as one, the database carries two full stream column
		// sets, two cached-actor sets and two cached-document sets — several
		// kilobytes a row, `source` and `details` among them — through the
		// `SELECT DISTINCT` the recipient join forces, to choose twenty rows.
		// Every client polls this on a timer, so it is the read where that
		// costs the most.
		// the recipient join fixes the viewer and the type, so it is one row
		$page = $this->getStreamNidsSelectSql(false);
		$actor = $page->getViewer();

		$this->notificationFilters($page, $options, $wanted, $actor->getId(), false);

		$nids = $this->getNidsFromRequest($page);
		if ($nids === []) {
			return [];
		}

		$qb = $this->getStreamSelectSql($options->getFormat());
		$qb->andWhere(
			$qb->expr()->in('s.nid', $qb->createNamedParameter($nids, IQueryBuilder::PARAM_STR_ARRAY))
		);
		$qb->orderBy('s.nid', $options->isInverted() ? 'asc' : 'desc');
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$qb->leftJoinStreamAction();
		$qb->leftJoinObjectStatus();

		return $this->getStreamsFromRequest($qb);
	}

	/**
	 * The page `getTimelineNotifications()` chooses for the same options, as
	 * what the notification policy asks of each row and nothing else: who sent
	 * it, when that account was made, and whether it is a mention in a direct
	 * message. Newest first.
	 *
	 * For the count of held senders the sidebar draws on every push and poll,
	 * which looks back over hundreds of notifications: read whole, each one
	 * is a post, its author and its object, dressed for a client, to draw one
	 * number. The sender is the joined cached actor, as `parseStreamSelectSql()`
	 * reads it, so a row the full read gives no actor has none here either.
	 *
	 * @return list<array{nid: string, subType: string, sender: string, creation: int, privateMention: bool}>
	 */
	public function getNotificationSenders(ProbeOptions $options): array {
		$wanted = Stream::subTypesOfNotificationTypes($options->getTypes());
		if ($options->getTypes() !== [] && $wanted === []) {
			return [];
		}

		$page = $this->getStreamNidsSelectSql(false);
		$this->notificationFilters($page, $options, $wanted, $page->getViewer()->getId(), false);

		$nids = $this->getNidsFromRequest($page);
		if ($nids === []) {
			return [];
		}

		$qb = $this->getQueryBuilder();
		$qb->select('s.nid', 's.subtype')
			->from(self::TABLE_STREAM, 's');
		$qb->setDefaultSelectAlias('s');
		$qb->selectAlias('ca.id', 'ca_id')
			->selectAlias('ca.type', 'ca_type')
			->selectAlias('ca.creation', 'ca_creation');
		$qb->andWhere(
			$qb->expr()->in('s.nid', $qb->createNamedParameter($nids, IQueryBuilder::PARAM_STR_ARRAY))
		);
		$qb->linkToCacheActors('ca', 's.attributed_to_prim', true, false);
		$qb->leftJoinObjectVisibility(Mention::TYPE);
		$qb->orderBy('s.nid', 'desc');

		$rows = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$sender = new Person();
			$sender->setType((string)($data['ca_type'] ?? ''));
			$creation = 0;
			try {
				// as `Person::importFromDatabase()` reads the column
				$creation = (new DateTime((string)($data['ca_creation'] ?? '') ?: 'yesterday', new DateTimeZone('UTC')))
					->getTimestamp();
			} catch (Exception) {
			}

			$rows[] = [
				'nid' => (string)$data['nid'],
				'subType' => (string)($data['subtype'] ?? ''),
				'sender' => AP::instance()->isActor($sender) ? (string)($data['ca_id'] ?? '') : '',
				'creation' => $creation,
				'privateMention' => ($data['os_visibility'] ?? '') === Stream::TYPE_DIRECT,
			];
		}
		$cursor->closeCursor();

		return $rows;
	}

	/**
	 * What makes a row one of this viewer's notifications.
	 *
	 * Shared by the page-selection query and nothing else — but kept apart
	 * from the hydration so that the two cannot drift into disagreeing about
	 * which notifications exist.
	 *
	 * @param string[] $wanted the notification subtypes asked for
	 * @param bool $select whether the joined author's columns are wanted
	 */
	private function notificationFilters(
		SocialQueryBuilder $qb,
		ProbeOptions $options,
		array $wanted,
		string $actorId,
		bool $select = true,
	): void {
		$qb->limitToType(SocialAppNotification::TYPE);
		$qb->limitToSubTypes($wanted);
		$qb->limitToSubTypes(Stream::subTypesOfNotificationTypes($options->getExcludeTypes()), true);
		// the notifications one account caused -- Mastodon's `account_id`,
		// resolved to the actor's id by the route
		if ($options->getAccountId() !== '') {
			$qb->limitToAttributedTo($options->getAccountId(), true);
		}
		$this->paginateOnRecipient($qb, $options);

		$qb->selectDestFollowing('sd', '');
		$qb->limitToDest($actorId, 'notif', '', 'sd');
		$qb->linkToCacheActors('ca', 's.attributed_to_prim', true, $select);

		$qb->filterHiddenActors(SocialCoreQueryBuilder::HIDDEN_NOTIFICATIONS);
		$this->filterMutedConversations($qb, $actorId);
	}

	/**
	 * Drops the notifications a muted thread would produce.
	 *
	 * Mastodon's conversation mute is about being *told*: the thread's posts
	 * stay on every timeline and only the notifications stop, which is what
	 * somebody muting a thread they are in has asked for.
	 *
	 * The mute is stored against the thread's root, so what is excluded here
	 * is every post of those threads — resolved on the way in rather than
	 * joined, because "the thread this post belongs to" is a walk up
	 * `in_reply_to` and there is no portable way to ask a database for it. An
	 * account mutes few threads and a thread is bounded, so this is a small
	 * `NOT IN` and it is skipped entirely by everyone who has muted nothing.
	 */
	private function filterMutedConversations(SocialQueryBuilder $qb, string $actorId): void {
		$roots = $this->conversationsRequest->getMutedRoots($actorId);
		if ($roots === []) {
			return;
		}

		$prims = [];
		foreach ($roots as $root) {
			foreach ($this->conversationsRequest->getThread($root) as $post) {
				$prims[$post['idPrim']] = true;
			}
		}

		if ($prims === []) {
			return;
		}

		$qb->andWhere(
			$qb->expr()->orX(
				$qb->expr()->isNull('s.object_id_prim'),
				$qb->expr()->notIn(
					's.object_id_prim',
					$qb->createNamedParameter(array_keys($prims), IQueryBuilder::PARAM_STR_ARRAY)
				)
			)
		);
	}

	/**
	 * Should return:
	 *  * Public/Unlisted/Followers-only post where current $actor is tagged,
	 *  - Events: (not yet)
	 *    - people liking or re-posting your posts (not yet)
	 *    - someone wants to follow you (not yet)
	 *    - someone is following you (not yet)
	 *
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Stream[]
	 * @throws DateTimeException
	 * @deprecated
	 */
	/**
	 * How many notifications the viewer has that they have not seen yet.
	 *
	 * Counted rather than fetched: the badge needs a number, and a viewer who
	 * has been away for a week should not cost a page of hydrated streams to
	 * find that out. The cap keeps the answer cheap — past it the badge says
	 * "lots", which is all anyone reads from a two-digit number anyway.
	 */
	/**
	 * The newest thing this account has been sent, as a nid, or 0.
	 *
	 * An entity tag for a timeline. A client polls the home page and the unread
	 * count every thirty seconds, and the answer is usually the same one it
	 * already has; the newest id a viewer can see changes exactly when that
	 * stops being true, so it is what an `ETag` should be built from.
	 *
	 * One index-only probe: the recipient rows are ordered by `nid` within a
	 * collection, so the newest is the first row of a descending range. The
	 * whole point is that it must cost far less than the page it stands in for
	 * — a validator that cost the same as the answer would save nothing.
	 *
	 * @param string[] $collections the prims to look in
	 */
	public function newestNidFor(array $collections, string $type = 'recipient'): string {
		if ($collections === []) {
			return '0';
		}

		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$qb->select('nid')
			->from(self::TABLE_STREAM_DEST)
			->where($expr->in(
				'actor_id',
				$qb->createNamedParameter($collections, IQueryBuilder::PARAM_STR_ARRAY)
			))
			->andWhere($expr->eq('type', $qb->createNamedParameter($type)))
			->orderBy('nid', 'desc')
			->setMaxResults(1);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return ($data === false) ? '0' : (string)$data['nid'];
	}

	public function countNotificationsSince(Person $actor, int|string $sinceNid, int $cap = 99): int {
		// the rows that make up the badge, as the notifications page chooses
		// them, projected to one column and cut at the cap
		$page = $this->getStreamNidsSelectSql();
		$page->setViewer($actor);
		$page->limitToType(SocialAppNotification::TYPE);
		$page->selectDestFollowing('sd', '');
		$page->limitToDest($actor->getId(), 'notif', '', 'sd');
		$page->filterHiddenActors(SocialCoreQueryBuilder::HIDDEN_NOTIFICATIONS);
		// a muted thread's notifications are not listed, so they are not unread
		$this->filterMutedConversations($page, $actor->getId());

		if (\OCA\Social\Tools\Nid::compare($sinceNid, '0') > 0) {
			// on the recipient row's nid where it can be trusted, which makes
			// the marker a range over `social_sd_atn`; see paginateOnRecipient()
			$nid = $this->recipientNidsAreFilled() ? 'sd.nid' : 's.nid';
			$page->andWhere($page->expr()->gt($nid, $page->createNamedParameter($sinceNid)));
		}

		$page->setMaxResults($cap + 1);

		// and counted by the database: nothing but the number comes back
		$qb = $this->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'unread'))
			->from($qb->createFunction('(' . $page->getSQL() . ')'), 'unread_page');
		$qb->setParameters($page->getParameters(), $page->getParameterTypes());

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return ($data === false) ? 0 : (int)$data['unread'];
	}

	/**
	 * Should return:
	 *  * All local public/federated posts
	 *
	 * @param ProbeOptions $options
	 *
	 * @return Stream[]
	 */
	/**
	 * A silenced account keeps its followers and loses the public square.
	 *
	 * The list is small — a moderator acts rarely — so it is read and passed
	 * as a literal set rather than joined against.
	 */
	private function filterSilencedActors(SocialQueryBuilder $qb): void {
		$silenced = $this->moderationRequest->getActorIdsAt(Moderation::SILENCE);
		if ($silenced !== []) {
			$prims = array_map(fn (string $id): string => $qb->prim($id), $silenced);
			$qb->andWhere(
				$qb->expr()->notIn(
					's.attributed_to_prim',
					$qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)
				)
			);
		}

		$this->filterSilencedInstances($qb);
	}

	/**
	 * Keeps a silenced instance out of the timeline this query is building.
	 *
	 * The middle tier of a domain block: an account there stays readable by
	 * whoever follows it and stops appearing in the public and global
	 * timelines, which is the whole difference between a nuisance and a menace.
	 *
	 * Matched on `author_host`, the host of the author's id: the instance
	 * itself by `NOT IN`, and everything under it by a suffix — a block reads
	 * subdomains the same way, and anything less would last as long as it
	 * takes to point a wildcard record at the same host. A suffix is not
	 * indexed either, but it is a comparison against a short column rather
	 * than a scan of the id's text, and the list is an admin-written handful.
	 *
	 * A row `Cron\StreamAuthorHosts` has not reached yet has no host, and is
	 * matched by its id instead, as a prefix — `https://evil.test/` and
	 * `%.evil.test/` — until the job says every row has one.
	 */
	private function filterSilencedInstances(SocialQueryBuilder $qb): void {
		$hosts = DomainBlocksRequestBuilder::hostsToMatch(
			array_map(static fn (string $host): string => rtrim(trim($host), '.'), $this->fediverseService->getSilencedAddresses())
		);
		if ($hosts === []) {
			return;
		}

		$expr = $qb->expr();
		$byHost = [$expr->notIn('s.author_host', $qb->createNamedParameter($hosts, IQueryBuilder::PARAM_STR_ARRAY))];
		$byId = [];
		foreach ($hosts as $host) {
			$byHost[] = $expr->notLike('s.author_host', $qb->createNamedParameter('%.' . $this->escapeLike($host)));
			$byId[] = $expr->notLike('s.attributed_to', $qb->createNamedParameter('%://' . $this->escapeLike($host) . '/%'));
			$byId[] = $expr->notLike('s.attributed_to', $qb->createNamedParameter('%.' . $this->escapeLike($host) . '/%'));
		}

		if ($this->authorHostsAreFilled()) {
			$qb->andWhere($expr->andX(...$byHost));

			return;
		}

		$qb->andWhere($expr->orX(
			$expr->andX($expr->isNotNull('s.author_host'), ...$byHost),
			$expr->andX($expr->isNull('s.author_host'), ...$byId)
		));
	}

	/** `%`, `_` and the escape itself are literals in a hostname. */
	private function escapeLike(string $value): string {
		return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
	}

	/**
	 * Should return:
	 *  * All public posts, newest first
	 *
	 * Two phases, as the home timeline: which posts, decided over the
	 * recipient rows alone, then what they say — see
	 * `publicTimelineNidsFromRecipients()` for why. The join query is kept
	 * for what that page cannot answer.
	 *
	 * @return Stream[]
	 */
	private function getTimelinePublic(ProbeOptions $options): array {
		if ($this->narrowedToAKind($options)) {
			$page = ($options->originLimit() === null)
				? $this->narrowedNidsByKind(
					$options, fn (array $window, ProbeOptions $next): array => $this->publicNidsOf($window, $next, true)
				)
				: null;
			if ($page === null) {
				return $this->publicTimelineJoined($options);
			}

			return ($page === []) ? [] : $this->publicStreams($page, $options);
		}

		$nids = $this->publicTimelineNidsFromRecipients($options);
		if ($nids === null) {
			return $this->publicTimelineJoined($options);
		}
		if ($nids === []) {
			return [];
		}

		$page = array_slice(
			$this->refilledPage(
				$this->publicNidsOf($nids, $options), $nids, $options,
				fn (ProbeOptions $next): array => $this->publicTimelineNidsFromRecipients($next) ?? [],
				fn (array $window, ProbeOptions $next): array => $this->publicNidsOf($window, $next)
			),
			0,
			$options->getLimit()
		);

		return ($page === []) ? [] : $this->publicStreams($page, $options);
	}

	/**
	 * A page of a Photos, Videos, Shorts or News timeline, chosen off the
	 * post's own kind index and filtered to the timeline by `$filter`.
	 *
	 * The recipient rows cannot answer a question about the post, and the
	 * join query that could was read in full by MySQL at a hundred thousand
	 * rows: the public Photos page scanned `social_stream` and sorted it, and
	 * the home one drove from every recipient row of everything the viewer
	 * follows into a temporary table. Each kind has an index of
	 * `(kind, nid)`, so the newest posts of one kind are one index range in
	 * nid order (`kindWindowNids()`); which of them belong on this timeline
	 * is then asked of those few dozen rows, and the page reads on, as the
	 * home timeline does, when that empties it.
	 *
	 * Returns **null** for the join query where this cannot answer: the
	 * index cannot be trusted yet, or the windows ran out before the page
	 * filled while there were still posts of the kind further down — a
	 * narrowing whose posts are mostly not on this timeline (photographs on
	 * the home page of somebody who follows two accounts) is one the join
	 * query reads as fast as it reads anything, and one an empty page would
	 * wrongly call finished.
	 *
	 * @param callable(string[], ProbeOptions): string[] $filter the ids of a
	 *                                                           window that belong on the timeline, in order
	 *
	 * @return string[]|null
	 */
	private function narrowedNidsByKind(ProbeOptions $options, callable $filter): ?array {
		$window = $this->kindWindowNids($options);
		if ($window === null) {
			return null;
		}

		$wanted = min(self::HOME_OVERREAD_MAX, $options->getLimit() * self::HOME_OVERREAD);
		$exhausted = count($window) < $wanted;
		$nids = $this->refilledPage(
			($window === []) ? [] : $filter($window, $options), $window, $options,
			function (ProbeOptions $next) use (&$exhausted, $wanted): array {
				$window = $this->kindWindowNids($next) ?? [];
				$exhausted = count($window) < $wanted;

				return $window;
			},
			$filter
		);

		if (count($nids) < $options->getLimit() && !$exhausted) {
			return null;
		}

		return array_slice($nids, 0, $options->getLimit());
	}

	/**
	 * The newest posts of the kind a narrowing asks for, before any other
	 * filter: one `(kind, nid)` index range per kind, merged — `only_media`
	 * is image, video, audio, gifv and mixed.
	 *
	 * Returns **null** where the index cannot be trusted yet — a media
	 * narrowing before every row carries its `media_kind` — and for a
	 * narrowing to both media and news, which no one index holds.
	 *
	 * @return string[]|null
	 */
	private function kindWindowNids(ProbeOptions $options): ?array {
		$media = $options->isOnlyMedia() || $options->isOnlyVideo() || $options->isOnlyShort() || $options->getMediaType() !== '';
		if ($media && $options->isOnlyNews()) {
			return null;
		}

		if ($options->isOnlyShort()) {
			$kinds = ['short_kind' => [Stream::SHORT_KIND_SHORT]];
		} elseif ($options->getMediaType() === 'visual') {
			$kinds = ['media_kind' => ['image', 'video', 'gifv', Stream::MEDIA_KIND_MIXED]];
		} elseif ($options->isOnlyVideo() || $options->getMediaType() === 'video') {
			$kinds = ['media_kind' => ['video', Stream::MEDIA_KIND_MIXED]];
		} elseif ($options->getMediaType() !== '') {
			$kinds = ['media_kind' => [$options->getMediaType(), Stream::MEDIA_KIND_MIXED]];
		} elseif ($options->isOnlyMedia()) {
			$kinds = ['media_kind' => [...Stream::MEDIA_KINDS, Stream::MEDIA_KIND_MIXED]];
		} else {
			$kinds = ['news_kind' => [Stream::NEWS_KIND_LINK, Stream::NEWS_KIND_ARTICLE]];
		}
		if (isset($kinds['media_kind']) && !$this->mediaKindIsFilled()) {
			return null;
		}

		if ($options->getMinId() > 0) {
			$options->setInverted(true);
		}
		$wanted = min(self::HOME_OVERREAD_MAX, $options->getLimit() * self::HOME_OVERREAD);

		$nids = [];
		foreach ($kinds as $column => $values) {
			foreach ($values as $value) {
				$qb = $this->getQueryBuilder();
				$qb->select('s.nid')
					->from(self::TABLE_STREAM, 's')
					->where($qb->expr()->eq('s.' . $column, $qb->createNamedParameter($value)));
				$this->boundNidPage($qb, 's', $options, $wanted);
				$nids = $this->mergeNidPages($nids, $this->getNidsFromRequest($qb), $options, $wanted);
			}
		}

		return $nids;
	}

	/**
	 * The ids of `$window` that belong on the public timeline, in its order:
	 * the filters the page query left out, applied as one projection of one
	 * column over a few dozen rows read by their key.
	 *
	 * Apart from the read of the rows because the read is wide — eighty
	 * columns, the author's beside them — and the window is three times the
	 * page: filtering first reads that width for the twenty rows that are
	 * shown rather than the sixty that were considered.
	 *
	 * @param string[] $window
	 * @param bool $byKind whether the window came off a kind index, so that
	 *                     whether each post is public is still to be asked
	 *
	 * @return string[]
	 */
	private function publicNidsOf(array $window, ProbeOptions $options, bool $byKind = false): array {
		$page = $this->getStreamNidsSelectSql(false);
		$page->andWhere($page->expr()->in('s.nid', $page->createNamedParameter($window, IQueryBuilder::PARAM_STR_ARRAY)));
		$page->limitToStatusTypes();
		if ($byKind) {
			// the recipient row, by its unique key: addressed *to* the public
			// collection, as the page off the recipient rows is
			$this->filterKind($page, $options);
			$page->selectDestFollowing('sd', '');
			$page->innerJoinStreamDest('recipient', 'id_prim', 'sd', 's');
			$page->limitToDest(ACore::CONTEXT_PUBLIC, 'recipient', 'to', 'sd');
		}
		// a post without a cached author is left out here, where it does not
		// take a place on the page
		$page->joinCacheActors('ca', 's.attributed_to_prim');
		$page->filterHiddenActors();
		$this->filterSilencedActors($page);
		$page->orderBy('s.nid', $options->isInverted() ? 'asc' : 'desc');

		return $this->getNidsFromRequest($page);
	}

	/**
	 * The rows of a public page that has been decided, in its order, with
	 * their author: a status carries no boosted object, so the object join
	 * `streamsByNids()` makes for the home page is not made.
	 *
	 * @param string[] $nids
	 *
	 * @return Stream[]
	 */
	private function publicStreams(array $nids, ProbeOptions $options): array {
		$qb = $this->getStreamSelectSql($options->getFormat());
		$qb->andWhere(
			$qb->expr()->in('s.nid', $qb->createNamedParameter($nids, IQueryBuilder::PARAM_STR_ARRAY))
		);
		$qb->orderBy('s.nid', $options->isInverted() ? 'asc' : 'desc');
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$qb->leftJoinStreamAction();

		return $this->getStreamsFromRequest($qb);
	}

	/**
	 * The ids of a page of the public timeline, off the recipient rows alone.
	 *
	 * The joined query has no selective predicate: on a public instance the
	 * public collection's recipient rows are nearly every post, and the
	 * type, archived and silenced filters each keep nearly every row. What
	 * made it cheap was one plan — walk `social_sd_atn` newest first, look
	 * each post up by its key, stop at the limit — and nothing in the query
	 * obliged the planner to choose it: the other plan reads `social_stream`
	 * in full, probes the recipient rows and sorts, and which one the
	 * optimizer picks follows its row estimates for the table in front of it.
	 * On a copy of an instance it picked the second, at a hundred times the
	 * cost, for the same SQL.
	 *
	 * So the page is read from one table with one possible plan: the public
	 * collection, type `recipient`, subtype `to`, newest nid first, which
	 * `social_sd_atn` answers as one index range in that order, with no join
	 * to reorder and no `OR` to estimate; the subtype is sifted per entry
	 * (an index carrying it as well was tried and made MySQL read the home
	 * page as a skip scan). The filters the join carried are applied to the
	 * rows it chose, in `publicNidsOf()`, where each is a lookup against a
	 * few dozen ids; the page is read wider than asked and read on when
	 * filtering empties it, as the home timeline is.
	 *
	 * Returns **null** where the page cannot be decided here and the join
	 * query answers: an instance whose backfill has not finished, and
	 * `local=` / `remote=`, a question about the post's origin the recipient
	 * rows cannot answer. A `local` flag on the recipient row would answer
	 * it, at the price of a column on the largest table this app has and a
	 * backfill over every row of it, for the two narrowings of the public
	 * timeline a client asks for least. A media or news narrowing is a
	 * question about the post too, and is answered off the post's own index
	 * instead: see `narrowedNidsByKind()`.
	 *
	 * @return string[]|null
	 */
	private function publicTimelineNidsFromRecipients(ProbeOptions $options): ?array {
		if (!$this->recipientNidsAreFilled() || $options->originLimit() !== null) {
			return null;
		}

		if ($options->getMinId() > 0) {
			$options->setInverted(true);
		}
		$wanted = min(self::HOME_OVERREAD_MAX, $options->getLimit() * self::HOME_OVERREAD);

		$qb = $this->getQueryBuilder();
		$this->selectHomeRecipients(
			$qb, $qb->expr()->eq('sd.actor_id', $qb->createNamedParameter(md5(ACore::CONTEXT_PUBLIC))), $options, $wanted
		);
		// addressed *to* the public collection: an unlisted post names it in
		// `cc` and is kept off this timeline, as its author asked
		$qb->andWhere($qb->expr()->eq('sd.subtype', $qb->createNamedParameter('to')));

		return $this->getNidsFromRequest($qb);
	}

	/**
	 * The public timeline as one joined page query: what answers a narrowed
	 * request, and an instance whose backfill has not finished.
	 *
	 * @return Stream[]
	 */
	private function publicTimelineJoined(ProbeOptions $options): array {
		// the recipient join fixes the actor (the public collection) and the
		// type, which the unique index makes at most one row
		$page = $this->getStreamNidsSelectSql(false);
		$this->paginateOnRecipient($page, $options);
		$this->filterKind($page, $options);

		// `local=true` is this instance's own posts, `remote=true` every other
		// instance's: Mastodon's two ways of narrowing the same timeline
		$origin = $options->originLimit();
		if ($origin !== null) {
			$page->limitToLocal($origin);
		}
		$page->limitToStatusTypes();
		$page->selectDestFollowing('sd', '');
		$page->innerJoinStreamDest('recipient', 'id_prim', 'sd', 's');
		$page->limitToDest(ACore::CONTEXT_PUBLIC, 'recipient', 'to', 'sd');
		// the rows are read with their author, so a post without a cached
		// author is left out here, where it does not take a place on the page
		$page->joinCacheActors('ca', 's.attributed_to_prim');
		$page->filterHiddenActors();
		$this->filterSilencedActors($page);

		$nids = $this->getNidsFromRequest($page);

		return ($nids === []) ? [] : $this->publicStreams($nids, $options);
	}
}
