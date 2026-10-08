<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20221118000002;
use OCA\Social\Migration\Version1000Date20261008000400;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/** The index a page of one author's posts is read from. */
class StreamAuthorNidIndexTest extends TestCase {
	/** @return array<string, object> */
	private function stand(): array {
		return [IAppConfig::class => $this->createStub(IAppConfig::class)];
	}

	public function testTheAuthorComesFirstAndTheNidSecond(): void {
		$schema = MigrationReplay::run([Version1000Date20221118000002::class], $this->stand());
		MigrationReplay::run([Version1000Date20261008000400::class], [], $schema);

		$indexes = array_values(array_filter(
			$schema->getTable('social_stream')->addedIndexes(),
			static fn (array $index): bool => $index['name'] === 'social_s_atn'
		));

		$this->assertCount(1, $indexes);
		$this->assertSame(['attributed_to_prim', 'nid'], $indexes[0]['columns']);
		$this->assertFalse($indexes[0]['unique']);
	}

	public function testRunTwiceItAsksForNothingTheSecondTime(): void {
		$schema = MigrationReplay::run([Version1000Date20221118000002::class], $this->stand());
		MigrationReplay::run([Version1000Date20261008000400::class], [], $schema);
		$once = $schema->shape();

		MigrationReplay::run([Version1000Date20261008000400::class], [], $schema);

		$this->assertSame($once, $schema->shape());
	}

	public function testItDoesNothingWhereTheTableIsNotThere(): void {
		$schema = MigrationReplay::run([Version1000Date20261008000400::class]);

		$this->assertArrayNotHasKey('social_stream', $schema->shape());
	}
}
