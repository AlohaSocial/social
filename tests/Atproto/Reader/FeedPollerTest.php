<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Model\Watch;
use OCA\Social\Atproto\Moderation\Blocklist;
use OCA\Social\Atproto\Moderation\LabelerService;
use OCA\Social\Atproto\Reader\AccountStatus;
use OCA\Social\Atproto\Reader\ActorMapper;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Reader\FeedPoller;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\AtprotoWatchRequest;
use OCA\Social\Exceptions\AppViewNotFoundException;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class FeedPollerTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const NOW = 1760000000;

	/** @var AtprotoWatchRequest&MockObject */
	private AtprotoWatchRequest $watches;
	/** @var AppViewClient&MockObject */
	private AppViewClient $appView;
	/** @var PostStore&MockObject */
	private PostStore $store;
	/** @var BlueskyActorService&MockObject */
	private BlueskyActorService $actors;
	/** @var AtprotoConfig&MockObject */
	private AtprotoConfig $config;
	private FeedPoller $poller;

	protected function setUp(): void {
		$this->config = $this->createMock(AtprotoConfig::class);
		$this->config->method('isEnabled')->willReturn(true);
		$this->config->method('syncCeiling')->willReturn(200);
		$this->watches = $this->createMock(AtprotoWatchRequest::class);
		$this->appView = $this->createMock(AppViewClient::class);
		$this->store = $this->createMock(PostStore::class);
		$this->actors = $this->createMock(BlueskyActorService::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$this->poller = new FeedPoller($this->config, $this->watches, $this->appView, $this->store, $this->actors, $this->createMock(Blocklist::class), $this->createMock(LabelerService::class), $time, new NullLogger());
	}

	public function testNewItemsAreStoredNewestFirstUntilTheCursor(): void {
		$watch = $this->watch(cursor: '2026-10-08T10:00:00.000Z');
		$this->appView->method('query')->with('app.bsky.feed.getAuthorFeed', ['actor' => self::DID, 'filter' => 'posts_with_replies', 'limit' => 50])->willReturn(['feed' => [
			$this->item('2026-10-08T12:00:00.000Z', 'three'),
			$this->item('2026-10-08T11:00:00.000Z', 'two'),
			$this->item('2026-10-08T10:00:00.000Z', 'one'),
			$this->item('2026-10-08T09:00:00.000Z', 'zero'),
		]]);
		$stored = [];
		$this->store->method('storeFeedItem')->willReturnCallback(static function (array $item) use (&$stored): int {
			$stored[] = $item['post']['record']['text'];

			return 1;
		});
		$this->watches->expects($this->once())->method('synced')->with(self::DID, '2026-10-08T12:00:00.000Z', self::NOW, self::NOW + FeedPoller::INTERVAL);

		$this->assertSame(3, $this->poller->pollWatch($watch));
		$this->assertSame(['three', 'two', 'one'], $stored, 'newest first, including a post tied with the cursor');
	}

	public function testARepostIsOrderedByItsOwnTime(): void {
		$watch = $this->watch(cursor: '2026-10-08T10:30:00.000Z');
		$repost = $this->item('2026-10-08T09:00:00.000Z', 'old post');
		$repost['reason'] = ['$type' => 'app.bsky.feed.defs#reasonRepost', 'by' => ['did' => 'did:plc:other'], 'indexedAt' => '2026-10-08T11:00:00.000Z'];
		$this->appView->method('query')->willReturn(['feed' => [$repost]]);
		$this->store->expects($this->once())->method('storeFeedItem')->willReturn(1);
		$this->watches->expects($this->once())->method('synced')->with(self::DID, '2026-10-08T11:00:00.000Z', self::NOW, self::NOW + FeedPoller::INTERVAL);

		$this->assertSame(1, $this->poller->pollWatch($watch));
	}

	public function testAnEmptyPageBacksOffAndAPostResetsIt(): void {
		$this->appView->method('query')->willReturn(['feed' => []]);
		$this->store->expects($this->never())->method('storeFeedItem');
		$this->watches->expects($this->exactly(3))->method('synced')->willReturnCallback(function (string $did, string $cursor, int $now, int $next): void {
			static $call = 0;
			$call++;
			$this->assertSame(match ($call) {
				1 => self::NOW + FeedPoller::INTERVAL,
				2 => self::NOW + 4 * FeedPoller::INTERVAL,
				3 => self::NOW + FeedPoller::MAX_BACKOFF,
			}, $next, 'call ' . $call);
		});

		$this->poller->pollWatch($this->watch(lastSync: 0));
		$this->poller->pollWatch($this->watch(lastSync: self::NOW - 600, nextSync: self::NOW - 600 + 2 * FeedPoller::INTERVAL));
		$this->poller->pollWatch($this->watch(lastSync: self::NOW - 30000, nextSync: self::NOW - 30000 + 5 * 3600));
	}

	public function testFailuresBackOffAndAGoneAccountWaitsLongest(): void {
		$this->appView->method('query')->willReturnOnConsecutiveCalls(
			$this->throwException(new AtprotoException('timeout')),
			$this->throwException(new AppViewNotFoundException('Profile not found')),
		);
		$this->watches->expects($this->exactly(2))->method('failed')->willReturnCallback(static function (string $did, string $error, int $next): void {
			static $call = 0;
			$call++;
			if ($call === 1) {
				self::assertSame(self::NOW + FeedPoller::INTERVAL * 4, $next, 'the second failure waits four intervals');
			} else {
				self::assertSame(self::NOW + FeedPoller::MAX_BACKOFF, $next);
			}
		});

		$this->assertSame(0, $this->poller->pollWatch($this->watch(failures: 1)));
		$this->assertSame(0, $this->poller->pollWatch($this->watch()));
	}

	/**
	 * The AppView names why it refuses an account; the watch backs off as
	 * after any failure, and the status is acted on once it has been said
	 * three times with no successful read between. A plain not-found says
	 * nothing of the account and waits longest, as before.
	 */
	public function testAnAccountTheAppViewNoLongerServesIsActedOnAfterThreeRefusals(): void {
		$status = $this->createMock(AccountStatus::class);
		$applied = [];
		$status->method('apply')->willReturnCallback(static function (string $did, bool $active, string $status) use (&$applied): void {
			$applied[] = [$did, $active, $status];
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$poller = new FeedPoller($this->config, $this->watches, $this->appView, $this->store, $this->actors, $this->createMock(Blocklist::class), $this->createMock(LabelerService::class), $time, new NullLogger(), $status);
		$this->appView->method('query')->willReturnOnConsecutiveCalls(
			$this->throwException(new AppViewNotFoundException('getAuthorFeed: Account is deactivated', 'AccountDeactivated')),
			$this->throwException(new AppViewNotFoundException('getAuthorFeed: Account is deactivated', 'AccountDeactivated')),
			$this->throwException(new AppViewNotFoundException('getAuthorFeed: Account is deactivated', 'AccountDeactivated')),
			$this->throwException(new AppViewNotFoundException('getAuthorFeed: Could not find repo', 'RepoNotFound')),
			$this->throwException(new AppViewNotFoundException('getAuthorFeed: Profile not found')),
		);
		$nexts = [];
		$errors = [];
		$this->watches->method('failed')->willReturnCallback(static function (string $did, string $error, int $next) use (&$nexts, &$errors): void {
			$nexts[] = $next - self::NOW;
			$errors[] = $error;
		});

		$poller->pollWatch($this->watch(failures: 0));
		$poller->pollWatch($this->watch(failures: 1, lastError: $errors[0]));
		$this->assertSame([], $applied, 'twice is not yet persistent');
		$poller->pollWatch($this->watch(failures: 2, lastError: $errors[1]));
		$this->assertSame([[self::DID, false, 'deactivated']], $applied);
		$poller->pollWatch($this->watch(failures: 5, lastError: '[status 2] getAuthorFeed: Could not find repo'));
		$this->assertSame([self::DID, false, 'deleted'], $applied[1], 'a repository that is gone');
		$poller->pollWatch($this->watch(failures: 7, lastError: '[status 3] getAuthorFeed: Could not find repo'));
		$this->assertCount(2, $applied, 'a plain not-found names no status');
		$this->assertSame('[status 1] getAuthorFeed: Account is deactivated', $errors[0]);
		$this->assertSame('[status 3] getAuthorFeed: Profile not found', $errors[4], 'and leaves the count as it was');
		$this->assertSame([2 * FeedPoller::INTERVAL, 4 * FeedPoller::INTERVAL, 8 * FeedPoller::INTERVAL, 64 * FeedPoller::INTERVAL, FeedPoller::MAX_BACKOFF], $nexts, 'the ordinary backoff, and the longest wait for a plain not-found');
	}

	/** Timeouts before a status refusal are not refusals: only what the AppView said counts. */
	public function testNetworkErrorsDoNotCountTowardsActingOnAStatus(): void {
		$status = $this->createMock(AccountStatus::class);
		$status->expects($this->never())->method('apply');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$poller = new FeedPoller($this->config, $this->watches, $this->appView, $this->store, $this->actors, $this->createMock(Blocklist::class), $this->createMock(LabelerService::class), $time, new NullLogger(), $status);
		$this->appView->method('query')->willReturnOnConsecutiveCalls(
			$this->throwException(new AppViewNotFoundException('getAuthorFeed: Account has been taken down', 'AccountTakedown')),
			$this->throwException(new AtprotoException('timeout')),
		);
		$errors = [];
		$this->watches->method('failed')->willReturnCallback(static function (string $did, string $error) use (&$errors): void {
			$errors[] = $error;
		});

		$poller->pollWatch($this->watch(failures: 6));
		$this->assertSame('[status 1] getAuthorFeed: Account has been taken down', $errors[0], 'six earlier timeouts are not counted');
		$poller->pollWatch($this->watch(failures: 7, lastError: $errors[0]));
		$this->assertSame('[status 1] timeout', $errors[1], 'a timeout keeps the count without adding to it');
		$this->assertSame(1, FeedPoller::statusAnswers($this->watch(lastError: $errors[1])));
	}

	/** An account Bluesky switched off is still asked about, and restored when its feed answers again. */
	public function testASwitchedOffAccountIsReadAgainAndRestoredWhenItAnswers(): void {
		$status = $this->createMock(AccountStatus::class);
		$status->expects($this->once())->method('restore')->with(self::DID);
		$status->expects($this->never())->method('apply');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$poller = new FeedPoller($this->config, $this->watches, $this->appView, $this->store, $this->actors, $this->createMock(Blocklist::class), $this->createMock(LabelerService::class), $time, new NullLogger(), $status);
		$off = new Person();
		$off->setDetailArray(ActorMapper::DETAIL, ['limited' => true, 'status' => AccountStatus::DEACTIVATED, 'labels' => []]);
		$this->actors->method('cached')->willReturn($off);
		$this->appView->expects($this->once())->method('query')->willReturn(['feed' => [$this->item('2026-10-08T12:00:00.000Z', 'back')]]);
		$this->store->method('storeFeedItem')->willReturn(1);

		$this->assertSame(1, $poller->pollWatch($this->watch()));
	}

	public function testALimitedAccountIsNotReadAndAChangedHandleIsNoted(): void {
		$limited = new Person();
		$limited->setDetailArray(ActorMapper::DETAIL, ['limited' => true]);
		$this->actors->method('cached')->willReturnOnConsecutiveCalls($limited, null);
		$this->appView->expects($this->once())->method('query')->willReturn(['feed' => [$this->item('2026-10-08T12:00:00.000Z', 'x', 'alice.new.social')]]);
		$this->store->method('storeFeedItem')->willReturn(1);
		$this->watches->expects($this->once())->method('setHandle')->with(self::DID, 'alice.new.social');

		$this->assertSame(0, $this->poller->pollWatch($this->watch()));
		$this->assertSame(1, $this->poller->pollWatch($this->watch()));
	}

	public function testAPassVisitsTheDueWatchesWithinTheCeiling(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$config->method('syncCeiling')->willReturn(2);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$poller = new FeedPoller($config, $this->watches, $this->appView, $this->store, $this->actors, $this->createMock(Blocklist::class), $this->createMock(LabelerService::class), $time, new NullLogger());
		$this->watches->method('getDue')->with(self::NOW, FeedPoller::BATCH)->willReturn([$this->watch(), $this->watch('did:plc:two'), $this->watch('did:plc:three')]);
		$this->appView->expects($this->exactly(2))->method('query')->willReturn(['feed' => [$this->item('2026-10-08T12:00:00.000Z', 'x')]]);
		$this->store->method('storeFeedItem')->willReturn(1);

		$this->assertSame(['watches' => 2, 'stored' => 2, 'requests' => 2], $poller->poll());
	}

	/** The clock is checked between watches, so a slow AppView ends the pass at the deadline. */
	public function testAPassStopsAtItsDeadline(): void {
		$now = self::NOW;
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(static function () use (&$now): int {
			return $now;
		});
		$poller = new FeedPoller($this->config, $this->watches, $this->appView, $this->store, $this->actors, $this->createMock(Blocklist::class), $this->createMock(LabelerService::class), $time, new NullLogger());
		$this->watches->method('getDue')->willReturn([$this->watch(), $this->watch('did:plc:two'), $this->watch('did:plc:three')]);
		$this->appView->expects($this->exactly(2))->method('query')->willReturnCallback(static function () use (&$now): array {
			$now += 20;

			return ['feed' => []];
		});

		$this->assertSame(['watches' => 2, 'stored' => 0, 'requests' => 2], $poller->poll(FeedPoller::BATCH, self::NOW + 30));
	}

	public function testABlockedAuthorsWatchIsDroppedUnread(): void {
		$blocklist = $this->createMock(Blocklist::class);
		$blocklist->method('isBlockedDid')->willReturn(true);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$poller = new FeedPoller($this->config, $this->watches, $this->appView, $this->store, $this->actors, $blocklist, $this->createMock(LabelerService::class), $time, new NullLogger());
		$this->appView->expects($this->never())->method('query');
		$this->watches->expects($this->once())->method('remove')->with(self::DID);

		$this->assertSame(0, $poller->pollWatch($this->watch()));
	}

	public function testTheReadAsksForTheLabelersSubscribedHere(): void {
		$labelers = $this->createMock(LabelerService::class);
		$labelers->expects($this->once())->method('acceptHeader')->willReturn('did:plc:mod, did:plc:other');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$poller = new FeedPoller($this->config, $this->watches, $this->appView, $this->store, $this->actors, $this->createMock(Blocklist::class), $labelers, $time, new NullLogger());
		$this->appView->expects($this->exactly(2))->method('query')->with('app.bsky.feed.getAuthorFeed', $this->anything(), ['atproto-accept-labelers' => 'did:plc:mod, did:plc:other'])->willReturn(['feed' => []]);

		$poller->pollWatch($this->watch());
		$poller->pollWatch($this->watch('did:plc:two'));
	}

	public function testAPinDoesNotStopReadingNewPostsOrAdvanceTheChronologicalCursor(): void {
		$oldPin = $this->item('2026-10-01T10:00:00.000Z', 'old pin');
		$oldPin['reason'] = ['$type' => 'app.bsky.feed.defs#reasonPin'];
		$newPin = $this->item('2026-10-10T10:00:00.000Z', 'new pin');
		$newPin['reason'] = ['$type' => 'app.bsky.feed.defs#reasonPin'];
		$new = $this->item('2026-10-08T12:00:00.000Z', 'new post');
		$this->appView->method('query')->willReturn(['feed' => [$oldPin, $newPin, $new]]);
		$this->store->expects($this->once())->method('storeFeedItem')->with($new)->willReturn(1);
		$this->watches->expects($this->once())->method('synced')->with(self::DID, '2026-10-08T12:00:00.000Z', self::NOW, self::NOW + FeedPoller::INTERVAL);

		$this->assertSame(1, $this->poller->pollWatch($this->watch(cursor: '2026-10-08T10:00:00.000Z')));
	}

	private function watch(string $did = self::DID, string $cursor = '', int $lastSync = self::NOW - 1000, int $nextSync = self::NOW, int $failures = 0, string $lastError = ''): Watch {
		return new Watch($did, 'alice.bsky.social', $cursor, $lastSync, $nextSync, $failures, $lastError, self::NOW - 86400);
	}

	private function item(string $indexedAt, string $text, string $handle = 'alice.bsky.social'): array {
		return ['post' => ['uri' => 'at://' . self::DID . '/app.bsky.feed.post/' . md5($text), 'author' => ['did' => self::DID, 'handle' => $handle], 'record' => ['text' => $text], 'indexedAt' => $indexedAt]];
	}
}
