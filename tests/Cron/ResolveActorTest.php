<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Cron\ResolveActor;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** The background half of a page that named an actor it did not have. */
class ResolveActorTest extends TestCase {
	private CacheActorService|MockObject $cacheActorService;
	private ResolveActor $job;

	protected function setUp(): void {
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->job = new ResolveActor(
			$this->createStub(ITimeFactory::class),
			$this->cacheActorService,
			new NullLogger()
		);
	}

	private function runJob($argument): void {
		(new \ReflectionMethod(ResolveActor::class, 'run'))->invoke($this->job, $argument);
	}

	public function testTheActorIsResolvedThroughTheCache(): void {
		$this->cacheActorService->expects($this->once())->method('getFromId')
			->with('https://remote.example/users/bob')
			->willReturn(new Person());

		$this->runJob(['id' => 'https://remote.example/users/bob']);
	}

	public function testAnUnreachableActorEndsTheJobQuietly(): void {
		$this->cacheActorService->expects($this->once())->method('getFromId')
			->willThrowException(new RequestNetworkException());

		$this->runJob(['id' => 'https://gone.example/users/carol']);
	}

	public function testAJobWithNoIdDoesNothing(): void {
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$this->runJob(null);
		$this->runJob(['id' => '']);
	}
}
