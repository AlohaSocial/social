<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\VideoTranscodeService;
use OCA\Social\Service\VideoTranscodingWorker;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\ITempManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The bookkeeping around a conversion: what is picked, what is recorded, and
 * the order in which the file is replaced.
 */
#[AllowMockObjectsWithoutExpectations]
class VideoTranscodingWorkerTest extends TestCase {
	private CacheDocumentsRequest|MockObject $cacheDocumentsRequest;
	private CacheDocumentService|Stub $cacheDocumentService;
	private VideoTranscodeService|MockObject $videoTranscodeService;
	private ITempManager|Stub $tempManager;
	private VideoTranscodingWorker $worker;

	/** paths made during a test, removed afterwards */
	private array $temporary = [];

	protected function setUp(): void {
		parent::setUp();

		$this->cacheDocumentsRequest = $this->createMock(CacheDocumentsRequest::class);
		$this->cacheDocumentService = $this->createStub(CacheDocumentService::class);
		$this->videoTranscodeService = $this->createMock(VideoTranscodeService::class);
		$this->tempManager = $this->createStub(ITempManager::class);

		$this->tempManager->method('getTemporaryFile')->willReturnCallback(
			function (string $suffix = ''): string {
				$path = tempnam(sys_get_temp_dir(), 'transcode') . $suffix;
				$this->temporary[] = $path;

				return $path;
			}
		);

		$this->worker = new VideoTranscodingWorker(
			$this->cacheDocumentsRequest,
			$this->cacheDocumentService,
			$this->videoTranscodeService,
			$this->tempManager,
			new NullLogger(),
		);
	}

	protected function tearDown(): void {
		foreach ($this->temporary as $path) {
			@unlink($path);
		}
		parent::tearDown();
	}

	private function document(int $nid, string $type, string $copy = 'stored-uuid'): Document {
		$document = new Document();
		$document->setNid($nid);
		$document->setId('https://cloud.example/media/' . $nid);
		$document->setMediaType($type);
		$document->setLocalCopy($copy);

		return $document;
	}

	/** Stored bytes the worker can read back. */
	private function storedFile(string $content = 'not really a video'): ISimpleFile|MockObject {
		$path = tempnam(sys_get_temp_dir(), 'stored');
		file_put_contents($path, $content);
		$this->temporary[] = $path;

		$file = $this->createMock(ISimpleFile::class);
		$file->method('read')->willReturnCallback(static fn () => fopen($path, 'rb'));

		return $file;
	}

	/**
	 * Without this the same page of MP4s would be read on every run for ever,
	 * and nothing behind them would ever be converted.
	 */
	public function testAVideoAlreadyInTheTargetFormatIsMarkedAndPassedOver(): void {
		$page = [$this->document(1, 'video/mp4')];
		$this->cacheDocumentsRequest->method('getVideosToTranscode')->willReturnCallback(
			static fn (int $limit, int $after = 0): array => ($after === 0) ? $page : []
		);
		$this->videoTranscodeService->method('shouldConvert')->willReturn(false);

		$this->cacheDocumentsRequest->expects($this->once())->method('setTranscoded')
			->with(1, VideoTranscodingWorker::NOT_NEEDED);

		$this->assertFalse($this->worker->convertNext());
	}

	public function testTheFirstVideoWorthConvertingIsTheOneConverted(): void {
		$mp4 = $this->document(1, 'video/mp4');
		$mov = $this->document(2, 'video/quicktime');
		$this->cacheDocumentsRequest->method('getVideosToTranscode')->willReturn([$mp4, $mov]);
		$this->videoTranscodeService->method('shouldConvert')->willReturnCallback(
			static fn (string $type): bool => $type === 'video/quicktime'
		);

		$this->cacheDocumentService->method('getContentFromCache')->willReturn($this->storedFile());
		$converted = tempnam(sys_get_temp_dir(), 'out');
		file_put_contents($converted, 'converted');
		$this->temporary[] = $converted;
		$this->videoTranscodeService->method('convert')->willReturn($converted);
		$this->cacheDocumentService->method('storeFile')->willReturn('new-uuid');

		$this->cacheDocumentsRequest->expects($this->once())->method('replaceVideo')
			->with(2, 'new-uuid', VideoTranscodeService::TARGET_TYPE);

		$this->assertTrue($this->worker->convertNext());
	}

	/**
	 * The original goes last. A failure anywhere before that leaves the
	 * document pointing at a file that exists, which is the difference between
	 * a video in an awkward format and a post whose video 404s.
	 */
	public function testTheOriginalIsOnlyDeletedOnceTheRowPointsAtTheNewFile(): void {
		$mov = $this->document(2, 'video/quicktime', 'old-uuid');
		$this->cacheDocumentService->method('getContentFromCache')->willReturn($this->storedFile());
		$converted = tempnam(sys_get_temp_dir(), 'out');
		file_put_contents($converted, 'converted');
		$this->temporary[] = $converted;
		$this->videoTranscodeService->method('convert')->willReturn($converted);
		$this->cacheDocumentService->method('storeFile')->willReturn('new-uuid');

		$order = [];
		$this->cacheDocumentsRequest->method('replaceVideo')
			->willReturnCallback(static function () use (&$order): void {
				$order[] = 'row';
			});
		$this->cacheDocumentService->method('removeFromCache')
			->willReturnCallback(static function (string $uuid) use (&$order): void {
				$order[] = 'deleted ' . $uuid;
			});

		$this->worker->convert($mov);

		$this->assertSame(['row', 'deleted old-uuid'], $order);
	}

	/** A file ffmpeg could not read is recorded, not retried for ever. */
	public function testAConversionThatFailedIsRecordedAsTried(): void {
		$mov = $this->document(2, 'video/quicktime');
		$this->cacheDocumentService->method('getContentFromCache')->willReturn($this->storedFile());
		$this->videoTranscodeService->method('convert')->willReturn(null);

		$this->cacheDocumentsRequest->expects($this->once())->method('setTranscoded')
			->with(2, VideoTranscodingWorker::FAILED);
		$this->cacheDocumentsRequest->expects($this->never())->method('replaceVideo');

		$this->assertFalse($this->worker->convert($mov));
	}

	/** And so is one whose bytes are not there any more. */
	public function testAVideoWhoseFileIsGoneIsRecordedRatherThanRead(): void {
		$mov = $this->document(2, 'video/quicktime');
		$this->cacheDocumentService->method('getContentFromCache')
			->willThrowException(new \RuntimeException('no such file'));

		$this->cacheDocumentsRequest->expects($this->once())->method('setTranscoded')
			->with(2, VideoTranscodingWorker::FAILED);
		$this->videoTranscodeService->expects($this->never())->method('convert');

		$this->assertFalse($this->worker->convert($mov));
	}

	/** Nothing to do is not a failure. */
	public function testAnEmptyQueueConvertsNothingAndSaysSo(): void {
		$this->cacheDocumentsRequest->method('getVideosToTranscode')->willReturn([]);

		$this->assertFalse($this->worker->convertNext());
	}

	/**
	 * Found on devel: a run reported "nothing was waiting" with a
	 * `video/quicktime` plainly in the table, because the first page it read
	 * was twenty MP4s and it gave up there. MP4 is what most things upload, so
	 * that is the ordinary case rather than an edge one.
	 */
	public function testItWalksPastAWholePageOfMp4sToReachTheVideoBehindThem(): void {
		$mp4s = [];
		for ($nid = 1; $nid <= 20; $nid++) {
			$mp4s[] = $this->document($nid, 'video/mp4');
		}
		$mov = $this->document(21, 'video/quicktime');

		$this->cacheDocumentsRequest->method('getVideosToTranscode')->willReturnCallback(
			static fn (int $limit, int $after = 0): array => ($after === 0) ? $mp4s : [$mov]
		);
		$this->videoTranscodeService->method('shouldConvert')->willReturnCallback(
			static fn (string $type): bool => $type === 'video/quicktime'
		);

		$this->cacheDocumentService->method('getContentFromCache')->willReturn($this->storedFile());
		$converted = tempnam(sys_get_temp_dir(), 'out');
		file_put_contents($converted, 'converted');
		$this->temporary[] = $converted;
		$this->videoTranscodeService->method('convert')->willReturn($converted);
		$this->cacheDocumentService->method('storeFile')->willReturn('new-uuid');

		$this->cacheDocumentsRequest->expects($this->once())->method('replaceVideo')
			->with(21, 'new-uuid', VideoTranscodeService::TARGET_TYPE);

		$this->assertTrue($this->worker->convertNext());
	}

	/** And it stops when the walk runs out, rather than asking for ever. */
	public function testAWholeLibraryOfMp4sEndsTheWalkRatherThanLoopingForever(): void {
		$page = [$this->document(1, 'video/mp4')];
		$this->cacheDocumentsRequest->method('getVideosToTranscode')->willReturnCallback(
			static fn (int $limit, int $after = 0): array => ($after === 0) ? $page : []
		);
		$this->videoTranscodeService->method('shouldConvert')->willReturn(false);

		$this->assertFalse($this->worker->convertNext());
	}
}
