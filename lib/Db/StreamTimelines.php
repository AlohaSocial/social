<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use InvalidArgumentException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Document;
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

			$window = $this->homeTimelineNidsFromRecipients($next) ?? [];
			if ($window === []) {
				break;
			}

			if ($this->followsAnyTag()) {
				$window = $this->mergeNidPages($window, $this->followedTagNids($next), $next);
			}

			$posts = array_merge($posts, $this->streamsByNids($window, $next, true));
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
	 * Applies `only_media` and `only_video` when the caller asked for them.
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
	 */
	private function filterMedia(SocialQueryBuilder $qb, ProbeOptions $options): void {
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

		return $this->homeTimelineNidsByJoin($options);
	}

	/**
	 * The old page query: correct everywhere, slow at scale.
	 *
	 * Kept as the fallback for an instance whose backfill has not finished and
	 * for a viewer the fast path cannot serve. See
	 * `homeTimelineNidsFromRecipients()` for what is wrong with it.
	 *
	 * @return string[]
	 */
	private function homeTimelineNidsByJoin(ProbeOptions $options): array {
		$page = $this->getStreamNidsSelectSql();
		$this->homeTimelineFilters($page, $options, false);

		return $this->getNidsFromRequest($page);
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
		if ($options->isOnlyMedia() || $options->isOnlyVideo()
			|| $options->getMediaType() !== '' || $options->isOnlyNews()) {
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

		if ($options->getSince() > 0) {
			$qb->andWhere($expr->gt('sd.nid', $qb->createNamedParameter($options->getSince())));
		}
		if ($options->getMaxId() > 0) {
			$qb->andWhere($expr->lt('sd.nid', $qb->createNamedParameter($options->getMaxId())));
		}
		if ($options->getMinId() > 0) {
			$qb->andWhere($expr->gt('sd.nid', $qb->createNamedParameter($options->getMinId())));
		}

		$qb->orderBy('sd.nid', $options->isInverted() ? 'asc' : 'desc');
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
	 * @return string[]
	 */
	protected function accountTimelineNids(ProbeOptions $options): array {
		$actorId = $options->getAccountId();
		$accountIsViewer = ($this->viewer !== null && $this->viewer->getId() === $actorId);
		$page = $this->getStreamNidsSelectSql($this->viewer !== null);

		$page->limitToStatusTypes();
		$page->paginate($options);
		$this->filterKind($page, $options);
		$page->limitToAttributedTo($actorId, true);

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
	 * @param string $mark the action column that has to be set
	 *
	 * @return Stream[]
	 */
	private function getTimelineMarked(ProbeOptions $options, string $mark): array {
		if (!in_array($mark, ['liked', 'bookmarked'], true)) {
			throw new InvalidArgumentException('unknown mark: ' . $mark);
		}

		// the action join is `(stream_id_prim, actor_id_prim)`, which is unique
		$page = $this->getStreamNidsSelectSql(false);
		$viewer = $page->createNamedParameter($page->prim($page->getViewer()->getId()));
		$page->limitToStatusTypes();
		$page->paginate($options);
		$this->filterKind($page, $options);
		$page->innerJoin(
			's', CoreRequestBuilder::TABLE_STREAM_ACTIONS, 'sa',
			$page->expr()->andX(
				$page->expr()->eq('sa.stream_id_prim', 's.id_prim'),
				$page->expr()->eq('sa.actor_id_prim', $viewer),
				$page->expr()->eq('sa.' . $mark, $page->createNamedParameter(1))
			)
		);
		$page->filterHiddenActors(SocialCoreQueryBuilder::HIDDEN_DIRECT);

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
		$qb->leftJoinStreamAction('sa');

		return $this->getStreamsFromRequest($qb);
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
		$nids = $this->hashtagTimelineNids($options);
		if ($nids === []) {
			return [];
		}

		return $this->streamsByNids($nids, $options);
	}

	/**
	 * The page of posts carrying a hashtag that the viewer may read.
	 *
	 * The tag join and the viewer's recipient join can each match a post more
	 * than once, so the page is `DISTINCT` -- over one decimal identifier, which
	 * is what this is for: it used to be over the whole post.
	 *
	 * @return string[]
	 */
	protected function hashtagTimelineNids(ProbeOptions $options): array {
		$page = $this->getStreamNidsSelectSql(true);
		$page->limitToStatusTypes();
		$page->paginate($options);
		$this->filterKind($page, $options);

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
	 * Matched on the author's id rather than on a host column, because there is
	 * none: an actor id begins with the scheme and host, so a domain and
	 * everything under it is a prefix — `https://evil.test/` and
	 * `%.evil.test/`. A block reads subdomains the same way, and anything less
	 * would last as long as it takes to point a wildcard record at the same
	 * host. The list is an admin-written handful, so one clause each is cheap;
	 * `LIKE` on the id is not indexed, which is why this runs only on the two
	 * timelines that need it.
	 */
	private function filterSilencedInstances(SocialQueryBuilder $qb): void {
		$silenced = $this->fediverseService->getSilencedAddresses();
		if ($silenced === []) {
			return;
		}

		foreach ($silenced as $host) {
			$host = strtolower(trim($host));
			if ($host === '') {
				continue;
			}

			$qb->andWhere($qb->expr()->andX(
				$qb->expr()->notLike(
					's.attributed_to',
					$qb->createNamedParameter('%://' . $this->escapeLike($host) . '/%')
				),
				$qb->expr()->notLike(
					's.attributed_to',
					$qb->createNamedParameter('%.' . $this->escapeLike($host) . '/%')
				)
			));
		}
	}

	/** `%`, `_` and the escape itself are literals in a hostname. */
	private function escapeLike(string $value): string {
		return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
	}

	private function getTimelinePublic(ProbeOptions $options): array {
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
		$page->filterHiddenActors();
		$this->filterSilencedActors($page);

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

		return $this->getStreamsFromRequest($qb);
	}
}
