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
	public function testAPageIsCheckedAndThePlaceKeptThenItStartsOver(): void {
		$streams = $this->createMock(StreamRequest::class);
		$store = $this->createMock(PostStore::class);
		$config = $this->createMock(ConfigService::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1760000000);
		$cursor = '0';
		$config->method('getAppValue')->willReturnCallback(static function (string $key) use (&$cursor): string {
			return $key === ConfigService::ATPROTO_DELETE_CURSOR ? $cursor : '';
		});
		$config->method('setAppValue')->willReturnCallback(static function (string $key, string $value) use (&$cursor): void {
			$cursor = $value;
		});
		$streams->method('getBlueskyPostIds')->willReturnCallback(static fn (int $since, int $after, int $limit): array => match ($after) {
			0 => [11 => 'https://bsky.app/profile/did:plc:a/post/1', 17 => 'https://bsky.app/profile/did:plc:a/post/2'],
			default => [],
		});
		$store->expects($this->once())->method('deleteGone')->with(['https://bsky.app/profile/did:plc:a/post/1', 'https://bsky.app/profile/did:plc:a/post/2'])->willReturn(1);
		$sweep = new DeletionSweep($streams, $store, $config, $time);

		$this->assertSame(1, $sweep->run());
		$this->assertSame('17', $cursor);
		$this->assertSame(0, $sweep->run(), 'the end: nothing left this round');
		$this->assertSame('0', $cursor, 'the next run starts over');
	}
}
