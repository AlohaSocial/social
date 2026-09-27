<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\RenditionsRequest;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\VideoRendition;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\VideoLadderService;
use OCA\Social\Service\VideoLadderWorker;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\ITempManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The bookkeeping around a ladder: what is picked, what is written, and what
 * is cleaned up when a rung fails.
 */
#[AllowMockObjectsWithoutExpectations]
class VideoLadderWorkerTest extends TestCase {
	private CacheDocumentsRequest|MockObject $cacheDocumentsRequest;
	private RenditionsRequest|MockObject $renditionsRequest;
	private CacheDocumentService|MockObject $cacheDocumentService;
	private VideoLadderService|MockObject $videoLadderService;
	private VideoLadderWorker $worker;

	/** paths made during a test, removed afterwards */
	private array $temporary = [];

	protected function setUp(): void {
		parent::setUp();

		$this->cacheDocumentsRequest = $this->createMock(CacheDocumentsRequest::class);
		$this->renditionsRequest = $this->createMock(RenditionsRequest::class);
		$this->cacheDocumentService = $this->createMock(CacheDocumentService::class);
		$this->videoLadderService = $this->createMock(VideoLadderService::class);

		$tempManager = $this->createStub(ITempManager::class);
		$tempManager->method('getTemporaryFile')->willReturnCallback(
			function (string $suffix = ''): string {
				$path = tempnam(sys_get_temp_dir(), 'ladder') . $suffix;
				$this->temporary[] = $path;

				return $path;
			}
		);

		$this->videoLadderService->method('heights')->willReturn([360, 720]);

		$this->worker = new VideoLadderWorker(
			$this->cacheDocumentsRequest,
			$this->renditionsRequest,
			$this->cacheDocumentService,
			$this->videoLadderService,
			$tempManager,
			new NullLogger(),
		);
	}

	protected function tearDown(): void {
		foreach ($this->temporary as $path) {
			@unlink($path);
		}
		parent::tearDown();
	}

	private function document(int $nid = 7): Document {
		$document = new Document();
		$document->setNid($nid)
			->setId('https://cloud.example/media/' . $nid)
			->setMediaType('video/mp4')
			->setLocalCopy('stored-uuid');

		return $document;
	}

	/** Stored bytes the worker can read back. */
	private function stored(): ISimpleFile|MockObject {
		$path = tempnam(sys_get_temp_dir(), 'source');
		file_put_contents($path, 'not really a video');
		$this->temporary[] = $path;

		$file = $this->createMock(ISimpleFile::class);
		$file->method('read')->willReturnCallback(static fn () => fopen($path, 'rb'));

		return $file;
	}

	/** One encoded rung, as the service hands it back. */
	private function encoded(int $size = 1000): array {
		$path = tempnam(sys_get_temp_dir(), 'rung');
		file_put_contents($path, 'encoded');
		$this->temporary[] = $path;

		return [
			'file' => $path,
			'playlist' => "#EXTM3U\n" . VideoRendition::URI_PLACEHOLDER . "\n",
			'size' => $size,
			'bandwidth' => 800000,
		];
	}

	public function testEveryRungIsWrittenAndRecorded(): void {
		$this->cacheDocumentService->method('getContentFromCache')->willReturn($this->stored());
		$this->videoLadderService->method('heightOf')->willReturn(1080);
		$this->videoLadderService->method('rungs')->willReturn([360, 720, 1080]);
		$this->videoLadderService->method('encode')->willReturnCallback(fn (): array => $this->encoded());
		$this->cacheDocumentService->method('storeFile')->willReturn('rung-uuid');

		$saved = [];
		$this->renditionsRequest->method('save')->willReturnCallback(
			static function (VideoRendition $rendition) use (&$saved): void {
				$saved[] = $rendition->getHeight();
			}
		);
		$this->cacheDocumentsRequest->expects($this->once())->method('setLaddered')
			->with(7, VideoLadderWorker::LADDERED);

		$this->assertTrue($this->worker->ladder($this->document()));
		$this->assertSame([360, 720, 1080], $saved);
	}

	/**
	 * A master playlist that advertises a rung whose file is not there is a
	 * player that stalls rather than one that picks another, so a ladder is
	 * published whole or not at all — and the rungs that did get written are
	 * disk nothing points at.
	 */
	public function testAFailedRungTearsDownTheRungsAlreadyWritten(): void {
		$this->cacheDocumentService->method('getContentFromCache')->willReturn($this->stored());
		$this->videoLadderService->method('heightOf')->willReturn(1080);
		$this->videoLadderService->method('rungs')->willReturn([360, 720, 1080]);

		$attempt = 0;
		$this->videoLadderService->method('encode')->willReturnCallback(
			function () use (&$attempt): ?array {
				$attempt++;

				return ($attempt < 3) ? $this->encoded() : null;
			}
		);
		$this->cacheDocumentService->method('storeFile')->willReturnOnConsecutiveCalls('one', 'two');

		$removed = [];
		$this->cacheDocumentService->method('removeFromCache')->willReturnCallback(
			static function (string $path) use (&$removed): void {
				$removed[] = $path;
			}
		);

		$this->renditionsRequest->expects($this->never())->method('save');
		$this->cacheDocumentsRequest->expects($this->once())->method('setLaddered')
			->with(7, VideoLadderWorker::FAILED);

		$this->assertFalse($this->worker->ladder($this->document()));
		$this->assertSame(['one', 'two'], $removed, 'the two rungs that were written are cleaned up');
	}

	/** Nothing is written until every rung is in the store. */
	public function testTheRowsArePointedAtTheRungsOnlyOnceEveryRungExists(): void {
		$this->cacheDocumentService->method('getContentFromCache')->willReturn($this->stored());
		$this->videoLadderService->method('heightOf')->willReturn(720);
		$this->videoLadderService->method('rungs')->willReturn([360, 720]);
		$this->videoLadderService->method('encode')->willReturnCallback(fn (): array => $this->encoded());

		$order = [];
		$this->cacheDocumentService->method('storeFile')->willReturnCallback(
			static function () use (&$order): string {
				$order[] = 'store';

				return 'rung-uuid';
			}
		);
		$this->renditionsRequest->method('save')->willReturnCallback(
			static function () use (&$order): void {
				$order[] = 'save';
			}
		);

		$this->worker->ladder($this->document());

		$this->assertSame(['store', 'store', 'save', 'save'], $order);
	}

	/** A previous ladder's files are deleted only after the new rows are in. */
	public function testTheOldLaddersFilesAreForgottenAfterTheNewOneIsWritten(): void {
		$this->cacheDocumentService->method('getContentFromCache')->willReturn($this->stored());
		$this->videoLadderService->method('heightOf')->willReturn(720);
		$this->videoLadderService->method('rungs')->willReturn([720]);
		$this->videoLadderService->method('encode')->willReturnCallback(fn (): array => $this->encoded());
		$this->cacheDocumentService->method('storeFile')->willReturn('new-rung');
		$this->renditionsRequest->method('deleteForDocument')->willReturn(['old-rung']);

		$this->cacheDocumentService->expects($this->once())->method('removeFromCache')->with('old-rung');

		$this->assertTrue($this->worker->ladder($this->document()));
	}

	/**
	 * A video already smaller than the smallest rung an administrator asked
	 * for would get a ladder with one rung on it: a second copy of the same
	 * file. Marked so the walk moves on rather than reading it every run.
	 */
	public function testAVideoSmallerThanTheLowestRungIsPassedOver(): void {
		$this->cacheDocumentService->method('getContentFromCache')->willReturn($this->stored());
		$this->videoLadderService->method('heightOf')->willReturn(240);
		$this->videoLadderService->method('rungs')->willReturn([240]);

		$this->videoLadderService->expects($this->never())->method('encode');
		$this->cacheDocumentsRequest->expects($this->once())->method('setLaddered')
			->with(7, VideoLadderWorker::NOT_NEEDED);

		$this->assertFalse($this->worker->ladder($this->document()));
	}

	/** The bytes are not there. Not a failure of the encode — recorded so the job moves on. */
	public function testAVideoWhoseBytesAreMissingIsMarkedRatherThanRetriedForever(): void {
		$this->cacheDocumentService->method('getContentFromCache')
			->willThrowException(new \RuntimeException('gone'));

		$this->cacheDocumentsRequest->expects($this->once())->method('setLaddered')
			->with(7, VideoLadderWorker::FAILED);

		$this->assertFalse($this->worker->ladder($this->document()));
	}

	/**
	 * Otherwise a page of videos that are all too small would report "nothing
	 * to do" with an hour-long one sitting behind them, for ever.
	 */
	public function testThePassedOverVideosAreWalkedPastRatherThanEndingTheRun(): void {
		$page = [$this->document(1), $this->document(2)];
		$this->cacheDocumentsRequest->method('getVideosToLadder')->willReturnCallback(
			static fn (int $limit, int $after = 0): array => ($after === 0) ? $page : []
		);
		$this->cacheDocumentService->method('getContentFromCache')->willReturn($this->stored());
		$this->videoLadderService->method('heightOf')->willReturn(240);
		$this->videoLadderService->method('rungs')->willReturn([240]);

		$marked = [];
		$this->cacheDocumentsRequest->method('setLaddered')->willReturnCallback(
			static function (int $nid, int $state) use (&$marked): void {
				$marked[] = $nid;
			}
		);

		$this->assertFalse($this->worker->ladderNext());
		$this->assertSame([1, 2], $marked, 'both were looked at, not just the first');
	}

	public function testTearingDownALadderRemovesItsFilesAndLetsItBeBuiltAgain(): void {
		$this->renditionsRequest->method('deleteForDocument')->willReturn(['a', 'b']);

		$removed = [];
		$this->cacheDocumentService->method('removeFromCache')->willReturnCallback(
			static function (string $path) use (&$removed): void {
				$removed[] = $path;
			}
		);
		$this->cacheDocumentsRequest->expects($this->once())->method('setLaddered')
			->with(7, VideoLadderWorker::NOT_LOOKED);

		$this->worker->tearDown(7);

		$this->assertSame(['a', 'b'], $removed);
	}
}
