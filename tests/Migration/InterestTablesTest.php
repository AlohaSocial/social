<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Migration\Version1000Date20221118000002;
use OCA\Social\Migration\Version1000Date20260925000020;
use OCP\DB\Types;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The two tables of For you, as the step that adds them leaves them.
 *
 * Replayed after the squash, the way an existing instance meets it: the squash
 * is already recorded as run there, so this step is the only thing that makes
 * these tables anywhere but on a fresh install.
 */
class InterestTablesTest extends TestCase {
	/** @return array<string, array{columns: array, indexes: array, primary: array}> */
	private function schema(): array {
		return MigrationReplay::run(
			[Version1000Date20221118000002::class, Version1000Date20260925000020::class],
			[IAppConfig::class => $this->createStub(IAppConfig::class)]
		)->shape();
	}

	public function testTheColumnsAreTheOnesTheCodeReadsAndWrites(): void {
		$schema = $this->schema();

		foreach ([CoreRequestBuilder::TABLE_INTERESTS, CoreRequestBuilder::TABLE_INTEREST_HIDES] as $table) {
			$columns = array_keys($schema[$table]['columns']);
			$declared = CoreRequestBuilder::$tables[$table];
			sort($columns);
			sort($declared);

			$this->assertSame($declared, $columns, $table . ' is what CoreRequestBuilder says it is');
		}
	}

	public function testATagIsAsWideAsTheTagsPostsCarry(): void {
		$column = $this->schema()[CoreRequestBuilder::TABLE_INTERESTS]['columns']['hashtag'];

		$this->assertSame(Types::STRING, $column['type']);
		$this->assertSame(127, $column['options']['length'], 'social_stream_tag.hashtag is 127 wide');
	}

	public function testOneRowPerReaderAndTagAndPerReaderAndPost(): void {
		$schema = $this->schema();

		foreach ([
			CoreRequestBuilder::TABLE_INTERESTS => ['actor_id_prim', 'hashtag'],
			CoreRequestBuilder::TABLE_INTEREST_HIDES => ['actor_id_prim', 'stream_nid'],
		] as $table => $columns) {
			$unique = array_values(array_filter($schema[$table]['indexes'], static fn (array $index): bool => (bool)$index['unique']));
			$this->assertSame($columns, $unique[0]['columns'] ?? null, $table);
		}
	}

	public function testAScoreIsAFractionAndItsTimeIsAnInteger(): void {
		$columns = $this->schema()[CoreRequestBuilder::TABLE_INTERESTS]['columns'];

		$this->assertSame(Types::FLOAT, $columns['score']['type']);
		$this->assertSame(Types::BIGINT, $columns['scored_at']['type'], 'unix time, never compared as a date');
		$this->assertFalse($columns['position']['options']['notnull'], 'null is a tag that floats');
	}

	public function testRunTwiceItAsksForNothingTheSecondTime(): void {
		$stand = [IAppConfig::class => $this->createStub(IAppConfig::class)];
		$schema = MigrationReplay::run([Version1000Date20221118000002::class, Version1000Date20260925000020::class], $stand);
		$once = $schema->shape();

		MigrationReplay::run([Version1000Date20260925000020::class], $stand, $schema);

		$this->assertSame($once, $schema->shape());
	}
}
