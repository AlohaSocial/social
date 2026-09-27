<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\AppInfo\Application;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\Details;

/**
 * What an account has done here, and what came back.
 *
 * Every number is counted from this instance's own rows at the moment it is
 * asked for; nothing is stored, and nothing is precomputed by a cron. That
 * keeps the page honest — it cannot show a total that a deletion has already
 * made false — and it is what bounds the work: the walk stops at
 * `MAX_POSTS`, and the answer says how many posts it actually looked at so the
 * page can say so too.
 *
 * The engagement figures are per *post*, taken from the `details` each post
 * carries. That column is a JSON blob, which is why this is a walk in PHP
 * rather than a `SUM()`: the three databases this app supports do not agree on
 * how to reach inside one, and a portable aggregate would mean a column that
 * has to be kept in step with the blob.
 *
 * A remote like is included, because `details.likes` is the local count plus
 * whatever the origin reported. What no instance can know is the part of the
 * network that never told anybody, so these are a floor rather than a total —
 * which is true of every Fediverse statistic and is worth the page saying.
 */
class StatisticsService {
	/**
	 * How far back the walk goes.
	 *
	 * An account with more posts than this gets the most recent ones, and the
	 * page says which. The alternative is a page whose cost grows without
	 * limit for the accounts that use the app most.
	 */
	public const MAX_POSTS = 2000;

	/** How many posts one query takes. */
	private const PAGE = 100;

	/** How many of the best posts are named. */
	private const TOP_POSTS = 3;

	/** How many hashtags are named. */
	private const TOP_HASHTAGS = 6;

	/** How many months of history the histograms cover. */
	private const MONTHS = 12;

	/**
	 * How many followers are read for the audience figures.
	 *
	 * Newest first, so an account past this ceiling gets its most recent
	 * followers rather than a random slice, and the page says so.
	 */
	private const MAX_FOLLOWERS = 5000;

	/**
	 * How long the two windows the page compares are.
	 *
	 * Thirty days beside the thirty before them: long enough that a quiet week
	 * does not decide the answer, short enough that both halves are the same
	 * account doing the same thing.
	 */
	public const PERIOD_DAYS = 30;

	/** How many of the window's posts are listed one by one. */
	public const TIMELINE_POSTS = 100;

	/** How many Announce rows the reach estimate reads at most. */
	private const MAX_BOOSTERS = 5000;

	/** A day, in seconds. */
	private const DAY = 86400;

	/**
	 * How many times a hashtag has to have been used before its average is
	 * reported. One post that did well is a post that did well, not a tag that
	 * works.
	 */
	private const HASHTAG_MIN_USES = 2;

	/**
	 * How long a computed page is kept.
	 *
	 * The walk below reads up to two thousand posts and then looks up the
	 * audience of everyone who boosted any of them, which is why the route
	 * carries a ceiling of thirty an hour: the page was expensive enough that
	 * reloading it twice cost a fifteenth of somebody's allowance. Fifteen
	 * minutes is long enough that reading the page, scrolling it and coming
	 * back is free, and short enough that a post from this morning shows up
	 * on it.
	 *
	 * Kept in `DurableCache`, so that it is kept at all on an instance with no
	 * memcache: in the distributed cache alone the page was counted again on
	 * every load there, `fresh` or not.
	 */
	private const CACHE_SECONDS = 900;

	/** The `DurableCache` namespace the computed pages live in. */
	public const CACHE_NAMESPACE = Application::APP_ID . '/statistics';

	public function __construct(
		private StreamRequest $streamRequest,
		private FollowsRequest $followsRequest,
		private CacheActorsRequest $cacheActorsRequest,
		private DurableCache $cache,
	) {
	}

	/**
	 * The page, from the cache where it is there and freshly counted where it
	 * is not.
	 *
	 * @param Person $actor whose page
	 * @param int $days the window, one of WINDOWS
	 * @param bool $fresh whether to count again rather than read the cache
	 *
	 * @return array<string, mixed>
	 */
	public function cachedForAccount(Person $actor, int $days = 0, bool $fresh = false): array {
		$key = md5($actor->getId()) . '.' . $days;

		if (!$fresh) {
			$page = $this->cache->get(self::CACHE_NAMESPACE, $key);
			if (is_array($page)) {
				$page['window']['cached'] = true;

				return $page;
			}
		}

		$page = $this->forAccount($actor, $days);
		$page['window']['cached'] = false;
		$this->cache->set(self::CACHE_NAMESPACE, $key, $page, self::CACHE_SECONDS);

		return $page;
	}

	/**
	 * @return array<string, mixed> the whole page, in one answer
	 */
	/**
	 * The windows a reader may ask for, in days. 0 is everything there is.
	 *
	 * A number rather than a free parameter: each one is a walk of up to
	 * `MAX_POSTS` posts and a cache entry of its own, and four choices is
	 * enough to answer "is this month unusual" without turning the page into
	 * a query builder.
	 */
	public const WINDOWS = [0, 30, 90, 365];

	/**
	 * @param Person $actor whose page
	 * @param int $days how far back to count, or 0 for as far as the cap allows
	 *
	 * @return array<string, mixed> the whole page, in one answer
	 */
	public function forAccount(Person $actor, int $days = 0): array {
		$days = in_array($days, self::WINDOWS, true) ? $days : 0;
		$since = ($days > 0) ? time() - ($days * self::DAY) : 0;
		$posts = [
			'total' => 0,
			'originals' => 0,
			'replies' => 0,
			'boosts' => 0,
			'with_media' => 0,
			'sensitive' => 0,
		];
		$engagement = ['likes' => 0, 'boosts' => 0, 'replies' => 0];
		$visibility = [
			Stream::TYPE_PUBLIC => 0,
			Stream::TYPE_UNLISTED => 0,
			Stream::TYPE_FOLLOWERS => 0,
			Stream::TYPE_DIRECT => 0,
		];
		$byMonth = $this->emptyMonths();
		$engagementByMonth = $this->emptyMonths();
		$byHour = array_fill(0, 24, 0);
		$engagementByHour = array_fill(0, 24, 0);
		$byWeekday = array_fill(0, 7, ['posts' => 0, 'engagement' => 0]);
		$hashtags = [];
		$hashtagEngagement = [];
		$best = [];
		/** the posts inside the two windows, kept whole so the comparison and
		 * the per-post list can both be built without a second walk */
		$recent = [];
		$bounds = $this->bounds(time());
		$scores = [];
		$silent = 0;
		/** what each kind of post collected, to say which kind works */
		$kinds = [];
		$first = 0;
		$last = 0;
		/** pictures, and how many of them describe themselves */
		$media = ['images' => 0, 'described' => 0];
		/** what the account writes in */
		$languages = [];
		/** where it links to */
		$domains = [];
		/** what the account *did*, month by month, rather than what came back */
		$activity = [
			'originals' => $this->emptyMonths(),
			'replies' => $this->emptyMonths(),
			'boosts' => $this->emptyMonths(),
		];
		/** the days it posted on at all, for the streak and the longest gap */
		$activeDays = [];
		/** the instances each post was actually delivered to */
		$deliveredTo = [];

		foreach ($this->posts($actor, $since) as $post) {
			$posts['total']++;

			$published = $post->getPublishedTime();
			$month = '';
			if ($published > 0) {
				$first = ($first === 0) ? $published : min($first, $published);
				$last = max($last, $published);
				$month = gmdate('Y-m', $published);
				if (array_key_exists($month, $byMonth)) {
					$byMonth[$month]++;
				}
				$byHour[(int)gmdate('G', $published)]++;
			}

			if ($post->getType() === 'Announce' || $post->getSubType() === 'Announce') {
				// a boost is something the account did, not something it wrote,
				// so it is counted and then left out of everything below: its
				// likes belong to whoever wrote it. It is still *activity*,
				// which is the one place a boost belongs on this page.
				$posts['boosts']++;
				if ($month !== '' && array_key_exists($month, $activity['boosts'])) {
					$activity['boosts'][$month]++;
				}
				if ($published > 0) {
					$activeDays[gmdate('Y-m-d', $published)] = true;
				}

				continue;
			}

			$isReply = ($post->getInReplyTo() !== '');
			if ($isReply) {
				$posts['replies']++;
			} else {
				$posts['originals']++;
			}

			if ($month !== '') {
				$bucket = $isReply ? 'replies' : 'originals';
				if (array_key_exists($month, $activity[$bucket])) {
					$activity[$bucket][$month]++;
				}
			}
			if ($published > 0) {
				$activeDays[gmdate('Y-m-d', $published)] = true;
			}

			$attachments = $post->getAttachments();
			if ($attachments !== []) {
				$posts['with_media']++;
			}

			// A picture nobody can see is a picture with no description. The
			// only number on this page somebody can act on the same afternoon,
			// so it is counted per *picture* rather than per post: one post
			// with four pictures and one alt text is not three-quarters done.
			foreach ($attachments as $attachment) {
				if (!in_array($attachment->getType(), ['image', 'gifv'], true)) {
					continue;
				}

				$media['images']++;
				if (trim($attachment->getDescription()) !== '') {
					$media['described']++;
				}
			}

			$language = strtolower(trim($post->getLanguage()));
			if ($language !== '') {
				$languages[$language] = ($languages[$language] ?? 0) + 1;
			}

			$card = $post->getCard();
			$host = ($card === null) ? '' : strtolower((string)parse_url($card->getUrl(), PHP_URL_HOST));
			if ($host !== '') {
				$domains[$host] = ($domains[$host] ?? 0) + 1;
			}

			// where this post was actually sent, as opposed to the estimate
			// below it: the instance paths are written when the post is
			// created and read back with it
			foreach ($post->getInstancePaths() as $path) {
				$to = strtolower((string)parse_url($path->getUri(), PHP_URL_HOST));
				if ($to !== '') {
					$deliveredTo[$to] = true;
				}
			}
			if ($post->isSensitive()) {
				$posts['sensitive']++;
			}

			$scope = $post->getVisibility();
			if (array_key_exists($scope, $visibility)) {
				$visibility[$scope]++;
			}

			$likes = $post->getDetailInt(Details::LIKES);
			$boosts = $post->getDetailInt(Details::BOOSTS);
			$replies = $post->getDetailInt(Details::REPLIES);
			$engagement['likes'] += $likes;
			$engagement['boosts'] += $boosts;
			$engagement['replies'] += $replies;

			// one post's engagement: what an agency means by the word, and
			// what every rate below is built out of
			$score = $likes + $boosts + $replies;
			$scores[] = $score;
			if ($score === 0) {
				$silent++;
			}

			$tags = [];
			foreach ($post->getHashtags() as $hashtag) {
				$hashtag = strtolower((string)$hashtag);
				if ($hashtag === '') {
					continue;
				}
				$tags[$hashtag] = true;
				$hashtags[$hashtag] = ($hashtags[$hashtag] ?? 0) + 1;
				$hashtagEngagement[$hashtag] = ($hashtagEngagement[$hashtag] ?? 0) + $score;
			}

			if ($month !== '' && array_key_exists($month, $engagementByMonth)) {
				$engagementByMonth[$month] += $score;
			}
			if ($published > 0) {
				$engagementByHour[(int)gmdate('G', $published)] += $score;
				$weekday = (int)gmdate('w', $published);
				$byWeekday[$weekday]['posts']++;
				$byWeekday[$weekday]['engagement'] += $score;
			}

			$this->addKind($kinds, ($post->getAttachments() === []) ? 'text_only' : 'with_media', $score);
			$this->addKind($kinds, ($tags === []) ? 'no_hashtag' : 'with_hashtag', $score);
			$this->addKind($kinds, ($post->getInReplyTo() === '') ? 'original' : 'reply', $score);
			$this->addKind($kinds, 'visibility_' . $scope, $score);

			$row = [
				'id' => (string)$post->getNid(),
				'url' => $post->getId(),
				'published_at' => $this->asDate($published),
				'excerpt' => $this->excerpt($post),
				'likes' => $likes,
				'boosts' => $boosts,
				'replies' => $replies,
				'score' => $score,
			];
			$best[] = $row;

			if ($published >= $bounds['previous']) {
				$recent[] = $row + [
					'published' => $published,
					'media' => $post->getAttachments() !== [],
					'visibility' => $scope,
					// filled in below, once every booster's audience has been
					// looked up in a single query rather than one per post
					'reach' => 0,
				];
			}
		}

		arsort($hashtags);
		usort($best, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

		$written = $posts['originals'] + $posts['replies'];
		$followers = $this->followsRequest->countFollowers($actor->getId());
		$audience = $this->audience($actor, $followers);
		$total = $engagement['likes'] + $engagement['boosts'] + $engagement['replies'];
		$reach = $this->withReach($recent, $followers);

		return [
			'account' => [
				'acct' => $actor->getPreferredUsername(),
				// the name the account publishes under, which is not always
				// the Nextcloud one the rest of the interface shows
				'display_name' => $actor->getName(),
				'created_at' => $this->asDate($actor->getCreation()),
				'followers' => $followers,
				'following' => $this->followsRequest->countFollowing($actor->getId()),
			],
			'posts' => $posts,
			'engagement' => array_merge($engagement, [
				// per post the account wrote, which is what the reader is
				// asking about — dividing by the boosts as well would make an
				// account that boosts a lot look like one nobody answers
				'likes_per_post' => $this->ratio($engagement['likes'], $written),
				'boosts_per_post' => $this->ratio($engagement['boosts'], $written),
				'replies_per_post' => $this->ratio($engagement['replies'], $written),
			]),
			'visibility' => $visibility,
			'rates' => [
				// the four an agency reports on, under the names they report
				// them under. Each is per post the account *wrote*: a boost is
				// not a post of yours and its numbers are not yours either
				'total' => $total,
				'per_post' => $this->ratio($total, $written),
				'applause' => $this->ratio($engagement['likes'], $written),
				'amplification' => $this->ratio($engagement['boosts'], $written),
				'conversation' => $this->ratio($engagement['replies'], $written),
				// the industry's engagement rate: engagement per post against
				// the audience it was published to, as a percentage. This app
				// has no impressions to divide by, so followers are the
				// denominator and the page says which
				'per_follower' => ($followers < 1) ? 0.0
					: round((float)$total / (float)max(1, $written) / (float)$followers * 100.0, 2),
				// a mean is one viral post away from meaningless, which is why
				// the median sits next to it rather than instead of it
				'median' => $this->median($scores),
				'best' => ($scores === []) ? 0 : max($scores),
				'silent' => $silent,
				'silent_share' => ($written < 1) ? 0.0 : round((float)$silent / (float)$written * 100.0, 1),
			],
			'by_month' => $byMonth,
			'engagement_by_month' => $engagementByMonth,
			'by_hour' => $byHour,
			'by_weekday' => $this->weekdays($byWeekday),
			'best_hour' => $this->bestHour($engagementByHour, $byHour),
			'content' => $this->content($kinds),
			'hashtags' => $this->named($hashtags, self::TOP_HASHTAGS),
			'hashtag_performance' => $this->hashtagPerformance($hashtags, $hashtagEngagement),
			'audience' => $audience,
			'best' => array_slice($best, 0, self::TOP_POSTS),
			'periods' => $this->periods($reach['posts'], $bounds),
			'timeline' => $this->timeline($reach['posts'], $bounds['current'], $bounds['until']),
			'reach' => [
				'followers' => $followers,
				'known_boosters' => $reach['known'],
				'unknown_boosters' => $reach['unknown'],
				'listed' => self::TIMELINE_POSTS,
				// measured rather than modelled: the servers this account's
				// posts were actually addressed to. Beside the estimate rather
				// than instead of it, because a post written before the
				// instance paths were stored has none and would read as zero
				'instances' => count($deliveredTo),
			],
			// what the account did, rather than what came back
			'activity' => $activity,
			'consistency' => $this->consistency($activeDays, $first, $last),
			'media' => $media + [
				'described_share' => ($media['images'] < 1) ? 0.0
					: round((float)$media['described'] / (float)$media['images'] * 100.0, 1),
			],
			// who the account actually talks with, from the replies rather
			// than from the follow graph
			'partners' => $this->partners($actor, $since),
			'languages' => $this->named($languages, self::TOP_HASHTAGS),
			'domains' => $this->named($domains, self::TOP_HASHTAGS),
			'window' => [
				'days' => $days,
				'choices' => self::WINDOWS,
				'counted' => $posts['total'],
				'followers_counted' => $audience['counted'],
				'capped' => $posts['total'] >= self::MAX_POSTS,
				'max' => self::MAX_POSTS,
				'first_at' => $this->asDate($first),
				'last_at' => $this->asDate($last),
			],
		];
	}

	/**
	 * How many conversation partners a page names in each direction.
	 *
	 * Long enough to see a pattern, short enough that the two follow lookups
	 * behind it stay one query each.
	 */
	private const TOP_PARTNERS = 10;

	/**
	 * Who the account talks with, and whether it follows them.
	 *
	 * The follow graph says who an account *asked* to hear from; the replies
	 * say who it actually talks to, and the two are rarely the same list. The
	 * `not_followed` share is the interesting half: a high one means the
	 * conversations are coming from outside the timeline the account built for
	 * itself.
	 *
	 * @return array<string, mixed>
	 */
	private function partners(Person $actor, int $since): array {
		$directions = [
			'inbound' => StreamRequest::PARTNERS_INBOUND,
			'outbound' => StreamRequest::PARTNERS_OUTBOUND,
		];

		$partners = [];
		foreach ($directions as $name => $direction) {
			$partners[$name] = $this->streamRequest->countConversationPartners(
				$actor->getId(), $direction, $since, self::TOP_PARTNERS
			);
		}

		$ids = array_values(array_unique(array_merge(
			array_column($partners['inbound'], 'id'),
			array_column($partners['outbound'], 'id')
		)));
		$following = ($ids === []) ? []
			: $this->followsRequest->getBetweenMany($actor->getId(), $ids)['following'];

		$counted = 0;
		$strangers = 0;
		foreach ($partners as $name => $list) {
			foreach ($list as $i => $partner) {
				$followed = array_key_exists($partner['id'], $following);
				$partners[$name][$i]['followed'] = $followed;
				if ($name === 'inbound') {
					$counted++;
					$strangers += $followed ? 0 : 1;
				}
			}
		}

		return $partners + [
			// of the people who replied, how many the account does not follow
			'not_followed_share' => ($counted < 1) ? 0.0
				: round((float)$strangers / (float)$counted * 100.0, 1),
			'listed' => self::TOP_PARTNERS,
		];
	}

	/**
	 * The account's own posts, newest first, up to the cap.
	 *
	 * Keyset by nid rather than an offset, which is how every other paged read
	 * in this app walks a timeline: an offset would skip a post whenever one
	 * was deleted while the walk was running.
	 *
	 * @return iterable<Stream>
	 */
	private function posts(Person $actor, int $since = 0): iterable {
		$this->streamRequest->setViewer($actor);
		$maxId = 0;
		$seen = 0;

		while ($seen < self::MAX_POSTS) {
			$options = new ProbeOptions();
			$options->setFormat(ACore::FORMAT_ACTIVITYPUB)
				->setProbe(ProbeOptions::ACCOUNT)
				->setAccountId($actor->getId())
				->setLimit(min(self::PAGE, self::MAX_POSTS - $seen));
			if ($maxId > 0) {
				$options->setMaxId($maxId);
			}

			$page = $this->streamRequest->getTimeline($options);
			if ($page === []) {
				return;
			}

			foreach ($page as $post) {
				// the timeline is newest first, so the first post older than
				// the window ends the walk rather than being skipped past
				$published = $post->getPublishedTime();
				if ($since > 0 && $published > 0 && $published < $since) {
					return;
				}

				$seen++;
				yield $post;
			}

			$last = end($page);
			$nid = ($last === false) ? 0 : $last->getNid();
			if ($nid <= 0 || ($maxId > 0 && $nid >= $maxId)) {
				// a row that cannot be paged on: stop rather than ask for the
				// same page for ever
				return;
			}

			$maxId = $nid;
		}
	}

	/**
	 * The two windows the page compares, in seconds since the epoch.
	 *
	 * Anchored to the start of today rather than to the minute the page was
	 * opened: otherwise opening it twice in an afternoon moves every bucket
	 * and the same thirty days draw a different shape each time.
	 *
	 * @return array{until: int, current: int, previous: int}
	 */
	private function bounds(int $now): array {
		$endOfToday = (int)strtotime(gmdate('Y-m-d', $now) . ' 00:00:00 UTC') + self::DAY;
		$current = $endOfToday - self::PERIOD_DAYS * self::DAY;

		return [
			'until' => $endOfToday,
			'current' => $current,
			'previous' => $current - self::PERIOD_DAYS * self::DAY,
		];
	}

	/**
	 * How many people each of these posts could have reached.
	 *
	 * The account's own followers plus the followers of everybody who boosted
	 * it, which is the only reach a Fediverse post has that any one server can
	 * put a number on. It is an estimate twice over, and the page has to say
	 * so: two audiences that overlap are counted twice, and a booster whose
	 * instance has never told this one how big it is counts as nothing at all.
	 * How many of those there were comes back with the figures, so that the
	 * page can name the gap rather than quietly absorb it.
	 *
	 * The followers are today's followers, not the followers the post had on
	 * the day it went out — nothing here records that.
	 *
	 * A direct message has no follower audience at all, so its base is nobody.
	 *
	 * @param list<array<string, mixed>> $recent
	 * @return array{posts: list<array<string, mixed>>, known: int, unknown: int}
	 */
	/**
	 * How regularly the account posts, out of the days it posted on.
	 *
	 * Every other figure on this page is about what came back. This is the
	 * one about what the account did, which is what moves all the others: an
	 * account that posts twice a week for a year and one that posted four
	 * hundred times in a fortnight can have the same totals and nothing else
	 * in common.
	 *
	 * @param array<string, bool> $days the days something was posted, as Y-m-d
	 * @param int $first when the earliest post counted was published
	 * @param int $last when the latest was
	 *
	 * @return array{active_days: int, span_days: int, share: float, longest_gap: int, streak: int}
	 */
	private function consistency(array $days, int $first, int $last): array {
		$dates = array_keys($days);
		sort($dates);

		$span = ($first > 0 && $last >= $first) ? (int)floor(($last - $first) / self::DAY) + 1 : count($dates);
		$gap = 0;
		$streak = 0;
		$run = 0;
		$previous = null;

		foreach ($dates as $date) {
			$stamp = strtotime($date . ' UTC');
			if ($stamp === false) {
				continue;
			}

			if ($previous !== null) {
				$between = (int)floor(($stamp - $previous) / self::DAY);
				$gap = max($gap, $between - 1);
				$run = ($between === 1) ? $run + 1 : 1;
			} else {
				$run = 1;
			}

			$streak = max($streak, $run);
			$previous = $stamp;
		}

		return [
			'active_days' => count($dates),
			'span_days' => max($span, count($dates)),
			'share' => ($span < 1) ? 0.0 : round((float)count($dates) / (float)$span * 100.0, 1),
			'longest_gap' => $gap,
			'streak' => $streak,
		];
	}

	private function withReach(array $recent, int $followers): array {
		$boosted = [];
		foreach ($recent as $row) {
			// a post nobody boosted needs no lookup: its reach is the
			// account's own audience and nothing else
			if ((int)$row['boosts'] > 0) {
				$boosted[] = (string)$row['url'];
			}
		}

		$boosters = ($boosted === []) ? [] : $this->streamRequest->boostersOf($boosted, self::MAX_BOOSTERS);

		$actors = [];
		foreach ($boosters as $list) {
			foreach ($list as $actor) {
				$actors[$actor] = $actor;
			}
		}
		$audiences = ($actors === []) ? [] : $this->cacheActorsRequest->followerCountsOf(array_values($actors));

		$known = 0;
		$unknown = 0;
		foreach ($recent as &$row) {
			$reach = ($row['visibility'] === Stream::TYPE_DIRECT) ? 0 : $followers;
			foreach ($boosters[(string)$row['url']] ?? [] as $actor) {
				if (array_key_exists($actor, $audiences)) {
					$reach += $audiences[$actor];
					$known++;
				} else {
					$unknown++;
				}
			}
			$row['reach'] = $reach;
		}
		unset($row);

		return ['posts' => $recent, 'known' => $known, 'unknown' => $unknown];
	}

	/**
	 * The last thirty days beside the thirty before them.
	 *
	 * Two windows of the same length, which is what makes the pair worth
	 * printing: "up 40%" against a fortnight is not a sentence.
	 *
	 * @param list<array<string, mixed>> $recent
	 * @param array{until: int, current: int, previous: int} $bounds
	 * @return array<string, mixed>
	 */
	private function periods(array $recent, array $bounds): array {
		$current = $this->period($recent, $bounds['current'], $bounds['until']);
		$previous = $this->period($recent, $bounds['previous'], $bounds['current']);

		$change = [];
		foreach (['posts', 'reach', 'interactions', 'likes', 'boosts', 'replies'] as $key) {
			$change[$key] = $this->change((int)$current[$key], (int)$previous[$key]);
		}

		return [
			'days' => self::PERIOD_DAYS,
			'current' => $current,
			'previous' => $previous,
			'change' => $change,
		];
	}

	/**
	 * One window: what it came to, and what each of its days came to.
	 *
	 * The daily series is what the page draws the two lines from, so both
	 * windows are counted into the same thirty buckets, day one first. A post
	 * is counted on the day it was published, and everything it has collected
	 * since is counted there with it — there is no record of *when* a like
	 * arrived, only that it did.
	 *
	 * @param list<array<string, mixed>> $recent
	 * @return array<string, mixed>
	 */
	private function period(array $recent, int $from, int $until): array {
		$totals = ['posts' => 0, 'reach' => 0, 'interactions' => 0, 'likes' => 0, 'boosts' => 0, 'replies' => 0];
		$series = [
			'reach' => array_fill(0, self::PERIOD_DAYS, 0),
			'interactions' => array_fill(0, self::PERIOD_DAYS, 0),
			'likes' => array_fill(0, self::PERIOD_DAYS, 0),
			'boosts' => array_fill(0, self::PERIOD_DAYS, 0),
		];

		foreach ($recent as $row) {
			$at = (int)$row['published'];
			if ($at < $from || $at >= $until) {
				continue;
			}

			$day = min(self::PERIOD_DAYS - 1, max(0, intdiv($at - $from, self::DAY)));
			$counts = [
				'reach' => (int)$row['reach'],
				'interactions' => (int)$row['score'],
				'likes' => (int)$row['likes'],
				'boosts' => (int)$row['boosts'],
			];

			$totals['posts']++;
			$totals['replies'] += (int)$row['replies'];
			foreach ($counts as $key => $count) {
				$totals[$key] += $count;
				$series[$key][$day] += $count;
			}
		}

		return array_merge($totals, [
			'from' => $this->asDate($from),
			// the last second of the window rather than the first of the next
			// one, so that a printed range reads as the days it covers
			'until' => $this->asDate($until - 1),
			'series' => $series,
		]);
	}

	/**
	 * How much bigger than last time, as a percentage.
	 *
	 * Null where the window before it was empty: everything is infinitely more
	 * than nothing, and a page that prints "+∞%" has stopped saying anything.
	 */
	private function change(int $now, int $before): ?float {
		if ($before < 1) {
			return null;
		}

		return round(((float)$now - (float)$before) / (float)$before * 100.0, 1);
	}

	/**
	 * Every post of the current window, one by one, newest first.
	 *
	 * The averages above answer "how is the account doing"; this answers "and
	 * which post was that", which is the question anybody who has just read a
	 * spike in a chart actually has.
	 *
	 * @param list<array<string, mixed>> $recent
	 * @return list<array<string, mixed>>
	 */
	private function timeline(array $recent, int $from, int $until): array {
		$rows = [];
		foreach ($recent as $row) {
			$at = (int)$row['published'];
			if ($at < $from || $at >= $until) {
				continue;
			}

			unset($row['published']);
			$rows[] = $row;

			if (count($rows) >= self::TIMELINE_POSTS) {
				break;
			}
		}

		return $rows;
	}

	/**
	 * Adds one post's engagement to a kind of post.
	 *
	 * @param array<string, array{posts: int, engagement: int}> $kinds
	 */
	private function addKind(array &$kinds, string $key, int $score): void {
		$kinds[$key] ??= ['posts' => 0, 'engagement' => 0];
		$kinds[$key]['posts']++;
		$kinds[$key]['engagement'] += $score;
	}

	/**
	 * What each kind of post averages, so the page can say what works.
	 *
	 * A kind with no posts is left out rather than reported as zero: "posts
	 * with a picture average 0" is a false statement about an account that has
	 * never posted one.
	 *
	 * @param array<string, array{posts: int, engagement: int}> $kinds
	 * @return array<int, array{key: string, posts: int, engagement: int, average: float}>
	 */
	private function content(array $kinds): array {
		$content = [];
		foreach ($kinds as $key => $kind) {
			if ($kind['posts'] < 1) {
				continue;
			}
			$content[] = [
				'key' => $key,
				'posts' => $kind['posts'],
				'engagement' => $kind['engagement'],
				'average' => $this->ratio($kind['engagement'], $kind['posts']),
			];
		}

		return $content;
	}

	/**
	 * The seven days, Sunday first, as PHP numbers them.
	 *
	 * The average is what the page sorts on: an account that posts twice as
	 * often on a Monday collects twice as much on a Monday without Monday
	 * being a better day to post.
	 *
	 * @param array<int, array{posts: int, engagement: int}> $byWeekday
	 * @return array<int, array{day: int, posts: int, engagement: int, average: float}>
	 */
	private function weekdays(array $byWeekday): array {
		$weekdays = [];
		foreach ($byWeekday as $day => $counted) {
			$weekdays[] = [
				'day' => $day,
				'posts' => $counted['posts'],
				'engagement' => $counted['engagement'],
				'average' => $this->ratio($counted['engagement'], $counted['posts']),
			];
		}

		return $weekdays;
	}

	/**
	 * The hour whose posts did best, and how sure of it to be.
	 *
	 * An hour with one lucky post in it is not a time of day that works, so an
	 * hour is only named once it holds at least three posts; below that the
	 * answer is that there is not enough to say.
	 *
	 * @param array<int, int> $engagementByHour
	 * @param array<int, int> $byHour
	 * @return array{hour: int|null, average: float, posts: int}
	 */
	private function bestHour(array $engagementByHour, array $byHour): array {
		$best = ['hour' => null, 'average' => 0.0, 'posts' => 0];
		foreach ($byHour as $hour => $posts) {
			if ($posts < 3) {
				continue;
			}
			$average = $this->ratio($engagementByHour[$hour] ?? 0, $posts);
			if ($average > $best['average']) {
				$best = ['hour' => $hour, 'average' => $average, 'posts' => $posts];
			}
		}

		return $best;
	}

	/**
	 * The hashtags that are worth using, which is not the same list as the
	 * hashtags that are used.
	 *
	 * Sorted by what a post carrying one averages, and only for tags used
	 * `HASHTAG_MIN_USES` times or more: one post that did well is a post that
	 * did well.
	 *
	 * @param array<string, int> $uses
	 * @param array<string, int> $engagement
	 * @return array<int, array{name: string, posts: int, average: float}>
	 */
	private function hashtagPerformance(array $uses, array $engagement): array {
		$performance = [];
		foreach ($uses as $name => $posts) {
			if ($posts < self::HASHTAG_MIN_USES) {
				continue;
			}
			$performance[] = [
				'name' => $name,
				'posts' => $posts,
				'average' => $this->ratio($engagement[$name] ?? 0, $posts),
			];
		}

		usort($performance, static fn (array $a, array $b): int => $b['average'] <=> $a['average']);

		return array_slice($performance, 0, self::TOP_HASHTAGS);
	}

	/**
	 * Who is listening, and where they are.
	 *
	 * Two questions an agency asks that the profile cannot answer: which
	 * instances the audience is on — which is what a Fediverse account has
	 * instead of a geography — and when it arrived.
	 *
	 * @return array{by_month: array<string, int>, instances: array<int, array{host: string, count: int}>, local_share: float, counted: int, capped: bool}
	 */
	private function audience(Person $actor, int $followers): array {
		$byMonth = $this->emptyMonths();
		$hosts = [];
		$local = 0;
		$rows = $this->followsRequest->getFollowerOrigins($actor->getId(), self::MAX_FOLLOWERS);
		$here = parse_url($actor->getId(), PHP_URL_HOST);

		foreach ($rows as $row) {
			$month = ($row['creation'] === '') ? '' : gmdate('Y-m', (int)strtotime($row['creation']));
			if ($month !== '' && array_key_exists($month, $byMonth)) {
				$byMonth[$month]++;
			}

			$host = parse_url($row['actor_id'], PHP_URL_HOST);
			if (!is_string($host) || $host === '') {
				continue;
			}
			if ($host === $here) {
				$local++;
			}
			$hosts[$host] = ($hosts[$host] ?? 0) + 1;
		}

		arsort($hosts);
		$instances = [];
		foreach (array_slice($hosts, 0, self::TOP_HASHTAGS, true) as $host => $count) {
			$instances[] = ['host' => $host, 'count' => $count];
		}

		return [
			'by_month' => $byMonth,
			'instances' => $instances,
			'local_share' => ($rows === []) ? 0.0 : round((float)$local / (float)count($rows) * 100.0, 1),
			'counted' => count($rows),
			'capped' => count($rows) >= self::MAX_FOLLOWERS && $followers > count($rows),
		];
	}

	/**
	 * The middle post rather than the average one.
	 *
	 * @param int[] $scores
	 */
	private function median(array $scores): float {
		if ($scores === []) {
			return 0.0;
		}

		sort($scores);
		$middle = intdiv(count($scores), 2);

		return (count($scores) % 2 === 1)
			? (float)$scores[$middle]
			: round(($scores[$middle - 1] + $scores[$middle]) / 2, 1);
	}

	/**
	 * The last twelve months, in order, each at zero.
	 *
	 * Built rather than derived from the posts so that a month nothing was
	 * posted in is a gap in the chart instead of a missing column.
	 *
	 * @return array<string, int>
	 */
	private function emptyMonths(): array {
		$months = [];
		for ($back = self::MONTHS - 1; $back >= 0; $back--) {
			$months[gmdate('Y-m', strtotime('-' . $back . ' months', time()))] = 0;
		}

		return $months;
	}

	/**
	 * @param array<string, int> $counted
	 * @return array<int, array{name: string, count: int}>
	 */
	private function named(array $counted, int $limit): array {
		$named = [];
		foreach (array_slice($counted, 0, $limit, true) as $name => $count) {
			$named[] = ['name' => $name, 'count' => $count];
		}

		return $named;
	}

	/** One decimal, and never a division by nothing. */
	private function ratio(int $total, int $over): float {
		return ($over < 1) ? 0.0 : round($total / $over, 1);
	}

	/** An ISO date, or '' where there is no date to give. */
	private function asDate(int $timestamp): string {
		return ($timestamp > 0) ? gmdate('Y-m-d\TH:i:s', $timestamp) . '.000Z' : '';
	}

	/**
	 * Enough of a post to recognise it by.
	 *
	 * Tags are stripped rather than rendered: this goes into a list of links,
	 * and a post's own markup has no business laying that list out.
	 */
	private function excerpt(Stream $post): string {
		$text = trim(html_entity_decode(strip_tags($post->getContent()), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		$text = (string)preg_replace('/\s+/u', ' ', $text);

		if (mb_strlen($text) <= 90) {
			return $text;
		}

		return mb_substr($text, 0, 89) . '…';
	}
}
