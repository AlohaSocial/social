<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\TrendsRequest;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\TrendingLink;
use OCA\Social\Model\StreamCard;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Trending statuses and trending links, computed on request as grouped
 * counts of `social_action` and `social_stream_card` rows; hashtag trends
 * need a cron-written table instead. The windows and default are
 * `HashtagService::PERIODS`'s, so all three trend routes cover the same span.
 */
class TrendService {
	/** @var array<string, int> the windows of HashtagService, in seconds */
	private const WINDOWS = [
		'1h' => HashtagService::TREND_1H,
		'12h' => HashtagService::TREND_12H,
		'1d' => HashtagService::TREND_1D,
		'3d' => HashtagService::TREND_3D,
		'10d' => HashtagService::TREND_10D,
	];

	/** What Mastodon defaults and caps both trend routes at. */
	public const LIMIT = 20;
	public const MAX_LIMIT = 40;

	public function __construct(
		private TrendReviewService $trendReviewService,
		private TrendsRequest $trendsRequest,
		private ?ITimeFactory $timeFactory = null,
	) {
	}

	/**
	 * The most interacted-with public statuses of the window.
	 *
	 * @return Stream[]
	 */
	public function trendingStatuses(
		string $period,
		int $limit,
		int $offset,
		bool $onlyMedia = false,
		string $mediaType = '',
	): array {
		$wanted = $this->limit($limit);
		$nids = $this->trendsRequest->trendingStatusNids(
			$this->since($period), $wanted, max(0, $offset), $onlyMedia, $mediaType
		);

		// A young instance has nothing trending, which leaves the Photos and
		// Videos tabs looking broken. The first page is topped up with the
		// newest media, after the trending posts in their order; deeper pages
		// page through trending only.
		if ($onlyMedia && $offset === 0 && count($nids) < $wanted) {
			$nids = array_merge($nids, $this->trendsRequest->recentMediaNids(
				$wanted - count($nids), $mediaType, $nids
			));
		}

		$statuses = $this->trendsRequest->statusesByNids($nids);

		// what a moderator has kept out never reaches a trending page; it is
		// still counted, so lifting the decision restores its number
		return array_values(array_filter(
			$statuses,
			fn (Stream $status): bool => !$this->trendReviewService->statusIsRejected($status->getId())
		));
	}

	/**
	 * The newest public posts, for a For you feed short of a screen while
	 * nothing is trending. Never shown as a trend.
	 *
	 * @param string[] $excluding nids already on the page
	 *
	 * @return Stream[]
	 */
	public function newestPublic(int $limit, array $excluding = []): array {
		$nids = $this->trendsRequest->recentPublicNids($this->limit($limit), $excluding);

		return array_values(array_filter(
			$this->trendsRequest->statusesByNids($nids),
			fn (Stream $status): bool => !$this->trendReviewService->statusIsRejected($status->getId())
		));
	}

	/**
	 * The public posts carrying one link, newest first: Mastodon's link
	 * timeline, opened from a trending link.
	 *
	 * @return Stream[]
	 */
	public function linkTimeline(string $url, int $limit, int|string $maxId = '0', int|string $minId = 0): array {
		$url = trim($url);
		if ($url === '') {
			return [];
		}

		return $this->trendsRequest->statusesByNids(
			$this->trendsRequest->statusNidsForUrl($url, $limit, $maxId, $minId)
		);
	}

	/**
	 * The links most often attached to a public status in the window. A link
	 * whose card was deleted with its post is still listed, with a card holding
	 * only the url, so the list matches its counts.
	 *
	 * @return TrendingLink[]
	 */
	public function trendingLinks(string $period, int $limit, int $offset): array {
		$counted = $this->trendsRequest->trendingLinks(
			$this->since($period), $this->limit($limit), max(0, $offset)
		);
		if ($counted === []) {
			return [];
		}

		$cards = $this->trendsRequest->cardsByUrls(array_column($counted, 'url'));

		$links = [];
		foreach ($this->trendReviewService->filterLinks($counted) as $link) {
			$card = $cards[$link['url']] ?? (new StreamCard())->setUrl($link['url']);
			$links[] = new TrendingLink($card, $link['shares']);
		}

		return $links;
	}

	/**
	 * The start of the window, as a unix time. An unknown period falls back to
	 * the default rather than being refused, which is what
	 * `HashtagService::getTrending()` does with the same parameter.
	 */
	private function since(string $period): int {
		$now = $this->timeFactory?->getTime() ?? time();

		return $now - (self::WINDOWS[$period] ?? self::WINDOWS[HashtagService::PERIOD_DEFAULT]);
	}

	private function limit(int $limit): int {
		return max(1, min(self::MAX_LIMIT, $limit));
	}
}
