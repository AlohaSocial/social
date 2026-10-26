<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\Atproto\Reader\DeletionSweep;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Service\ConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class DeletionSweepTest extends TestCase {
	public function testAnUnansweredAppViewDoesNotAdvanceTheDeletionCursor(): void {
		$streams = $this->createMock(StreamRequest::class);
		$store = $this->createMock(PostStore::class);
		$config = $this->createMock(ConfigService::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1760000000);
		$cursor = '0';
		$config->method('getAppValue')->willReturnCallback(static function (string $key) use (&$cursor): string {
			return match ($key) {
				ConfigService::ATPROTO_DELETE_CURSOR => $cursor,
				ConfigService::ATPROTO_DELETE_MAX_NID => '0',
				default => '',
			};
		});
		$streams->method('getBlueskyPostMaxNid')->willReturn(11);
		$streams->method('getBlueskyPostIds')->willReturn([11 => 'https://bsky.app/profile/did:plc:a/post/1']);
		$store->expects($this->once())->method('deleteGone')->willReturn(null);

		$sweep = new DeletionSweep($streams, $store, $config, $time);

		$this->assertSame(0, $sweep->run());
		$this->assertSame('0', $cursor, 'an incomplete AppView response keeps the current page retryable');
	}

	public function testAPageIsCheckedAndThePlaceKeptThenItStartsOver(): void {
		$streams = $this->createMock(StreamRequest::class);
		$store = $this->createMock(PostStore::class);
		$config = $this->createMock(ConfigService::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1760000000);
		$cursor = '0';
		$upper = '0';
		$streams->method('getBlueskyPostMaxNid')->willReturn(17);
		$config->method('getAppValue')->willReturnCallback(static function (string $key) use (&$cursor, &$upper): string {
			return match ($key) {
				ConfigService::ATPROTO_DELETE_CURSOR => $cursor,
				ConfigService::ATPROTO_DELETE_MAX_NID => $upper,
				default => '',
			};
		});
		$config->method('setAppValue')->willReturnCallback(static function (string $key, string $value) use (&$cursor, &$upper): void {
			if ($key === ConfigService::ATPROTO_DELETE_CURSOR) {
				$cursor = $value;
			} elseif ($key === ConfigService::ATPROTO_DELETE_MAX_NID) {
				$upper = $value;
			}
		});
		$streams->method('getBlueskyPostIds')->willReturnCallback(static function (int $since, int $after, int $limit, ?int $through): array {
			if ($through !== 17) {
				return [];
			}
			return $after === 0
				? [11 => 'https://bsky.app/profile/did:plc:a/post/1', 17 => 'https://bsky.app/profile/did:plc:a/post/2']
				: [];
		});
		$store->expects($this->once())->method('deleteGone')->with(['https://bsky.app/profile/did:plc:a/post/1', 'https://bsky.app/profile/did:plc:a/post/2'])->willReturn(1);
		$sweep = new DeletionSweep($streams, $store, $config, $time);

		$this->assertSame(1, $sweep->run());
		$this->assertSame('17', $cursor);
		$this->assertSame(0, $sweep->run(), 'the end: nothing left this round');
		$this->assertSame('0', $cursor, 'the next run starts over');
		$this->assertSame('0', $upper, 'a completed bounded cycle releases its snapshot ceiling');
	}

	public function testAConfirmedPageAdvancesEvenWhenNoPostWasDeleted(): void {
		$streams = $this->createMock(StreamRequest::class);
		$store = $this->createMock(PostStore::class);
		$config = $this->createMock(ConfigService::class);
		$time = $this->createMock(ITimeFactory::class);
		$cursor = '0';
		$upper = '0';
		$streams->method('getBlueskyPostMaxNid')->willReturn(11);
		$config->method('getAppValue')->willReturnCallback(static function (string $key) use (&$cursor, &$upper): string {
			return match ($key) {
				ConfigService::ATPROTO_DELETE_CURSOR => $cursor,
				ConfigService::ATPROTO_DELETE_MAX_NID => $upper,
				default => '',
			};
		});
		$config->expects($this->exactly(2))->method('setAppValue')->willReturnCallback(static function (string $key, string $value) use (&$cursor, &$upper): void {
			if ($key === ConfigService::ATPROTO_DELETE_CURSOR) {
				$cursor = $value;
			} elseif ($key === ConfigService::ATPROTO_DELETE_MAX_NID) {
				$upper = $value;
			}
		});
		$streams->expects($this->once())->method('getBlueskyPostIds')->with(0, 0, DeletionSweep::PAGE, 11)
			->willReturn([11 => 'https://bsky.app/profile/did:plc:a/post/1']);
		$store->expects($this->once())->method('deleteGone')->willReturn(0);

		$sweep = new DeletionSweep($streams, $store, $config, $time);

		$this->assertSame(0, $sweep->run());
		$this->assertSame('11', $cursor, 'a live or inconclusive individual post does not block later pages');
	}

	public function testAnInconclusivePostIsRetriedAfterTheCursorCompletesARound(): void {
		$streams = $this->createMock(StreamRequest::class);
		$store = $this->createMock(PostStore::class);
		$config = $this->createMock(ConfigService::class);
		$time = $this->createMock(ITimeFactory::class);
		$cursor = '0';
		$upper = '0';
		$streams->method('getBlueskyPostMaxNid')->willReturn(11);
		$config->method('getAppValue')->willReturnCallback(static function (string $key) use (&$cursor, &$upper): string {
			return match ($key) {
				ConfigService::ATPROTO_DELETE_CURSOR => $cursor,
				ConfigService::ATPROTO_DELETE_MAX_NID => $upper,
				default => '',
			};
		});
		$config->method('setAppValue')->willReturnCallback(static function (string $key, string $value) use (&$cursor, &$upper): void {
			if ($key === ConfigService::ATPROTO_DELETE_CURSOR) {
				$cursor = $value;
			} elseif ($key === ConfigService::ATPROTO_DELETE_MAX_NID) {
				$upper = $value;
			}
		});
		$streams->method('getBlueskyPostIds')->willReturnCallback(static fn (int $since, int $after, int $limit, ?int $through): array => $after === 0 && $through === 11
			? [11 => 'https://bsky.app/profile/did:plc:a/post/1']
			: []);
		$store->expects($this->exactly(2))->method('deleteGone')
			->with(['https://bsky.app/profile/did:plc:a/post/1'])
			->willReturnOnConsecutiveCalls(0, 1);
		$sweep = new DeletionSweep($streams, $store, $config, $time);

		$this->assertSame(0, $sweep->run(), 'an inconclusive PDS lookup does not delete the post');
		$this->assertSame('11', $cursor);
		$this->assertSame(0, $sweep->run(), 'the sweep reaches the end and resets its cursor');
		$this->assertSame('0', $cursor);
		$this->assertSame(1, $sweep->run(), 'the next round checks the previously inconclusive post again');
	}
}
