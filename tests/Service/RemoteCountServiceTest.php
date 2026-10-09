<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\Counts\CountService;
use OCA\Social\Service\RemoteCountService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The cron's pass over the remote posts nobody looked at: the ones due are
 * handed to the network each lives on, a round at a time, until the pass's
 * deadline.
 */
#[AllowMockObjectsWithoutExpectations]
class RemoteCountServiceTest extends TestCase {
	/** @return Note[] */
	private function posts(int $count): array {
		$posts = [];
		for ($i = 0; $i < $count; $i++) {
			$post = new Note();
			$post->setId('https://remote.example/notes/' . $i);
			$posts[] = $post;
		}

		return $posts;
	}

	public function testTheDuePostsAreHandedOverARoundAtATime(): void {
		$streamRequest = $this->createMock(StreamRequest::class);
		$streamRequest->expects($this->once())->method('getRemoteStreamsDueForCounts')->with($this->anything(), 30)->willReturn($this->posts(30));
		$counts = $this->createMock(CountService::class);
		$counts->expects($this->exactly(2))->method('refreshPosts')
			->willReturnCallback(static fn (array $posts): array => ['asked' => count($posts), 'answered' => 1]);

		$service = new RemoteCountService($streamRequest, $counts, $this->createStub(ITimeFactory::class), new NullLogger());

		$this->assertSame(['asked' => 30, 'answered' => 2], $service->refresh(false, 30));
	}

	public function testAPassPastItsDeadlineLeavesTheRestForTheNext(): void {
		$streamRequest = $this->createMock(StreamRequest::class);
		$streamRequest->method('getRemoteStreamsDueForCounts')->willReturn($this->posts(RemoteCountService::PARALLEL + 1));
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnOnConsecutiveCalls(100, 200);
		$counts = $this->createMock(CountService::class);
		$counts->expects($this->once())->method('refreshPosts')->willReturn(['asked' => RemoteCountService::PARALLEL, 'answered' => 0]);

		$service = new RemoteCountService($streamRequest, $counts, $time, new NullLogger());

		$this->assertSame(['asked' => RemoteCountService::PARALLEL, 'answered' => 0], $service->refresh(true, 0, 150));
	}
}
