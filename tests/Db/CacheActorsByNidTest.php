<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\SocialQueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * The cached actors read by numeric id, which is what `GET /api/v1/accounts/{id}`
 * answers with.
 *
 * Every other lookup joins the cached icon in; this one did not, so an
 * account's avatar had no local copy to name here and the entity carried
 * its placeholder while `verify_credentials` carried the uploaded picture.
 */
#[AllowMockObjectsWithoutExpectations]
class CacheActorsByNidTest extends TestCase {
	public function testTheCachedIconIsJoinedIn(): void {
		$qb = $this->createMock(SocialQueryBuilder::class);
		$qb->expects($this->once())->method('limitInArray')->with('nid', [6]);
		$qb->expects($this->once())->method('leftJoinCacheDocuments')->with('icon_id');

		$request = $this->getMockBuilder(CacheActorsRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getCacheActorsSelectSql', 'getCacheActorsFromRequest'])
			->getMock();
		$request->method('getCacheActorsSelectSql')->willReturn($qb);
		$request->expects($this->once())->method('getCacheActorsFromRequest')->with($qb)->willReturn([]);

		$this->assertSame([], $request->getFromNids([6]));
	}
}
