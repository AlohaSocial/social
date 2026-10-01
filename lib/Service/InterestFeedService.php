<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tools\Nid;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * The For you feed: posts carrying the reader's hashtags, ranked.
 *
 * Built in three steps. The candidates are every post of the last few days
 * that carries one of the reader's interests and that they may see — the same
 * visibility, blocks, mutes and silences as the hashtag timeline, less their
 * own posts, the posts they said "less like this" about, and anything not in
 * their languages. Each is ranked by how well it matches, halved for every day
 * of its age. Then the page is spread out: no author more than twice in any
 * twenty, no one hashtag more than two fifths of them, and one post in ten
 * from a hashtag that often travels with the reader's own but is not on their
 * list yet.
 *
 * A ranked feed cannot be paged by id the way a timeline is, because the next
 * page is not "older than the last post". So the ranking is made once, kept
 * for an hour, and cut into pages; a client that pages with the last post's
 * id as `max_id`, as every Mastodon client does, gets the page after that
 * post in the ranking. Refreshing — a request with no cursor — ranks afresh.
 *
 * The same ranking also comes narrowed to one kind of post, for the Photos
 * and Videos pages and the Shorts stack: `photos`, `videos`, or `media` for
 * either. Each is a ranking of its own, kept under its own key, so paging
 * one never lands in another. Pictures and videos carry fewer hashtags than
 * text and are rarer, so those rankings look twice as far back, and where
 * one is still shorter than `POPULAR_FILL` the rest is what is trending in
 * the same kind — marked `popular`, so the line over the post says so rather
 * than inventing a hashtag it matched.
 */
class InterestFeedService {
	/** How long a ranking is kept to be paged through; every read extends it. */
	public const SNAPSHOT_TTL = 3600;
	/** The longest ranking kept. */
	public const SNAPSHOT_MAX = 500;
	/** Rows read for the candidates: one per post and matching tag. */
	public const CANDIDATE_ROWS = 1500;

	public const DIVERSITY_WINDOW = 20;
	public const PER_AUTHOR = 2;
	public const TAG_SHARE = 0.4;
	/** One slot in this many is an exploration post. */
	public const EXPLORE_EVERY = 10;
	public const RELATED_TAGS = 5;

	/** The kinds a ranking can be narrowed to; '' is every post. */
	public const MEDIA = ['', 'photos', 'videos', 'media'];
	/** How much further back a narrowed ranking looks. */
	public const MEDIA_WINDOW_FACTOR = 2;
	/** A narrowed ranking shorter than this is filled from what is trending. */
	public const POPULAR_FILL = TrendService::MAX_LIMIT;
	/** What `TrendService` is asked for, by kind. */
	private const TREND_KIND = ['photos' => 'image', 'videos' => 'video', 'media' => ''];

	private ?ICache $cache = null;

	public function __construct(
		private InterestService $interestService,
		private StreamRequest $streamRequest,
		private StreamService $streamService,
		private TrendService $trendService,
		private ICacheFactory $cacheFactory,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * One page of the feed.
	 *
	 * @param string $maxId the last post of the previous page, or '0'
	 * @param int $offset the rank to start at, for clients that page by it
	 * @param string $media one of `MEDIA`; anything else is every post
	 *
	 * @return Stream[] in rank order, each carrying why it is there
	 */
	public function page(Person $viewer, int $limit, string $maxId = '0', int $offset = 0, string $media = ''): array {
		$media = self::media($media);
		$this->streamService->setViewer($viewer);
		$key = self::snapshotKey($viewer->getId(), $media);
		$snapshot = null;
		if ($maxId !== '0' || $offset > 0) {
			$snapshot = $this->cache()->get($key);
		}
		if (!is_array($snapshot)) {
			// no ranking kept — the first page, one that has expired, or an
			// instance with no memory cache at all. Ranked again, the cursor
			// is usually still in it; where it is not, the page below is empty
			// and refreshing starts over
			$snapshot = $this->rank($viewer, $media);
		}
		$this->cache()->set($key, $snapshot, self::SNAPSHOT_TTL);

		$start = max(0, $offset);
		if ($maxId !== '0') {
			$position = array_search($maxId, array_column($snapshot, 'nid'), true);
			if ($position === false) {
				return [];
			}
			$start = $position + 1;
		}

		$entries = array_slice($snapshot, $start, max(1, $limit));
		$posts = $this->streamService->visiblePosts(array_column($entries, 'nid'));

		$page = [];
		foreach ($entries as $entry) {
			$post = $posts[$entry['nid']] ?? null;
			if ($post !== null) {
				$page[] = $post->setInterest(['tags' => $entry['tags'], 'reason' => $entry['reason']]);
			}
		}

		return $page;
	}

	/**
	 * Where a reader's ranking of one kind is kept. The kind is part of it, so
	 * the three rankings are three snapshots rather than one that each of
	 * them overwrites and pages into.
	 */
	public static function snapshotKey(string $viewerId, string $media): string {
		return 'feed/' . $media . '/' . md5($viewerId);
	}

	/** One of `MEDIA`: an unknown kind is every post, as the timelines read it. */
	public static function media(string $media): string {
		return in_array($media, self::MEDIA, true) ? $media : '';
	}

	/**
	 * The whole ranking, as nids with the tags and the reason each is there.
	 *
	 * @param string $media one of `MEDIA`
	 *
	 * @return list<array{nid: string, tags: string[], reason: string}>
	 */
	public function rank(Person $viewer, string $media = ''): array {
		$media = self::media($media);
		$hidden = $this->interestService->hiddenFor($viewer);
		$ranking = $this->ranked($viewer, $media, $hidden);

		if ($media !== '' && count($ranking) < self::POPULAR_FILL) {
			$ranking = array_merge($ranking, $this->popular(
				$viewer, $media, array_merge($hidden, array_column($ranking, 'nid')), self::POPULAR_FILL - count($ranking)
			));
		}

		return $ranking;
	}

	/**
	 * The ranking by the reader's hashtags alone.
	 *
	 * @param string[] $hidden the posts the reader hid
	 *
	 * @return list<array{nid: string, tags: string[], reason: string}>
	 */
	private function ranked(Person $viewer, string $media, array $hidden): array {
		$profile = $this->interestService->feedProfile($viewer);
		$positive = array_keys(array_filter($profile['weights'], static fn (float $w): bool => $w > 0));
		if ($positive === []) {
			return [];
		}

		$now = $this->timeFactory->getTime();
		$days = $this->interestService->windowDays() * (($media === '') ? 1 : self::MEDIA_WINDOW_FACTOR);
		$since = Nid::fromPublishedTime(max(0, $now - $days * 86400), 0, StreamRequest::NID_LIMIT);
		$languages = $this->interestService->languagesFor($viewer->getUserId());

		$this->streamRequest->setViewer($viewer);
		$candidates = $this->group($this->streamRequest->interestCandidates(
			array_map('strval', $positive), $since, self::CANDIDATE_ROWS, $hidden, $languages, $media
		));
		if ($candidates === []) {
			return [];
		}

		$allTags = $this->streamRequest->hashtagsOfStreams(array_column($candidates, 'idPrim'));
		$scorer = $this->interestService->scorer();

		$ranked = [];
		foreach ($candidates as $nid => $candidate) {
			$tags = array_values(array_unique($allTags[$candidate['idPrim']] ?? $candidate['tags']));
			$weights = [];
			foreach ($tags as $tag) {
				if (isset($profile['weights'][$tag])) {
					$weights[$tag] = $profile['weights'][$tag];
				}
			}

			$relevance = $scorer->relevance(array_values($weights));
			if ($relevance <= 0) {
				continue;
			}

			$matched = array_keys(array_filter($weights, static fn (float $w): bool => $w > 0));
			usort($matched, static fn (string $a, string $b): int => $weights[$b] <=> $weights[$a]);

			$ranked[] = [
				'nid' => (string)$nid,
				'author' => $candidate['author'],
				'tags' => $matched,
				'all' => $tags,
				'reason' => $profile['reasons'][$matched[0]] ?? 'interest',
				'rank' => $relevance * $scorer->recency($now - $this->publishedTime((string)$nid)),
			];
		}

		usort($ranked, static fn (array $a, array $b): int => $b['rank'] <=> $a['rank']);

		$related = $profile['thin'] ? [] : $this->related($ranked, $profile, $since, $hidden, $languages, $media);

		return array_map(
			static fn (array $entry): array => ['nid' => $entry['nid'], 'tags' => $entry['tags'], 'reason' => $entry['reason']],
			array_slice($this->spread($ranked, $related), 0, self::SNAPSHOT_MAX)
		);
	}

	/**
	 * Lays the ranking out so no author and no hashtag has a stretch of it to
	 * themselves, and so one slot in ten looks slightly beyond the list.
	 *
	 * A post that would break a limit waits for the first slot where it no
	 * longer does, rather than being dropped: this reorders the feed and never
	 * shortens it. Where every post left breaks one, the hashtag limit gives
	 * way before the author limit does.
	 *
	 * @param list<array{nid: string, author: string, tags: string[], reason: string, ...}> $ranked
	 * @param list<array{nid: string, author: string, tags: string[], reason: string, ...}> $related
	 *
	 * @return list<array{nid: string, author: string, tags: string[], reason: string, ...}>
	 */
	public function spread(array $ranked, array $related): array {
		$placed = [];
		$tagLimit = (int)floor((float)self::DIVERSITY_WINDOW * self::TAG_SHARE);

		while ($ranked !== [] || $related !== []) {
			$slot = count($placed);
			if ($related !== [] && ($slot % self::EXPLORE_EVERY) === self::EXPLORE_EVERY - 1) {
				$placed[] = array_shift($related);
				continue;
			}
			if ($ranked === []) {
				// the related posts are a seasoning, not a feed of their own
				break;
			}

			$window = array_slice($placed, -(self::DIVERSITY_WINDOW - 1));
			$authors = array_count_values(array_column($window, 'author'));
			$tags = array_count_values(array_map(static fn (array $e): string => $e['tags'][0] ?? '', $window));

			// the best post that breaks neither limit; failing that, one from
			// an author with room, since a run of one hashtag is a topic and a
			// run of one person is a timeline of theirs; failing that, the best
			$pick = null;
			$authorOnly = null;
			foreach ($ranked as $index => $entry) {
				if (($authors[$entry['author']] ?? 0) >= self::PER_AUTHOR) {
					continue;
				}
				$authorOnly ??= $index;
				if (($tags[$entry['tags'][0] ?? ''] ?? 0) < $tagLimit) {
					$pick = $index;
					break;
				}
			}
			$pick ??= $authorOnly ?? 0;

			$placed[] = $ranked[$pick];
			array_splice($ranked, $pick, 1);
		}

		return $placed;
	}

	/**
	 * Posts carrying a hashtag that keeps company with the reader's own: the
	 * tags most often found on the ranked posts that the reader has no weight
	 * for, and the newest posts that carry one of them and nothing listed.
	 *
	 * @return list<array{nid: string, author: string, tags: string[], reason: string}>
	 */
	private function related(array $ranked, array $profile, string $since, array $hidden, array $languages, string $media): array {
		$company = [];
		foreach (array_slice($ranked, 0, 100) as $entry) {
			foreach ($entry['all'] as $tag) {
				if (!isset($profile['weights'][$tag])) {
					$company[$tag] = ($company[$tag] ?? 0) + 1;
				}
			}
		}
		$company = array_filter($company, static fn (int $count): bool => $count >= 2);
		arsort($company);
		$tags = array_slice(array_map('strval', array_keys($company)), 0, self::RELATED_TAGS);
		if ($tags === []) {
			return [];
		}

		$seen = array_merge($hidden, array_column($ranked, 'nid'));
		$related = [];
		foreach ($this->group($this->streamRequest->interestCandidates($tags, $since, 200, $seen, $languages, $media)) as $nid => $candidate) {
			$related[] = [
				'nid' => (string)$nid,
				'author' => $candidate['author'],
				'tags' => [$candidate['tags'][0]],
				'reason' => 'related',
			];
		}

		return $related;
	}

	/**
	 * What is trending in this kind, for a narrowed ranking too short to fill
	 * a screen: a newcomer with no history yet, or interests that few pictures
	 * carry. `TrendService` is what Discover's Pictures and Videos tabs read,
	 * so these are the same posts, topped up on a young instance with its
	 * newest media. None of the reader's own, none they hid, none already
	 * ranked; the page reads each again as the reader may see it.
	 *
	 * @param string[] $seen the posts to leave out
	 *
	 * @return list<array{nid: string, tags: string[], reason: string}>
	 */
	private function popular(Person $viewer, string $media, array $seen, int $wanted): array {
		$statuses = $this->trendService->trendingStatuses(
			HashtagService::PERIOD_DEFAULT, TrendService::MAX_LIMIT, 0, true, self::TREND_KIND[$media] ?? ''
		);

		$seen = array_flip(array_map('strval', $seen));
		$popular = [];
		foreach ($statuses as $status) {
			$nid = (string)$status->getNid();
			if (count($popular) >= $wanted) {
				break;
			}
			if ($nid === '' || $nid === '0' || isset($seen[$nid]) || $status->getAttributedTo() === $viewer->getId()) {
				continue;
			}
			$seen[$nid] = true;
			$popular[] = ['nid' => $nid, 'tags' => [], 'reason' => 'popular'];
		}

		return $popular;
	}

	/**
	 * One entry per post out of one row per post and tag, in the order the
	 * rows came.
	 *
	 * @param list<array{nid: string, idPrim: string, tag: string, author: string}> $rows
	 *
	 * @return array<string, array{idPrim: string, author: string, tags: string[]}>
	 */
	private function group(array $rows): array {
		$posts = [];
		foreach ($rows as $row) {
			$posts[$row['nid']] ??= ['idPrim' => $row['idPrim'], 'author' => $row['author'], 'tags' => []];
			$posts[$row['nid']]['tags'][] = $row['tag'];
		}

		return $posts;
	}

	/** When a post was published, which is what its nid begins with. */
	private function publishedTime(string $nid): int {
		$suffix = strlen((string)StreamRequest::NID_LIMIT) - 1;

		return (strlen($nid) > $suffix) ? (int)substr($nid, 0, -$suffix) : 0;
	}

	private function cache(): ICache {
		return $this->cache ??= $this->cacheFactory->createDistributed('social.interests.feed');
	}
}
