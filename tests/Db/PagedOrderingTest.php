<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\SocialQueryBuilder;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCP\DB\IResult;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * What a page of follows or of likes is ordered by.
 *
 * `creation` is a DATETIME, which this schema stores to the second. Rows
 * sharing a second have no order of their own, and the order a database
 * happens to return them in is free to differ between two statements — so an
 * `OFFSET` page ordered on that column alone can hand out the same follower
 * twice and never mention another. Every one of these lists is read that way
 * by `followers`, `following`, `follow_requests` and `favourited_by`.
 *
 * The statements need a real database; what they were built to ask for does
 * not, and that is what is asserted here.
 */
#[AllowMockObjectsWithoutExpectations]
class PagedOrderingTest extends TestCase {
	private const ALICE = 'https://cloud.example/@alice';
	private const POST = 'https://cloud.example/@bob/1';

	/** @var string[] every orderBy()/addOrderBy() the query was given, in order */
	private array $order = [];

	/** A query builder that records nothing but its ordering. */
	private function builder(): SocialQueryBuilder&MockObject {
		$qb = $this->createMock(SocialQueryBuilder::class);
		foreach (['select', 'selectDistinct', 'from', 'where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnCallback(static fn (): SocialQueryBuilder => $qb);
		}
		$qb->method('expr')->willReturn(new FakeExpressions());
		$qb->method('prim')->willReturnCallback(static fn (string $id): string => md5($id));
		$qb->method('createNamedParameter')->willReturnArgument(0);

		$empty = $this->createStub(IResult::class);
		$empty->method('fetch')->willReturn(false);
		$qb->method('executeQuery')->willReturn($empty);

		$qb->method('orderBy')->willReturnCallback(
			function (string $sort, ?string $direction = null) use (&$qb): SocialQueryBuilder {
				$this->order[] = trim($sort . ' ' . (string)$direction);

				return $qb;
			}
		);
		$qb->method('addOrderBy')->willReturnCallback(
			function (string $sort, ?string $direction = null) use (&$qb): SocialQueryBuilder {
				$this->order[] = trim($sort . ' ' . (string)$direction);

				return $qb;
			}
		);

		return $qb;
	}

	private function follows(): FollowsRequest&MockObject {
		$request = $this->getMockBuilder(FollowsRequest::class)
			->disableOriginalConstructor()
			->onlyMethods([
				'getFollowsSelectSql', 'getQueryBuilder', 'limitToPrim',
				'leftJoinCacheActors', 'leftJoinDetails', 'getFollowsFromRequest',
			])
			->getMock();

		$qb = $this->builder();
		$request->method('getFollowsSelectSql')->willReturn($qb);
		$request->method('getQueryBuilder')->willReturn($qb);
		$request->method('getFollowsFromRequest')->willReturn([]);

		return $request;
	}

	private function actions(): ActionsRequest&MockObject {
		$request = $this->getMockBuilder(ActionsRequest::class)
			->disableOriginalConstructor()
			->onlyMethods([
				'getActionsSelectSql', 'limitToPrim', 'leftJoinCacheActors',
				'getActionsFromRequest',
			])
			->getMock();

		$request->method('getActionsSelectSql')->willReturn($this->builder());
		$request->method('getActionsFromRequest')->willReturn([]);

		return $request;
	}

	/**
	 * The unique key of each of these tables, which is what makes an order
	 * total rather than only mostly decided.
	 */
	private function assertTieBroken(string $alias): void {
		$this->assertGreaterThan(
			1, count($this->order), 'a date alone is not an order a page can be cut on'
		);
		$this->assertSame($alias . 'id_prim desc', end($this->order));
	}

	public function testAPageOfFollowersIsOrderedByMoreThanTheSecondItArrivedIn(): void {
		$this->follows()->getFollowersByActorId(self::ALICE, 20, 40);

		$this->assertSame('f.creation desc', $this->order[0]);
		$this->assertTieBroken('f.');
	}

	public function testAPageOfFollowedAccountsIsOrderedTheSameWay(): void {
		$this->follows()->getFollowingByActorId(self::ALICE, 20, 40);

		$this->assertSame('f.creation desc', $this->order[0]);
		$this->assertTieBroken('f.');
	}

	public function testTheFollowRequestsListIsOrderedTheSameWay(): void {
		// not paged today, but cut by a limit, which has the same tie
		$this->follows()->getPendingByObjectId(self::ALICE, 20);

		$this->assertTieBroken('f.');
	}

	public function testTheFollowerOriginsSampleIsOrderedTheSameWay(): void {
		$this->follows()->getFollowerOrigins(self::ALICE, 1000);

		$this->assertTieBroken('');
	}

	public function testAPageOfFavouritesIsOrderedByMoreThanTheSecondItArrivedIn(): void {
		$this->actions()->getActionsOnObject(self::POST, Like::TYPE, 20, 40);

		$this->assertSame('a.creation desc', $this->order[0]);
		$this->assertTieBroken('a.');
	}

	public function testAnActorsOwnActionsAreOrderedTheSameWay(): void {
		$this->actions()->getActionsByActor(self::ALICE, Like::TYPE, 20);

		$this->assertTieBroken('a.');
	}
}
