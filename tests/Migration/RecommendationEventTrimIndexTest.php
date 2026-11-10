<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20261023000002;
use OCA\Social\Migration\Version1000Date20261108300000;
use PHPUnit\Framework\TestCase;

class RecommendationEventTrimIndexTest extends TestCase {
	public function testTheEventLedgerIsIndexedByReaderAndTimeAndTheStepCanBeRepeated(): void {
		$schema = MigrationReplay::run([Version1000Date20261023000002::class, Version1000Date20261108300000::class]);
		MigrationReplay::run([Version1000Date20261108300000::class], [], $schema);

		$added = array_values(array_filter(
			$schema->getTable('social_rec_event')->addedIndexes(),
			static fn (array $index): bool => $index['name'] === 'social_rec_event_actor_at'
		));
		$this->assertSame([['columns' => ['actor_key', 'created_at'], 'name' => 'social_rec_event_actor_at', 'unique' => false]], $added, 'added once');
		$this->assertLessThanOrEqual(30, strlen('social_rec_event_actor_at'));
	}

	public function testWithoutTheTableThereIsNothingToIndex(): void {
		$schema = MigrationReplay::run([Version1000Date20261108300000::class]);

		$this->assertFalse($schema->hasTable('social_rec_event'));
	}
}
