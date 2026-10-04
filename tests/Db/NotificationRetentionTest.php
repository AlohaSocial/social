<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use DateTime;
use OCA\Social\Db\SocialQueryBuilder;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryFunction;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Which rows the notification retention selects, and which it deletes.
 *
 * The selection is the dangerous half: a predicate short of the type would
 * hand the prune every local post older than the cutoff.
 */
#[AllowMockObjectsWithoutExpectations]
class NotificationRetentionTest extends TestCase {
	/** @var string[] */
	private array $where = [];
	/** @var string[] */
	private array $deleteWhere = [];
	private ?int $limit = null;
	private string $deleted = '';

	private function queryBuilder(): SocialQueryBuilder {
		$qb = $this->createMock(SocialQueryBuilder::class);
		foreach (['select', 'selectAlias', 'from', 'orderBy'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('expr')->willReturn(new FakeExpressions());
		$qb->method('createNamedParameter')->willReturnCallback(static function ($value): string {
			if ($value instanceof DateTime) {
				return ':' . $value->format('Y-m-d');
			}

			return ':' . (is_array($value) ? implode(',', $value) : (string)$value);
		});
		$qb->method('createFunction')->willReturnCallback(function (string $call): IQueryFunction {
			$function = $this->createMock(IQueryFunction::class);
			$function->method('__toString')->willReturn($call);

			return $function;
		});
		foreach (['where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnCallback(function ($predicate) use ($qb) {
				if ($this->deleted !== '') {
					$this->deleteWhere[] = (string)$predicate;
				} else {
					$this->where[] = (string)$predicate;
				}

				return $qb;
			});
		}
		$qb->method('setMaxResults')->willReturnCallback(function (int $limit) use ($qb) {
			$this->limit = $limit;

			return $qb;
		});
		$qb->method('delete')->willReturnCallback(function (string $table) use ($qb) {
			$this->deleted = $table;

			return $qb;
		});
		$result = $this->createStub(IResult::class);
		$result->method('fetchAll')->willReturn([['id_prim' => 'n1']]);
		$result->method('fetch')->willReturn(['count' => '3']);
		$qb->method('executeQuery')->willReturn($result);
		$qb->method('executeStatement')->willReturn(1);

		return $qb;
	}

	private function request(): StreamRequest {
		$request = $this->getMockBuilder(StreamRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getQueryBuilder'])
			->getMock();
		$request->method('getQueryBuilder')->willReturnCallback(fn (): SocialQueryBuilder => $this->queryBuilder());

		return $request;
	}

	public function testOnlyLocalNotificationRowsOlderThanTheCutoffAreSelected(): void {
		$cutoff = new DateTime('2026-01-01');

		$this->assertSame(['n1'], $this->request()->getNotificationPrimsBefore($cutoff, 500));

		$this->assertSame([
			's.type = :' . SocialAppNotification::TYPE,
			's.local = :1',
			's.creation < :2026-01-01',
		], $this->where);
		$this->assertSame(500, $this->limit);
	}

	public function testTheCountAsksTheSameQuestion(): void {
		$this->assertSame(3, $this->request()->countNotificationsBefore(new DateTime('2026-01-01')));

		$this->assertContains('s.type = :' . SocialAppNotification::TYPE, $this->where);
		$this->assertContains('s.local = :1', $this->where);
	}

	public function testTheDeleteIsBoundToTheGivenRowsAlone(): void {
		$this->request()->deleteByPrims(['n1', 'n2']);

		$this->assertSame('social_stream', $this->deleted);
		$this->assertSame(['id_prim IN (:n1,n2)'], $this->deleteWhere);
	}

	public function testNothingIsDeletedForAnEmptyList(): void {
		$this->request()->deleteByPrims([]);

		$this->assertSame('', $this->deleted);
	}
}
