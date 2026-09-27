<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\HashtagsRequest;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * How one hashtag's trend is written.
 *
 * `hashtag` is this table's primary key, and the trends cron cannot tell a
 * hashtag that has a row from one that has not: what it reads first is the
 * rows that currently *claim a trend*, and a hashtag that trended, fell to
 * zero and is trending again is not among them while its row is still there.
 * An INSERT for it is refused, and the refusal used to end the whole pass.
 *
 * The statements need a real database; which of them is sent, and what it
 * carries, does not.
 */
#[AllowMockObjectsWithoutExpectations]
class HashtagsRequestTest extends TestCase {
	private const TREND = ['1h' => 3, '12h' => 4, '1d' => 9, '3d' => 9, '10d' => 12];

	private IDBConnection|MockObject $connection;
	/** @var array<int, array{0: string, 1: array}> every insert the write attempted */
	private array $inserted = [];

	protected function setUp(): void {
		parent::setUp();

		$this->connection = $this->createMock(IDBConnection::class);
		$this->connection->method('insertIgnoreConflict')
			->willReturnCallback(function (string $table, array $values): int {
				$this->inserted[] = [$table, $values];

				return 1;
			});
	}

	/** @param int $written what the UPDATE reports having changed */
	private function request(int $written): HashtagsRequest&MockObject {
		$request = $this->getMockBuilder(HashtagsRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['update'])
			->getMock();
		$request->method('update')->willReturn($written);

		(new ReflectionProperty(CoreRequestBuilder::class, 'dbConnection'))
			->setValue($request, $this->connection);

		return $request;
	}

	public function testAHashtagThatAlreadyHasARowIsOnlyUpdated(): void {
		$request = $this->request(1);
		$request->expects($this->once())->method('update')->with('nextcloud', self::TREND);

		$request->upsert('nextcloud', self::TREND);

		$this->assertSame([], $this->inserted);
	}

	public function testAHashtagWithNoRowIsInserted(): void {
		$this->request(0)->upsert('nextcloud', self::TREND);

		$this->assertCount(1, $this->inserted);
		[$table, $values] = $this->inserted[0];
		$this->assertSame(CoreRequestBuilder::TABLE_HASHTAGS, $table);
		$this->assertSame('nextcloud', $values['hashtag']);
		$this->assertSame(json_encode(self::TREND), $values['trend']);
	}

	public function testTheInsertFillsTheColumnsTheTrendsListIsOrderedOn(): void {
		// the JSON is what the API hands back; the columns are what
		// getTrending() orders and cuts on, and a row written with zeroed
		// columns is a row no trends page can ever show
		$this->request(0)->upsert('nextcloud', self::TREND);

		[, $values] = $this->inserted[0];
		foreach (HashtagsRequest::TREND_COLUMNS as $period => $column) {
			$this->assertSame(self::TREND[$period], $values[$column]);
		}
	}

	public function testAWindowWithNoCountIsWrittenAsZeroRatherThanLeftOut(): void {
		$this->request(0)->upsert('nextcloud', ['1h' => 2]);

		[, $values] = $this->inserted[0];
		$this->assertSame(2, $values['trend_1h']);
		$this->assertSame(0, $values['trend_10d']);
	}

	public function testTheInsertIsOneTheDatabaseIsAskedToSkipOnAConflict(): void {
		// a plain INSERT that the primary key refuses aborts the surrounding
		// transaction on PostgreSQL and takes every later statement with it
		$this->connection->expects($this->once())->method('insertIgnoreConflict');

		$this->request(0)->upsert('nextcloud', self::TREND);
	}

	public function testThisTableHoldsAsLongAHashtagAsThePostsItCounts(): void {
		// social_stream_tag.hashtag is 127; a tag longer than social_hashtag
		// can hold fails the write outright on PostgreSQL and on MySQL in
		// strict mode
		$this->assertSame(127, HashtagsRequest::HASHTAG_MAX_LENGTH);
	}
}
