<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\StatusDeletionEventsRequest;
use PHPUnit\Framework\TestCase;

/** The deletion log is trimmed now and then, not on every recorded deletion. */
class StatusDeletionEventsTrimTest extends TestCase {
	public function testOnlyEveryHundredthInsertPastTheRetainedTenThousandTrims(): void {
		$this->assertSame(0, StatusDeletionEventsRequest::trimBelow(1));
		$this->assertSame(0, StatusDeletionEventsRequest::trimBelow(10000), 'nothing beyond what is kept yet');
		$this->assertSame(0, StatusDeletionEventsRequest::trimBelow(10099));
		$this->assertSame(100, StatusDeletionEventsRequest::trimBelow(10100));
		$this->assertSame(0, StatusDeletionEventsRequest::trimBelow(10101));
		$this->assertSame(90000, StatusDeletionEventsRequest::trimBelow(100000));
	}

	public function testBetweenTrimsTheLogHoldsAtMostTheRetainedEventsAndOneInterval(): void {
		$largest = 0;
		$floor = 0;
		for ($id = 1; $id <= 20000; $id++) {
			$floor = max($floor, StatusDeletionEventsRequest::trimBelow($id));
			$largest = max($largest, $id - $floor);
		}

		$this->assertLessThan(10000 + StatusDeletionEventsRequest::TRIM_EVERY, $largest);
	}
}
