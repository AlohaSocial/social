<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Service\ExternalUserService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Whether people may sign up, and how many still can: what the instance
 * entity, NodeInfo and the Aloha Directory heartbeat report alike.
 */
#[AllowMockObjectsWithoutExpectations]
class ExternalUserServiceRegistrationsTest extends TestCase {
	/**
	 * @return array<string, array{bool, string, int, int, bool, int}>
	 */
	public static function states(): array {
		return [
			'open with room' => [true, ExternalUserService::MODE_OPEN, 100, 40, true, 60],
			'approval with room' => [true, ExternalUserService::MODE_APPROVAL, 100, 40, true, 60],
			'invitations only' => [true, ExternalUserService::MODE_INVITE, 100, 40, false, 60],
			'full' => [true, ExternalUserService::MODE_OPEN, 100, 100, false, 0],
			'over the limit' => [true, ExternalUserService::MODE_OPEN, 10, 12, false, 0],
			'switched off' => [false, ExternalUserService::MODE_OPEN, 100, 0, false, 0],
		];
	}

	#[DataProvider('states')]
	public function testRegistrationsAndFreeAccounts(bool $enabled, string $mode, int $max, int $count, bool $open, int $free): void {
		$service = $this->getMockBuilder(ExternalUserService::class)
			->disableOriginalConstructor()
			->onlyMethods(['isEnabled', 'mode', 'maxAccounts', 'count'])
			->getMock();
		$service->method('isEnabled')->willReturn($enabled);
		$service->method('mode')->willReturn($mode);
		$service->method('maxAccounts')->willReturn($max);
		$service->method('count')->willReturn($count);

		$this->assertSame($open, $service->acceptsPublicRegistrations());
		$this->assertSame($free, $service->freeAccounts());
	}
}
