<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Cron\FillFollowLists;
use OCA\Social\Service\FollowList\FollowListService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

class FillFollowListsTest extends TestCase {
	public function testTheAccountAndDirectionItWasQueuedWithAreRead(): void {
		$followLists = $this->createMock(FollowListService::class);
		$followLists->expects($this->once())->method('fill')->with('https://remote.example/users/bob', 'following');
		$job = new FillFollowLists($this->createStub(ITimeFactory::class), $followLists);
		$run = new \ReflectionMethod($job, 'run');

		$run->invoke($job, ['actor' => 'https://remote.example/users/bob', 'direction' => 'following']);
		$run->invoke($job, ['actor' => '']);
		$run->invoke($job, null);
	}
}
