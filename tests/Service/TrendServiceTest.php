<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\TrendsRequest;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\StreamCard;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\TrendReviewService;
use OCA\Social\Service\TrendService;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * The window a trend is computed over, and what happens to a link whose
 * preview row has gone.
 *
 * The windows are the ones `HashtagService` already counts, so that a client
 * drawing an "explore" page from `/trends/tags`, `/trends/statuses` and
 * `/trends/links` is shown three views of the same stretch of time rather than
 * three unrelated ones.
 */
class TrendServiceTest extends TestCase {
	private TrendsRequest|Stub $trendsRequest;
	private TrendReviewService|Stub $trendReviewService;
	private TrendService $service;

	/** @var array{since: int, limit: int, offset: int}|null what the store was asked */
	private ?array $askedStatuses = null;
	private ?array $askedLinks = null;
	/** @var int[] the nids the store answers a status trend with */
	private array $nids = [];
	/** @var int[] the newest media this instance holds */
	private array $recent = [];
	private array $recentPublic = [];
	private ?array $askedRecentPublic = null;
	/** @var array{limit: int, media: string, excluding: int[]}|null what the top-up asked for */
	private ?array $askedRecent = null;
	/** @var array<array{url: string, shares: int}> the counted links */
	private array $links = [];
	/** @var array<string, StreamCard> the stored previews, by url */
	private array $cards = [];

	protected function setUp(): void {
		$this->trendReviewService = $this->createStub(TrendReviewService::class);
		$this->trendReviewService->method('filterLinks')->willReturnArgument(0);
		$this->trendReviewService->method('statusIsRejected')->willReturn(false);

		$this->trendsRequest = $this->createStub(TrendsRequest::class);

		$this->trendsRequest->method('trendingStatusNids')
			->willReturnCallback(function (int $since, int $limit, int $offset): array {
				$this->askedStatuses = ['since' => $since, 'limit' => $limit, 'offset' => $offset];

				return $this->nids;
			});
		$this->trendsRequest->method('statusesByNids')
			->willReturnCallback(static fn (array $nids): array => array_map(
				static function (int $nid): Stream {
					$note = new Note();
					$note->setNid($nid);

					return $note;
				}, $nids
			));

		$this->trendsRequest->method('recentMediaNids')
			->willReturnCallback(function (int $limit, string $mediaType, array $excluding): array {
				$this->askedRecent = ['limit' => $limit, 'media' => $mediaType, 'excluding' => $excluding];

				return array_slice($this->recent, 0, $limit);
			});

		$this->trendsRequest->method('recentPublicNids')
			->willReturnCallback(function (int $limit, array $excluding): array {
				$this->askedRecentPublic = ['limit' => $limit, 'excluding' => $excluding];
				return array_slice($this->recentPublic, 0, $limit);
			});
		$this->trendsRequest->method('trendingLinks')
			->willReturnCallback(function (int $since, int $limit, int $offset): array {
				$this->askedLinks = ['since' => $since, 'limit' => $limit, 'offset' => $offset];

				return $this->links;
			});
		$this->trendsRequest->method('cardsByUrls')
			->willReturnCallback(fn (): array => $this->cards);

		$this->service = new TrendService($this->trendReviewService, $this->trendsRequest);
	}

	private function card(string $url, string $title): StreamCard {
		return (new StreamCard())->setUrl($url)->setTitle($title);
	}

	public function testTheDefaultWindowIsTheOneTheHashtagTrendsDefaultTo(): void {
		$this->service->trendingStatuses(HashtagService::PERIOD_DEFAULT, TrendService::LIMIT, 0);

		$this->assertEqualsWithDelta(
			time() - HashtagService::TREND_1D, $this->askedStatuses['since'], 2
		);
	}

	public function testEachWindowOfTheHashtagTrendsIsAccepted(): void {
		$expected = [
			'1h' => HashtagService::TREND_1H,
			'12h' => HashtagService::TREND_12H,
			'1d' => HashtagService::TREND_1D,
			'3d' => HashtagService::TREND_3D,
			'10d' => HashtagService::TREND_10D,
		];

		foreach ($expected as $period => $seconds) {
			$this->service->trendingStatuses($period, TrendService::LIMIT, 0);
			$this->assertEqualsWithDelta(
				time() - $seconds, $this->askedStatuses['since'], 2, 'window ' . $period
			);
		}
	}

	/** An unknown window falls back, as `HashtagService::getTrending()` does. */
	public function testAnUnknownWindowFallsBackToTheDefault(): void {
		$this->service->trendingStatuses('since-tuesday', TrendService::LIMIT, 0);

		$this->assertEqualsWithDelta(
			time() - HashtagService::TREND_1D, $this->askedStatuses['since'], 2
		);
	}

	public function testThePageSizeIsCapped(): void {
		$this->service->trendingStatuses('1d', 5000, 0);

		$this->assertSame(TrendService::MAX_LIMIT, $this->askedStatuses['limit']);
	}

	public function testANegativeOffsetIsReadAsTheStart(): void {
		$this->service->trendingLinks('1d', TrendService::LIMIT, -5);

		$this->assertSame(0, $this->askedLinks['offset']);
	}

	public function testTheTrendOrderIsTheOrderTheStoreChose(): void {
		$this->nids = [9, 3, 7];

		$statuses = $this->service->trendingStatuses('1d', TrendService::LIMIT, 0);

		$this->assertSame([9, 3, 7], array_map(static fn (Stream $s): int => $s->getNid(), $statuses));
	}

	public function testAWindowWithNoInteractionsIsAnEmptyList(): void {
		$this->assertSame([], $this->service->trendingStatuses('1d', TrendService::LIMIT, 0));
		$this->assertSame([], $this->service->trendingLinks('1d', TrendService::LIMIT, 0));
	}

	public function testATrendingLinkCarriesTheStoredPreview(): void {
		$this->links = [['url' => 'https://example.org/a', 'shares' => 4]];
		$this->cards = ['https://example.org/a' => $this->card('https://example.org/a', 'An article')];

		$link = $this->service->trendingLinks('1d', TrendService::LIMIT, 0)[0]->jsonSerialize();

		$this->assertSame('https://example.org/a', $link['url']);
		$this->assertSame('An article', $link['title']);
		$this->assertSame('link', $link['type']);
	}

	/**
	 * A url that is still being shared but whose preview row went with the
	 * post it was fetched for is a bare link, not a missing row: dropping it
	 * would make the list disagree with the counts that built it.
	 */
	public function testALinkWithNoStoredPreviewIsStillListed(): void {
		$this->links = [['url' => 'https://example.org/gone', 'shares' => 2]];

		$links = $this->service->trendingLinks('1d', TrendService::LIMIT, 0);

		$this->assertCount(1, $links);
		$this->assertSame('https://example.org/gone', $links[0]->jsonSerialize()['url']);
		$this->assertSame('', $links[0]->jsonSerialize()['title']);
	}

	/**
	 * The same `history` shape a Tag carries, including `accounts` always `0`:
	 * this instance counts uses, not distinct accounts.
	 */
	public function testTheHistoryOfALinkIsTheShapeATagCarries(): void {
		$this->links = [['url' => 'https://example.org/a', 'shares' => 6]];

		$history = $this->service->trendingLinks('1d', TrendService::LIMIT, 0)[0]
			->jsonSerialize()['history'];

		$this->assertCount(1, $history);
		$this->assertSame(['day', 'accounts', 'uses'], array_keys($history[0]));
		$this->assertSame('6', $history[0]['uses']);
		$this->assertSame('0', $history[0]['accounts']);
	}

	public function testTheOrderOfTheLinksIsTheOrderTheStoreChose(): void {
		$this->links = [
			['url' => 'https://example.org/b', 'shares' => 9],
			['url' => 'https://example.org/a', 'shares' => 2],
		];

		$links = $this->service->trendingLinks('1d', TrendService::LIMIT, 0);

		$this->assertSame('https://example.org/b', $links[0]->jsonSerialize()['url']);
		$this->assertSame('https://example.org/a', $links[1]->jsonSerialize()['url']);
	}

	/**
	 * Trending needs an interaction inside the window, and a young instance
	 * has none — so the Pictures and Videos tabs were empty on exactly the
	 * instances whose readers most need something to look at, and empty in a
	 * way that reads as broken rather than as new.
	 */
	public function testAMediaGridWithNothingTrendingShowsTheNewestMediaInstead(): void {
		$this->nids = [];
		$this->recent = [9, 8, 7];

		$statuses = $this->service->trendingStatuses(HashtagService::PERIOD_DEFAULT, 3, 0, true, 'video');

		$this->assertSame([9, 8, 7], array_map(static fn (Stream $s): int => $s->getNid(), $statuses));
		$this->assertSame('video', $this->askedRecent['media']);
	}

	/** What is trending stays first and keeps its order; the rest is a top-up. */
	public function testTrendingComesFirstAndTheRestIsTheNewest(): void {
		$this->nids = [42];
		$this->recent = [9, 8];

		$statuses = $this->service->trendingStatuses(HashtagService::PERIOD_DEFAULT, 3, 0, true);

		$this->assertSame([42, 9, 8], array_map(static fn (Stream $s): int => $s->getNid(), $statuses));
		$this->assertSame(2, $this->askedRecent['limit'], 'it asked for more than it had room for');
		$this->assertSame([42], $this->askedRecent['excluding'], 'a top-up must not repeat the page');
	}

	public function testAFullPageOfTrendingIsNotToppedUp(): void {
		$this->nids = [1, 2, 3];
		$this->recent = [9];

		$statuses = $this->service->trendingStatuses(HashtagService::PERIOD_DEFAULT, 3, 0, true);

		$this->assertCount(3, $statuses);
		$this->assertNull($this->askedRecent);
	}

	/** A page deeper in is paging through trending and has no business being padded. */
	public function testASecondPageIsNotToppedUp(): void {
		$this->nids = [];
		$this->recent = [9, 8];

		$this->service->trendingStatuses(HashtagService::PERIOD_DEFAULT, 3, 20, true);

		$this->assertNull($this->askedRecent);
	}

	/**
	 * Only the media grids: the text trends are a list of what people are
	 * talking about, and padding it with the newest posts would say they are
	 * trending when they are not.
	 */
	/**
	 * The For you feed asks for the newest posts by name: they are not a
	 * trend and never come back from the trending pages.
	 */
	public function testTheNewestPublicPostsAreAskedForByNameAndSkipWhatIsShown(): void {
		$this->recentPublic = [9, 8, 7];

		$statuses = $this->service->newestPublic(2, [42]);

		$this->assertSame([9, 8], array_map(static fn (Stream $status): int => $status->getNid(), $statuses));
		$this->assertSame(['limit' => 2, 'excluding' => [42]], $this->askedRecentPublic);
	}

	public function testTheNewestPublicPostsLeaveOutWhatAModeratorRejected(): void {
		$this->recentPublic = [9, 8];
		$review = $this->createStub(TrendReviewService::class);
		$review->method('statusIsRejected')->willReturn(true);

		$statuses = (new TrendService($review, $this->trendsRequest))->newestPublic(5);

		$this->assertSame([], $statuses, 'a rejected post never reaches the feed through the top-up');
	}

	public function testTheTextTrendsAreNotPadded(): void {
		$this->nids = [];
		$this->recent = [9, 8];

		$this->service->trendingStatuses(HashtagService::PERIOD_DEFAULT, 3, 0);

		$this->assertNull($this->askedRecent);
	}
}
