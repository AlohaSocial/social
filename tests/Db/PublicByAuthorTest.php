<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\SocialQueryBuilder;
use OCA\Social\Db\StreamRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * `StreamRequest::getPublicByAuthor()`, what an outbox page serves: only the
 * rows written on this instance. A post fetched from elsewhere under a local
 * actor's name would otherwise be served to peers as that actor's own.
 */
#[AllowMockObjectsWithoutExpectations]
class PublicByAuthorTest extends TestCase {
	public function testOnlyLocalRowsAreRead(): void {
		$qb = $this->createMock(SocialQueryBuilder::class);
		$qb->method('expr')->willReturn(new FakeExpressions());
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('setFirstResult')->willReturnSelf();
		$qb->expects($this->once())->method('limitToAttributedTo')->with('https://social.example/users/alice', true);
		$qb->expects($this->once())->method('limitToLocal')->with(true);

		$request = $this->getMockBuilder(StreamRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getStreamSelectSql', 'getStreamsFromRequest'])
			->getMock();
		$request->method('getStreamSelectSql')->willReturn($qb);
		$request->expects($this->once())->method('getStreamsFromRequest')->with($qb)->willReturn([]);

		$this->assertSame([], $request->getPublicByAuthor('https://social.example/users/alice', 20));
	}
}
