<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service\Counts;

use DateTime;
use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Details;
use OCA\Social\Service\Counts\CountWriter;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * What storing a network's totals writes: the origin's halves into
 * `details`, and the totals through the recount that adds this instance's
 * own share inside the statement that stores them.
 */
#[AllowMockObjectsWithoutExpectations]
class CountWriterTest extends TestCase {
	private const POST = 'https://remote.example/notes/1';
	private const NOW = 1790000000;

	private StreamRequest&MockObject $streams;
	private ActionsRequest&MockObject $actions;

	protected function setUp(): void {
		$this->streams = $this->createMock(StreamRequest::class);
		$this->actions = $this->createMock(ActionsRequest::class);
		$this->actions->method('countActions')->willReturnCallback(static fn (string $id, string $type): int => $type === Like::TYPE ? 2 : ($type === Announce::TYPE ? 1 : 0));
		$this->streams->method('countRepliesTo')->willReturn(1);
		$this->streams->method('countQuotesOf')->willReturn(1);
	}

	private function writer(): CountWriter {
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new CountWriter($this->streams, $this->actions, $time);
	}

	public function testTheOriginsTotalsAreStoredAsTheirHalvesAndThenRecounted(): void {
		$post = new Note();
		$post->setId(self::POST);
		$this->streams->method('getStreamById')->with(self::POST)->willReturn($post);

		$calls = [];
		$this->streams->expects($this->once())->method('updateDetails')
			->with($this->identicalTo($post), $this->callback(static fn (DateTime $at): bool => $at->getTimestamp() === self::NOW))
			->willReturnCallback(function () use (&$calls, $post): void {
				$calls[] = 'details';
				$this->assertSame(8, $post->getDetailInt(Details::REMOTE_LIKES));
				$this->assertSame(3, $post->getDetailInt(Details::REMOTE_BOOSTS));
				$this->assertSame(2, $post->getDetailInt(Details::REMOTE_REPLIES));
			});
		$this->streams->expects($this->once())->method('recount')
			->with($this->identicalTo($post), Details::LIKES, Details::BOOSTS, Details::REPLIES, Details::QUOTES)
			->willReturnCallback(function () use (&$calls): void {
				$calls[] = 'recount';
			});

		$this->assertTrue($this->writer()->write(self::POST, 10, 4, 3));
		$this->assertSame(['details', 'recount'], $calls, 'the halves are stored before the recount that adds to them');
	}

	public function testTheQuotesTheOriginCountsAreStoredAsTheirHalfToo(): void {
		$post = new Note();
		$post->setId(self::POST);
		$this->streams->method('getStreamById')->willReturn($post);

		$this->writer()->write(self::POST, null, null, null, [], 5);

		$this->assertSame(4, $post->getDetailInt(Details::REMOTE_QUOTES), 'the one quote held here is added by the recount');
	}

	public function testACountTheOriginDoesNotStateKeepsWhatWasStored(): void {
		$post = new Note();
		$post->setId(self::POST);
		$post->setDetailInt(Details::REMOTE_REPLIES, 7);
		$post->setDetailInt(Details::BOOSTS, 6);
		$this->streams->method('getStreamById')->willReturn($post);

		$this->writer()->write(self::POST, 2, null, null);

		$this->assertSame(0, $post->getDetailInt(Details::REMOTE_LIKES), 'never below zero');
		$this->assertSame(7, $post->getDetailInt(Details::REMOTE_REPLIES));
		$this->assertSame(5, $post->getDetailInt(Details::REMOTE_BOOSTS), 'the total on the row less what is held here');
	}

	public function testANetworksOwnBlockIsMergedIntoWhatThePostHolds(): void {
		$post = new Note();
		$post->setId(self::POST);
		$post->setDetailArray(Details::ATPROTO, ['uri' => 'at://x', 'quotes' => 1]);
		$this->streams->method('getStreamById')->willReturn($post);

		$this->writer()->write(self::POST, 1, 1, 1, [Details::ATPROTO => ['quotes' => 5]]);

		$this->assertSame(['uri' => 'at://x', 'quotes' => 5], $post->getDetails(Details::ATPROTO));
	}

	public function testAPostThatIsGoneIsNotWritten(): void {
		$this->streams->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->streams->expects($this->never())->method('updateDetails');

		$this->assertFalse($this->writer()->write(self::POST, 1, 1, 1));
	}

	public function testAStampLeavesTheCountsAlone(): void {
		$this->streams->expects($this->once())->method('markCountsRefreshed')
			->with(self::POST, $this->callback(static fn (DateTime $at): bool => $at->getTimestamp() === self::NOW));
		$this->streams->expects($this->never())->method('updateDetails');

		$this->writer()->stamp(self::POST);
	}
}
