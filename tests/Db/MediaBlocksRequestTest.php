<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\MediaBlocksRequest;
use OCA\Social\Db\SocialQueryBuilder;
use OCP\DB\IResult;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * What a page of the refused files asks the database for. The rows need a
 * database (tests/Integration/Db/MediaBlocksPagingTest reads them); the
 * statement does not.
 */
#[AllowMockObjectsWithoutExpectations]
class MediaBlocksRequestTest extends TestCase {
	/** @var string[] */
	private array $where = [];
	/** @var string[] */
	private array $order = [];
	private ?int $max = null;

	private function request(): MediaBlocksRequest&MockObject {
		$qb = $this->createMock(SocialQueryBuilder::class);
		foreach (['select', 'from'] as $method) {
			$qb->method($method)->willReturnCallback(static fn (): SocialQueryBuilder => $qb);
		}
		$qb->method('expr')->willReturn(new FakeExpressions());
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value): string => (string)$value);
		$qb->method('where')->willReturnCallback(function ($clause) use (&$qb): SocialQueryBuilder {
			$this->where[] = (string)$clause;

			return $qb;
		});
		$qb->method('orderBy')->willReturnCallback(
			function (string $sort, ?string $direction = null) use (&$qb): SocialQueryBuilder {
				$this->order[] = trim($sort . ' ' . (string)$direction);

				return $qb;
			}
		);
		$qb->method('setMaxResults')->willReturnCallback(function (?int $max) use (&$qb): SocialQueryBuilder {
			$this->max = $max;

			return $qb;
		});

		$result = $this->createStub(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(
			['id' => '7', 'hash' => str_repeat('a', 64), 'reason' => 'r', 'moderator' => 'alice', 'blocked' => '3', 'creation' => '2026-09-15 10:00:00'],
			false
		);
		$qb->method('executeQuery')->willReturn($result);

		$request = $this->getMockBuilder(MediaBlocksRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getQueryBuilder'])
			->getMock();
		$request->method('getQueryBuilder')->willReturn($qb);

		return $request;
	}

	public function testTheFirstPageIsTheNewestRows(): void {
		$rows = $this->request()->getPage(101);

		$this->assertSame([], $this->where);
		$this->assertSame(['id desc'], $this->order);
		$this->assertSame(101, $this->max);
		$this->assertSame(7, $rows[0]['id'], 'the row carries the id a later page is asked from');
		$this->assertSame(3, $rows[0]['blocked']);
	}

	/** The id only grows, so the pages cut on it neither overlap nor leave a gap. */
	public function testALaterPageStartsBelowTheCursor(): void {
		$this->request()->getPage(101, 501);

		$this->assertSame(['id < 501'], $this->where);
		$this->assertSame(['id desc'], $this->order);
	}
}
