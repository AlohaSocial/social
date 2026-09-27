<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\MediaPurgeService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Taking stored media away: the files first, because the row is the only
 * thing that remembers their names.
 */
#[AllowMockObjectsWithoutExpectations]
class MediaPurgeServiceTest extends TestCase {
	private CacheDocumentsRequest|MockObject $cacheDocumentsRequest;
	private CacheDocumentService|Stub $cacheDocumentService;
	/** @var list<string> what was removed, in order */
	private array $removed = [];
	private MediaPurgeService $service;

	protected function setUp(): void {
		$this->cacheDocumentsRequest = $this->createMock(CacheDocumentsRequest::class);
		$this->cacheDocumentService = $this->createStub(CacheDocumentService::class);
		$this->removed = [];
		$this->cacheDocumentService->method('removeFromCache')->willReturnCallback(
			function (string $name): void {
				$this->removed[] = $name;
			}
		);

		$this->service = new MediaPurgeService(
			$this->cacheDocumentsRequest, $this->cacheDocumentService, new NullLogger()
		);
	}

	private function document(string $id = 'https://remote.example/media/1'): Document {
		$document = new Document();
		$document->setId($id);
		$document->setLocalCopy('full');
		$document->setResizedCopy('small');

		return $document;
	}

	public function testBothCopiesGoBeforeTheRowThatNamesThem(): void {
		$order = [];
		$this->cacheDocumentService->method('removeFromCache')->willReturnCallback(
			static function (string $name) use (&$order): void {
				$order[] = 'file:' . $name;
			}
		);
		$this->cacheDocumentsRequest->method('deleteById')->willReturnCallback(
			static function (string $id) use (&$order): void {
				$order[] = 'row:' . $id;
			}
		);

		$this->service->purge($this->document());

		$this->assertSame(
			['file:full', 'file:small', 'row:https://remote.example/media/1'], $order
		);
	}

	public function testPurgeByIdIsANoOpForARowThatIsNotThere(): void {
		$this->cacheDocumentsRequest->method('getById')
			->willThrowException(new CacheDocumentDoesNotExistException());
		$this->cacheDocumentsRequest->expects($this->never())->method('deleteById');

		$this->assertFalse($this->service->purgeById('https://remote.example/media/1'));
	}

	public function testEverythingHangingOffAParentGoesWithIt(): void {
		$this->cacheDocumentsRequest->method('getByParent')
			->with('https://gone.example/users/a')
			->willReturn([$this->document('a'), $this->document('b')]);
		$this->cacheDocumentsRequest->expects($this->once())->method('deleteByParent')
			->with('https://gone.example/users/a');

		$this->assertSame(2, $this->service->purgeByParent('https://gone.example/users/a'));
		$this->assertSame(['full', 'small', 'full', 'small'], $this->removed);
	}

	/**
	 * A url identifies a row and nothing else, so an account that points its
	 * profile at somebody else's picture must not be able to have that
	 * picture deleted by taking its own down.
	 */
	public function testAUrlOnlyIdentifiesTheOwnersOwnDocument(): void {
		$document = $this->document();
		$document->setAccount('bob');
		$this->cacheDocumentsRequest->method('getByUrl')->willReturn($document);
		$this->cacheDocumentsRequest->expects($this->never())->method('deleteById');

		$this->assertFalse(
			$this->service->purgeLocalByUrl('https://cloud.example/media/abc', 'alice')
		);
		$this->assertSame([], $this->removed);
	}

	public function testTheOwnersOwnDocumentAtAUrlGoes(): void {
		$document = $this->document();
		$document->setAccount('alice');
		$this->cacheDocumentsRequest->method('getByUrl')->willReturn($document);
		$this->cacheDocumentsRequest->expects($this->once())->method('deleteById');

		$this->assertTrue(
			$this->service->purgeLocalByUrl('https://cloud.example/media/abc', 'alice')
		);
	}

	public function testOneRowThatWillNotGoDoesNotStopTheSweep(): void {
		$this->cacheDocumentsRequest->method('getOrphanedByParent')
			->with(30, 500)
			->willReturn([$this->document('a'), $this->document('b'), $this->document('c')]);
		$this->cacheDocumentsRequest->method('deleteById')->willReturnCallback(
			static function (string $id): void {
				if ($id === 'b') {
					throw new \RuntimeException('the database said no');
				}
			}
		);

		$this->assertSame(2, $this->service->sweepOrphans(30, 500));
	}
}
