<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\Watch;
use OCA\Social\Atproto\Moderation\Blocklist;
use OCA\Social\Atproto\Moderation\LabelerService;
use OCA\Social\Atproto\Reader\ActorMapper;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Reader\FeedPoller;
use OCA\Social\Atproto\Reader\LocalRecordResolver;
use OCA\Social\Atproto\Reader\NotificationPoller;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\AtprotoWatchRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Service\ImportService;
use OCA\Social\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class PollingBacklogTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const AUTHOR = 'did:plc:z72i7hdynmk6r22z27h6tvur';
	private const NOW = 1791453600;
	private AppViewClient $appView;
	private string $checkpoint;
	private array $stored = [];
	private array $scheduled = [];
	private int $failures = 0;

	protected function setUp(): void {
		$this->checkpoint = self::stamp(100);
	}

	public static function readers(): iterable {
		yield 'author feed' => [false];
		yield 'notifications' => [true];
	}

	#[DataProvider('readers')]
	public function testBacklogsResumeAcrossBoundedRunsAndEmptyPagesWithoutSkippingEvents(bool $notifications): void {
		$poller = $this->poller($notifications);
		$pages = [
			'' => $this->page($notifications, range(225, 176), 'second'),
			'second' => $this->page($notifications, [], 'third'),
			'third' => $this->page($notifications, range(175, 126), 'last'),
			'last' => $this->page($notifications, range(125, 99), 'older'),
		];
		$asked = [];
		$this->appView->method($notifications ? 'queryAs' : 'query')->willReturnCallback(function (...$args) use ($notifications, &$pages, &$asked): array {
			$params = $args[$notifications ? 3 : 1];
			$this->assertSame(50, $params['limit']);
			$cursor = $params['cursor'] ?? '';
			$asked[] = $cursor;

			return $pages[$cursor];
		});

		foreach ([50, 0, 50, 26] as $i => $count) {
			$result = $poller->poll(1);
			$this->assertSame($notifications || $i === 0 ? 1 : 2, $result['requests'], 'a feed walk reads its newest page too');
			$this->assertSame($count, $result[$notifications ? 'handled' : 'stored']);
		}
		$this->assertSame($notifications ? ['', 'second', 'third', 'last'] : ['', '', 'second', '', 'third', '', 'last'], $asked);
		$this->assertSame(array_map(self::uri(...), range(225, 100)), $this->stored);
		$this->assertSame(self::stamp(225), $this->checkpoint);
		$this->assertSame(array_fill(0, 4, $notifications ? 60 : 120), $this->scheduled);

		$pages[''] = $this->page($notifications, [226, 225], 'older');
		$poller->poll(1);
		$this->assertSame(self::uri(226), $this->stored[126]);
		$pages['older'] = $this->page($notifications, [99], '');
		$poller->poll(1);
		$this->assertSame(self::stamp(226), $this->checkpoint);
	}

	#[DataProvider('readers')]
	public function testAFailedContinuationRetriesTheSamePageBeforeCommittingTheWatermark(bool $notifications): void {
		$poller = $this->poller($notifications);
		$failedOnce = false;
		$this->appView->method($notifications ? 'queryAs' : 'query')->willReturnCallback(function (...$args) use ($notifications, &$failedOnce): array {
			$params = $args[$notifications ? 3 : 1];
			if (!isset($params['cursor'])) {
				return $this->page($notifications, [102], 'second');
			}
			$this->assertSame('second', $params['cursor']);
			if (!$failedOnce) {
				$failedOnce = true;
				throw new AtprotoException('Temporary AppView failure');
			}

			return $this->page($notifications, [101, 100], '');
		});
		$poller->poll(1);
		$pending = $this->checkpoint;
		$poller->poll(1);
		$this->assertSame($pending, $this->checkpoint);
		$this->assertSame(1, $this->failures);
		$poller->poll(1);
		$this->assertSame([self::uri(102), self::uri(101), self::uri(100)], $this->stored);
		$this->assertSame(self::stamp(102), $this->checkpoint);
	}

	#[DataProvider('readers')]
	public function testMalformedResponsesDoNotCompleteAPendingBacklog(bool $notifications): void {
		$poller = $this->poller($notifications);
		$this->appView->method($notifications ? 'queryAs' : 'query')->willReturnOnConsecutiveCalls($this->page($notifications, [102], 'second'), []);
		$poller->poll(1);
		$pending = $this->checkpoint;
		$poller->poll(1);
		$this->assertSame($pending, $this->checkpoint);
		$this->assertSame(1, $this->failures);
	}

	#[DataProvider('readers')]
	public function testAnInitialReadWalksTheBlueskyFeedButSnapshotsNotifications(bool $notifications): void {
		$this->checkpoint = '';
		$poller = $this->poller($notifications);
		$this->appView->method($notifications ? 'queryAs' : 'query')->willReturnCallback(function (...$args) use ($notifications): array {
			$params = $args[$notifications ? 3 : 1];
			if (!isset($params['cursor'])) {
				return $this->page($notifications, [102, 101], 'older');
			}
			$this->assertSame('older', $params['cursor']);

			return $this->page($notifications, [100, 99], '');
		});
		$poller->poll(1);
		if ($notifications) {
			$this->assertSame(self::stamp(102), $this->checkpoint);
			$this->assertSame([self::uri(102), self::uri(101)], $this->stored);
			return;
		}

		$this->assertStringContainsString('"since":""', $this->checkpoint, 'the first feed page retains an initial backfill checkpoint');
		$this->assertStringContainsString('"page":"older"', $this->checkpoint);
		$poller->poll(1);
		$this->assertSame(self::stamp(102), $this->checkpoint);
		$this->assertSame([self::uri(102), self::uri(101), self::uri(100), self::uri(99)], $this->stored);
	}

	/** An AppView that hands back the cursor it was given has no older page: the walk ends instead of sticking. */
	#[DataProvider('readers')]
	public function testARepeatedRemoteCursorEndsTheWalk(bool $notifications): void {
		$poller = $this->poller($notifications);
		$this->appView->method($notifications ? 'queryAs' : 'query')->willReturnCallback(function (...$args) use ($notifications): array {
			$params = $args[$notifications ? 3 : 1];

			return isset($params['cursor'])
				? $this->page($notifications, [102], 'second')
				: $this->page($notifications, [103], 'second');
		});
		$poller->poll(1);
		$this->assertStringContainsString('"page":"second"', $this->checkpoint);
		$poller->poll(1);
		$this->assertSame(self::stamp(103), $this->checkpoint);
		$this->assertSame(0, $this->failures);
		$this->assertContains(self::uri(102), $this->stored);
	}

	#[DataProvider('readers')]
	public function testDistinctEventsWithTheWatermarkTimestampAreNotSkipped(bool $notifications): void {
		$poller = $this->poller($notifications);
		$first = $this->page($notifications, [101, 100], 'same-time');
		$second = $this->page($notifications, [102, 99], 'older');
		$setTime = static function (array &$page, int $index, string $time) use ($notifications): void {
			if ($notifications) {
				$page['notifications'][$index]['indexedAt'] = $time;
			} else {
				$page['feed'][$index]['post']['indexedAt'] = $time;
			}
		};
		$setTime($first, 1, self::stamp(100));
		$setTime($second, 0, self::stamp(100));
		$asked = [];
		$this->appView->method($notifications ? 'queryAs' : 'query')->willReturnCallback(function (...$args) use ($notifications, $first, $second, &$asked): array {
			$params = $args[$notifications ? 3 : 1];
			$cursor = $params['cursor'] ?? '';
			$asked[] = $cursor;

			return $cursor === '' ? $first : $second;
		});

		$poller->poll(1);
		$this->assertStringContainsString('same-time', $this->checkpoint, 'the first page must retain its continuation cursor');
		$poller->poll(1);

		$this->assertSame($notifications ? ['', 'same-time'] : ['', '', 'same-time'], $asked);
		$this->assertSame([self::uri(101), self::uri(100), self::uri(102)], $this->stored);
		$this->assertSame(self::stamp(101), $this->checkpoint);
	}

	/** A new watch reads back no further than BACKFILL_AGE. */
	public function testAFirstFeedReadStopsAtTheAgeLimit(): void {
		$this->checkpoint = '';
		$poller = $this->poller(false);
		$floor = -FeedPoller::BACKFILL_AGE;
		$this->appView->method('query')->willReturnCallback(function (string $method, array $params) use ($floor): array {
			return isset($params['cursor'])
				? $this->page(false, [$floor + 10, $floor - 10, $floor - 20], 'older-still')
				: $this->page(false, [100, 50], 'older');
		});
		$poller->poll(1);
		$poller->poll(1);

		$this->assertSame(self::stamp(100), $this->checkpoint, 'committed at the age limit');
		$this->assertSame([self::uri(100), self::uri(50), self::uri($floor + 10)], $this->stored);
	}

	/** Nor more than WALK_PAGES pages, however much history is within the age limit. */
	public function testAFeedWalkCommitsAfterItsPageLimit(): void {
		$this->checkpoint = '';
		$poller = $this->poller(false);
		$asked = [];
		$this->appView->method('query')->willReturnCallback(function (string $method, array $params) use (&$asked): array {
			$cursor = $params['cursor'] ?? '';
			$asked[] = $cursor;
			$page = $cursor === '' ? 0 : (int)substr($cursor, 1);

			return $this->page(false, [100 - $page], 'p' . ($page + 1));
		});
		for ($run = 0; $run < FeedPoller::WALK_PAGES + 2; $run++) {
			$poller->poll(1);
		}

		$this->assertSame(self::stamp(100), $this->checkpoint);
		$walk = [''];
		for ($page = 1; $page < FeedPoller::WALK_PAGES; $page++) {
			array_push($walk, '', 'p' . $page);
		}
		$this->assertSame($walk, array_slice($asked, 0, count($walk)), 'the first page, then each older one beside the newest');
		$this->assertNotContains('p' . FeedPoller::WALK_PAGES, $asked, 'no page past the limit');
	}

	/** Posts written while a walk is unfinished are stored on the next pass, not after it. */
	public function testNewPostsArriveWhileAWalkIsUnfinished(): void {
		$poller = $this->poller(false);
		$head = $this->page(false, [150, 149], 'older');
		$this->appView->method('query')->willReturnCallback(function (string $method, array $params) use (&$head): array {
			return match ($params['cursor'] ?? '') {
				'' => $head,
				'older' => $this->page(false, [148], 'oldest'),
				'oldest' => $this->page(false, [101, 99], ''),
			};
		});
		$poller->poll(1);
		$this->assertStringContainsString('"page":"older"', $this->checkpoint);

		$head = $this->page(false, [152, 151, 150], 'older');
		$poller->poll(1);
		$this->assertSame([self::uri(150), self::uri(149), self::uri(152), self::uri(151), self::uri(148)], $this->stored, 'the new posts before the next older page');
		$poller->poll(1);
		$this->assertSame(self::stamp(152), $this->checkpoint, 'the newest page reached the walk, so its newest post is committed');
	}

	/** A newest page that does not reach the walk leaves a gap, read by the walk after. */
	public function testANewestPageThatDoesNotReachTheWalkIsNotCommitted(): void {
		$poller = $this->poller(false);
		$head = $this->page(false, [150], 'older');
		$this->appView->method('query')->willReturnCallback(function (string $method, array $params) use (&$head): array {
			return match ($params['cursor'] ?? '') {
				'' => $head,
				'older' => $this->page(false, [99], 'oldest'),
				'gap' => $this->page(false, [151], ''),
			};
		});
		$poller->poll(1);

		$head = $this->page(false, [170, 169], 'gap');
		$poller->poll(1);
		$this->assertSame(self::stamp(150), $this->checkpoint, 'the walk commits only what it covered');
		$this->assertContains(self::uri(170), $this->stored);
		$poller->poll(1);
		$this->assertStringContainsString('"page":"gap"', $this->checkpoint, 'the next walk reads the gap');
		$poller->poll(1);
		$this->assertContains(self::uri(151), $this->stored);
		$this->assertSame(self::stamp(170), $this->checkpoint);
	}

	private function poller(bool $notifications): FeedPoller|NotificationPoller {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$config->method('syncCeiling')->willReturn(1);
		$this->appView = $this->createMock(AppViewClient::class);
		$watches = $this->createMock(AtprotoWatchRequest::class);
		$watches->method('getDue')->willReturnCallback(fn (): array => [new Watch(self::DID, 'alice.test', $this->checkpoint, self::NOW - 600, self::NOW, 0, '', 0)]);
		$watches->method('synced')->willReturnCallback(function (string $did, string $cursor, int $now, int $next): void {
			$this->checkpoint = $cursor;
			$this->scheduled[] = $next - $now;
		});
		$watches->method('failed')->willReturnCallback(function (): void {
			$this->failures++;
		});
		$store = $this->createMock(PostStore::class);
		$store->method('storeFeedItem')->willReturnCallback(function (array $item): int {
			$this->stored[] = $item['post']['uri'];

			return 1;
		});
		$store->method('storeByUri')->willReturnCallback(function (string $uri): bool {
			$this->stored[] = $uri;

			return true;
		});
		$actors = $this->createMock(BlueskyActorService::class);
		$blocklist = $this->createMock(Blocklist::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		if (!$notifications) {
			return new FeedPoller($config, $watches, $this->appView, $store, $actors, $blocklist, $this->createMock(LabelerService::class), $time, new NullLogger());
		}
		$identity = new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', 'sealed', '', '', Identity::STATE_ACTIVE, '', 0);
		$identities = $this->createMock(IdentityService::class);
		$identities->method('getByDid')->willReturn($identity);
		$identities->method('getAll')->willReturn([$identity]);
		$identities->method('signingKey')->willReturn(PrivateKey::generate(Curve::K256));

		return new NotificationPoller($config, $identities, $watches, $this->appView, $store, $this->createMock(LocalRecordResolver::class), new ActorMapper(), $actors, $blocklist, $this->createMock(ImportService::class), $this->createMock(NotificationService::class), $time, new NullLogger());
	}

	private function page(bool $notifications, array $ids, string $next): array {
		$items = [];
		foreach ($ids as $id) {
			$post = ['uri' => self::uri($id), 'indexedAt' => self::stamp($id), 'author' => ['did' => self::AUTHOR]];
			$items[] = $notifications ? $post + ['reason' => 'reply'] : ['post' => $post];
		}

		return [$notifications ? 'notifications' : 'feed' => $items, 'cursor' => $next];
	}

	private static function stamp(int $id): string {
		return gmdate('Y-m-d\TH:i:s.000\Z', self::NOW + $id);
	}

	private static function uri(int $id): string {
		return 'at://' . self::AUTHOR . '/app.bsky.feed.post/p' . $id;
	}
}
