<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\InterestFeedService;
use OCA\Social\Service\InterestScorer;
use OCA\Social\Service\InterestService;
use OCA\Social\Service\StreamService;
use OCA\Social\Service\TrendService;
use OCA\Social\Tools\Nid;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

/** How the For you feed ranks, spreads and pages. */
class InterestFeedServiceTest extends TestCase {
	private const NOW = 1790000000;
	private const HOUR = 3600;

	private array $profile = ['weights' => [], 'reasons' => [], 'thin' => false, 'top' => []];
	/** @var list<array{nid: string, author: string, tags: string[], media: string}> every post there is */
	private array $posts = [];
	/** @var list<array{nid: string, author: string}> what TrendService calls trending */
	private array $trending = [];
	/** @var string[] the kind every trending question asked for */
	private array $trendKinds = [];
	/** @var list<array{nid: string, author: string}> */
	private array $newest = [];
	private ?array $askedNewest = null;
	/** @var string[] posts that are gone by the time they are read */
	private array $gone = [];
	private array $cache = [];
	private bool $memory = true;
	/** @var array[] the arguments of every candidate query */
	private array $queries = [];

	private function nid(int $ageSeconds, int $n): string {
		return Nid::fromPublishedTime(self::NOW - $ageSeconds, $n, StreamRequest::NID_LIMIT);
	}

	private function given(int $ageSeconds, array $tags, string $author = 'a', int $n = 1, string $media = ''): string {
		$nid = $this->nid($ageSeconds, $n);
		$this->posts[] = ['nid' => $nid, 'author' => $author, 'tags' => $tags, 'media' => $media];

		return $nid;
	}

	private function service(): InterestFeedService {
		$interests = $this->createStub(InterestService::class);
		$interests->method('feedProfile')->willReturnCallback(fn (): array => $this->profile);
		$interests->method('scorer')->willReturn(new InterestScorer());
		$interests->method('windowDays')->willReturn(7);
		$interests->method('hiddenFor')->willReturn([]);
		$interests->method('languagesFor')->willReturn([]);

		$streamRequest = $this->createStub(StreamRequest::class);
		$streamRequest->method('interestCandidates')->willReturnCallback(
			function (array $tags, string $since, int $cap, array $exclude, array $languages = [], string $media = ''): array {
				$this->queries[] = compact('tags', 'since', 'exclude', 'media');
				$rows = [];
				$posts = $this->posts;
				usort($posts, static fn (array $a, array $b): int => Nid::compare($b['nid'], $a['nid']));
				foreach ($posts as $post) {
					if (in_array($post['nid'], $exclude, true) || Nid::compare($post['nid'], $since) <= 0) {
						continue;
					}
					// what the query's media filter does, by the kind each post is
					if (($media === 'media' && $post['media'] === '') || !in_array($media, ['', 'media', $post['media']], true)) {
						continue;
					}
					foreach (array_intersect($post['tags'], $tags) as $tag) {
						$rows[] = ['nid' => $post['nid'], 'idPrim' => 'p' . $post['nid'], 'tag' => $tag, 'author' => $post['author']];
					}
				}

				return array_slice($rows, 0, $cap);
			}
		);
		$streamRequest->method('hashtagsOfStreams')->willReturnCallback(function (array $prims): array {
			$tags = [];
			foreach ($this->posts as $post) {
				if (in_array('p' . $post['nid'], $prims, true)) {
					$tags['p' . $post['nid']] = $post['tags'];
				}
			}

			return $tags;
		});

		$streamService = $this->createStub(StreamService::class);
		$streamService->method('visiblePosts')->willReturnCallback(function (array $nids): array {
			$posts = [];
			foreach ($nids as $nid) {
				if (!in_array($nid, $this->gone, true)) {
					$note = new Note();
					$note->setNid($nid);
					$posts[$nid] = $note;
				}
			}

			return $posts;
		});

		$cache = $this->createStub(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key) => $this->memory ? ($this->cache[$key] ?? null) : null);
		$cache->method('set')->willReturnCallback(function (string $key, $value): bool {
			$this->cache[$key] = $value;

			return true;
		});
		$cacheFactory = $this->createStub(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$trends = $this->createStub(TrendService::class);
		$trends->method('trendingStatuses')->willReturnCallback(
			function (string $period, int $limit, int $offset, bool $onlyMedia, string $mediaType): array {
				$this->trendKinds[] = $mediaType;

				return array_map(static function (array $post): Note {
					$note = new Note();
					$note->setNid($post['nid']);
					$note->setAttributedTo($post['author']);

					return $note;
				}, array_slice($this->trending, 0, $limit));
			}
		);
		$trends->method('newestPublic')->willReturnCallback(
			function (int $limit, array $excluding): array {
				$this->askedNewest = ['limit' => $limit, 'excluding' => $excluding];

				return array_map(static function (array $post): Note {
					$note = new Note();
					$note->setNid($post['nid']);
					$note->setAttributedTo($post['author']);

					return $note;
				}, array_slice($this->newest, 0, $limit));
			}
		);

		return new InterestFeedService($interests, $streamRequest, $streamService, $trends, $cacheFactory, $time);
	}

	private function viewer(): Person {
		return (new Person())->setId('https://cloud.example/users/alice')->setUserId('alice');
	}

	private function weights(array $weights, array $reasons = []): void {
		$this->profile['weights'] = $weights;
		$this->profile['reasons'] = $reasons + array_fill_keys(array_keys($weights), 'interest');
	}

	public function testAGoodMatchFromYesterdayLosesToAPerfectOneFromThisMorning(): void {
		$this->weights(['cats' => 1.0, 'dogs' => 0.5]);
		$weakNew = $this->given(1 * self::HOUR, ['dogs'], 'a', 1);
		$strongNew = $this->given(2 * self::HOUR, ['cats'], 'b', 2);
		$strongOld = $this->given(30 * self::HOUR, ['cats'], 'c', 3);

		$this->assertSame(
			[$strongNew, $weakNew, $strongOld],
			array_column($this->service()->rank($this->viewer()), 'nid')
		);
	}

	public function testAPostTheReaderTurnedAwayFromDropsOut(): void {
		$this->weights(['cats' => 0.4, 'politics' => InterestScorer::NEGATIVE_WEIGHT]);
		$kept = $this->given(self::HOUR, ['cats'], 'a', 1);
		$this->given(self::HOUR, ['cats', 'politics'], 'b', 2);

		$this->assertSame([$kept], array_column($this->service()->rank($this->viewer()), 'nid'));
	}

	public function testEveryPostSaysWhichTagsMatchedBestFirstAndWhy(): void {
		$this->weights(['cats' => 0.5, 'nextcloud' => 1.0], ['nextcloud' => 'followed']);
		$this->given(self::HOUR, ['cats', 'nextcloud', 'unrelated']);

		$ranked = $this->service()->rank($this->viewer());

		$this->assertSame(['nextcloud', 'cats'], $ranked[0]['tags']);
		$this->assertSame('followed', $ranked[0]['reason']);
	}

	public function testNoAuthorHasMoreThanTwoOfAnyTwenty(): void {
		$this->weights(['cats' => 1.0, 'dogs' => 1.0]);
		foreach (range(1, 6) as $i) {
			$this->given($i * 60, ['cats'], 'prolific', $i);
		}
		foreach (range(1, 20) as $i) {
			$this->given(10 * self::HOUR + $i * 60, [$i % 2 === 0 ? 'cats' : 'dogs'], 'other' . $i, 100 + $i);
		}

		$ranked = $this->service()->rank($this->viewer());
		$authors = array_count_values(array_column(array_slice($this->withAuthors($ranked), 0, 20), 'author'));

		$this->assertSame(2, $authors['prolific']);
		$this->assertCount(26, $ranked, 'reordered, never shortened');
	}

	public function testNoOneTagHasMoreThanTwoFifthsOfAnyTwenty(): void {
		// three tags: with two, two fifths each cannot fill twenty slots
		$this->weights(['cats' => 1.0, 'dogs' => 0.2, 'birds' => 0.2]);
		foreach (range(1, 20) as $i) {
			$this->given($i * 60, ['cats'], 'c' . $i, $i);
			$this->given(self::HOUR + $i * 60, ['dogs'], 'd' . $i, 100 + $i);
			$this->given(self::HOUR + $i * 60, ['birds'], 'b' . $i, 200 + $i);
		}

		$first = array_slice($this->service()->rank($this->viewer()), 0, 20);
		$tags = array_count_values(array_map(static fn (array $e): string => $e['tags'][0], $first));

		$this->assertSame(8, $tags['cats']);
	}

	public function testOneSlotInTenIsATagThatKeepsCompanyWithTheReadersOwn(): void {
		$this->weights(['cats' => 1.0]);
		foreach (range(1, 12) as $i) {
			$this->given($i * 60, ['cats', 'kittens'], 'a' . $i, $i);
		}
		$related = $this->given(2 * self::HOUR, ['kittens'], 'someone', 99);

		$ranked = $this->service()->rank($this->viewer());

		$this->assertSame($related, $ranked[9]['nid']);
		$this->assertSame(['tags' => ['kittens'], 'reason' => 'related'], ['tags' => $ranked[9]['tags'], 'reason' => $ranked[9]['reason']]);
	}

	public function testThereIsNoExplorationWhileLearningIsThin(): void {
		$this->weights(['cats' => 1.0]);
		$this->profile['thin'] = true;
		foreach (range(1, 12) as $i) {
			$this->given($i * 60, ['cats', 'kittens'], 'a' . $i, $i);
		}
		$this->given(2 * self::HOUR, ['kittens'], 'someone', 99);

		$this->assertCount(12, $this->service()->rank($this->viewer()));
	}

	public function testNothingToGoOnIsAnEmptyFeed(): void {
		$this->given(60, ['cats']);

		$this->assertSame([], $this->service()->rank($this->viewer()));
		$this->assertSame([], $this->queries, 'and it did not ask the database');
	}

	public function testTheFeedLooksBackOnlyAsFarAsTheWindow(): void {
		$this->weights(['cats' => 1.0]);
		$this->given(60, ['cats'], 'a', 1);
		$this->given(8 * 86400, ['cats'], 'b', 2);

		$this->assertCount(1, $this->service()->rank($this->viewer()));
	}

	public function testPagingByTheLastPostContinuesTheSameRanking(): void {
		$this->weights(['cats' => 1.0]);
		$nids = [];
		foreach (range(1, 5) as $i) {
			$nids[] = $this->given($i * 60, ['cats'], 'a' . $i, $i);
		}
		$service = $this->service();

		$first = $service->page($this->viewer(), 2);
		$this->assertSame(array_slice($nids, 0, 2), array_map(static fn (Note $p): string => (string)$p->getNid(), $first));
		$this->assertSame(['tags' => ['cats'], 'reason' => 'interest'], $first[0]->getInterest());

		// a newer post arrives; the ranking being paged through does not move
		$this->given(1, ['cats'], 'late', 50);
		$second = $service->page($this->viewer(), 2, (string)$first[1]->getNid());
		$this->assertSame(array_slice($nids, 2, 2), array_map(static fn (Note $p): string => (string)$p->getNid(), $second));

		$this->assertSame([], $service->page($this->viewer(), 2, '12345'), 'a cursor that is not in the ranking ends it');
	}

	public function testWithoutAMemoryCacheTheRankingIsMadeAgainAndStillPages(): void {
		$this->memory = false;
		$this->weights(['cats' => 1.0]);
		$nids = [];
		foreach (range(1, 4) as $i) {
			$nids[] = $this->given($i * 60, ['cats'], 'a' . $i, $i);
		}

		$second = $this->service()->page($this->viewer(), 2, $nids[1]);

		$this->assertSame(array_slice($nids, 2, 2), array_map(static fn (Note $p): string => (string)$p->getNid(), $second));
	}

	public function testAPostThatWentAwayIsLeftOutOfItsPage(): void {
		$this->weights(['cats' => 1.0]);
		$kept = $this->given(60, ['cats'], 'a', 1);
		$this->gone[] = $this->given(120, ['cats'], 'b', 2);

		$this->assertSame([$kept], array_map(static fn (Note $p): string => (string)$p->getNid(), $this->service()->page($this->viewer(), 20)));
	}

	public function testEachKindIsARankingOfItsOwn(): void {
		$this->weights(['cats' => 1.0]);
		$text = $this->given(60, ['cats'], 'a', 1);
		$photo = $this->given(120, ['cats'], 'b', 2, 'photos');
		$video = $this->given(180, ['cats'], 'c', 3, 'videos');
		$service = $this->service();

		$this->assertSame([$text, $photo, $video], array_column($service->rank($this->viewer()), 'nid'));
		$this->assertSame([$photo], array_column($service->rank($this->viewer(), 'photos'), 'nid'));
		$this->assertSame([$video], array_column($service->rank($this->viewer(), 'videos'), 'nid'));
		$this->assertSame([$photo, $video], array_column($service->rank($this->viewer(), 'media'), 'nid'));
	}

	/**
	 * Kept under three keys: a cursor from one ranking is not a place in
	 * another, and a photo page must not overwrite the text one being paged.
	 */
	public function testTheRankingsAreKeptApartInTheCache(): void {
		$this->weights(['cats' => 1.0]);
		$texts = [];
		foreach (range(1, 3) as $i) {
			$texts[] = $this->given($i * 60, ['cats'], 't' . $i, $i);
		}
		$photos = [];
		foreach (range(1, 3) as $i) {
			$photos[] = $this->given(self::HOUR + $i * 60, ['cats'], 'p' . $i, 10 + $i, 'photos');
		}
		$service = $this->service();

		$service->page($this->viewer(), 2);
		$service->page($this->viewer(), 2, '0', 0, 'photos');

		$viewerId = $this->viewer()->getId();
		$this->assertArrayHasKey(InterestFeedService::snapshotKey($viewerId, ''), $this->cache);
		$this->assertArrayHasKey(InterestFeedService::snapshotKey($viewerId, 'photos'), $this->cache);
		$this->assertNotSame(InterestFeedService::snapshotKey($viewerId, ''), InterestFeedService::snapshotKey($viewerId, 'photos'));
		$this->assertNotSame(InterestFeedService::snapshotKey($viewerId, 'photos'), InterestFeedService::snapshotKey($viewerId, 'videos'));

		$ids = static fn (array $page): array => array_map(static fn (Note $p): string => (string)$p->getNid(), $page);
		// the whole feed holds the photos too, after the newer texts
		$this->assertSame([$texts[2], $photos[0]], $ids($service->page($this->viewer(), 2, $texts[1])));
		$this->assertSame([$photos[2]], $ids($service->page($this->viewer(), 2, $photos[1], 0, 'photos')));
		$this->assertSame([], $service->page($this->viewer(), 2, $texts[1], 0, 'photos'), 'a text cursor is not in the photos');
	}

	public function testAnUnknownKindIsTheWholeFeed(): void {
		$this->weights(['cats' => 1.0]);
		$text = $this->given(60, ['cats'], 'a', 1);

		$this->service()->page($this->viewer(), 20, '0', 0, 'podcasts');

		$this->assertSame('', $this->queries[0]['media']);
		$this->assertArrayHasKey(InterestFeedService::snapshotKey($this->viewer()->getId(), ''), $this->cache);
		$this->assertSame([$text], array_column($this->service()->rank($this->viewer(), 'podcasts'), 'nid'));
	}

	public function testAMediaRankingLooksTwiceAsFarBack(): void {
		$this->weights(['cats' => 1.0]);
		$this->given(10 * 86400, ['cats'], 'a', 1);
		$photo = $this->given(10 * 86400, ['cats'], 'b', 2, 'photos');
		$this->given(15 * 86400, ['cats'], 'c', 3, 'photos');

		$this->assertSame([$photo], array_column($this->service()->rank($this->viewer(), 'photos'), 'nid'));
		$this->assertSame([], $this->service()->rank($this->viewer()), 'the whole feed keeps its week');
	}

	/**
	 * The plain feed is filled the same way: what is trending first, and on
	 * an instance where nothing is, the newest public posts — so a newcomer's
	 * For you is never an empty page while the instance has anything.
	 */
	public function testAShortPlainRankingIsFilledWithWhatIsPopularThenTheNewest(): void {
		$this->weights(['cats' => 1.0]);
		$matched = $this->given(60, ['cats'], 'b');
		$trending = $this->nid(120, 2);
		$newest = $this->nid(30, 3);
		$this->trending = [['nid' => $trending, 'author' => 'c']];
		$this->newest = [
			['nid' => $trending, 'author' => 'c'],
			['nid' => $this->nid(90, 4), 'author' => $this->viewer()->getId()],
			['nid' => $newest, 'author' => 'd'],
		];

		$ranked = $this->service()->rank($this->viewer());

		$this->assertSame([$matched, $trending, $newest], array_column($ranked, 'nid'));
		$this->assertSame(['interest', 'popular', 'popular'], array_column($ranked, 'reason'));
		$this->assertSame([''], $this->trendKinds, 'the plain feed asks for trending of every kind');
		$this->assertContains($matched, $this->askedNewest['excluding']);
		$this->assertContains($trending, $this->askedNewest['excluding']);
	}

	/** A narrowed ranking never asks for the newest posts: its trending is already topped up with media. */
	public function testANarrowedRankingDoesNotAskForTheNewestPosts(): void {
		$this->weights(['cats' => 1.0]);
		$this->trending = [['nid' => $this->nid(120, 2), 'author' => 'c']];

		$this->service()->rank($this->viewer(), 'photos');

		$this->assertNull($this->askedNewest);
	}

	/**
	 * Short of a screenful, a photo ranking is topped up with what is trending
	 * in photos — each marked popular, none twice, none the reader's own.
	 */
	public function testAShortMediaRankingIsFilledWithWhatIsPopular(): void {
		$this->weights(['cats' => 1.0]);
		$matched = $this->given(60, ['cats'], 'b', 1, 'photos');
		$popular = $this->nid(120, 2);
		$this->trending = [
			['nid' => $matched, 'author' => 'b'],
			['nid' => $this->nid(90, 3), 'author' => $this->viewer()->getId()],
			['nid' => $popular, 'author' => 'c'],
		];

		$ranked = $this->service()->rank($this->viewer(), 'photos');

		$this->assertSame([$matched, $popular], array_column($ranked, 'nid'));
		$this->assertSame('interest', $ranked[0]['reason']);
		$this->assertSame(['nid' => $popular, 'tags' => [], 'reason' => 'popular'], $ranked[1]);
		$this->assertSame(['image'], $this->trendKinds);
	}

	public function testANewcomerStillGetsPopularVideos(): void {
		$popular = $this->nid(60, 1);
		$this->trending = [['nid' => $popular, 'author' => 'c']];

		$this->assertSame([['nid' => $popular, 'tags' => [], 'reason' => 'popular']], $this->service()->rank($this->viewer(), 'videos'));
		$this->assertSame(['video'], $this->trendKinds);
		$this->assertSame([], $this->queries, 'no interests to ask the database about');
	}

	/**
	 * A reader with no interests yet used to get an empty plain feed; it is
	 * filled with what is popular, like a narrowed one, so the page is never
	 * blank while the instance has anything to show.
	 */
	public function testAnEmptyPlainFeedIsFilledWithWhatIsPopular(): void {
		$popular = $this->nid(60, 1);
		$this->trending = [['nid' => $popular, 'author' => 'c']];

		$this->assertSame([['nid' => $popular, 'tags' => [], 'reason' => 'popular']], $this->service()->rank($this->viewer()));
		$this->assertSame([''], $this->trendKinds);
	}

	public function testAFullMediaRankingIsLeftAlone(): void {
		$this->weights(['cats' => 1.0]);
		foreach (range(1, InterestFeedService::POPULAR_FILL) as $i) {
			$this->given($i * 60, ['cats'], 'a' . $i, $i, 'videos');
		}
		$this->trending = [['nid' => $this->nid(1, 999), 'author' => 'c']];

		$ranked = $this->service()->rank($this->viewer(), 'videos');

		$this->assertCount(InterestFeedService::POPULAR_FILL, $ranked);
		$this->assertNotContains('popular', array_column($ranked, 'reason'));
		$this->assertSame([], $this->trendKinds);
	}

	/** The ranked entries with their authors put back, for the author count. */
	private function withAuthors(array $ranked): array {
		$authors = array_column($this->posts, 'author', 'nid');

		return array_map(static fn (array $e): array => $e + ['author' => $authors[$e['nid']]], $ranked);
	}
}
