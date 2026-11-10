<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Db\SocialQueryBuilder;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * The repository head row and the blob bytes it counts. Blobs can be
 * uploaded before the account's first commit, when there is no row for
 * `addBlobBytes()` to add to; the first commit starts the count with them.
 */
#[AllowMockObjectsWithoutExpectations]
class AtprotoRepoHeadTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	/** @var list<string> the statements run, by kind and table */
	private array $ran = [];
	/** @var array<string, mixed> the values the INSERT set */
	private array $inserted = [];

	private function request(int $updated, string $blobBytes): AtprotoRepoRequest {
		$request = $this->getMockBuilder(AtprotoRepoRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getQueryBuilder'])
			->getMock();
		$request->method('getQueryBuilder')->willReturnCallback(fn (): SocialQueryBuilder => $this->builder($updated, $blobBytes));

		return $request;
	}

	private function builder(int $updated, string $blobBytes): SocialQueryBuilder {
		$qb = $this->createMock(SocialQueryBuilder::class);
		$kind = '';
		foreach (['insert', 'update', 'from'] as $method) {
			$qb->method($method)->willReturnCallback(function (string $table) use ($qb, $method, &$kind): SocialQueryBuilder {
				$kind = $method . ' ' . $table;

				return $qb;
			});
		}
		foreach (['set', 'where', 'andWhere', 'selectAlias'] as $method) {
			$qb->method($method)->willReturn($qb);
		}
		$qb->method('setValue')->willReturnCallback(function (string $column, $value) use ($qb): SocialQueryBuilder {
			$this->inserted[$column] = $value;

			return $qb;
		});
		$qb->method('expr')->willReturn(new FakeExpressions());
		$functions = $this->createStub(IFunctionBuilder::class);
		$functions->method('sum')->willReturn($this->createStub(IQueryFunction::class));
		$qb->method('func')->willReturn($functions);
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value) => $value);
		$qb->method('executeStatement')->willReturnCallback(function () use (&$kind, $updated): int {
			$this->ran[] = $kind;

			return str_starts_with($kind, 'update') ? $updated : 1;
		});
		$result = $this->createStub(IResult::class);
		$result->method('fetch')->willReturn(['bytes' => $blobBytes]);
		$qb->method('executeQuery')->willReturnCallback(function () use (&$kind, $result): IResult {
			$this->ran[] = 'select ' . $kind;

			return $result;
		});

		return $qb;
	}

	public function testTheFirstCommitCountsTheBlobsRegisteredBeforeIt(): void {
		$this->request(0, '4096')->setHead(self::DID, Cid::forRaw('commit'), '3kznmn7xqxl22', 1);

		$this->assertSame(['update social_atproto_repo', 'select from social_atproto_blob', 'insert social_atproto_repo'], $this->ran);
		$this->assertSame(4096, $this->inserted['blob_bytes']);
	}

	public function testAFirstCommitWithoutBlobsStartsAtZero(): void {
		$this->request(0, '')->setHead(self::DID, Cid::forRaw('commit'), '3kznmn7xqxl22', 1);

		$this->assertSame(0, $this->inserted['blob_bytes']);
	}

	public function testALaterCommitLeavesTheCountAlone(): void {
		$this->request(1, '4096')->setHead(self::DID, Cid::forRaw('commit'), '3kznmn7xqxl23', 2);

		$this->assertSame(['update social_atproto_repo'], $this->ran);
		$this->assertArrayNotHasKey('blob_bytes', $this->inserted);
	}
}
