<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service\Counts;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Reader\BlueskyCountSource;
use OCA\Social\Atproto\Reader\BlueskyPublishedCountSource;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\Counts\ActivityPubCountSource;
use OCA\Social\Service\Counts\CountService;
use OCA\Social\Service\Counts\CountSource;
use OCA\Social\Service\Counts\CountWriter;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\RemoteFetchQueue;
use OCA\Social\Tests\Helper\InMemoryDurableCacheRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Which posts a page hands to the background for their counts, and how the
 * background hands each to the network it lives on.
 */
#[AllowMockObjectsWithoutExpectations]
class CountServiceTest extends TestCase {
	private const NOW = 1790000000;

	private StreamRequest&MockObject $streams;
	private CountWriter&MockObject $writer;
	private RemoteFetchQueue&MockObject $queue;
	private InMemoryDurableCacheRequest $cacheRows;
	private ILockingProvider $locks;
	/** @var array<string, Stream> */
	private array $posts = [];
	/** @var array<class-string, CountSource> */
	private array $sources = [];

	protected function setUp(): void {
		$this->streams = $this->createMock(StreamRequest::class);
		$this->writer = $this->createMock(CountWriter::class);
		$this->queue = $this->createMock(RemoteFetchQueue::class);
		$this->cacheRows = new InMemoryDurableCacheRequest();
		$this->locks = $this->createStub(ILockingProvider::class);
		$this->streams->method('getStreamById')->willReturnCallback(fn (string $id): Stream => $this->posts[$id] ?? throw new StreamNotFoundException());
	}

	private function service(): CountService {
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$cacheFactory = $this->createStub(ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturn(false);
		$durableCache = new DurableCache($cacheFactory, $this->cacheRows, $time);
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $class): ?CountSource => $this->sources[$class] ?? null);

		return new CountService($this->streams, $this->writer, $this->queue, $time, new NullLogger(), $this->locks, $durableCache, $container);
	}

	/**
	 * @param int $age seconds since it was published
	 * @param ?int $countedAgo seconds since its counts were asked for, null for never
	 */
	private function post(string $id, int $age, ?int $countedAgo, bool $local = false, string $visibility = Stream::TYPE_PUBLIC): Note {
		$post = new Note();
		$post->setId($id);
		$post->setLocal($local);
		$post->setVisibility($visibility);
		$post->setPublishedTime(self::NOW - $age);
		$post->setCountsAt($countedAgo === null ? null : self::NOW - $countedAgo);
		$this->posts[$id] = $post;

		return $post;
	}

	public function testAYoungPostIsAskedAboutEveryQuarterHourAnOlderOneEverySixHours(): void {
		$this->assertSame(CountService::YOUNG_INTERVAL, CountService::interval($this->post('a', 3600, null), self::NOW));
		$this->assertSame(CountService::OLD_INTERVAL, CountService::interval($this->post('a', 2 * 86400, null), self::NOW));
		$this->assertSame(900, CountService::YOUNG_INTERVAL);
		$this->assertSame(21600, CountService::OLD_INTERVAL);
	}

	public function testAPostIsDueOnceItsIntervalIsOut(): void {
		$service = $this->service();
		$this->assertFalse($service->isDue($this->post('https://r.example/1', 3600, 600), self::NOW), 'young, counted ten minutes ago');
		$this->assertTrue($service->isDue($this->post('https://r.example/1', 3600, 900), self::NOW), 'young, counted a quarter of an hour ago');
		$this->assertFalse($service->isDue($this->post('https://r.example/1', 3 * 86400, 3600), self::NOW), 'old, counted an hour ago');
		$this->assertTrue($service->isDue($this->post('https://r.example/1', 3 * 86400, 7 * 3600), self::NOW), 'old, counted seven hours ago');
		$this->assertTrue($service->isDue($this->post('https://r.example/1', 3 * 86400, null), self::NOW), 'never counted');
	}

	public function testOnlyRemotePostsThatAreNotDirectMessagesAreEverDue(): void {
		$service = $this->service();
		$this->assertFalse($service->isDue($this->post('https://here.example/1', 3600, null, true), self::NOW), 'a local post no source counts');
		$this->assertFalse($service->isDue($this->post('https://r.example/1', 3600, null, false, Stream::TYPE_DIRECT), self::NOW));
		$this->assertFalse($service->isDue($this->post('', 3600, null), self::NOW));
	}

	public function testALocalPostOnAnotherNetworkIsDueOnItsIntervalToo(): void {
		$published = $this->createMock(CountSource::class);
		$published->method('supports')->willReturnCallback(static fn (Stream $p): bool => $p->isLocal() && $p->getId() === 'https://here.example/on-bluesky');
		$this->sources[BlueskyPublishedCountSource::class] = $published;
		$service = $this->service();

		$this->assertTrue($service->isDue($this->post('https://here.example/on-bluesky', 3600, null, true), self::NOW));
		$this->assertFalse($service->isDue($this->post('https://here.example/on-bluesky', 3600, 600, true), self::NOW), 'asked ten minutes ago');
		$this->assertFalse($service->isDue($this->post('https://here.example/here-only', 3600, null, true), self::NOW));
		$this->assertFalse($service->isDue($this->post('https://here.example/on-bluesky', 3600, null, true, Stream::TYPE_DIRECT), self::NOW));
	}

	public function testALocalPostOnBlueskyGoesToItsOwnSource(): void {
		$mine = $this->post('https://here.example/1', 60, null, true);
		$published = $this->createMock(CountSource::class);
		$published->method('supports')->willReturnCallback(static fn (Stream $p): bool => $p === $mine);
		$published->expects($this->once())->method('refresh')->with([$mine])->willReturn(1);
		$bluesky = $this->createMock(CountSource::class);
		$bluesky->method('supports')->willReturn(false);
		$bluesky->expects($this->never())->method('refresh');
		$this->sources = [BlueskyCountSource::class => $bluesky, BlueskyPublishedCountSource::class => $published];

		$this->assertSame(['asked' => 1, 'answered' => 1], $this->service()->refreshPosts([$mine]));
	}

	public function testVisibleBlueskyPostIsRefreshedImmediatelyInsteadOfWaitingForCron(): void {
		$post = $this->post('at://did:plc:author/app.bsky.feed.post/1', 3600, null);
		$source = $this->getMockBuilder(BlueskyCountSource::class)->disableOriginalConstructor()->onlyMethods(['supports', 'refresh'])->getMock();
		$source->method('supports')->with($post)->willReturn(true);
		$source->expects($this->once())->method('refresh')->with([$post], \OCA\Social\Atproto\AppView\AppViewClient::INTERACTIVE_TIMEOUT)->willReturn(1);
		$this->sources[BlueskyCountSource::class] = $source;

		$this->assertTrue($this->service()->refreshVisibleIfDue($post));
	}

	public function testVisibleBlueskyPostIsNotAskedAgainWithinFiveSeconds(): void {
		$post = $this->post('at://did:plc:author/app.bsky.feed.post/1', 3 * 86400, CountService::VISIBLE_INTERVAL - 1);
		$source = $this->getMockBuilder(BlueskyCountSource::class)->disableOriginalConstructor()->onlyMethods(['supports', 'refresh'])->getMock();
		$source->method('supports')->with($post)->willReturn(true);
		$source->expects($this->never())->method('refresh');
		$this->sources[BlueskyCountSource::class] = $source;

		$this->assertFalse($this->service()->refreshVisibleIfDue($post));
	}

	public function testOpeningARecentlyCountedPostCanRequestFreshCountsImmediately(): void {
		$post = $this->post('at://did:plc:author/app.bsky.feed.post/1', 3600, 1);
		$source = $this->getMockBuilder(BlueskyCountSource::class)->disableOriginalConstructor()->onlyMethods(['supports', 'refresh'])->getMock();
		$source->method('supports')->with($post)->willReturn(true);
		$source->expects($this->exactly(2))->method('refresh')->with([$post], AppViewClient::INTERACTIVE_TIMEOUT)->willReturn(1);
		$this->sources[BlueskyCountSource::class] = $source;

		$service = $this->service();
		$this->assertTrue($service->refreshVisibleIfDue($post, true));
		$this->assertFalse($service->visibleRefreshPending($post->getId()), 'an answered live read is complete');
		$this->assertTrue($service->refreshVisibleIfDue($post, true), 'a new detail-page open bypasses the prior short freshness window');
		$this->assertFalse($service->visibleRefreshPending($post->getId()));
	}

	public function testVisibleRefreshReportsPendingWhenAnotherRequestHoldsTheLock(): void {
		$post = $this->post('https://remote.example/1', 3600, null);
		$locks = $this->createMock(ILockingProvider::class);
		$locks->expects($this->once())->method('isLocked')->with('social-visible-count-' . md5($post->getId()), ILockingProvider::LOCK_EXCLUSIVE)->willReturn(true);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$cacheFactory = $this->createStub(ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturn(false);
		$durableCache = new DurableCache($cacheFactory, $this->cacheRows, $time);
		$container = $this->createStub(ContainerInterface::class);
		$service = new CountService($this->streams, $this->writer, $this->queue, $time, new NullLogger(), $locks, $durableCache, $container);

		$this->assertTrue($service->visibleRefreshPending($post->getId()));
	}

	public function testConcurrentVisibleRefreshDoesNotAskBlueskyTwice(): void {
		$post = $this->post('at://did:plc:author/app.bsky.feed.post/1', 3600, null);
		$source = $this->getMockBuilder(BlueskyCountSource::class)->disableOriginalConstructor()->onlyMethods(['supports', 'refresh'])->getMock();
		$source->method('supports')->with($post)->willReturn(true);
		$source->expects($this->never())->method('refresh');
		$this->sources[BlueskyCountSource::class] = $source;
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $class): ?CountSource => $this->sources[$class] ?? null);
		$locks = $this->createMock(ILockingProvider::class);
		$locks->expects($this->once())->method('isLocked')->with('social-visible-count-' . md5($post->getId()), ILockingProvider::LOCK_EXCLUSIVE)->willReturn(true);
		$cacheFactory = $this->createStub(ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturn(false);
		$durableCache = new DurableCache($cacheFactory, $this->cacheRows, $time);
		$service = new CountService($this->streams, $this->writer, $this->queue, $time, new NullLogger(), $locks, $durableCache, $container);

		$this->assertTrue($service->refreshVisibleIfDue($post), 'the other request is already refreshing this post');
	}

	public function testVisibleFediversePostIsRefreshedImmediatelyInsteadOfWaitingForCron(): void {
		$post = $this->post('https://remote.example/notes/1', 3600, null);
		$source = $this->getMockBuilder(ActivityPubCountSource::class)->disableOriginalConstructor()->onlyMethods(['supports', 'refresh'])->getMock();
		$source->method('supports')->with($post)->willReturn(true);
		$source->expects($this->once())->method('refresh')->with([$post], \OCA\Social\Atproto\AppView\AppViewClient::INTERACTIVE_TIMEOUT)->willReturn(1);
		$this->sources[ActivityPubCountSource::class] = $source;

		$this->assertTrue($this->service()->refreshVisibleIfDue($post));
	}

	public function testFailedForcedVisibleReadQueuesRetryEvenWhenOrdinaryCountIntervalIsFresh(): void {
		$id = 'at://did:plc:author/app.bsky.feed.post/1';
		$post = $this->post($id, 3600, 1);
		$source = $this->getMockBuilder(BlueskyCountSource::class)->disableOriginalConstructor()->onlyMethods(['supports', 'refresh'])->getMock();
		$source->method('supports')->with($post)->willReturn(true);
		$source->expects($this->once())->method('refresh')->with([$post], AppViewClient::INTERACTIVE_TIMEOUT)->willThrowException(new RuntimeException('AppView is temporarily unavailable'));
		$this->sources[BlueskyCountSource::class] = $source;
		$this->queue->expects($this->once())->method('refreshCounts')->with([$id => CountService::VISIBLE_INTERVAL], true)->willReturn(true);

		$service = $this->service();

		$this->assertTrue($service->refreshVisibleIfDue($post, true));
		$this->assertTrue($service->visibleRefreshPending($id));
	}

	public function testForcedBackgroundRetryReadsCountsEvenWhenTheyAreOrdinarilyFresh(): void {
		$id = 'at://did:plc:author/app.bsky.feed.post/1';
		$post = $this->post($id, 3600, 1);
		$source = $this->getMockBuilder(BlueskyCountSource::class)->disableOriginalConstructor()->onlyMethods(['supports', 'refresh'])->getMock();
		$source->method('supports')->with($post)->willReturn(true);
		$source->expects($this->once())->method('refresh')->with([$post])->willReturn(1);
		$this->sources[BlueskyCountSource::class] = $source;

		$this->assertSame(['asked' => 1, 'answered' => 1], $this->service()->refresh([$id], true));
	}

	public function testFailedVisibleReadStaysPendingUntilBackgroundStampUpdatesThePost(): void {
		$post = $this->post('at://did:plc:author/app.bsky.feed.post/1', 3600, null);
		$source = $this->getMockBuilder(BlueskyCountSource::class)->disableOriginalConstructor()->onlyMethods(['supports', 'refresh'])->getMock();
		$source->method('supports')->with($post)->willReturn(true);
		$source->expects($this->exactly(2))->method('refresh')->willReturnCallback(
			static function (array $posts, ?int $timeout = null) use ($post): int {
				if ($timeout === AppViewClient::INTERACTIVE_TIMEOUT) {
					throw new RuntimeException('AppView is temporarily unavailable');
				}
				throw new RuntimeException('AppView is still temporarily unavailable');
			}
		);
		$this->sources[BlueskyCountSource::class] = $source;
		$this->queue->expects($this->once())->method('refreshCounts')->with([
			$post->getId() => CountService::VISIBLE_INTERVAL,
		], true)->willReturn(true);
		$service = $this->service();

		$this->assertTrue($service->refreshVisibleIfDue($post), 'the failed live read schedules a background retry');
		$this->assertTrue($service->refreshVisibleIfDue($post), 'later status polls see that the background retry is pending');
		$this->writer->expects($this->once())->method('stamp')->with($post->getId())->willReturnCallback(
			static function () use ($post): void {
				$post->setCountsAt(self::NOW);
			}
		);
		$cacheKey = hash('sha256', "social.counts.visible\n" . md5($post->getId()));
		$pendingState = $this->cacheRows->rows[$cacheKey];
		$this->assertSame(['asked' => 1, 'answered' => 0], $service->refresh([$post->getId()], true));
		$this->assertSame($pendingState, $this->cacheRows->rows[$cacheKey], 'background refresh does not write one cache row per post');
		$this->assertFalse($service->refreshVisibleIfDue($post), 'the background timestamp prevents a repeat request');
	}

	public function testOpeningABoostRefreshesTheUnderlyingPostsCounts(): void {
		$post = $this->post('https://remote.example/notes/1', 3600, null);
		$boost = new Announce();
		$boost->setObject($post);
		$source = $this->getMockBuilder(ActivityPubCountSource::class)->disableOriginalConstructor()->onlyMethods(['supports', 'refresh'])->getMock();
		$source->method('supports')->with($post)->willReturn(true);
		$source->expects($this->once())->method('refresh')->with([$post], \OCA\Social\Atproto\AppView\AppViewClient::INTERACTIVE_TIMEOUT)->willReturn(1);
		$this->sources[ActivityPubCountSource::class] = $source;

		$this->assertTrue($this->service()->refreshVisibleIfDue($boost));
	}

	public function testTheOnScreenWindowGrowsWithThePostsAge(): void {
		$window = fn (int $age): int => CountService::visibleInterval($this->post('a', $age, null), self::NOW);

		$this->assertSame(CountService::VISIBLE_INTERVAL, $window(60), 'a post of a minute');
		$this->assertSame(30, $window(1800), 'half an hour');
		$this->assertSame(60, $window(3 * 3600), 'three hours');
		$this->assertSame(300, $window(12 * 3600), 'half a day');
		$this->assertSame(900, $window(3 * 86400), 'three days');
		$this->assertSame(CountService::VISIBLE_OLD_INTERVAL, $window(30 * 86400), 'a month');
		foreach ([60, 1800, 3 * 3600, 12 * 3600, 3 * 86400, 30 * 86400] as $age) {
			$this->assertLessThanOrEqual(
				CountService::interval($this->post('a', $age, null), self::NOW), $window($age),
				'a post on screen is never asked about less often than the background asks'
			);
		}
	}

	public function testAnOldPostOnScreenIsNotAskedAgainWithinItsLongerWindow(): void {
		$post = $this->post('at://did:plc:author/app.bsky.feed.post/1', 30 * 86400, 600);
		$source = $this->getMockBuilder(BlueskyCountSource::class)->disableOriginalConstructor()->onlyMethods(['supports', 'refresh'])->getMock();
		$source->method('supports')->willReturn(true);
		$source->expects($this->never())->method('refresh');
		$this->sources[BlueskyCountSource::class] = $source;

		$this->assertFalse($this->service()->refreshVisibleIfDue($post), 'counted ten minutes ago, a month old');
	}

	/**
	 * @param list<Stream> $answered the posts the source stores counts for
	 */
	private function batchSource(string $class, callable $supports, array $answered = [], ?array &$calls = null): CountSource {
		$calls = [];
		$source = $this->getMockBuilder($class)->disableOriginalConstructor()->onlyMethods(['supports', 'refresh'])->getMock();
		$source->method('supports')->willReturnCallback($supports);
		$source->method('refresh')->willReturnCallback(static function (array $posts, ?int $timeout = null) use (&$calls, $answered): int {
			$calls[] = [array_map(static fn (Stream $post): string => $post->getId(), $posts), $timeout];
			$count = 0;
			foreach ($posts as $post) {
				if (in_array($post, $answered, true)) {
					$post->setCountsAt(self::NOW);
					$count++;
				}
			}

			return $count;
		});

		return $source;
	}

	public function testPostsOnScreenAreAskedTogetherOneCallPerNetwork(): void {
		$a = $this->post('https://r.example/1', 600, null);
		$b = $this->post('https://r.example/2', 3600 * 3, 120);
		$c = $this->post('at://did:plc:x/app.bsky.feed.post/3', 600, null);
		$fresh = $this->post('https://r.example/4', 600, 2);
		$direct = $this->post('https://r.example/5', 600, null, false, Stream::TYPE_DIRECT);
		$boost = new Announce();
		$boost->setObject($c);
		$this->sources[ActivityPubCountSource::class] = $this->batchSource(
			ActivityPubCountSource::class, static fn (Stream $p): bool => str_starts_with($p->getId(), 'https://'), [$a, $b], $apCalls
		);
		$this->sources[BlueskyCountSource::class] = $this->batchSource(
			BlueskyCountSource::class, static fn (Stream $p): bool => str_starts_with($p->getId(), 'at://'), [$c], $bskyCalls
		);
		$this->queue->expects($this->never())->method('refreshCounts');
		$service = $this->service();

		$result = $service->refreshVisibleMany([$a, $b, $boost, $fresh, $direct]);

		$this->assertSame([[['https://r.example/1', 'https://r.example/2'], AppViewClient::INTERACTIVE_TIMEOUT]], $apCalls);
		$this->assertSame([[['at://did:plc:x/app.bsky.feed.post/3'], AppViewClient::INTERACTIVE_TIMEOUT]], $bskyCalls);
		$this->assertEqualsCanonicalizing(['https://r.example/1', 'https://r.example/2', 'at://did:plc:x/app.bsky.feed.post/3'], $result['refreshed']);
		$this->assertFalse($result['pending']);

		$a->setCountsAt(null);
		$again = $service->refreshVisibleMany([$a, $b, $boost]);
		$this->assertSame([], $again['refreshed'], 'a post just read stands for its window, whoever asks');
		$this->assertCount(1, $apCalls);
	}

	public function testAPostAnotherRequestIsReadingIsLeftToItAndReportedPending(): void {
		$a = $this->post('https://r.example/1', 600, null);
		$b = $this->post('https://r.example/2', 600, null);
		$locks = $this->createMock(ILockingProvider::class);
		$locks->method('acquireLock')->willReturnCallback(static function (string $name) use ($b): void {
			if ($name === 'social-visible-count-' . md5($b->getId())) {
				throw new LockedException($name);
			}
		});
		$locks->expects($this->once())->method('releaseLock')->with('social-visible-count-' . md5($a->getId()), ILockingProvider::LOCK_EXCLUSIVE);
		$this->locks = $locks;
		$this->sources[ActivityPubCountSource::class] = $this->batchSource(ActivityPubCountSource::class, static fn (): bool => true, [$a], $calls);

		$result = $this->service()->refreshVisibleMany([$a, $b]);

		$this->assertSame([[['https://r.example/1'], AppViewClient::INTERACTIVE_TIMEOUT]], $calls);
		$this->assertSame(['https://r.example/1'], $result['refreshed']);
		$this->assertTrue($result['pending']);
	}

	public function testAPostTheNetworkDidNotAnswerForIsRetriedInTheBackground(): void {
		$a = $this->post('https://r.example/1', 600, null);
		$b = $this->post('https://r.example/2', 600, 3600);
		$this->sources[ActivityPubCountSource::class] = $this->batchSource(ActivityPubCountSource::class, static fn (): bool => true, [$a]);
		$this->queue->expects($this->once())->method('refreshCounts')->with([$b->getId() => CountService::VISIBLE_INTERVAL], true)->willReturn(true);
		$service = $this->service();

		$result = $service->refreshVisibleMany([$a, $b]);

		$this->assertTrue($result['pending']);
		$this->assertTrue($service->visibleRefreshPending($b->getId()));
		$this->assertFalse($service->visibleRefreshPending($a->getId()));
	}

	public function testAFailingNetworkLeavesItsPostsPendingAndTheOthersRead(): void {
		$a = $this->post('https://r.example/1', 600, null);
		$c = $this->post('at://did:plc:x/app.bsky.feed.post/3', 600, null);
		$failing = $this->getMockBuilder(ActivityPubCountSource::class)->disableOriginalConstructor()->onlyMethods(['supports', 'refresh'])->getMock();
		$failing->method('supports')->willReturnCallback(static fn (Stream $p): bool => $p === $a);
		$failing->method('refresh')->willThrowException(new RuntimeException('origin down'));
		$this->sources[ActivityPubCountSource::class] = $failing;
		$this->sources[BlueskyCountSource::class] = $this->batchSource(BlueskyCountSource::class, static fn (Stream $p): bool => $p === $c, [$c]);
		$this->queue->expects($this->once())->method('refreshCounts')->with([$a->getId() => CountService::VISIBLE_INTERVAL], true)->willReturn(true);
		$service = $this->service();

		$result = $service->refreshVisibleMany([$a, $c]);

		$this->assertTrue($result['pending']);
		$this->assertTrue($service->visibleRefreshPending($a->getId()));
		$this->assertFalse($service->visibleRefreshPending($c->getId()));
	}

	public function testOneRequestReadsABatchOfPostsAtMost(): void {
		$posts = [];
		for ($i = 0; $i < CountService::VISIBLE_BATCH + 5; $i++) {
			$posts[] = $this->post('https://r.example/' . $i, 600, null);
		}
		$this->sources[ActivityPubCountSource::class] = $this->batchSource(ActivityPubCountSource::class, static fn (): bool => true, $posts, $calls);

		$result = $this->service()->refreshVisibleMany($posts);

		$this->assertCount(CountService::VISIBLE_BATCH, $calls[0][0]);
		$this->assertCount(CountService::VISIBLE_BATCH, $result['refreshed']);
	}

	public function testAPageQueuesItsDuePostsWithTheirIntervals(): void {
		$this->queue->expects($this->once())->method('refreshCounts')->with([
			'https://r.example/young' => CountService::YOUNG_INTERVAL,
			'https://r.example/old' => CountService::OLD_INTERVAL,
		])->willReturn(true);

		$this->assertTrue($this->service()->seen([
			$this->post('https://r.example/young', 60, null),
			$this->post('https://r.example/fresh', 60, 60),
			$this->post('https://here.example/mine', 60, null, true),
			$this->post('https://r.example/old', 5 * 86400, 86400),
		]));
	}

	public function testAConcurrentPageLeavesCountQueueingToTheRequestHoldingTheLock(): void {
		$this->locks = $this->createMock(ILockingProvider::class);
		$this->locks->expects($this->once())
			->method('acquireLock')
			->with('social-counts-queue', ILockingProvider::LOCK_EXCLUSIVE)
			->willThrowException(new LockedException('social-counts-queue'));
		$this->queue->expects($this->never())->method('refreshCounts');

		$this->assertTrue($this->service()->seen([
			$this->post('https://r.example/1', 60, null),
		]), 'the other request will enqueue the due post');
	}

	public function testABoostStandsForThePostItBoosts(): void {
		$boost = new Announce();
		$boost->setId('https://r.example/boost');
		$boost->setObject($this->post('https://r.example/boosted', 60, null));
		$this->queue->expects($this->once())->method('refreshCounts')->with(['https://r.example/boosted' => CountService::YOUNG_INTERVAL])->willReturn(true);

		$this->assertTrue($this->service()->seen([$boost]));
	}

	public function testAPageWithNothingDueQueuesNothing(): void {
		$this->queue->expects($this->never())->method('refreshCounts');

		$this->assertFalse($this->service()->seen([$this->post('https://r.example/1', 60, 60), 'not a post']));
	}

	public function testAPageStaysMarkedAsFillingWhileQueuedCountsAreStillDue(): void {
		$this->queue->expects($this->exactly(2))->method('refreshCounts')->with([
			'https://r.example/1' => CountService::YOUNG_INTERVAL,
		])->willReturn(false);
		$service = $this->service();
		$post = $this->post('https://r.example/1', 60, null);

		$this->assertTrue($service->seen([$post]), 'the first request queues the stale counts');
		$this->assertTrue($service->seen([$post]), 'a deduplicated request is still pending until counts_at changes');
	}

	public function testTheBackgroundReadAsksOnlyAboutPostsStillDue(): void {
		$due = $this->post('https://r.example/due', 60, null);
		$done = $this->post('https://r.example/done', 60, 30);
		$this->streams->method('getStreamById')->willReturnCallback(fn (string $id): Stream => match ($id) {
			$due->getId() => $due,
			$done->getId() => $done,
			default => throw new StreamNotFoundException(),
		});
		$source = $this->createMock(CountSource::class);
		$source->method('supports')->willReturn(true);
		$source->expects($this->once())->method('refresh')->with([$due])->willReturn(1);
		$this->sources[ActivityPubCountSource::class] = $source;

		$this->assertSame(['asked' => 1, 'answered' => 1], $this->service()->refresh([$due->getId(), $done->getId(), 'https://r.example/gone', $due->getId()]));
	}

	public function testEachPostGoesToTheNetworkItIsOnAndTheRestAreStamped(): void {
		$fedi = $this->post('https://r.example/1', 60, null);
		$bsky = $this->post('https://bsky.app/profile/did:plc:a/post/1', 60, null);
		$nowhere = $this->post('https://bsky.app/profile/did:plc:a/convo/x/1', 60, null);
		$activityPub = $this->createMock(CountSource::class);
		$activityPub->method('supports')->willReturnCallback(static fn (Stream $p): bool => $p === $fedi);
		$activityPub->expects($this->once())->method('refresh')->with([$fedi])->willReturn(1);
		$bluesky = $this->createMock(CountSource::class);
		$bluesky->method('supports')->willReturnCallback(static fn (Stream $p): bool => $p === $bsky);
		$bluesky->expects($this->once())->method('refresh')->with([$bsky])->willReturn(0);
		$this->sources = [ActivityPubCountSource::class => $activityPub, BlueskyCountSource::class => $bluesky];
		$this->writer->expects($this->once())->method('stamp')->with($nowhere->getId());

		$this->assertSame(['asked' => 3, 'answered' => 1], $this->service()->refreshPosts([$fedi, $bsky, $nowhere]));
	}

	public function testANetworkThatFailsHasItsPostsStampedAllTheSame(): void {
		$post = $this->post('https://r.example/1', 60, null);
		$source = $this->createMock(CountSource::class);
		$source->method('supports')->willReturn(true);
		$source->method('refresh')->willThrowException(new RuntimeException('down'));
		$this->sources[ActivityPubCountSource::class] = $source;
		$this->writer->expects($this->once())->method('stamp')->with($post->getId());

		$this->assertSame(['asked' => 1, 'answered' => 0], $this->service()->refreshPosts([$post]));
	}
}
