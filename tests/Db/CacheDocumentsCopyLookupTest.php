<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\CacheDocumentsRequestBuilder;
use OCA\Social\Db\SocialQueryBuilder;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCP\DB\IResult;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * `/media/{uuid}` finds its row through the indexed md5 of the copy names,
 * so every write of a name writes its key too, and the lookup never touches
 * the unindexed copy columns.
 */
#[AllowMockObjectsWithoutExpectations]
class CacheDocumentsCopyLookupTest extends TestCase {
	private const UUID = '0a1b2c3d-0000-4000-8000-00000000abcd';

	/** @var list<array{0: string, 1: string}> */
	private array $filters = [];
	/** @var array<string, mixed> */
	private array $written = [];

	/**
	 * A builder that records its filters and the values it writes, and
	 * answers successive queries with the given rows.
	 *
	 * @param list<array<string, mixed>|false> $answers
	 */
	private function builder(array $answers = []): SocialQueryBuilder {
		$qb = $this->createMock(SocialQueryBuilder::class);
		$qb->method('limitToDBField')->willReturnCallback(function (string $field, string $value): void {
			$this->filters[] = [$field, $value];
		});
		$record = function ($column, $value) use ($qb) {
			$this->written[(string)$column] = $value;

			return $qb;
		};
		$qb->method('set')->willReturnCallback($record);
		$qb->method('setValue')->willReturnCallback($record);
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('prim')->willReturn('');
		$qb->method('update')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('expr')->willReturn(new FakeExpressions());
		$qb->method('executeQuery')->willReturnCallback(function () use (&$answers) {
			$result = $this->createStub(IResult::class);
			$result->method('fetch')->willReturn(array_shift($answers) ?? false);

			return $result;
		});

		return $qb;
	}

	private function request(SocialQueryBuilder $qb): CacheDocumentsRequest {
		$request = $this->getMockBuilder(CacheDocumentsRequest::class)
			->disableOriginalConstructor()
			->onlyMethods([
				'getCacheDocumentsSelectSql', 'getCacheDocumentsInsertSql',
				'getCacheDocumentsUpdateSql', 'getQueryBuilder',
			])
			->getMock();
		$request->method('getCacheDocumentsSelectSql')->willReturn($qb);
		$request->method('getCacheDocumentsInsertSql')->willReturn($qb);
		$request->method('getCacheDocumentsUpdateSql')->willReturn($qb);
		$request->method('getQueryBuilder')->willReturn($qb);

		return $request;
	}

	private function document(): Document {
		$document = new Document();
		$document->setId('https://remote.example/documents/1');
		$document->setLocalCopy('full-copy');
		$document->setResizedCopy('small-copy');

		return $document;
	}

	public function testTheKeyIsTheMd5OfTheNameAndNothingForNoName(): void {
		$this->assertSame(md5(self::UUID), CacheDocumentsRequestBuilder::copyPrim(self::UUID));
		$this->assertSame('', CacheDocumentsRequestBuilder::copyPrim(''));
	}

	public function testAFullCopyIsFoundWithOneIndexedLookup(): void {
		$request = $this->request($this->builder([['nid' => 7, 'id' => 'https://remote.example/documents/7', 'local_copy' => self::UUID]]));

		$this->assertSame('https://remote.example/documents/7', $request->getByCopy(self::UUID)->getId());
		$this->assertSame([['local_copy_prim', md5(self::UUID)]], $this->filters);
	}

	public function testAResizedCopyIsFoundOnTheSecondIndexedLookup(): void {
		$request = $this->request($this->builder([false, ['nid' => 8, 'id' => 'https://remote.example/documents/8', 'resized_copy' => self::UUID]]));

		$this->assertSame('https://remote.example/documents/8', $request->getByCopy(self::UUID)->getId());
		$this->assertSame([
			['local_copy_prim', md5(self::UUID)],
			['resized_copy_prim', md5(self::UUID)],
		], $this->filters);
	}

	public function testAnUnknownCopyIsAnException(): void {
		$request = $this->request($this->builder());

		$this->expectException(CacheDocumentDoesNotExistException::class);
		$request->getByCopy(self::UUID);
	}

	public function testTheFullCopyLookupUsesOnlyItsOwnKey(): void {
		$request = $this->request($this->builder([['nid' => 9, 'id' => 'https://remote.example/documents/9']]));

		$request->getByLocalCopy(self::UUID);

		$this->assertSame([['local_copy_prim', md5(self::UUID)]], $this->filters);
	}

	public function testAnEmptyNameIsNeverLookedUp(): void {
		$request = $this->request($this->builder([['nid' => 1]]));

		try {
			$request->getByCopy('');
			$this->fail('an empty name matched a row');
		} catch (CacheDocumentDoesNotExistException) {
		}
		$this->assertSame([], $this->filters);
	}

	public function testSavingWritesBothKeys(): void {
		$this->request($this->builder())->save($this->document());

		$this->assertSame(md5('full-copy'), $this->written['local_copy_prim']);
		$this->assertSame(md5('small-copy'), $this->written['resized_copy_prim']);
	}

	public function testUpdatingWritesBothKeys(): void {
		$this->request($this->builder())->update($this->document());

		$this->assertSame(md5('full-copy'), $this->written['local_copy_prim']);
		$this->assertSame(md5('small-copy'), $this->written['resized_copy_prim']);
	}

	public function testFinishingACacheWritesBothKeys(): void {
		$document = $this->document()->setResizedCopy('');
		$this->request($this->builder())->endCaching($document);

		$this->assertSame(md5('full-copy'), $this->written['local_copy_prim']);
		$this->assertSame('', $this->written['resized_copy_prim']);
	}

	public function testAPosterWritesTheResizedKey(): void {
		$this->request($this->builder())->updatePoster($this->document());

		$this->assertSame(md5('small-copy'), $this->written['resized_copy_prim']);
	}

	public function testAnIconThumbnailWritesTheResizedKey(): void {
		$this->request($this->builder())->updateThumbnail($this->document());

		$this->assertSame(md5('small-copy'), $this->written['resized_copy_prim']);
	}

	public function testARewrittenCopyWritesItsKey(): void {
		$this->request($this->builder())->replaceLocalCopy(3, 'rewritten', 10);

		$this->assertSame(md5('rewritten'), $this->written['local_copy_prim']);
	}

	public function testATranscodedVideoWritesItsKey(): void {
		$this->request($this->builder())->replaceVideo(3, 'converted', 'video/mp4');

		$this->assertSame(md5('converted'), $this->written['local_copy_prim']);
	}
}
