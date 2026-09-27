<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\SocialQueryBuilder;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCP\DB\IResult;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * `social_cache_doc.nid` is an autoincrement, so its width is not the
 * problem a stream nid has. Its type is: the media routes take it as
 * `int|string` and a url segment arrives as a string, which the integer
 * predicate refuses under strict types.
 */
#[AllowMockObjectsWithoutExpectations]
class CacheDocumentsRequestNidTest extends TestCase {
	public function testADocumentIsLookedUpByTheStringARouteWasGiven(): void {
		$empty = $this->createStub(IResult::class);
		$empty->method('fetch')->willReturn(false);

		$qb = $this->createMock(SocialQueryBuilder::class);
		$qb->expects($this->once())->method('limitToNid')->with($this->identicalTo('42'));
		$qb->method('executeQuery')->willReturn($empty);

		$request = $this->getMockBuilder(CacheDocumentsRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getCacheDocumentsSelectSql'])
			->getMock();
		$request->method('getCacheDocumentsSelectSql')->willReturn($qb);

		$this->expectException(CacheDocumentDoesNotExistException::class);

		$request->getByNid('42');
	}
}
