<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\SocialQueryBuilder;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCP\DB\IResult;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Which videos the transcoder is offered.
 *
 * The selection was every video with bytes here, and a remote attachment is
 * cached with its bytes here too — so the cron re-encoded other servers'
 * videos, minutes of CPU each, for files this instance only mirrors.
 */
#[AllowMockObjectsWithoutExpectations]
class VideosToTranscodeScopeTest extends TestCase {
	/** @var string[] */
	private array $where = [];

	private function request(): CacheDocumentsRequest {
		$qb = $this->createMock(SocialQueryBuilder::class);
		$qb->method('getDefaultSelectAlias')->willReturn('cd');
		$qb->method('expr')->willReturn(new FakeExpressions());
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value): string => ':' . $value);
		$qb->method('andWhere')->willReturnCallback(function ($predicate) use ($qb) {
			$this->where[] = (string)$predicate;

			return $qb;
		});
		foreach (['orderBy', 'setMaxResults'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$empty = $this->createStub(IResult::class);
		$empty->method('fetch')->willReturn(false);
		$qb->method('executeQuery')->willReturn($empty);

		$request = $this->getMockBuilder(CacheDocumentsRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getCacheDocumentsSelectSql'])
			->getMock();
		$request->method('getCacheDocumentsSelectSql')->willReturn($qb);

		return $request;
	}

	public function testOnlyVideosUploadedByALocalAccountAreOffered(): void {
		$this->request()->getVideosToTranscode();

		$this->assertContains('cd.account <> :', $this->where);
		// and the bytes have to be here, as before
		$this->assertContains('cd.media_type LIKE :video/%', $this->where);
		$this->assertContains('cd.local_copy <> :' . Document::COPY_STREAMED, $this->where);
	}

	public function testARowCachedFromAPeerHasNoAccountAndIsNotALocalUpload(): void {
		$this->assertFalse((new Document())->isLocalUpload());
		$this->assertTrue((new Document())->setAccount('alice')->isLocalUpload());
	}
}
