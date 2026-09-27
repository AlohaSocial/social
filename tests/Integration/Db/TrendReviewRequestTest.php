<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\TrendReviewRequest;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * What moderators decided about trending things: rejected is what is stored
 * as a filter, and a second decision replaces the first.
 */
class TrendReviewRequestTest extends TestCase {
	private const KIND = 'tag';
	private const REF_A = 'itest-trend-a';
	private const REF_B = 'itest-trend-b';

	private TrendReviewRequest $reviews;

	protected function setUp(): void {
		parent::setUp();
		$this->reviews = Server::get(TrendReviewRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach (['tag', 'link', 'status'] as $kind) {
			$this->reviews->forget($kind, self::REF_A);
			$this->reviews->forget($kind, self::REF_B);
		}
	}

	public function testOnlyTheRejectedAreFilteredOut(): void {
		$this->reviews->decide(self::KIND, self::REF_A, false, 'admin');
		$this->reviews->decide(self::KIND, self::REF_B, true, 'admin');

		$rejected = $this->reviews->rejected(self::KIND);
		$this->assertContains(self::REF_A, $rejected);
		$this->assertNotContains(self::REF_B, $rejected);
	}

	public function testASecondDecisionReplacesTheFirst(): void {
		$this->reviews->decide(self::KIND, self::REF_A, false, 'alice');
		$this->reviews->decide(self::KIND, self::REF_A, true, 'bob');

		$this->assertNotContains(self::REF_A, $this->reviews->rejected(self::KIND));
		$mine = array_values(array_filter($this->reviews->decisions(self::KIND), static fn (array $row): bool => $row['ref'] === self::REF_A));
		$this->assertCount(1, $mine);
		$this->assertTrue($mine[0]['approved']);
		$this->assertSame('bob', $mine[0]['moderator']);
	}

	public function testKindsDoNotShareDecisions(): void {
		$this->reviews->decide('link', self::REF_A, false, 'admin');

		$this->assertNotContains(self::REF_A, $this->reviews->rejected(self::KIND));
		$this->assertContains(self::REF_A, $this->reviews->rejected('link'));
	}

	public function testForgettingLiftsTheDecision(): void {
		$this->reviews->decide(self::KIND, self::REF_A, false, 'admin');

		$this->assertTrue($this->reviews->forget(self::KIND, self::REF_A));
		$this->assertFalse($this->reviews->forget(self::KIND, self::REF_A));
		$this->assertNotContains(self::REF_A, $this->reviews->rejected(self::KIND));
	}
}
