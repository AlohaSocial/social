<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Publisher;

use OCA\Social\Atproto\Publisher\InteractionQueue;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Cron\AtprotoPublish;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class InteractionQueueTest extends TestCase {
	public function testEachActionQueuesOneJobWhileBlueskyIsOn(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$jobs = $this->createMock(IJobList::class);
		$queued = [];
		$jobs->method('add')->willReturnCallback(static function (string $job, $argument) use (&$queued): void {
			$queued[] = [$job, $argument];
		});
		$queue = new InteractionQueue($config, $jobs);

		$queue->liked('https://social.test/@alice', 'https://bsky.app/profile/did:plc:x/post/1', 'like-1');
		$queue->unliked('like-1');
		$queue->boosted('https://social.test/@alice', 'https://social.test/@bob/1', 'announce-1');
		$queue->unboosted('announce-1');
		$queue->unliked('');

		$this->assertSame([
			[AtprotoPublish::class, ['action' => 'like', 'id' => 'like-1', 'post' => 'https://bsky.app/profile/did:plc:x/post/1', 'actor' => 'https://social.test/@alice']],
			[AtprotoPublish::class, ['action' => 'unlike', 'id' => 'like-1']],
			[AtprotoPublish::class, ['action' => 'repost', 'id' => 'announce-1', 'post' => 'https://social.test/@bob/1', 'actor' => 'https://social.test/@alice']],
			[AtprotoPublish::class, ['action' => 'unrepost', 'id' => 'announce-1']],
		], $queued);
	}

	public function testNothingIsQueuedWhileBlueskyIsOff(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(false);
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->never())->method('add');
		(new InteractionQueue($config, $jobs))->liked('a', 'p', 'l');
	}
}
