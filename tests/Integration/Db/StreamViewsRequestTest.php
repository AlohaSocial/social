<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\StreamViewsRequest;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Who has opened a post: people, not visits, so a second look counts nothing.
 */
class StreamViewsRequestTest extends TestCase {
	private const POST = 'https://itest.example/posts/views-1';
	private const OTHER = 'https://itest.example/posts/views-2';
	private const ALICE = 'https://itest.example/users/views-alice';
	private const BOB = 'https://itest.example/users/views-bob';

	private StreamViewsRequest $views;

	protected function setUp(): void {
		parent::setUp();
		$this->views = Server::get(StreamViewsRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		$this->views->deleteByStream(self::POST);
		$this->views->deleteByStream(self::OTHER);
	}

	public function testAViewIsCountedOncePerPerson(): void {
		$this->assertTrue($this->views->seen(self::POST, self::ALICE));
		$this->assertFalse($this->views->seen(self::POST, self::ALICE), 'the second look is the same person');
		$this->assertTrue($this->views->seen(self::POST, self::BOB));

		$this->assertSame(2, $this->views->countFor(self::POST));
		$this->assertSame(0, $this->views->countFor(self::OTHER));
	}

	public function testManyPostsAreCountedInOneRead(): void {
		$this->views->seen(self::POST, self::ALICE);
		$this->views->seen(self::POST, self::BOB);
		$this->views->seen(self::OTHER, self::ALICE);

		// keyed by the hashed id, which is what the page's posts are matched on
		$counts = $this->views->countForMany([self::POST, self::OTHER]);

		$this->assertSame(2, $counts[md5(self::POST)] ?? null);
		$this->assertSame(1, $counts[md5(self::OTHER)] ?? null);
		$this->assertSame([], $this->views->countForMany([]));
	}

	public function testAViewerWhoGoesTakesTheirViewsWithThem(): void {
		$this->views->seen(self::POST, self::ALICE);
		$this->views->seen(self::POST, self::BOB);

		$this->views->deleteByActor(self::ALICE);

		$this->assertSame(1, $this->views->countFor(self::POST));
	}
}
