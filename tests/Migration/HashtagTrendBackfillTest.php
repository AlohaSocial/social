<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use Closure;
use OCA\Social\Db\HashtagsRequest;
use OCA\Social\Migration\Version1000Date20260910000003;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The step that fills in the hashtag trend counters of rows written before the
 * columns existed.
 *
 * Without it those rows keep a correct JSON trend and zeroed columns forever
 * on a quiet instance, and the trends endpoint reads the columns.
 */
#[AllowMockObjectsWithoutExpectations]
class HashtagTrendBackfillTest extends TestCase {
	use RecordsSchemaChanges;

	private const LAGGING = [
		'hashtag' => '#lagging',
		'trend' => '{"1h":0,"12h":1,"1d":5,"3d":7,"10d":9}',
		'trend_1h' => 0, 'trend_12h' => 0, 'trend_1d' => 0, 'trend_3d' => 0, 'trend_10d' => 0,
	];

	private const AGREEING = [
		'hashtag' => '#agreeing',
		'trend' => '{"1h":1,"12h":1,"1d":1,"3d":1,"10d":1}',
		'trend_1h' => 1, 'trend_12h' => 1, 'trend_1d' => 1, 'trend_3d' => 1, 'trend_10d' => 1,
	];

	private const UNREADABLE = [
		'hashtag' => '#unreadable',
		'trend' => '',
		'trend_1h' => 0, 'trend_12h' => 0, 'trend_1d' => 0, 'trend_3d' => 0, 'trend_10d' => 0,
	];

	private IOutput|MockObject $output;

	protected function setUp(): void {
		parent::setUp();
		$this->output = $this->createMock(IOutput::class);
	}

	private function schemaClosure(bool $hasTable = true, bool $hasColumns = true): Closure {
		// the step reads every trend column before it backfills any of them
		$table = $this->recordTable(
			'social_hashtag',
			$hasColumns ? array_values(HashtagsRequest::TREND_COLUMNS) : [],
		);

		$schema = $this->createStub(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn($hasTable);
		$schema->method('getTable')->willReturn($table);

		return static fn (): ISchemaWrapper => $schema;
	}

	public function testARowWhoseCountersLagItsJsonIsFilledIn(): void {
		$connection = new FakeConnection([[self::LAGGING, self::AGREEING, self::UNREADABLE]]);
		$step = new Version1000Date20260910000003($connection);

		$step->postSchemaChange($this->output, $this->schemaClosure(), []);

		$writes = $connection->writes();
		$this->assertCount(1, $writes, 'only the row that disagrees with its JSON is written');
		$this->assertSame('social_hashtag', $writes[0]->table);
		$this->assertSame(
			['trend_1h' => 0, 'trend_12h' => 1, 'trend_1d' => 5, 'trend_3d' => 7, 'trend_10d' => 9],
			$writes[0]->sets
		);
		$this->assertSame(['hashtag = #lagging'], $writes[0]->wheres);
	}

	public function testARunOverRowsThatAlreadyAgreeWritesNothing(): void {
		// the step runs once per upgrade forever, and re-running it must cost
		// the reads and nothing else
		$connection = new FakeConnection([[self::AGREEING, self::UNREADABLE]]);
		$step = new Version1000Date20260910000003($connection);
		$this->output->expects($this->never())->method('info');

		$step->postSchemaChange($this->output, $this->schemaClosure(), []);

		$this->assertSame([], $connection->writes());
	}

	public function testTheTableIsReadInBoundedPagesOrderedByItsKey(): void {
		// an instance that has been federating for years has a row per hashtag
		// it has ever seen; this may not load the table in one go
		$connection = new FakeConnection([[self::AGREEING]]);
		$step = new Version1000Date20260910000003($connection);

		$step->postSchemaChange($this->output, $this->schemaClosure(), []);

		$this->assertNotSame([], $connection->queries);
		$select = $connection->queries[0];
		$this->assertSame(1000, $select->maxResults);
		$this->assertSame('hashtag asc', $select->orderBy);
		$this->assertSame(['hashtag > '], $select->wheres);
		foreach (['hashtag', 'trend', 'trend_1h', 'trend_10d'] as $column) {
			$this->assertContains($column, $select->selects);
		}
	}

	public function testNothingIsReadBeforeTheColumnsExist(): void {
		$connection = new FakeConnection([[self::LAGGING]]);
		$step = new Version1000Date20260910000003($connection);

		$step->postSchemaChange($this->output, $this->schemaClosure(true, false), []);
		$step->postSchemaChange($this->output, $this->schemaClosure(false), []);

		$this->assertSame([], $connection->queries);
	}
}
