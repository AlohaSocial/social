<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Cron\ResolveActor;
use OCA\Social\Service\RemoteFetchQueue;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** What a page hands to the background instead of fetching it while it renders. */
#[AllowMockObjectsWithoutExpectations]
class RemoteFetchQueueTest extends TestCase {
	private IJobList|MockObject $jobList;
	private RemoteFetchQueue $queue;

	protected function setUp(): void {
		$this->jobList = $this->createMock(IJobList::class);
		$this->queue = new RemoteFetchQueue($this->jobList, new NullLogger());
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
}
