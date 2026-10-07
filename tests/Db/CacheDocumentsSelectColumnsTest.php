<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\SocialQueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A column the document select leaves out reads back as its default on every
 * document. `transcoded` was one of them: each local video read as never
 * looked at, so every post carrying one held its deliveries for the whole
 * `VideoDeliveryHold::HOLD_SECONDS`, an H.264 MP4 already marked as needing
 * nothing included.
 */
#[AllowMockObjectsWithoutExpectations]
class CacheDocumentsSelectColumnsTest extends TestCase {
	public function testTheDocumentSelectReadsEveryColumnOfTheTable(): void {
		$selected = [];
		$qb = $this->createMock(SocialQueryBuilder::class);
		$qb->method('select')->willReturnCallback(function (string ...$columns) use (&$selected, $qb) {
			$selected = $columns;

			return $qb;
		});
		$qb->method('from')->willReturnSelf();

		$request = $this->getMockBuilder(CacheDocumentsRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getQueryBuilder'])
			->getMock();
		$request->method('getQueryBuilder')->willReturn($qb);

		(new ReflectionMethod(CacheDocumentsRequest::class, 'getCacheDocumentsSelectSql'))->invoke($request);

		$read = array_map(static fn (string $column): string => substr($column, strlen('cd.')), $selected);
		$missing = array_diff(
			CoreRequestBuilder::$tables[CoreRequestBuilder::TABLE_CACHE_DOCUMENTS],
			$read,
			['id_prim']
		);
		$this->assertSame([], array_values($missing), 'the document select leaves these columns at their default');
	}
}
