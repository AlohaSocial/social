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
use OCA\Social\Tools\Nid;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

/** How the My interests feed ranks, spreads and pages. */
class InterestFeedServiceTest extends TestCase {
	private const NOW = 1790000000;
	private const HOUR = 3600;

	private array $profile = ['weights' => [], 'reasons' => [], 'thin' => false, 'top' => []];
	/** @var list<array{nid: string, author: string, tags: string[]}> every post there is */
	private array $posts = [];
	/** @var string[] posts that are gone by the time they are read */
	private array $gone = [];
	private array $cache = [];
	private bool $memory = true;
	/** @var array[] the arguments of every candidate query */
	private array $queries = [];

	private function nid(int $ageSeconds, int $n): string {
		return Nid::fromPublishedTime(self::NOW - $ageSeconds, $n, StreamRequest::NID_LIMIT);
	}

	private function given(int $ageSeconds, array $tags, string $author = 'a', int $n = 1): string {
		$nid = $this->nid($ageSeconds, $n);
		$this->posts[] = ['nid' => $nid, 'author' => $author, 'tags' => $tags];

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
			function (array $tags, string $since, int $cap, array $exclude): array {
				$this->queries[] = compact('tags', 'since', 'exclude');
				$rows = [];
				$posts = $this->posts;
				usort($posts, static fn (array $a, array $b): int => Nid::compare($b['nid'], $a['nid']));
				foreach ($posts as $post) {
					if (in_array($post['nid'], $exclude, true) || Nid::compare($post['nid'], $since) <= 0) {
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

		return new InterestFeedService($interests, $streamRequest, $streamService, $cacheFactory, $time);
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

	/** The ranked entries with their authors put back, for the author count. */
	private function withAuthors(array $ranked): array {
		$authors = array_column($this->posts, 'author', 'nid');

		return array_map(static fn (array $e): array => $e + ['author' => $authors[$e['nid']]], $ranked);
	}
}
