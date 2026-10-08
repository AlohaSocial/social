<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Cron\SyncRemoteTimeline;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\StreamService;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** The background half of opening a remote profile. */
class SyncRemoteTimelineTest extends TestCase {
	private CacheActorService|MockObject $cacheActorService;
	private StreamService|MockObject $streamService;
	private SyncRemoteTimeline $job;

	protected function setUp(): void {
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->streamService = $this->createMock(StreamService::class);
		$this->job = new SyncRemoteTimeline(
			$this->createStub(ITimeFactory::class),
			$this->cacheActorService,
			$this->streamService,
			new NullLogger()
		);
	}

	private function runJob($argument): void {
		(new \ReflectionMethod(SyncRemoteTimeline::class, 'run'))->invoke($this->job, $argument);
	}

	public function testTheActorsOutboxIsSynced(): void {
		$bob = new Person();
		$this->cacheActorService->expects($this->once())->method('getFromId')
			->with('https://remote.example/users/bob')->willReturn($bob);
		$this->streamService->expects($this->once())->method('syncRemoteTimeline')->with($bob);

		$this->runJob(['actor' => 'https://remote.example/users/bob']);
	}

	public function testAnActorThatCannotBeResolvedEndsTheJobQuietly(): void {
		$this->cacheActorService->expects($this->once())->method('getFromId')->willThrowException(new RequestNetworkException());
		$this->streamService->expects($this->never())->method('syncRemoteTimeline');

		$this->runJob(['actor' => 'https://gone.example/users/carol']);
	}

	public function testAJobWithNoActorDoesNothing(): void {
		$this->cacheActorService->expects($this->never())->method('getFromId');
		$this->streamService->expects($this->never())->method('syncRemoteTimeline');

		$this->runJob(null);
		$this->runJob(['actor' => '']);
	}
}
