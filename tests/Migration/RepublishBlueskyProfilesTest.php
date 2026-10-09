<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Cron\AtprotoPublish;
use OCA\Social\Migration\RepublishBlueskyProfiles;
use OCA\Social\Service\ConfigService;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class RepublishBlueskyProfilesTest extends TestCase {
	/** @var list<array> */
	private array $queued = [];
	private int $marker = 0;

	private function step(bool $enabled): RepublishBlueskyProfiles {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn($enabled);
		$identities = $this->createMock(IdentityService::class);
		$identities->method('getAll')->willReturnCallback(static fn (int $limit, int $offset): array => $offset > 0 ? [] : [
			new Identity(1, 'https://social.test/users/alice', 'did:plc:alice', 'alice.social.test', 'sealed', '', '', Identity::STATE_ACTIVE, '', 0),
			new Identity(2, 'https://social.test/users/bob', 'did:plc:bob', 'bob.social.test', 'sealed', '', '', Identity::STATE_DEACTIVATED, '', 0),
		]);
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('add')->willReturnCallback(function (string $job, mixed $argument): void {
			$this->assertSame(AtprotoPublish::class, $job);
			$this->queued[] = $argument;
		});
		$configService = $this->createMock(ConfigService::class);
		$configService->method('getAppValueInt')->willReturnCallback(fn (): int => $this->marker);
		$configService->method('setAppValue')->willReturnCallback(function (string $key, string $value): void {
			$this->marker = (int)$value;
		});

		return new RepublishBlueskyProfiles($config, $identities, $jobs, $configService);
	}

	public function testEveryAccountOnBlueskyHasItsProfileWrittenOnce(): void {
		$this->step(true)->run($this->createMock(IOutput::class));
		$this->step(true)->run($this->createMock(IOutput::class));

		$this->assertSame([['action' => 'profile', 'id' => 'https://social.test/users/alice']], $this->queued, 'not the account switched off, and only once');
	}

	public function testNothingWhileBlueskyIsOffAndNotAgainLater(): void {
		$this->step(false)->run($this->createMock(IOutput::class));

		$this->assertSame([], $this->queued);
		$this->assertSame(1, $this->marker);
	}
}
