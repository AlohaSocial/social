<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service\Discovery;

use OCA\Social\Db\FollowedTagsRequest;
use OCA\Social\Service\Discovery\FollowedTagsFill;
use OCA\Social\Service\Discovery\PostDiscoveryService;
use OCA\Social\Service\RemoteFetchQueue;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class FollowedTagsFillTest extends TestCase {
	private const NOW = 1_800_000_000;

	private FollowedTagsRequest&MockObject $followedTags;
	private PostDiscoveryService&MockObject $discovery;
	private RemoteFetchQueue&MockObject $queue;
	/** @var list<array{0: string, 1: int, 2: int}> what was read: tag, limit, since */
	private array $read = [];
	/** @var array<string, int> the tags stamped read, and when */
	private array $marked = [];

	protected function setUp(): void {
		$this->followedTags = $this->createMock(FollowedTagsRequest::class);
		$this->followedTags->method('markFilled')->willReturnCallback(function (string $tag, int $time): void {
			$this->marked[$tag] = $time;
		});
		$this->discovery = $this->createMock(PostDiscoveryService::class);
		$this->discovery->method('fillTag')->willReturnCallback(function (string $tag, int $limit, int $since): int {
			$this->read[] = [$tag, $limit, $since];

			return 3;
		});
		$this->queue = $this->createMock(RemoteFetchQueue::class);
	}

	private function fill(): FollowedTagsFill {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new FollowedTagsFill($this->followedTags, $this->discovery, $this->queue, $time, new NullLogger());
	}

	public function testTheTagsReadLongestAgoAreReadOnEveryNetworkAndStamped(): void {
		$this->queue->method('claimPostsFill')->willReturn(true);
		$this->followedTags->expects($this->once())->method('dueForFill')->with(FollowedTagsFill::TAGS)->willReturn([
			['hashtag' => 'nextcloud', 'filled' => 0],
			['hashtag' => 'fediverse', 'filled' => self::NOW - 3600],
		]);

		$this->assertSame(6, $this->fill()->run());

		$this->assertSame([
			['nextcloud', FollowedTagsFill::LIMIT, self::NOW - FollowedTagsFill::WINDOW],
			['fediverse', FollowedTagsFill::LIMIT, self::NOW - 3600 - 600],
		], $this->read, 'a first read goes back two days; a later one to the last, with a little overlap');
		$this->assertSame(['nextcloud' => self::NOW, 'fediverse' => self::NOW], $this->marked);
	}

	public function testATagNotReadForLongStillGoesBackNoFurtherThanTheWindow(): void {
		$this->queue->method('claimPostsFill')->willReturn(true);

		$this->fill()->fill('nextcloud', self::NOW - 30 * 86400);

		$this->assertSame([['nextcloud', FollowedTagsFill::LIMIT, self::NOW - FollowedTagsFill::WINDOW]], $this->read);
	}

	public function testATagAPageHasJustHadReadIsNotReadAgainButWaitsItsTurn(): void {
		$this->queue->expects($this->once())->method('claimPostsFill')->with(PostDiscoveryService::TAG, 'nextcloud')->willReturn(false);

		$this->assertSame(0, $this->fill()->fill('nextcloud'));

		$this->assertSame([], $this->read);
		$this->assertSame(['nextcloud' => self::NOW], $this->marked, 'to the back of the line');
	}

	public function testATagIsReadInTheFormItIsFollowedIn(): void {
		$this->queue->method('claimPostsFill')->willReturn(true);

		$this->fill()->fill('#NextCloud');
		$this->assertSame(0, $this->fill()->fill('#'));

		$this->assertSame('nextcloud', $this->read[0][0]);
		$this->assertCount(1, $this->read, 'nothing is read for what is not a tag');
	}

	public function testOneTagFailingDoesNotStopTheOthers(): void {
		$this->queue->method('claimPostsFill')->willReturnCallback(static function (string $kind, string $tag): bool {
			if ($tag === 'broken') {
				throw new \RuntimeException('down');
			}

			return true;
		});
		$this->followedTags->method('dueForFill')->willReturn([
			['hashtag' => 'broken', 'filled' => 0],
			['hashtag' => 'nextcloud', 'filled' => 0],
		]);

		$this->assertSame(3, $this->fill()->run());
		$this->assertSame('nextcloud', $this->read[0][0]);
	}
}
