<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\WatchRequest;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Where somebody stopped in a video, for "continue watching".
 */
class WatchRequestTest extends TestCase {
	private const ALICE = 'https://itest.example/users/watch-alice';
	private const BOB = 'https://itest.example/users/watch-bob';
	private const VIDEO = 'https://itest.example/videos/watch-1';
	private const SHORT = 'https://itest.example/videos/watch-2';

	private WatchRequest $watch;

	protected function setUp(): void {
		parent::setUp();
		$this->watch = Server::get(WatchRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		$this->watch->deleteRelatedId(self::ALICE);
		$this->watch->deleteRelatedId(self::BOB);
	}

	public function testThePositionIsRememberedAndMovesOn(): void {
		$this->assertSame(0, $this->watch->positionOf(self::VIDEO, self::ALICE));

		$this->watch->remember(self::VIDEO, self::ALICE, 42, 600);
		$this->watch->remember(self::VIDEO, self::ALICE, 90, 600);

		$this->assertSame(90, $this->watch->positionOf(self::VIDEO, self::ALICE));
		$this->assertSame(0, $this->watch->positionOf(self::VIDEO, self::BOB));
	}

	/** a video barely started, or one whose length is unknown, is not "continue watching" */
	public function testOnlyVideosReallyStartedAreUnfinished(): void {
		$this->watch->remember(self::VIDEO, self::ALICE, 120, 600);
		$this->watch->remember(self::SHORT, self::ALICE, WatchRequest::STARTED_SECONDS - 1, 600);
		$this->watch->remember('https://itest.example/videos/watch-3', self::ALICE, 120, 0);

		$prims = $this->watch->unfinished(self::ALICE);

		$this->assertSame([md5(self::VIDEO)], $prims);
	}

	public function testTheMostRecentlyWatchedComesFirst(): void {
		$this->watch->remember(self::VIDEO, self::ALICE, 120, 600);
		sleep(1);
		$this->watch->remember(self::SHORT, self::ALICE, 30, 60);

		$this->assertSame([md5(self::SHORT), md5(self::VIDEO)], $this->watch->unfinished(self::ALICE));
	}

	public function testForgettingRemovesThePosition(): void {
		$this->watch->remember(self::VIDEO, self::ALICE, 120, 600);

		$this->assertTrue($this->watch->forget(self::VIDEO, self::ALICE));
		$this->assertFalse($this->watch->forget(self::VIDEO, self::ALICE));
		$this->assertSame(0, $this->watch->positionOf(self::VIDEO, self::ALICE));
	}
}
