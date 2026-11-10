<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\Atproto\Reader\DeletionSweep;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DurableCache;
use OCA\Social\Tests\Helper\InMemoryDurableCacheRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class DeletionSweepTest extends TestCase {
	private const NOW = 1760000000;

	/** @var array<string, string> app values */
	private array $values = [];
	private int $now = self::NOW;
	/** @var StreamRequest&MockObject */
	private StreamRequest $streams;
	/** @var PostStore&MockObject */
	private PostStore $store;
	private DurableCache $durableCache;

	protected function setUp(): void {
		$this->values = [
			ConfigService::ATPROTO_DELETE_CURSOR => '0',
			ConfigService::ATPROTO_DELETE_MAX_NID => '0',
			ConfigService::ATPROTO_DELETE_RECENT_CURSOR => '0',
		];
		$this->streams = $this->createMock(StreamRequest::class);
		$this->store = $this->createMock(PostStore::class);
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);
		$this->durableCache = new DurableCache($factory, new InMemoryDurableCacheRequest(), $this->clock());
	}

	private function clock(): ITimeFactory {
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return $time;
	}

	private function sweep(): DeletionSweep {
		$config = $this->createMock(ConfigService::class);
		$config->method('getAppValue')->willReturnCallback(fn (string $key): string => $this->values[$key] ?? '');
		$config->method('setAppValue')->willReturnCallback(function (string $key, string $value): void {
			$this->values[$key] = $value;
		});

		return new DeletionSweep($this->streams, $this->store, $config, $this->clock(), $this->durableCache);
	}

	/**
	 * @param array<int, string> $full what the slow pass finds after nid 0 below its ceiling
	 * @param list<array<int, string>> $recent the fast pass's pages, by position
	 */
	private function posts(int $max, array $full, array $recent = []): void {
		$this->streams->method('getBlueskyPostMaxNid')->willReturn($max);
		$this->streams->method('getBlueskyPostIds')->willReturnCallback(function (int $since, int $after, int $limit, ?int $through = null) use ($max, $full, $recent): array {
			if ($through !== null) {
				$this->assertSame(0, $since, 'the slow pass covers the whole history');
				$this->assertSame($max, $through);

				return array_filter($full, static fn (int $nid): bool => $nid > $after, ARRAY_FILTER_USE_KEY);
			}
			$this->assertSame(self::NOW - DeletionSweep::RECENT_WINDOW, $since, 'the fast pass covers the last week');
			foreach ($recent as $page) {
				if (min(array_keys($page)) > $after) {
					return $page;
				}
			}

			return [];
		});
	}

	public function testAnUnansweredAppViewDoesNotAdvanceTheDeletionCursor(): void {
		$this->posts(11, [11 => 'https://bsky.app/profile/did:plc:a/post/1']);
		$this->store->expects($this->once())->method('deleteGone')->willReturn(null);

		$this->assertSame(0, $this->sweep()->run());
		$this->assertSame('0', $this->values[ConfigService::ATPROTO_DELETE_CURSOR], 'an incomplete AppView response keeps the current page retryable');
	}

	public function testAPageIsCheckedAndThePlaceKeptThenItStartsOver(): void {
		$this->posts(17, [11 => 'https://bsky.app/profile/did:plc:a/post/1', 17 => 'https://bsky.app/profile/did:plc:a/post/2']);
		$this->store->expects($this->once())->method('deleteGone')->with(['https://bsky.app/profile/did:plc:a/post/1', 'https://bsky.app/profile/did:plc:a/post/2'])->willReturn(1);
		$sweep = $this->sweep();

		$this->assertSame(1, $sweep->run());
		$this->assertSame('17', $this->values[ConfigService::ATPROTO_DELETE_CURSOR]);
		$this->assertSame(0, $sweep->run(), 'the end: nothing left this round');
		$this->assertSame('0', $this->values[ConfigService::ATPROTO_DELETE_CURSOR], 'the next run starts over');
		$this->assertSame('0', $this->values[ConfigService::ATPROTO_DELETE_MAX_NID], 'a completed bounded cycle releases its snapshot ceiling');
	}

	public function testAConfirmedPageAdvancesEvenWhenNoPostWasDeleted(): void {
		$this->posts(11, [11 => 'https://bsky.app/profile/did:plc:a/post/1']);
		$this->store->expects($this->once())->method('deleteGone')->willReturn(0);

		$this->assertSame(0, $this->sweep()->run());
		$this->assertSame('11', $this->values[ConfigService::ATPROTO_DELETE_CURSOR], 'a live or inconclusive individual post does not block later pages');
	}

	public function testAnInconclusivePostIsRetriedAfterTheCursorCompletesARound(): void {
		$this->posts(11, [11 => 'https://bsky.app/profile/did:plc:a/post/1']);
		$this->store->expects($this->exactly(2))->method('deleteGone')
			->with(['https://bsky.app/profile/did:plc:a/post/1'])
			->willReturnOnConsecutiveCalls(0, 1);
		$sweep = $this->sweep();

		$this->assertSame(0, $sweep->run(), 'an inconclusive PDS lookup does not delete the post');
		$this->assertSame('11', $this->values[ConfigService::ATPROTO_DELETE_CURSOR]);
		$this->assertSame(0, $sweep->run(), 'the sweep reaches the end and resets its cursor');
		$this->assertSame('0', $this->values[ConfigService::ATPROTO_DELETE_CURSOR]);
		$this->assertSame(1, $sweep->run(), 'the next round checks the previously inconclusive post again');
	}

	/** The last week is checked several pages a run, beside the slow page of the whole history. */
	public function testTheRecentWindowIsWalkedSeveralPagesARunAndStartsOverAtItsEnd(): void {
		$recent = [];
		for ($page = 0; $page < DeletionSweep::RECENT_PAGES + 2; $page++) {
			$recent[] = [1000 + $page * 10 => 'https://bsky.app/profile/did:plc:a/post/r' . $page];
		}
		$this->posts(5000, [1 => 'https://bsky.app/profile/did:plc:a/post/old'], $recent);
		$checked = [];
		$this->store->method('deleteGone')->willReturnCallback(function (array $ids) use (&$checked): int {
			$checked[] = $ids[0];

			return 1;
		});
		$sweep = $this->sweep();

		$this->assertSame(1 + DeletionSweep::RECENT_PAGES, $sweep->run());
		$this->assertSame('https://bsky.app/profile/did:plc:a/post/old', $checked[0], 'the slow page first');
		$this->assertCount(1 + DeletionSweep::RECENT_PAGES, $checked);
		$this->assertSame((string)(1000 + (DeletionSweep::RECENT_PAGES - 1) * 10), $this->values[ConfigService::ATPROTO_DELETE_RECENT_CURSOR]);

		$this->assertSame(2, $sweep->run(), 'the rest of the window, the slow pass finding its end');
		$this->assertSame('0', $this->values[ConfigService::ATPROTO_DELETE_RECENT_CURSOR], 'the end of the window starts it over');
	}

	public function testTheRecentWindowStopsAtItsTimeBudget(): void {
		$this->posts(5000, [], [
			[1000 => 'https://bsky.app/profile/did:plc:a/post/r0'],
			[1010 => 'https://bsky.app/profile/did:plc:a/post/r1'],
			[1020 => 'https://bsky.app/profile/did:plc:a/post/r2'],
		]);
		$this->store->method('deleteGone')->willReturnCallback(function (): int {
			$this->now += DeletionSweep::RECENT_BUDGET;

			return 0;
		});

		$this->sweep()->run();
		$this->assertSame('1000', $this->values[ConfigService::ATPROTO_DELETE_RECENT_CURSOR], 'one page, then the budget was spent');
	}

	public function testAnUnansweredRecentPageKeepsTheRecentCursor(): void {
		$this->values[ConfigService::ATPROTO_DELETE_RECENT_CURSOR] = '999';
		$this->posts(5000, [], [[1000 => 'https://bsky.app/profile/did:plc:a/post/r0']]);
		$this->store->method('deleteGone')->willReturn(null);

		$this->assertSame(0, $this->sweep()->run());
		$this->assertSame('999', $this->values[ConfigService::ATPROTO_DELETE_RECENT_CURSOR]);
	}

	/** Without any Bluesky post the table is not scanned again for an hour. */
	public function testAnInstanceWithoutBlueskyPostsIsNotAskedAgainForAWhile(): void {
		$this->streams->expects($this->exactly(2))->method('getBlueskyPostMaxNid')->willReturn(0);
		$this->streams->expects($this->never())->method('getBlueskyPostIds');
		$sweep = $this->sweep();

		$this->assertSame(0, $sweep->run());
		$this->now += 1800;
		$this->assertSame(0, $sweep->run(), 'remembered as empty');
		$this->now += 1801;
		$this->assertSame(0, $sweep->run(), 'asked again after an hour');
	}
}
