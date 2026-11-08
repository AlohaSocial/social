<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Service\EnabledUserService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class EnabledUserServiceTest extends TestCase {
	private function service(?bool $enabled): EnabledUserService {
		$user = null;
		if ($enabled !== null) {
			$user = $this->createStub(IUser::class);
			$user->method('isEnabled')->willReturn($enabled);
		}
		$users = $this->createStub(IUserManager::class);
		$users->method('get')->willReturnCallback(static fn (string $uid): ?IUser => $uid === 'alice' ? $user : null);

		return new EnabledUserService($users);
	}

	public function testAnEnabledAccountMaySignIn(): void {
		$this->assertTrue($this->service(true)->isEnabled('alice'));
	}

	public function testADisabledAccountMayNot(): void {
		$this->assertFalse($this->service(false)->isEnabled('alice'));
	}

	public function testNorMayOneThatDoesNotExist(): void {
		$this->assertFalse($this->service(true)->isEnabled('bob'));
		$this->assertFalse($this->service(null)->isEnabled('alice'));
		$this->assertFalse($this->service(true)->isEnabled(''));
	}
}
