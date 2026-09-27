<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\FeedsRequest;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Feed pruning and the order feeds are re-read in, against the real database.
 *
 * Both depend on the database rather than on the code: the prune on an offset
 * into an index and a date comparison, the order on where NULL sorts, which
 * is first on MySQL and SQLite and last on PostgreSQL.
 */
class FeedsRequestTest extends TestCase {
	private const USER = 'itest-feeds-user';

	private FeedsRequest $feeds;

	protected function setUp(): void {
		parent::setUp();
		$this->feeds = Server::get(FeedsRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach ($this->feeds->feedsOf(self::USER) as $feed) {
			$this->feeds->delete(self::USER, (int)$feed['id']);
		}
	}

	private function item(int $feedId, string $guid, int $ago): void {
		$this->feeds->addItem($feedId, [
			'guid' => $guid,
			'link' => 'https://feeds.example/' . $guid,
			'title' => $guid,
			'summary' => '',
			'thumbnail' => '',
			'published' => gmdate('Y-m-d H:i:s', time() - $ago),
		]);
	}

	public function testPruningKeepsTheNewestAndDropsTheOld(): void {
		$id = $this->feeds->create(self::USER, 'https://feeds.example/prune', '', '');
		for ($i = 0; $i < 5; $i++) {
			$this->item($id, 'recent-' . $i, 60 * ($i + 1));
		}
		$this->item($id, 'ancient', 400 * 86400);

		$this->assertSame(0, $this->feeds->prune($id, 10, time() - 180 * 86400), 'below the allowance, age drops nothing');
		$this->assertSame(3, $this->feeds->prune($id, 3, time() - 180 * 86400), 'past the three newest');
		$this->assertSame([$id => 3], $this->feeds->countsFor([$id]));
	}

	/**
	 * A feed that stopped publishing years ago keeps everything it has: every
	 * entry is past the age cut-off, and applying it would empty the feed.
	 */
	public function testADormantFeedIsNotEmptiedByTheAgeCutOff(): void {
		$id = $this->feeds->create(self::USER, 'https://feeds.example/dormant', '', '');
		for ($i = 0; $i < 3; $i++) {
			$this->item($id, 'ancient-' . $i, (1000 + $i) * 86400);
		}

		$this->assertSame(0, $this->feeds->prune($id, 500, time() - 180 * 86400));
		$this->assertSame([$id => 3], $this->feeds->countsFor([$id]));
	}

	public function testAFeedNeverReadIsDueBeforeAStaleOne(): void {
		$stale = $this->feeds->create(self::USER, 'https://feeds.example/stale', '', '');
		$this->feeds->recordRead($stale, '', '', '', '', '');
		$fresh = $this->feeds->create(self::USER, 'https://feeds.example/fresh', '', '');

		$due = array_map(
			static fn (array $row): int => (int)$row['id'],
			$this->feeds->due(1000, time() + 60)
		);

		$this->assertContains($stale, $due);
		$this->assertLessThan(array_search($stale, $due, true), array_search($fresh, $due, true));
	}

	/**
	 * A subscribed feed is shown like a video timeline: the newest publication
	 * is at the top, and moving to the next page neither repeats nor skips two
	 * entries published in the same second.
	 */
	public function testTimelineIsNewestFirstAndPagesEqualPublicationTimes(): void {
		$id = $this->feeds->create(self::USER, 'https://feeds.example/videos', '', '');
		$this->itemAt($id, 'same-time-first', '2026-09-25 10:00:00');
		$this->itemAt($id, 'same-time-second', '2026-09-25 10:00:00');
		$this->itemAt($id, 'newest', '2026-09-27 10:00:00');
		// This old entry arrives after the cursor row, so id-only paging would
		// silently lose it despite it belonging on the next publication page.
		$this->itemAt($id, 'oldest', '2026-09-20 10:00:00');

		$firstPage = $this->feeds->timelineOf(self::USER, 2, 0);
		$this->assertSame(['newest', 'same-time-second'], array_column($firstPage, 'title'));

		$last = $firstPage[array_key_last($firstPage)];
		$secondPage = $this->feeds->timelineOf(
			self::USER,
			2,
			0,
			(string)$last['published'],
			(int)$last['id']
		);

		$this->assertSame(['same-time-first', 'oldest'], array_column($secondPage, 'title'));
		$legacyPage = $this->feeds->timelineOf(self::USER, 2, (int)$last['id']);
		$this->assertSame(['same-time-first', 'oldest'], array_column($legacyPage, 'title'));
	}

	private function itemAt(int $feedId, string $guid, string $published): void {
		$this->feeds->addItem($feedId, [
			'guid' => $guid,
			'link' => 'https://feeds.example/' . $guid,
			'title' => $guid,
			'summary' => '',
			'thumbnail' => '',
			'published' => $published,
		]);
	}
}
