<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service\Thread;

use OCA\Social\Atproto\Reader\BlueskyThreadSource;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\Thread\ActivityPubThreadSource;
use OCA\Social\Service\Thread\ThreadService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class ThreadServiceTest extends TestCase {
	/** A post written here and published to Bluesky is asked of both. */
	public function testEveryNetworkThePostIsOnIsAskedWithWhatIsLeftOfTheBudget(): void {
		$post = (new Note())->setId('https://social.test/@alice/1');
		$streams = $this->createMock(StreamRequest::class);
		$streams->method('getStreamById')->willReturn($post);
		$fediverse = $this->createMock(ActivityPubThreadSource::class);
		$fediverse->method('supports')->willReturn(true);
		$fediverse->expects($this->once())->method('fill')->with($post, ThreadService::BUDGET)->willReturn(100);
		$bluesky = $this->createMock(BlueskyThreadSource::class);
		$bluesky->method('supports')->willReturn(true);
		$bluesky->expects($this->once())->method('fill')->with($post, ThreadService::BUDGET - 100)->willReturn(7);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([[ActivityPubThreadSource::class, $fediverse], [BlueskyThreadSource::class, $bluesky]]);

		$this->assertSame(107, (new ThreadService($streams, new NullLogger(), $container))->fill($post->getId()));
	}
}
