<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Migration\CacheFeaturedCollections;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ConfigService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * The repair step that rebuilds the cached copy of every local actor, and the
 * guard that hands the job to the admin on a large instance instead.
 */
#[AllowMockObjectsWithoutExpectations]
class CacheFeaturedCollectionsTest extends TestCase {
	private ActorsRequest|MockObject $actorsRequest;
	private AccountService|MockObject $accountService;
	private ConfigService|MockObject $configService;
	private IOutput|Stub $output;

	protected function setUp(): void {
		parent::setUp();
		$this->actorsRequest = $this->createMock(ActorsRequest::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->output = $this->createStub(IOutput::class);
	}

	private function step(FakeConnection $connection): CacheFeaturedCollections {
		return new CacheFeaturedCollections(
			$this->actorsRequest,
			$this->accountService,
			$this->configService,
			$connection,
		);
	}

	private function actor(string $username): Person {
		$actor = new Person();
		$actor->setPreferredUsername($username);

		return $actor;
	}

	public function testTooManyActorsAreCountedRatherThanLoaded(): void {
		// the guard exists to keep `occ upgrade` short on a large instance;
		// getAll() builds a Person per row with no limit, so asking it how many
		// there are does the very work being declined — and can run the upgrade
		// out of memory before the guard is reached
		$connection = new FakeConnection([[['total' => 501]]]);
		$this->actorsRequest->expects($this->never())->method('getAll');
		$this->accountService->expects($this->never())->method('cacheLocalActorByUsername');

		$warnings = [];
		$this->output->method('warning')->willReturnCallback(
			function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);
		$this->configService->expects($this->once())
			->method('setAppValue')
			->with('migration_local_actor_cache', '2');

		$this->step($connection)->run($this->output);

		$this->assertCount(1, $warnings);
		$this->assertStringContainsString('501 local actors', $warnings[0]);
		$this->assertStringContainsString('occ social:cache:refresh', $warnings[0]);
		$this->assertSame(['COUNT(*) AS total'], $connection->queries[0]->selects);
		$this->assertSame('social_actor', $connection->queries[0]->table);
	}

	public function testASmallInstanceIsRefreshedInline(): void {
		$connection = new FakeConnection([[['total' => 2]]]);
		$this->actorsRequest->method('getAll')->willReturn([
			$this->actor('alice'), $this->actor('bob'),
		]);

		$refreshed = [];
		$this->accountService->method('cacheLocalActorByUsername')->willReturnCallback(
			function (string $username) use (&$refreshed): void {
				$refreshed[] = $username;
			}
		);

		$this->step($connection)->run($this->output);

		$this->assertSame(['alice', 'bob'], $refreshed);
	}

	public function testAnActorThatCannotBeRefreshedDoesNotStopTheOthers(): void {
		$connection = new FakeConnection([[['total' => 2]]]);
		$this->actorsRequest->method('getAll')->willReturn([
			$this->actor('broken'), $this->actor('bob'),
		]);
		$this->accountService->method('cacheLocalActorByUsername')->willReturnCallback(
			static function (string $username): void {
				if ($username === 'broken') {
					throw new \Exception('no such user');
				}
			}
		);

		$warnings = [];
		$this->output->method('warning')->willReturnCallback(
			function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);

		$this->step($connection)->run($this->output);

		$this->assertCount(1, $warnings);
		$this->assertStringContainsString('broken', $warnings[0]);
	}

	public function testTheMarkerKeepsItFromRunningTwice(): void {
		$connection = new FakeConnection([[['total' => 2]]]);
		$this->configService->method('getAppValueInt')->willReturn(2);
		$this->actorsRequest->expects($this->never())->method('getAll');

		$this->step($connection)->run($this->output);

		$this->assertSame([], $connection->queries);
	}
}
