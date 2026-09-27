<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Tools\Db;

use OCA\Social\Tools\Db\ExtendedQueryBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

class ExtendedQueryBuilderTest extends TestCase {
	public function testNidsInsideTheNativeIntegerRangeUseTheIntegerPredicate(): void {
		$queryBuilder = $this->createStub(IQueryBuilder::class);
		$builder = $this->getMockBuilder(ExtendedQueryBuilder::class)
			->setConstructorArgs([$queryBuilder])
			->onlyMethods(['limitToDBFieldInt', 'limitToDBField'])
			->getMock();
		$builder->expects($this->once())->method('limitToDBFieldInt')->with('nid', 42);
		$builder->expects($this->never())->method('limitToDBField');

		$builder->limitToNid(42);
	}

	public function testOversizedNidsAreBoundAsExactDecimalStrings(): void {
		$queryBuilder = $this->createStub(IQueryBuilder::class);
		$builder = $this->getMockBuilder(ExtendedQueryBuilder::class)
			->setConstructorArgs([$queryBuilder])
			->onlyMethods(['limitToDBFieldInt', 'limitToDBField'])
			->getMock();
		$builder->expects($this->never())->method('limitToDBFieldInt');
		$builder->expects($this->once())
			->method('limitToDBField')
			->with('nid', '17901664697144251759');

		$builder->limitToNid('017901664697144251759');
	}
}
