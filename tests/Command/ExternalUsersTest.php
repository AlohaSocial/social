<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Command;

use OCA\Social\Command\ExternalUsers;
use OCA\Social\Exceptions\ExternalUserException;
use OCA\Social\Service\ExternalUserService;
use OCP\IUser;
use OCP\Security\IHasher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ExternalUsersTest extends TestCase {
	protected function tearDown(): void {
		putenv('OC_PASS');
	}

	private function tester(ExternalUserService $service): CommandTester {
		$hasher = $this->createStub(IHasher::class);
		$hasher->method('hash')->willReturnCallback(static fn (string $p): string => 'hash:' . $p);

		return new CommandTester(new ExternalUsers($service, $hasher));
	}

	public function testTheListSaysHowManyThereAreOfHowMany(): void {
		$service = $this->createStub(ExternalUserService::class);
		$service->method('maxAccounts')->willReturn(100);
		$service->method('list')->willReturn([
			['uid' => 'alice', 'displayName' => 'Alice', 'email' => 'a@example.org', 'created' => 0, 'lastLogin' => 0, 'enabled' => false, 'mediaBytes' => 0, 'origin' => 'open'],
		]);
		$tester = $this->tester($service);

		$this->assertSame(0, $tester->execute(['action' => 'list']));
		$this->assertStringContainsString('1 of at most 100 external user(s)', $tester->getDisplay());
		$this->assertStringContainsString('alice', $tester->getDisplay());
		$this->assertStringContainsString('(disabled)', $tester->getDisplay());
	}

	public function testAddingTakesThePasswordFromTheEnvironmentOnly(): void {
		$service = $this->createMock(ExternalUserService::class);
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$service->expects($this->once())->method('createAccount')
			->with('alice', 'a@example.org', 'hash:s3cret-pass', 'occ')->willReturn($user);
		$tester = $this->tester($service);

		$this->assertSame(1, $tester->execute(['action' => 'add', 'handle' => 'alice', '--email' => 'a@example.org']));

		putenv('OC_PASS=s3cret-pass');
		$this->assertSame(0, $tester->execute(['action' => 'add', 'handle' => 'alice', '--email' => 'a@example.org', '--password-from-env' => true]));
		$this->assertStringContainsString('created the external user alice', $tester->getDisplay());
	}

	public function testARefusalIsPrintedAndExitsWithOne(): void {
		$service = $this->createStub(ExternalUserService::class);
		$service->method('promote')->willThrowException(new ExternalUserException('This is not an external user.'));
		$tester = $this->tester($service);

		$this->assertSame(1, $tester->execute(['action' => 'promote', 'handle' => 'bob']));
		$this->assertStringContainsString('This is not an external user.', $tester->getDisplay());
		$this->assertSame(1, $tester->execute(['action' => 'promote']));
		$this->assertSame(1, $tester->execute(['action' => 'explode']));
	}
}
