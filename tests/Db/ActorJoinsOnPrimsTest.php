<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\SocialQueryBuilder;
use OCA\Social\Model\ActivityPub\Actor\Person;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * How the follower, following, likes and account-search pages join the actor
 * each row names.
 *
 * None of the id columns — `social_cache_actor.id`, `social_actor.id`,
 * `social_follow.actor_id`, `.object_id` — carries an index, and a `LOWER()`
 * over them defeats one anyway: MariaDB planned every one of these joins as a
 * full scan of the joined table per row of the page (`ALL … BNL join`). The
 * indexed columns are the `_prim` forms, so that is what each join must compare,
 * and nothing it compares may be wrapped in a function.
 */
#[AllowMockObjectsWithoutExpectations]
class ActorJoinsOnPrimsTest extends TestCase {
	private const ALICE = 'https://cloud.example/@alice';

	/** @var array<string, string> alias => the ON clause it was joined with */
	private array $joins = [];

	/** @var array<int, mixed> */
	private array $bound = [];

	private function qb(): SocialQueryBuilder&MockObject {
		$qb = $this->createMock(SocialQueryBuilder::class);
		$qb->method('getType')->willReturn(SocialQueryBuilder::SELECT);
		$qb->method('expr')->willReturn(new FakeExpressions());
		$qb->method('prim')->willReturnCallback(static fn (string $id): string => md5($id));
		$qb->method('selectAlias')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturnCallback(function ($value): string {
			$this->bound[] = $value;

			return ':p' . count($this->bound);
		});
		$qb->method('exprLimitToDBFieldInt')->willReturnCallback(
			static fn (string $field, int $value, string $alias): string => $alias . '.' . $field . ' = ' . $value
		);
		$qb->method('func')->willThrowException(
			new \LogicException('an actor join must not wrap a column in a function')
		);
		$qb->method('leftJoin')->willReturnCallback(
			function (string $from, string $table, string $alias, $on) use ($qb): SocialQueryBuilder {
				$this->joins[$alias] = (string)$on;

				return $qb;
			}
		);

		return $qb;
	}

	private function request(string $alias): FollowsRequest {
		$request = $this->getMockBuilder(FollowsRequest::class)
			->disableOriginalConstructor()
			->onlyMethods([])
			->getMock();

		$viewer = new Person();
		$viewer->setId(self::ALICE);
		(new ReflectionProperty(CoreRequestBuilder::class, 'viewer'))->setValue($request, $viewer);
		(new ReflectionProperty(CoreRequestBuilder::class, 'defaultSelectAlias'))->setValue($request, $alias);

		return $request;
	}

	private function call(FollowsRequest $request, string $method, mixed ...$arguments): void {
		(new ReflectionMethod(CoreRequestBuilder::class, $method))->invoke($request, ...$arguments);
	}

	public function testTheCachedActorOfAFollowIsJoinedOnItsPrim(): void {
		$this->call($this->request('f'), 'leftJoinCacheActors', $this->qb(), 'actor_id');

		$this->assertSame('ca.id_prim = f.actor_id_prim', $this->joins['ca']);
	}

	public function testTheFollowedSideIsJoinedOnItsPrimToo(): void {
		$this->call($this->request('f'), 'leftJoinCacheActors', $this->qb(), 'object_id');

		$this->assertSame('ca.id_prim = f.object_id_prim', $this->joins['ca']);
	}

	public function testTheLocalAccountOfAFollowIsJoinedOnItsPrim(): void {
		$this->call($this->request('f'), 'leftJoinAccounts', $this->qb(), 'actor_id');

		$this->assertSame('lja.id_prim = f.actor_id_prim', $this->joins['lja']);
	}

	/**
	 * Both relations of the viewer to each listed account, on the columns of
	 * `social_f_oa_u` (object_id_prim, actor_id_prim) — one index lookup each.
	 */
	public function testTheViewersRelationToEachAccountIsLookedUpOnTheFollowIndex(): void {
		$this->call($this->request('f'), 'leftJoinDetails', $this->qb(), 'id', 'ca');

		$viewer = array_search(md5(self::ALICE), $this->bound, true);
		$this->assertNotFalse($viewer, 'the viewer is matched by prim');
		$this->assertStringNotContainsString(self::ALICE, implode(' ', array_map('strval', $this->bound)));

		$this->assertSame(
			'(as_follower_f.accepted = 1 AND as_follower_f.object_id_prim = ca.id_prim'
			. ' AND as_follower_f.actor_id_prim = :p' . ($viewer + 1) . ')',
			$this->joins['as_follower_f']
		);
		$this->assertSame(
			'(as_followed_f.accepted = 1 AND as_followed_f.actor_id_prim = ca.id_prim'
			. ' AND as_followed_f.object_id_prim = :p' . ($viewer + 2) . ')',
			$this->joins['as_followed_f']
		);
	}
}
