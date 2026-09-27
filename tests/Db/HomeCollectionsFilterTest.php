<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\SocialQueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * How a home timeline names the collections it is read from.
 *
 * Naming them one by one is what makes that query a range scan, and it is the
 * right shape for almost every account. It is not a shape that can be used
 * unbounded: each collection is one bound parameter, a SQLite built with the
 * historical default refuses a statement carrying more than 999 of them, and
 * the other two plan a list worse the longer it is. An account following ten
 * thousand others would have built a ten-thousand-parameter statement on every
 * read of its timeline.
 */
#[AllowMockObjectsWithoutExpectations]
class HomeCollectionsFilterTest extends TestCase {
	private const ALICE = 'https://cloud.example/@alice';

	/** @var array<int, mixed> every value the outer query was asked to bind */
	private array $bound = [];

	private function outer(): SocialQueryBuilder&MockObject {
		$qb = $this->createMock(SocialQueryBuilder::class);
		$qb->method('expr')->willReturn(new FakeExpressions());
		$qb->method('prim')->willReturnCallback(static fn (string $id): string => md5($id));
		$qb->method('createNamedParameter')->willReturnCallback(function ($value) {
			$this->bound[] = $value;

			return ':p' . count($this->bound);
		});

		return $qb;
	}

	/** The builder the correlated sub-select is built on. */
	private function inner(): SocialQueryBuilder&MockObject {
		$qb = $this->createMock(SocialQueryBuilder::class);
		foreach (['select', 'from', 'where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnCallback(static fn (): SocialQueryBuilder => $qb);
		}
		$qb->method('createFunction')->willReturnArgument(0);
		$qb->method('getSQL')->willReturn('SELECT 1 FROM `oc_social_follow` hf WHERE …');

		return $qb;
	}

	/** @param int $follows how many accepted collections this account has */
	private function request(int $follows): FollowsRequest&MockObject {
		$request = $this->getMockBuilder(FollowsRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getHomeCollectionPrims', 'getQueryBuilder'])
			->getMock();

		$request->method('getHomeCollectionPrims')->willReturnCallback(
			static function (string $actorId, int $limit) use ($follows): array {
				$prims = [];
				for ($i = 0; $i < $follows && ($limit < 1 || $i < $limit); $i++) {
					$prims[] = md5('collection-' . $i);
				}

				return $prims;
			}
		);
		$request->method('getQueryBuilder')->willReturn($this->inner());

		return $request;
	}

	public function testAnOrdinaryAccountHasItsCollectionsNamedOneByOne(): void {
		$clause = $this->request(12)
			->limitToHomeCollections($this->outer(), 'sd.actor_id', self::ALICE);

		$this->assertStringStartsWith('sd.actor_id IN (', $clause);
		$this->assertStringNotContainsStringIgnoringCase('exists', $clause);
		$this->assertCount(1, $this->bound);
		$this->assertCount(12, $this->bound[0]);
	}

	public function testTheAccountsOwnCollectionIsMatchedAsWell(): void {
		// a post of the account's own reaches its timeline through the
		// recipient row addressed to its own follower collection, which it is
		// not a follower of
		$own = md5(self::ALICE . '/followers');
		$this->request(3)->limitToHomeCollections($this->outer(), 'sd.actor_id', self::ALICE, [$own]);

		$this->assertContains($own, $this->bound[0]);
		$this->assertCount(4, $this->bound[0]);
	}

	public function testAnAccountWithNothingToReadMatchesNothingAtAll(): void {
		$this->assertSame(
			'',
			$this->request(0)->limitToHomeCollections($this->outer(), 'sd.actor_id', self::ALICE)
		);
	}

	public function testAnAccountPastTheCapIsLeftToTheDatabase(): void {
		$clause = $this->request(FollowsRequest::HOME_COLLECTIONS_IN_A_QUERY + 1)
			->limitToHomeCollections($this->outer(), 'sd.actor_id', self::ALICE);

		$this->assertStringStartsWith('EXISTS (', $clause);
		foreach ($this->bound as $value) {
			$this->assertIsNotArray(
				$value, 'the collections are what this query must not carry as parameters'
			);
		}
		$this->assertLessThan(
			10, count($this->bound), 'a bounded statement, whatever the account follows'
		);
	}

	public function testThePredicateIsCorrelatedOnTheColumnItFilters(): void {
		$inner = $this->inner();
		$where = [];
		$inner->method('where')->willReturnCallback(
			static function (string $predicate) use ($inner, &$where): SocialQueryBuilder {
				$where[] = $predicate;

				return $inner;
			}
		);

		$request = $this->getMockBuilder(FollowsRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getHomeCollectionPrims', 'getQueryBuilder'])
			->getMock();
		$request->method('getHomeCollectionPrims')->willReturn(
			array_map('md5', range(0, FollowsRequest::HOME_COLLECTIONS_IN_A_QUERY))
		);
		$request->method('getQueryBuilder')->willReturn($inner);

		$request->limitToHomeCollections($this->outer(), 'sd.actor_id', self::ALICE);

		$this->assertSame(['hf.follow_id_prim = sd.actor_id'], $where);
	}

	public function testTheAccountsOwnCollectionIsStillMatchedPastTheCap(): void {
		$own = md5(self::ALICE . '/followers');
		$clause = $this->request(FollowsRequest::HOME_COLLECTIONS_IN_A_QUERY + 1)
			->limitToHomeCollections($this->outer(), 'sd.actor_id', self::ALICE, [$own]);

		$this->assertStringContainsString('EXISTS (', $clause);
		$this->assertStringContainsString('OR sd.actor_id IN (', $clause);
		$this->assertContains([$own], $this->bound);
	}

	public function testTheListIsAskedForNoMoreThanOneRowPastTheCap(): void {
		// deciding which shape to use must not read the whole follow list
		$asked = [];
		$request = $this->getMockBuilder(FollowsRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getHomeCollectionPrims', 'getQueryBuilder'])
			->getMock();
		$request->method('getHomeCollectionPrims')->willReturnCallback(
			static function (string $actorId, int $limit) use (&$asked): array {
				$asked[] = $limit;

				return [];
			}
		);
		$request->method('getQueryBuilder')->willReturn($this->inner());

		$request->limitToHomeCollections($this->outer(), 'sd.actor_id', self::ALICE);

		$this->assertSame([FollowsRequest::HOME_COLLECTIONS_IN_A_QUERY + 1], $asked);
	}
}
