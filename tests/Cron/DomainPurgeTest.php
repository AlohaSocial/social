<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Cron\DomainPurge;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Service\DomainPurgeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The job that finishes a domain purge across cron runs.
 *
 * It has to do a bounded amount of work and then either stop or ask for
 * another run — a job that tried to finish in one pass would own a cron slot
 * for as long as the blocked instance had been federating with us.
 */
#[AllowMockObjectsWithoutExpectations]
class DomainPurgeTest extends TestCase {
	private DomainPurgeService|MockObject $domainPurgeService;
	private IJobList|MockObject $jobList;
	private DomainPurge $job;

	protected function setUp(): void {
		$this->domainPurgeService = $this->createMock(DomainPurgeService::class);
		$this->jobList = $this->createMock(IJobList::class);

		$this->job = new DomainPurge(
			$this->createStub(ITimeFactory::class),
			$this->domainPurgeService,
			$this->jobList,
			new NullLogger()
		);
	}

	private function runJob($argument): void {
		// run() is protected, as every background job's is; the alternative is
		// start(), which would also want a job list to record the run on
		(new \ReflectionMethod(DomainPurge::class, 'run'))->invoke($this->job, $argument);
	}

	public function testTheRunIsBoundedRatherThanRunningToTheEnd(): void {
		$this->domainPurgeService->expects($this->once())->method('purge')
			->with('spam.example', $this->greaterThan(0))
			->willReturn(500);
		$this->domainPurgeService->method('hasRemains')->willReturn(false);

		$this->runJob(['domain' => 'spam.example']);
	}

	public function testAPurgeThatIsNotFinishedAsksForAnotherRun(): void {
		$this->domainPurgeService->method('purge')->willReturn(500);
		$this->domainPurgeService->method('hasRemains')->willReturn(true);
		$this->jobList->expects($this->once())->method('add')
			->with(DomainPurge::class, ['domain' => 'spam.example']);

		$this->runJob(['domain' => 'spam.example']);
	}

	public function testAFinishedPurgeDoesNotQueueItselfAgain(): void {
		$this->domainPurgeService->method('purge')->willReturn(3);
		$this->domainPurgeService->method('hasRemains')->willReturn(false);
		$this->jobList->expects($this->never())->method('add');

		$this->runJob(['domain' => 'spam.example']);
	}

	public function testAJobWithNoDomainDoesNothing(): void {
		// a job listed in info.xml would be added with no argument at install
		// time; this one is queued with a domain or it is not work
		$this->domainPurgeService->expects($this->never())->method('purge');

		$this->runJob([]);
		$this->runJob(null);
	}

	public function testAFailingPurgeIsNotRetriedForEver(): void {
		$this->domainPurgeService->method('purge')
			->willThrowException(new InvalidResourceException('not a domain'));
		$this->jobList->expects($this->never())->method('add');

		$this->runJob(['domain' => 'spam.example']);

		$this->assertTrue(true, 'the job swallowed the failure instead of propagating it');
	}
}
