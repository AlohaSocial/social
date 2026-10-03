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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Whose outbox the cron syncs.
 *
 * A synced timeline feeds the home timelines of the actor's local followers
 * and nothing else reads it. The selection used to be every remote actor the
 * instance had ever met — one HTTP request per cached account per rotation,
 * most of them for accounts nobody here follows.
 */
#[AllowMockObjectsWithoutExpectations]
class RemoteTimelineSyncScopeTest extends TestCase {
	/** @var string[] the outer query's predicates */
	private array $where = [];
	/** @var string[] the tables the correlated sub-select reads */
	private array $innerFrom = [];
	/** @var string[] its predicates */
	private array $innerWhere = [];
	private ?int $limit = null;

	private function outer(): SocialQueryBuilder&MockObject {
		$qb = $this->createMock(SocialQueryBuilder::class);
		$qb->method('expr')->willReturn(new FakeExpressions());
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value): string => ':' . $value);
		$qb->method('limitToLocal')->willReturnCallback(function (bool $local): void {
			$this->where[] = 'ca.local = ' . ($local ? '1' : '0');
		});
		$qb->method('andWhere')->willReturnCallback(function ($predicate) use ($qb) {
			$this->where[] = (string)$predicate;

			return $qb;
		});
		foreach (['orderBy', 'addOrderBy'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('setMaxResults')->willReturnCallback(function (int $limit) use ($qb) {
			$this->limit = $limit;

			return $qb;
		});

		return $qb;
	}

	private function inner(): SocialQueryBuilder&MockObject {
		$qb = $this->createMock(SocialQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('createFunction')->willReturnArgument(0);
		$qb->method('from')->willReturnCallback(function (string $table, ?string $alias = null) use ($qb) {
			$this->innerFrom[] = $table . ' ' . $alias;

			return $qb;
		});
		foreach (['where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnCallback(function ($predicate) use ($qb) {
				$this->innerWhere[] = (string)$predicate;

				return $qb;
			});
		}
		$qb->method('getSQL')->willReturn('SELECT 1 FROM follows …');

		return $qb;
	}

	private function request(): CacheActorsRequest&MockObject {
		$request = $this->getMockBuilder(CacheActorsRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getCacheActorsSelectSql', 'getQueryBuilder', 'getCacheActorsFromRequest'])
			->getMock();
		$request->method('getCacheActorsSelectSql')->willReturn($this->outer());
		$request->method('getQueryBuilder')->willReturn($this->inner());
		$request->method('getCacheActorsFromRequest')->willReturn([]);

		return $request;
	}

	public function testOnlyRemoteActorsWithAnAcceptedLocalFollowAreSynced(): void {
		$this->request()->getRemoteActorsToSync();

		$this->assertSame([
			'ca.local = 0',
			'ca.sync_failures < :' . CacheActorsRequest::SYNC_MAX_FAILURES,
			'EXISTS (SELECT 1 FROM follows …)',
		], $this->where);
		$this->assertSame(['social_follow f', 'social_cache_actor la'], $this->innerFrom);
		$this->assertSame([
			'f.object_id_prim = ca.id_prim',
			"f.accepted = '1'",
			'la.id_prim = f.actor_id_prim',
			"la.local = '1'",
		], $this->innerWhere);
	}

	public function testTheBatchIsTheSyncBatch(): void {
		$this->request()->getRemoteActorsToSync();

		$this->assertSame(CacheActorsRequest::SYNC_BATCH, $this->limit);
	}
}
