<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Cron\FillFollowLists;
use OCA\Social\Cron\FillInteractions;
use OCA\Social\Cron\FillPosts;
use OCA\Social\Cron\FillThread;
use OCA\Social\Cron\RefreshCounts;
use OCA\Social\Cron\ResolveActor;
use OCA\Social\Cron\SyncRemoteTimeline;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\RemoteFetchQueue;
use OCA\Social\Tests\Helper\InMemoryDurableCacheRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** What a page hands to the background instead of fetching it while it renders. */
#[AllowMockObjectsWithoutExpectations]
class RemoteFetchQueueTest extends TestCase {
	private IJobList|MockObject $jobList;
	private RemoteFetchQueue $queue;
	private int $now = 1790000000;

	protected function setUp(): void {
		$this->jobList = $this->createMock(IJobList::class);
		// the table backend, as on an instance with no memcache
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$durableCache = new DurableCache($factory, new InMemoryDurableCacheRequest(), $time);
		$this->queue = new RemoteFetchQueue($this->jobList, $durableCache, new NullLogger());
	}

	private function remote(string $id = 'https://remote.example/users/bob'): Person {
		$person = new Person();
		$person->setId($id);

		return $person;
	}

	/**
	 * However many people open a profile, its server is asked for the outbox
	 * at most once per interval.
	 */
	public function testATimelineIsSyncedAtMostOncePerInterval(): void {
		$this->jobList->method('has')->willReturn(false);
		$this->jobList->expects($this->exactly(2))->method('add')
			->with(SyncRemoteTimeline::class, ['actor' => 'https://remote.example/users/bob']);

		$this->assertTrue($this->queue->syncTimeline($this->remote()));
		$this->assertFalse($this->queue->syncTimeline($this->remote()));

		$this->now += RemoteFetchQueue::TIMELINE_SYNC_INTERVAL - 1;
		$this->assertFalse($this->queue->syncTimeline($this->remote()));

		$this->now += 2;
		$this->assertTrue($this->queue->syncTimeline($this->remote()));
	}

	public function testRefreshReturnsQueuedPendingIdleAndFailedWithoutLosingBackoff(): void {
		$actor = $this->remote();
		$this->jobList->method('has')->willReturn(false);
		$this->jobList->expects($this->exactly(2))->method('add');
		$this->assertSame('queued', $this->queue->refreshTimelineState($actor));
		$this->assertSame('pending', $this->queue->refreshTimelineState($actor));
		$this->queue->finishTimelinePage($actor->getId(), true, null);
		$this->assertSame('idle', $this->queue->refreshTimelineState($actor));
		$this->now += RemoteFetchQueue::TIMELINE_SYNC_INTERVAL + 1;
		$this->assertSame('queued', $this->queue->refreshTimelineState($actor));
		$this->queue->finishTimelinePage($actor->getId(), false, null);
		$this->assertSame('failed', $this->queue->refreshTimelineState($actor));
	}

	public function testEachAccountHasItsOwnInterval(): void {
		$this->jobList->method('has')->willReturn(false);
		$this->jobList->expects($this->exactly(2))->method('add');

		$this->assertTrue($this->queue->syncTimeline($this->remote('https://remote.example/users/bob')));
		$this->assertTrue($this->queue->syncTimeline($this->remote('https://remote.example/users/carol')));
	}

	public function testAShortProfilePageQueuesItsSavedOutboxCursor(): void {
		$id = 'https://remote.example/users/bob';
		$cursor = ['url' => 'https://remote.example/outbox?page=2', 'offset' => 0];
		$this->jobList->method('has')->willReturn(false);
		$this->jobList->expects($this->once())->method('add')->with(SyncRemoteTimeline::class, [
			'actor' => $id,
			'continuation' => '1',
		]);

		$this->queue->finishTimelinePage($id, true, $cursor);
		$this->assertTrue($this->queue->fillTimeline($this->remote($id)));
		$this->assertTrue($this->queue->isTimelinePending($id));
	}

	public function testTheOutboxCursorRemainsAvailableUntilEveryPageIsRead(): void {
		$id = 'https://remote.example/users/bob';
		$cursor = ['url' => 'https://remote.example/outbox?page=2', 'offset' => 0];
		$this->jobList->method('has')->willReturn(false);
		$this->jobList->expects($this->exactly(2))->method('add');

		$this->queue->finishTimelinePage($id, true, $cursor);
		$this->assertTrue($this->queue->fillTimeline($this->remote($id)));
		$this->queue->finishTimelinePage($id, true, ['url' => 'https://remote.example/outbox?page=3', 'offset' => 0]);
		$this->assertTrue($this->queue->fillTimeline($this->remote($id)));
		$this->queue->finishTimelinePage($id, true, null);
		$this->assertFalse($this->queue->fillTimeline($this->remote($id)));
	}

	public function testALocalAccountHasNoOutboxToSync(): void {
		$alice = $this->remote('https://cloud.example/apps/social/@alice');
		$alice->setLocal(true);
		$this->jobList->expects($this->never())->method('add');

		$this->assertFalse($this->queue->syncTimeline($alice));
	}

	public function testASyncStillPendingIsNotQueuedTwice(): void {
		$this->jobList->method('has')
			->with(SyncRemoteTimeline::class, ['actor' => 'https://remote.example/users/bob'])
			->willReturn(true);
		$this->jobList->expects($this->never())->method('add');

		$this->queue->syncTimeline($this->remote());
	}

	public function testATimelineSyncQueueFailureCanBeRetried(): void {
		$fail = true;
		$this->jobList->method('has')->willReturnCallback(static function () use (&$fail): bool {
			if ($fail) {
				throw new \RuntimeException('queue storage unavailable');
			}

			return false;
		});
		$this->jobList->expects($this->once())->method('add')->with(SyncRemoteTimeline::class, ['actor' => 'https://remote.example/users/bob']);
		$actor = $this->remote();

		$this->assertFalse($this->queue->syncTimeline($actor));
		$fail = false;
		$this->assertTrue($this->queue->timelineSyncFailed($actor->getId()));
		$this->assertFalse($this->queue->syncTimeline($actor));
		$this->now += RemoteFetchQueue::TIMELINE_RETRY_INTERVAL;
		$this->assertTrue($this->queue->syncTimeline($actor));
		$this->assertFalse($this->queue->syncTimeline($actor));
	}

	public function testFailedOutboxReadIsVisibleAndRetriesOnlyAfterBackoff(): void {
		$actor = $this->remote();
		$this->jobList->method('has')->willReturn(false);
		$this->jobList->expects($this->exactly(2))->method('add');
		$this->assertTrue($this->queue->syncTimeline($actor));
		$this->queue->finishTimelinePage($actor->getId(), false, null);
		$this->assertTrue($this->queue->timelineSyncFailed($actor->getId()));
		$this->assertFalse($this->queue->isTimelinePending($actor->getId()));
		$this->assertFalse($this->queue->syncTimeline($actor));
		$this->assertFalse($this->queue->fillTimeline($actor));
		$this->now += RemoteFetchQueue::TIMELINE_RETRY_INTERVAL;
		$this->assertFalse($this->queue->timelineSyncFailed($actor->getId()));
		$this->assertTrue($this->queue->syncTimeline($actor));
		$this->queue->finishTimelinePage($actor->getId(), true, null);
		$this->assertFalse($this->queue->timelineSyncFailed($actor->getId()));
	}

	public function testEachActorIsQueuedOnceWithoutItsKeyFragment(): void {
		$this->jobList->method('has')->willReturn(false);
		$added = [];
		$this->jobList->method('add')->willReturnCallback(function (string $job, $argument) use (&$added): void {
			$this->assertSame(ResolveActor::class, $job);
			$added[] = $argument;
		});

		$this->queue->resolveActors([
			'https://remote.example/users/bob#main-key',
			'https://remote.example/users/bob',
			'not a url',
			'',
		]);

		$this->assertSame([['id' => 'https://remote.example/users/bob']], $added);
	}

	/** A busy page asked for a hundred times before cron runs is still one job. */
	public function testAnActorAlreadyQueuedIsNotQueuedAgain(): void {
		$this->jobList->method('has')
			->with(ResolveActor::class, ['id' => 'https://remote.example/users/bob'])
			->willReturn(true);
		$this->jobList->expects($this->never())->method('add');

		$this->queue->resolveActors(['https://remote.example/users/bob']);
	}

	public function testAnIncompleteActorProfileIsRefreshedInTheBackgroundAtMostOncePerInterval(): void {
		$id = 'https://bsky.app/profile/did:plc:ann';
		$this->jobList->method('has')->willReturn(false);
		$this->jobList->expects($this->exactly(2))->method('add')
			->with(ResolveActor::class, ['id' => $id, 'refresh' => true]);

		$this->queue->refreshActors([$id . '#main', $id]);
		$this->queue->refreshActors([$id]);
		$this->now += RemoteFetchQueue::ACTOR_REFRESH_INTERVAL + 1;
		$this->queue->refreshActors([$id]);
	}

	public function testOneCallQueuesABoundedNumberOfJobs(): void {
		$ids = [];
		for ($i = 0; $i < 200; $i++) {
			$ids[] = 'https://remote.example/users/u' . $i;
		}
		$this->jobList->method('has')->willReturn(false);
		$this->jobList->expects($this->exactly(RemoteFetchQueue::MAX_PER_CALL))->method('add');

		$this->queue->resolveActors($ids);
	}

	public function testEachAccountIsQueuedOnceByItsHandle(): void {
		$this->jobList->method('has')->willReturn(false);
		$added = [];
		$this->jobList->method('add')->willReturnCallback(function (string $job, $argument) use (&$added): void {
			$this->assertSame(ResolveActor::class, $job);
			$added[] = $argument;
		});

		$this->queue->resolveAccounts(['@bob@remote.example', 'bob@remote.example', 'not a handle', 'https://remote.example/users/bob', '']);

		$this->assertSame([['account' => 'bob@remote.example']], $added);
	}

	public function testAJobListThatFailsDoesNotFailThePage(): void {
		$this->jobList->method('has')->willThrowException(new \RuntimeException('database gone'));

		$this->queue->resolveActors(['https://remote.example/users/bob']);
		$this->addToAssertionCount(1);
	}

	public function testAConversationIsReadAtMostOncePerIntervalAndOnlyWhereItMayHaveRepliesElsewhere(): void {
		$this->jobList->method('has')->willReturn(false);
		$this->jobList->expects($this->exactly(3))->method('add')->with(FillThread::class, $this->anything());
		$remote = (new Note())->setId('https://remote.example/notes/1');
		$public = (new Note())->setId('https://social.test/@alice/2')->setLocal(true);
		$public->setVisibility(Stream::TYPE_PUBLIC);
		$private = (new Note())->setId('https://social.test/@alice/3')->setLocal(true);
		$private->setVisibility(Stream::TYPE_FOLLOWERS);

		$this->assertTrue($this->queue->fillThread($remote));
		$this->assertTrue($this->queue->fillThread($remote), 'the context endpoint keeps reporting a job that is still queued');
		$this->assertTrue($this->queue->isThreadFillPending($remote->getId()));
		$this->queue->completeThreadFill($remote->getId());
		$this->assertFalse($this->queue->isThreadFillPending($remote->getId()));
		$this->assertFalse($this->queue->fillThread($remote), 'a completed thread is not queued again within its interval');
		$this->queue->retryThreadFill($remote->getId());
		$this->assertTrue($this->queue->fillThread($remote), 'a saved continuation can be retried without waiting ten minutes');
		$this->assertTrue($this->queue->fillThread($public), 'a public post of ours is read on other networks too');
		$this->assertFalse($this->queue->fillThread($private), 'nobody elsewhere can reply to it');
		$this->now += RemoteFetchQueue::THREAD_FILL_INTERVAL + 1;
	}

	public function testThreadFillThrottleUsesSharedCacheForWebAndCron(): void {
		$post = (new Note())->setId('https://remote.example/notes/shared-cache');
		$key = md5($post->getId());
		$cache = $this->createMock(DurableCache::class);
		$cache->expects($this->exactly(2))->method('getShared')->willReturnCallback(
			static fn (string $namespace, string $askedKey): mixed => match ([$namespace, $askedKey]) {
				['social.threadfill', $key] => null,
				['social.threadfill.pending', $key] => 1,
				default => throw new \RuntimeException('Unexpected shared cache key'),
			}
		);
		$cache->expects($this->exactly(2))->method('setShared')->willReturnCallback(
			static function (string $namespace, string $askedKey, mixed $value, int $ttl) use ($key): void {
				self::assertSame($key, $askedKey);
				self::assertSame(1, $value);
				self::assertSame(match ($namespace) {
					'social.threadfill' => RemoteFetchQueue::THREAD_FILL_INTERVAL,
					'social.threadfill.pending' => RemoteFetchQueue::THREAD_FILL_INTERVAL + 60,
					default => throw new \RuntimeException('Unexpected shared cache namespace'),
				}, $ttl);
			}
		);
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())->method('has')->with(FillThread::class, ['post' => $post->getId()])->willReturn(false);
		$jobList->expects($this->once())->method('add')->with(FillThread::class, ['post' => $post->getId()]);
		$queue = new RemoteFetchQueue($jobList, $cache, new NullLogger());

		$this->assertTrue($queue->fillThread($post));
		$this->assertTrue($queue->isThreadFillPending($post->getId()));
	}

	public function testAQueueFailureCanBeRetriedOnTheNextContextRequest(): void {
		$fail = true;
		$this->jobList->method('has')->willReturnCallback(static function () use (&$fail): bool {
			if ($fail) {
				throw new \RuntimeException('queue storage unavailable');
			}

			return false;
		});
		$this->jobList->expects($this->once())->method('add')->with(FillThread::class, ['post' => 'https://remote.example/notes/1']);
		$post = (new Note())->setId('https://remote.example/notes/1');

		$this->assertFalse($this->queue->fillThread($post));
		$this->assertFalse($this->queue->isThreadFillPending($post->getId()));
		$fail = false;
		$this->assertTrue($this->queue->fillThread($post));
	}

	public function testWhoReactedIsReadOncePerIntervalAndKind(): void {
		$this->jobList->method('has')->willReturn(false);
		$this->jobList->expects($this->exactly(2))->method('add')->with(FillInteractions::class, $this->anything());
		$remote = (new Note())->setId('https://remote.example/notes/1');

		$this->assertTrue($this->queue->fillInteractions($remote, 'Like'));
		$this->assertFalse($this->queue->fillInteractions($remote, 'Like'));
		$this->assertTrue($this->queue->fillInteractions($remote, 'Announce'));
	}

	public function testAnIncompleteReactionFillCanBeQueuedAgainAfterItFinishes(): void {
		$this->jobList->method('has')->willReturn(false);
		$this->jobList->expects($this->exactly(2))->method('add')->with(FillInteractions::class, [
			'post' => 'https://remote.example/notes/1',
			'type' => 'Like',
		]);
		$post = (new Note())->setId('https://remote.example/notes/1');

		$this->assertTrue($this->queue->fillInteractions($post, 'Like'));
		$this->assertFalse($this->queue->fillInteractions($post, 'Like'), 'an active fill remains deduplicated');
		$this->queue->completeInteractionFill($post->getId(), 'Like');
		$this->assertTrue($this->queue->fillInteractions($post, 'Like'), 'a finished incomplete fill can resume from its cursor');
	}

	public function testAReactionFillQueueFailureCanBeRetriedOnTheNextRequest(): void {
		$fail = true;
		$this->jobList->method('has')->willReturnCallback(static function () use (&$fail): bool {
			if ($fail) {
				throw new \RuntimeException('queue storage unavailable');
			}

			return false;
		});
		$this->jobList->expects($this->once())->method('add')->with(FillInteractions::class, [
			'post' => 'https://remote.example/notes/1',
			'type' => 'Like',
		]);
		$post = (new Note())->setId('https://remote.example/notes/1');

		$this->assertFalse($this->queue->fillInteractions($post, 'Like'));
		$fail = false;
		$this->assertTrue($this->queue->fillInteractions($post, 'Like'));
		$this->assertFalse($this->queue->fillInteractions($post, 'Like'), 'a queued read remains deduplicated');
	}

	public function testAFollowListIsReadOncePerIntervalAndDirection(): void {
		$this->jobList->method('has')->willReturn(false);
		$this->jobList->expects($this->exactly(2))->method('add')->with(FillFollowLists::class, $this->anything());
		$local = $this->remote('https://social.test/@alice')->setLocal(true);

		$this->assertTrue($this->queue->fillFollowList($this->remote(), 'followers'));
		$this->assertFalse($this->queue->fillFollowList($this->remote(), 'followers'), 'once per interval');
		$this->assertTrue($this->queue->fillFollowList($this->remote(), 'following'));
		$this->assertFalse($this->queue->fillFollowList($local, 'followers'), 'this server knows its own accounts');
	}

	public function testAQueuedFollowListStaysPendingUntilItsResultIsCachedOrTheThrottleExpires(): void {
		$this->jobList->method('has')->willReturn(false);
		$this->jobList->expects($this->once())->method('add')->with(FillFollowLists::class, $this->anything());
		$account = $this->remote();

		$this->assertTrue($this->queue->fillFollowList($account, 'followers'));
		$this->assertTrue($this->queue->followListPending($account, 'followers'));

		$this->now += 601;
		$this->assertFalse($this->queue->followListPending($account, 'followers'));
	}

	public function testAFollowListQueueFailureCanBeRetried(): void {
		$fail = true;
		$this->jobList->method('has')->willReturnCallback(static function () use (&$fail): bool {
			if ($fail) {
				throw new \RuntimeException('queue storage unavailable');
			}

			return false;
		});
		$this->jobList->expects($this->once())->method('add')->with(FillFollowLists::class, [
			'actor' => 'https://remote.example/users/bob',
			'direction' => 'followers',
		]);
		$account = $this->remote();

		$this->assertFalse($this->queue->fillFollowList($account, 'followers'));
		$fail = false;
		$this->assertTrue($this->queue->fillFollowList($account, 'followers'));
		$this->assertFalse($this->queue->fillFollowList($account, 'followers'));
	}

	public function testAHashtagIsReadOncePerIntervalAndASearchPerPerson(): void {
		$this->jobList->method('has')->willReturn(false);
		$this->jobList->expects($this->exactly(3))->method('add')->with(FillPosts::class, $this->anything());

		$this->assertTrue($this->queue->fillPosts('tag', 'Nextcloud'));
		$this->assertFalse($this->queue->fillPosts('tag', 'nextcloud'), 'one hashtag, however it is written');
		$this->assertTrue($this->queue->fillPosts('search', 'open source', $this->remote('https://social.test/@alice')));
		$this->assertTrue($this->queue->fillPosts('search', 'open source', $this->remote('https://social.test/@bob')), 'asked as each person');
		$this->assertFalse($this->queue->fillPosts('search', ''));
	}

	public function testAReadOfAHashtagElsewhereSharesTheOneThrottle(): void {
		$this->jobList->method('has')->willReturn(false);
		$this->jobList->expects($this->never())->method('add');

		$this->assertTrue($this->queue->claimPostsFill('tag', 'Nextcloud'), 'nobody read it yet');
		$this->assertFalse($this->queue->fillPosts('tag', 'nextcloud'), 'a page looking at it right after queues nothing');
		$this->assertFalse($this->queue->claimPostsFill('tag', 'nextcloud'));
		$this->assertFalse($this->queue->claimPostsFill('tag', '  '));
	}

	public function testAPostFillQueueFailureCanBeRetried(): void {
		$fail = true;
		$this->jobList->method('has')->willReturnCallback(static function () use (&$fail): bool {
			if ($fail) {
				throw new \RuntimeException('queue storage unavailable');
			}

			return false;
		});
		$this->jobList->expects($this->once())->method('add')->with(FillPosts::class, [
			'kind' => 'tag',
			'term' => 'nextcloud',
			'viewer' => '',
		]);

		$this->assertFalse($this->queue->fillPosts('tag', 'Nextcloud'));
		$fail = false;
		$this->assertTrue($this->queue->fillPosts('tag', 'Nextcloud'));
		$this->assertFalse($this->queue->fillPosts('tag', 'Nextcloud'));
	}

	public function testAPostsCountsAreAskedForAtMostOncePerItsInterval(): void {
		$this->jobList->method('has')->willReturn(false);
		$added = [];
		$this->jobList->method('add')->willReturnCallback(function (string $job, array $argument) use (&$added): void {
			$this->assertSame(RefreshCounts::class, $job);
			$added[] = $argument['posts'];
		});

		$this->assertTrue($this->queue->refreshCounts(['https://r.example/1' => 900, 'https://r.example/2' => 21600]));
		$this->assertFalse($this->queue->refreshCounts(['https://r.example/1' => 900, 'https://r.example/2' => 21600]));
		$this->now += 901;
		$this->assertTrue($this->queue->refreshCounts(['https://r.example/1' => 900, 'https://r.example/2' => 21600]));

		$this->assertSame([['https://r.example/1', 'https://r.example/2'], ['https://r.example/1']], $added);
	}

	public function testAForcedVisibleCountRetryHasItsOwnThrottleAndJobFlag(): void {
		$this->jobList->method('has')->willReturn(false);
		$added = [];
		$this->jobList->method('add')->willReturnCallback(function (string $job, array $argument) use (&$added): void {
			$this->assertSame(RefreshCounts::class, $job);
			$added[] = $argument;
		});

		$this->assertTrue($this->queue->refreshCounts(['https://r.example/1' => 900]));
		$this->assertTrue($this->queue->refreshCounts(['https://r.example/1' => 15], true));
		$this->assertFalse($this->queue->refreshCounts(['https://r.example/1' => 15], true));

		$this->assertSame([
			['posts' => ['https://r.example/1']],
			['posts' => ['https://r.example/1'], 'force' => true],
		], $added);
	}

	public function testACountRefreshQueueFailureCanBeRetriedOnTheNextRequest(): void {
		$fail = true;
		$this->jobList->method('has')->willReturnCallback(static function () use (&$fail): bool {
			if ($fail) {
				throw new \RuntimeException('queue storage unavailable');
			}

			return false;
		});
		$this->jobList->expects($this->once())->method('add')->with(RefreshCounts::class, [
			'posts' => ['https://remote.example/notes/1', 'https://remote.example/notes/2'],
		]);
		$posts = ['https://remote.example/notes/1' => 900, 'https://remote.example/notes/2' => 900];

		$this->assertFalse($this->queue->refreshCounts($posts));
		$fail = false;
		$this->assertTrue($this->queue->refreshCounts($posts));
		$this->assertFalse($this->queue->refreshCounts($posts), 'a successfully queued refresh remains deduplicated');
	}

	public function testOneCallAsksAboutABoundedNumberOfPostsInJobsTheJobListAccepts(): void {
		$this->jobList->method('has')->willReturn(false);
		$asked = [];
		$this->jobList->method('add')->willReturnCallback(function (string $job, array $argument) use (&$asked): void {
			$this->assertLessThanOrEqual(4000, strlen((string)json_encode($argument)));
			$asked = array_merge($asked, $argument['posts']);
		});
		$intervals = [];
		for ($i = 0; $i < 3 * RemoteFetchQueue::MAX_PER_CALL; $i++) {
			$intervals['https://a-rather-long-host-name.example/users/somebody/statuses/' . str_repeat('9', 100) . $i] = 900;
		}

		$this->assertTrue($this->queue->refreshCounts($intervals));
		$this->assertSame(array_slice(array_keys($intervals), 0, RemoteFetchQueue::MAX_PER_CALL), $asked);
	}
}
