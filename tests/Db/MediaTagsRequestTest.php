<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\MediaTagsRequest;
use OCA\Social\Db\SocialQueryBuilder;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * `stream_id` is a post's nid, which is wider than a PHP int on a 32-bit
 * server and will be on a 64-bit one too once nids reach twenty digits.
 * Every statement here has to carry it as the decimal string it is: bound as
 * an int it is truncated to PHP_INT_MAX, and names a different post.
 *
 * The statements are not run; what they were asked to bind is what is
 * asserted.
 */
#[AllowMockObjectsWithoutExpectations]
class MediaTagsRequestTest extends TestCase {
	private const WIDE = '92233720368547758070';
	private const ALICE = 'https://cloud.example/@alice';

	/** @var array<int, array{0: mixed, 1: mixed}> every value bound, with its type */
	private array $bound = [];
	/** @var string[] every predicate added */
	private array $where = [];
	/** @var array<int, array<string, mixed>> the rows the SELECT hands back */
	private array $rows = [];

	private function request(): MediaTagsRequest&MockObject {
		$qb = $this->createMock(SocialQueryBuilder::class);
		foreach (['select', 'selectAlias', 'from', 'insert', 'delete', 'setValue', 'orderBy', 'setMaxResults'] as $method) {
			$qb->method($method)->willReturnCallback(static fn (): SocialQueryBuilder => $qb);
		}
		foreach (['where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnCallback(function (string $predicate) use ($qb): SocialQueryBuilder {
				$this->where[] = $predicate;

				return $qb;
			});
		}
		$qb->method('expr')->willReturn(new FakeExpressions());
		$functions = $this->createStub(IFunctionBuilder::class);
		$functions->method('count')->willReturn($this->createStub(IQueryFunction::class));
		$qb->method('func')->willReturn($functions);
		$qb->method('prim')->willReturnCallback(static fn (string $id): string => md5($id));
		$qb->method('createNamedParameter')->willReturnCallback(function ($value, $type = IQueryBuilder::PARAM_STR): string {
			$this->bound[] = [$value, $type];

			return is_scalar($value) ? (string)$value : ':p';
		});

		$result = $this->createStub(IResult::class);
		$result->method('fetch')->willReturnCallback(fn () => array_shift($this->rows) ?? false);
		$qb->method('executeQuery')->willReturn($result);
		$qb->method('executeStatement')->willReturn(1);

		$request = $this->getMockBuilder(MediaTagsRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getQueryBuilder'])
			->getMock();
		$request->method('getQueryBuilder')->willReturn($qb);

		return $request;
	}

	/** The one binding that names the post, whatever else the statement binds. */
	private function streamBinding(): array {
		foreach ($this->bound as $binding) {
			if ($binding[0] === self::WIDE) {
				return $binding;
			}
		}

		$this->fail('the nid was not bound as its decimal string: ' . var_export($this->bound, true));
	}

	public function testEveryStatementNamingAPostBindsItsNidAsAString(): void {
		$statements = [
			'tag' => fn (MediaTagsRequest $r) => $r->tag(self::WIDE, md5('post'), self::ALICE, self::ALICE),
			'untag' => fn (MediaTagsRequest $r) => $r->untag(self::WIDE, self::ALICE),
			'isTagged' => fn (MediaTagsRequest $r) => $r->isTagged(self::WIDE, self::ALICE),
			'countForStream' => fn (MediaTagsRequest $r) => $r->countForStream(self::WIDE),
			'deleteByStream' => fn (MediaTagsRequest $r) => $r->deleteByStream(self::WIDE),
		];

		foreach ($statements as $name => $statement) {
			$this->bound = [];
			$statement($this->request());

			$this->assertSame(
				[self::WIDE, IQueryBuilder::PARAM_STR], $this->streamBinding(), $name
			);
		}
	}

	public function testALeadingZeroNamesTheSamePost(): void {
		$this->request()->deleteByStream('0' . self::WIDE);

		$this->assertSame([self::WIDE, IQueryBuilder::PARAM_STR], $this->streamBinding());
	}

	public function testThePhotosOfSomebodyArePagedOnTheExactCursor(): void {
		$this->request()->streamsFor(self::ALICE, 20, self::WIDE);

		$this->assertSame([self::WIDE, IQueryBuilder::PARAM_STR], $this->streamBinding());
		$this->assertContains('stream_id < ' . self::WIDE, $this->where);
	}

	public function testNoCursorAndAMalformedOneBoundNothing(): void {
		foreach (['0', 0, '', 'abc'] as $cursor) {
			$this->where = [];
			$this->request()->streamsFor(self::ALICE, 20, $cursor);

			$this->assertCount(1, $this->where, var_export($cursor, true));
		}
	}

	public function testThePostsComeBackAsTheirExactIds(): void {
		$this->rows = [['stream_id' => self::WIDE], ['stream_id' => 1790166469714425175]];

		$this->assertSame(
			[self::WIDE, '1790166469714425175'],
			$this->request()->streamsFor(self::ALICE)
		);
	}
}
