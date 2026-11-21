<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\AppInfo\Application;
use OCA\Social\Atproto\Reader\BlueskyStatistics;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\Details;
use OCA\Social\Service\Statistics\StatisticsSource;
use OCA\Social\Tools\DbTime;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Container\ContainerInterface;

/**
 * What an account has done here, and what came back.
 *
 * Everything is counted from this instance's rows when asked, nothing stored
 * or precomputed, bounded by `MAX_POSTS` with the number of posts looked at
 * reported. Engagement is read from each post's JSON `details`, hence a walk
 * in PHP rather than a portable `SUM()`. Remote likes are included as the
 * origin reported them, so figures are a floor, not a total.
 */
class StatisticsService {
	/**
	 * How far back the walk goes: an account with more posts gets the most
	 * recent ones, and the page says so.
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
	 * How many followers are read for the audience figures, newest first; the
	 * page says when the ceiling is reached.
	 */
	private const MAX_FOLLOWERS = 5000;

	/**
	 * How long the two compared windows are: thirty days beside the thirty
	 * before them.
	 */
	public const PERIOD_DAYS = 30;

	/** How many of the window's posts are listed one by one. */
	public const TIMELINE_POSTS = 100;

	/** How many Announce rows the reach estimate reads at most. */
	private const MAX_BOOSTERS = 5000;

	/** A day, in seconds. */
	private const DAY = 86400;

	/**
	 * How many times a hashtag must have been used before its average is
	 * reported, so one good post does not make a tag look effective.
	 */
	private const HASHTAG_MIN_USES = 2;

	/**
	 * How long a computed page is kept: fifteen minutes, as computing it reads
	 * up to two thousand posts and their boosters' audiences (the route is
	 * limited to thirty an hour). Kept in `DurableCache`, so it is cached even
	 * without a memcache.
	 */
	private const CACHE_SECONDS = 900;

	/** The `DurableCache` namespace the computed pages live in. */
	public const CACHE_NAMESPACE = Application::APP_ID . '/statistics';

	public function __construct(
		private StreamRequest $streamRequest,
		private FollowsRequest $followsRequest,
		private CacheActorsRequest $cacheActorsRequest,
		private DurableCache $cache,
		private ITimeFactory $timeFactory,
		private ?ContainerInterface $container = null,
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
	 * The windows a reader may ask for, in days; 0 is everything. A fixed set,
	 * as each is a walk of up to `MAX_POSTS` and a cache entry of its own.
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
		$now = $this->timeFactory->getTime();
		$since = ($days > 0) ? $now - ($days * self::DAY) : 0;
		$posts = [
			'total' => 0,
			'originals' => 0,
			'replies' => 0,
			'boosts' => 0,
			'with_media' => 0,
			'sensitive' => 0,
		];
		$engagement = ['likes' => 0, 'boosts' => 0, 'replies' => 0, 'quotes' => 0];
		/** the posts the account wrote that were counted, for who answered them */
		$writtenIds = [];
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
		$bounds = $this->bounds($now);
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
				// a boost is activity but not authorship, so it is counted and
				// then left out of everything below; its likes are the author's
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

			// counted per image rather than per post: one post with four
			// images and one alt text is not three-quarters done
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

			$writtenIds[] = $post->getId();
			$likes = $post->getDetailInt(Details::LIKES);
			$boosts = $post->getDetailInt(Details::BOOSTS);
			$replies = $post->getDetailInt(Details::REPLIES);
			$quotes = $post->getDetailInt(Details::QUOTES);
			$engagement['likes'] += $likes;
			$engagement['boosts'] += $boosts;
			$engagement['replies'] += $replies;
			$engagement['quotes'] += $quotes;

			// one post's engagement, which every rate below is built from; a
			// quote counts once, like a boost or a reply
			$score = $likes + $boosts + $replies + $quotes;
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
				'quotes' => $quotes,
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
		$audience['starter_pack_joins'] = $this->streamRequest->countNotifiersOf(
			$actor->getId(), Stream::SUBTYPE_BLUESKY_STARTER_PACK, $since
		);
		$total = $engagement['likes'] + $engagement['boosts'] + $engagement['replies'] + $engagement['quotes'];
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
				// per post the account wrote; dividing by boosts too would make
				// an account that boosts a lot look ignored
				'likes_per_post' => $this->ratio($engagement['likes'], $written),
				'boosts_per_post' => $this->ratio($engagement['boosts'], $written),
				'replies_per_post' => $this->ratio($engagement['replies'], $written),
				'quotes_per_post' => $this->ratio($engagement['quotes'], $written),
			]),
			'engagement_sources' => $this->engagementSources($actor, $writtenIds),
			'visibility' => $visibility,
			'rates' => [
				// the four figures agencies report, under their names, per post
				// the account wrote
				'total' => $total,
				'per_post' => $this->ratio($total, $written),
				'applause' => $this->ratio($engagement['likes'], $written),
				'amplification' => $this->ratio($engagement['boosts'], $written),
				'conversation' => $this->ratio($engagement['replies'], $written),
				// the industry's engagement rate, as a percentage of the audience;
				// without impressions, followers are the denominator
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
				// the servers this account's posts were actually addressed to,
				// beside the estimate, as posts without stored instance paths
				// would read as zero
				'instances' => count($deliveredTo),
				// the networks a post goes out on without an instance to
				// deliver it to, which an instance path cannot record
				'networks' => $this->networksReached($actor, $writtenIds),
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
	 * Who the account talks with, from the replies, and whether it follows
	 * them; a high `not_followed` share means conversations come from outside
	 * its own timeline.
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
	 * The account's own posts, newest first, up to the cap, keyset by nid so a
	 * deletion during the walk does not skip a post.
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
	 * The two windows the page compares, in seconds since the epoch, anchored
	 * to the start of today so reloads draw the same buckets.
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
	 * How many people each post could have reached: the account's followers
	 * plus those of everybody who boosted it. An estimate: overlaps count
	 * twice, boosters whose follower count is unknown count as nothing (and
	 * are reported), and today's followers are used. Direct messages reach
	 * nobody.
	 *
	 * @param list<array<string, mixed>> $recent
	 * @return array{posts: list<array<string, mixed>>, known: int, unknown: int}
	 */
	/**
	 * How regularly the account posts, from the days it posted on.
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
	 * One window: its totals and a daily series in thirty buckets. A post and
	 * all it has collected are counted on its publication day, as arrival
	 * times of likes are not recorded.
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
	 * The change from the previous window, as a percentage; null when that
	 * window was empty.
	 */
	private function change(int $now, int $before): ?float {
		if ($before < 1) {
			return null;
		}

		return round(((float)$now - (float)$before) / (float)$before * 100.0, 1);
	}

	/**
	 * Every post of the current window, newest first, so a spike in the chart
	 * can be traced to its post.
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
	 * What each kind of post averages; kinds without posts are left out
	 * rather than reported as zero.
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
	 * The seven days, Sunday first, as PHP numbers them, sorted by average so
	 * posting more on a day does not make it look better.
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
	 * The hour whose posts did best; only named once it holds at least three
	 * posts.
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
	 * The hashtags worth using: sorted by what a post carrying one averages,
	 * for tags used at least `HASHTAG_MIN_USES` times.
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
	 * Who is listening: the instances the followers are on and when they
	 * arrived.
	 *
	 * @return array{by_month: array<string, int>, instances: array<int, array{host: string, count: int}>, local_share: float, counted: int, capped: bool}
	 */
	private function audience(Person $actor, int $followers): array {
		$byMonth = $this->emptyMonths();
		$hosts = [];
		$local = 0;
		$rows = $this->followsRequest->getFollowerOrigins($actor->getId(), self::MAX_FOLLOWERS);
		$here = parse_url($actor->getId(), PHP_URL_HOST);
		$homes = $this->homeServers(array_map(static fn (array $row): string => (string)$row['actor_id'], $rows));

		foreach ($rows as $row) {
			$month = ($row['creation'] === '') ? '' : gmdate('Y-m', DbTime::toTimestamp($row['creation']));
			if ($month !== '' && array_key_exists($month, $byMonth)) {
				$byMonth[$month]++;
			}

			$host = $homes[(string)$row['actor_id']] ?? '';
			if ($host === '') {
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
	 * The last twelve months, in order, each at zero, so a month without posts
	 * is a gap in the chart rather than a missing column.
	 *
	 * @return array<string, int>
	 */
	private function emptyMonths(): array {
		// counted back from the first of the month: "-1 month" from the 31st
		// overflows into the same month again wherever the month before is shorter
		$first = (new \DateTimeImmutable('@' . $this->timeFactory->getTime()))
			->modify('first day of this month midnight');
		$months = [];
		for ($back = self::MONTHS - 1; $back >= 0; $back--) {
			$months[$first->modify('-' . $back . ' months')->format('Y-m')] = 0;
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
	 * Enough of a post to recognise it by, with tags stripped, as it goes
	 * into a list of links.
	 */
	private function excerpt(Stream $post): string {
		$text = trim(html_entity_decode(strip_tags($post->getContent()), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		$text = (string)preg_replace('/\s+/u', ' ', $text);

		if (mb_strlen($text) <= 90) {
			return $text;
		}

		return mb_substr($text, 0, 89) . '…';
	}
	/**
	 * Where the likes, boosts and replies to these posts came from: the
	 * servers of the accounts that made them, the most frequent first, and
	 * the share made by accounts on this server. The account's own replies
	 * to itself are a thread it wrote, not an answer, and are left out.
	 *
	 * @param list<string> $postIds
	 *
	 * @return array{servers: list<array{host: string, count: int}>, local_share: float, counted: int}
	 */
	private function engagementSources(Person $actor, array $postIds): array {
		$reactions = $this->streamRequest->reactionsOf($postIds);
		$homes = $this->homeServers(array_keys($reactions));
		$here = parse_url($actor->getId(), PHP_URL_HOST);

		$hosts = [];
		$local = 0;
		$counted = 0;
		foreach ($reactions as $actorId => $count) {
			$host = $homes[(string)$actorId] ?? '';
			if ($host === '' || (string)$actorId === $actor->getId()) {
				continue;
			}
			$counted += $count;
			if ($host === $here) {
				$local += $count;
			}
			$hosts[$host] = ($hosts[$host] ?? 0) + $count;
		}

		arsort($hosts);
		$servers = [];
		foreach (array_slice($hosts, 0, self::TOP_HASHTAGS, true) as $host => $count) {
			$servers[] = ['host' => (string)$host, 'count' => $count];
		}

		return [
			'servers' => $servers,
			'local_share' => ($counted < 1) ? 0.0 : round((float)$local / (float)$counted * 100.0, 1),
			'counted' => $counted,
		];
	}

	/**
	 * The networks the account's posts also went out on, each with how many
	 * of the counted posts did.
	 *
	 * @param list<string> $postIds
	 *
	 * @return list<array{network: string, posts: int}>
	 */
	private function networksReached(Person $actor, array $postIds): array {
		if ($postIds === []) {
			return [];
		}

		$networks = [];
		foreach ($this->sources() as $source) {
			$reached = $source->reached($actor, $postIds);
			if ($reached !== null && $reached['posts'] > 0) {
				$networks[] = $reached;
			}
		}

		return $networks;
	}

	/**
	 * The server each account lives on: the host of its id, unless a network
	 * that holds the account knows better.
	 *
	 * @param list<string> $actorIds
	 *
	 * @return array<string, string> host by actor id; '' where there is none
	 */
	private function homeServers(array $actorIds): array {
		$homes = [];
		foreach ($actorIds as $actorId) {
			$host = parse_url($actorId, PHP_URL_HOST);
			$homes[$actorId] = is_string($host) ? strtolower($host) : '';
		}
		foreach ($this->sources() as $source) {
			$homes = array_merge($homes, $source->homeServers(array_values(array_unique($actorIds))));
		}

		return $homes;
	}

	/** @return list<StatisticsSource> */
	private function sources(): array {
		$sources = [];
		foreach ([BlueskyStatistics::class] as $class) {
			$source = $this->container?->get($class);
			if ($source instanceof StatisticsSource) {
				$sources[] = $source;
			}
		}

		return $sources;
	}
}
