<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\FiltersRequest;
use OCA\Social\Db\SocialQueryBuilder;
use OCA\Social\Model\Client\FilterStatus;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * `social_filter_st.status_id` is a post's nid. It is written and read back
 * as its decimal string, because a nid does not fit a PHP int everywhere and
 * an int binding clamps it to PHP_INT_MAX — a filter on a different post.
 *
 * The statement is not run; what it was asked to bind is what is asserted.
 */
#[AllowMockObjectsWithoutExpectations]
class FilterStatusesRequestTest extends TestCase {
	private const WIDE = '92233720368547758070';

	public function testTheNamedPostIsWrittenAsItsDecimalString(): void {
		/** @var array<string, array{0: mixed, 1: mixed}> $values */
		$values = [];
		$qb = $this->createMock(SocialQueryBuilder::class);
		$qb->method('createNamedParameter')
			->willReturnCallback(static fn ($value, $type = IQueryBuilder::PARAM_STR): array => [$value, $type]);
		$qb->method('setValue')->willReturnCallback(
			static function (string $column, array $bound) use ($qb, &$values): SocialQueryBuilder {
				$values[$column] = $bound;

				return $qb;
			}
		);
		$qb->method('getLastInsertId')->willReturn(5);

		$request = $this->getMockBuilder(FiltersRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getStatusesInsertSql'])
			->getMock();
		$request->method('getStatusesInsertSql')->willReturn($qb);

		$request->saveStatus((new FilterStatus())->setFilterId(3)->setStatusId(self::WIDE));

		$this->assertSame([self::WIDE, IQueryBuilder::PARAM_STR], $values['status_id']);
	}

	public function testTheNamedPostIsReadBackExactly(): void {
		$request = $this->getMockBuilder(FiltersRequest::class)
			->disableOriginalConstructor()
			->onlyMethods([])
			->getMock();
		$parse = new ReflectionMethod(FiltersRequest::class, 'parseStatusesSelectSql');

		/** @var FilterStatus $wide */
		$wide = $parse->invoke($request, ['id' => '5', 'filter_id' => '3', 'status_id' => self::WIDE]);
		/** @var FilterStatus $native */
		$native = $parse->invoke($request, ['id' => 6, 'filter_id' => 3, 'status_id' => 1790166469714425175]);

		$this->assertSame(self::WIDE, $wide->getStatusId());
		$this->assertSame('1790166469714425175', $native->getStatusId());
	}
}
