<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\SocialQueryBuilder;
use OCA\Social\Model\ActivityPub\Object\Follow;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Every local actor has an accepted `Loopback` row on itself, which is how its
 * own posts reach its home timeline. It is not a follow: the `followers` and
 * `following` collections, the migration export and every other list of
 * follows have to leave it out, or each actor follows itself and the page is
 * one longer than the collection's `totalItems`.
 */
#[AllowMockObjectsWithoutExpectations]
class FollowCollectionsTypeTest extends TestCase {
	/** @var string[] */
	private array $types = [];

	private function follows(): FollowsRequest&MockObject {
		$qb = $this->createMock(SocialQueryBuilder::class);
		$qb->method('limitToType')->willReturnCallback(
			function (string $type) use (&$qb): SocialQueryBuilder {
				$this->types[] = $type;

				return $qb;
			}
		);

		$request = $this->getMockBuilder(FollowsRequest::class)
			->disableOriginalConstructor()
			->onlyMethods([
				'getFollowsSelectSql', 'limitToPrim', 'leftJoinCacheActors',
				'leftJoinDetails', 'getFollowsFromRequest',
			])
			->getMock();
		$request->method('getFollowsSelectSql')->willReturn($qb);
		$request->method('getFollowsFromRequest')->willReturn([]);

		return $request;
	}

	public function testTheFollowersOfAnActorAreFollowsOnly(): void {
		$this->follows()->getFollowersByActorId('https://cloud.example/@alice', 40);

		$this->assertSame([Follow::TYPE], $this->types);
	}

	public function testTheAccountsAnActorFollowsAreFollowsOnly(): void {
		$this->follows()->getFollowingByActorId('https://cloud.example/@alice', 40);

		$this->assertSame([Follow::TYPE], $this->types);
	}
}
