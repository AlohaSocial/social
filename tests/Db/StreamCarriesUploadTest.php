<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\SocialQueryBuilder;
use OCA\Social\Db\StreamRequest;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Whether one of an account's posts carries an upload. An upload has no
 * `parent_id` (it exists before its post), so the post is found by its stored
 * attachment copy, narrowed by the indexed author first.
 */
#[AllowMockObjectsWithoutExpectations]
class StreamCarriesUploadTest extends TestCase {
	/** @var string[] */
	private array $where = [];
	/** @var array{0: string, 1: bool}|null */
	private ?array $author = null;
	private ?int $max = null;

	private function request(array|false $row): StreamRequest&MockObject {
		$qb = $this->createMock(SocialQueryBuilder::class);
		foreach (['select', 'from'] as $method) {
			$qb->method($method)->willReturnCallback(static fn (): SocialQueryBuilder => $qb);
		}
		$qb->method('expr')->willReturn(new FakeExpressions());
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value): string => (string)$value);
		$qb->method('andWhere')->willReturnCallback(function ($clause) use (&$qb): SocialQueryBuilder {
			$this->where[] = (string)$clause;

			return $qb;
		});
		$qb->method('limitToAttributedTo')->willReturnCallback(function (string $actorId, bool $prim): void {
			$this->author = [$actorId, $prim];
		});
		$qb->method('setMaxResults')->willReturnCallback(function (?int $max) use (&$qb): SocialQueryBuilder {
			$this->max = $max;

			return $qb;
		});

		$result = $this->createStub(IResult::class);
		$result->method('fetch')->willReturn($row);
		$qb->method('executeQuery')->willReturn($result);

		$request = $this->getMockBuilder(StreamRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getQueryBuilder'])
			->getMock();
		$request->method('getQueryBuilder')->willReturn($qb);

		$connection = $this->createStub(IDBConnection::class);
		$connection->method('escapeLikeParameter')->willReturnArgument(0);
		(new ReflectionProperty(StreamRequest::class, 'dbConnection'))->setValue($request, $connection);

		return $request;
	}

	public function testAPostOfTheAccountThatCarriesTheUploadIsFound(): void {
		$this->assertTrue($this->request(['nid' => '40'])->carriesUpload('https://cloud.example/users/alice', '7'));

		$this->assertSame(['https://cloud.example/users/alice', true], $this->author);
		$this->assertSame(['s.attachments LIKE %"id":"7"%'], $this->where);
		$this->assertSame(1, $this->max);
	}

	public function testAnUploadNoPostCarriesIsNotFound(): void {
		$this->assertFalse($this->request(false)->carriesUpload('https://cloud.example/users/alice', '7'));
	}
}
