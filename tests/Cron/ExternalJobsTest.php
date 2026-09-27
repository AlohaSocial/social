<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\DAV\CardDAV\SyncService;
use OCA\Social\Cron\ExternalPromoted;
use OCA\Social\Cron\ExternalSignups;
use OCA\Social\Service\ExternalSignupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ExternalJobsTest extends TestCase {
	protected function tearDown(): void {
		\OC::$server->reset();
	}

	private function runJob(object $job, $argument = null): void {
		$method = new \ReflectionMethod($job, 'run');
		$method->invoke($job, $argument);
	}

	public function testTheHourlyJobForgetsWhatExpired(): void {
		$signups = $this->createMock(ExternalSignupService::class);
		$signups->expects($this->once())->method('purge')->willReturn(3);

		$this->runJob(new ExternalSignups($this->createStub(ITimeFactory::class), $signups, new NullLogger()));
	}

	public function testAFailingPurgeIsLeftForTheNextHour(): void {
		$signups = $this->createStub(ExternalSignupService::class);
		$signups->method('purge')->willThrowException(new \RuntimeException('db gone'));

		$this->runJob(new ExternalSignups($this->createStub(ITimeFactory::class), $signups, new NullLogger()));

		$this->addToAssertionCount(1);
	}

	public function testAPromotedUserGetsTheirAddressBookCard(): void {
		$sync = new SyncService();
		\OC::$server->register(SyncService::class, $sync);
		$user = $this->createStub(IUser::class);
		$users = $this->createStub(IUserManager::class);
		$users->method('get')->willReturnCallback(static fn (string $uid): ?IUser => ($uid === 'frank') ? $user : null);
		$job = new ExternalPromoted($this->createStub(ITimeFactory::class), $users, new NullLogger());

		$this->runJob($job, ['uid' => 'frank']);
		$this->runJob($job, ['uid' => 'nobody']);
		$this->runJob($job, 'garbage');

		$this->assertSame([$user], $sync->updated);
	}
}
