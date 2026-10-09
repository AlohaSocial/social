<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Cron\FillThread;
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

	public function testEachAccountHasItsOwnInterval(): void {
		$this->jobList->method('has')->willReturn(false);
		$this->jobList->expects($this->exactly(2))->method('add');

		$this->assertTrue($this->queue->syncTimeline($this->remote('https://remote.example/users/bob')));
		$this->assertTrue($this->queue->syncTimeline($this->remote('https://remote.example/users/carol')));
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

	public function testOneCallQueuesABoundedNumberOfJobs(): void {
		$ids = [];
		for ($i = 0; $i < 200; $i++) {
			$ids[] = 'https://remote.example/users/u' . $i;
		}
		$this->jobList->method('has')->willReturn(false);
		$this->jobList->expects($this->exactly(RemoteFetchQueue::MAX_PER_CALL))->method('add');

		$this->queue->resolveActors($ids);
	}

	public function testAJobListThatFailsDoesNotFailThePage(): void {
		$this->jobList->method('has')->willThrowException(new \RuntimeException('database gone'));

		$this->queue->resolveActors(['https://remote.example/users/bob']);
		$this->addToAssertionCount(1);
	}

	public function testAConversationIsReadAtMostOncePerIntervalAndOnlyWhereItMayHaveRepliesElsewhere(): void {
		$this->jobList->method('has')->willReturn(false);
		$this->jobList->expects($this->exactly(2))->method('add')->with(FillThread::class, $this->anything());
		$remote = (new Note())->setId('https://remote.example/notes/1');
		$public = (new Note())->setId('https://social.test/@alice/2')->setLocal(true);
		$public->setVisibility(Stream::TYPE_PUBLIC);
		$private = (new Note())->setId('https://social.test/@alice/3')->setLocal(true);
		$private->setVisibility(Stream::TYPE_FOLLOWERS);

		$this->assertTrue($this->queue->fillThread($remote));
		$this->assertFalse($this->queue->fillThread($remote), 'once per interval');
		$this->assertTrue($this->queue->fillThread($public), 'a public post of ours is read on other networks too');
		$this->assertFalse($this->queue->fillThread($private), 'nobody elsewhere can reply to it');
		$this->now += RemoteFetchQueue::THREAD_FILL_INTERVAL + 1;
	}
}
