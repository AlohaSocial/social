<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\MediaBlocksRequest;
use OCA\Social\Exceptions\CacheContentDecodeException;
use OCA\Social\Exceptions\CacheContentException;
use OCA\Social\Exceptions\CacheContentMimeTypeException;
use OCA\Social\Exceptions\CacheContentSizeException;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Service\BlurService;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\ImageConversionService;
use OCA\Social\Service\VideoThumbnailService;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\ITempManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class CacheDocumentServiceTest extends TestCase {
	private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

	private IAppData|MockObject $appData;
	private CurlService|MockObject $curlService;
	private BlurService|MockObject $blurService;
	private ImageConversionService|MockObject $imageConversionService;
	private VideoThumbnailService|MockObject $videoThumbnailService;
	private ITempManager|MockObject $tempManager;
	private MediaBlocksRequest|MockObject $mediaBlocksRequest;
	private \OCA\Social\Service\RemoteMediaQuotaService|MockObject $domainQuota;
	/** @var string[] */
	private array $tempFiles = [];
	/** What the conversion does to a picture, and how often it was asked. */
	private \Closure $conversion;
	private int $converted = 0;
	private CacheDocumentService $service;

	#[\Override]
	protected function tearDown(): void {
		foreach ($this->tempFiles as $path) {
			@unlink($path);
		}
		$this->tempFiles = [];
		parent::tearDown();
	}

	protected function setUp(): void {
		$this->conversion = static fn (string $content, string $mime): array => [$content, $mime];
		$this->converted = 0;
		$this->appData = $this->createMock(IAppData::class);
		// The conversion is exercised by its own test; here it stands in as the
		// identity it is for a picture that needs neither turning nor
		// converting, so these tests keep asserting what they were written for.
		// A test that is about the conversion replaces $this->conversion.
		$this->imageConversionService = $this->createMock(ImageConversionService::class);
		$this->imageConversionService->method('prepareForStorage')
			->willReturnCallback(function (string $content, string $mime): array {
				$this->converted++;

				return ($this->conversion)($content, $mime);
			});
		$this->curlService = $this->createMock(CurlService::class);
		$this->blurService = $this->createMock(BlurService::class);
		// no ffmpeg by default: a poster is what a server that has it adds, and
		// every path here has to work on one that does not
		$this->videoThumbnailService = $this->createMock(VideoThumbnailService::class);
		// a real file, because the code under test writes the stored video to
		// it and hands the path to ffmpeg
		$this->tempManager = $this->createMock(ITempManager::class);
		$this->tempManager->method('getTemporaryFile')->willReturnCallback(
			function (string $suffix = ''): string {
				$path = tempnam(sys_get_temp_dir(), 'social-test-') . $suffix;
				$this->tempFiles[] = $path;

				return $path;
			}
		);
		$this->mediaBlocksRequest = $this->createMock(MediaBlocksRequest::class);

		$this->service = new CacheDocumentService(
			$this->appData,
			$this->curlService,
			$this->blurService,
			$this->createMock(ConfigService::class),
			$this->imageConversionService,
			$this->videoThumbnailService,
			$this->tempManager,
			$this->mediaBlocksRequest,
			$this->createMock(\OCA\Social\Service\VideoQuotaService::class),
			$this->unlimitedDomainQuota(),
			new NullLogger(),
		);
	}

	/** No per-domain media quota, which is what an instance has by default. */
	private function unlimitedDomainQuota(): \OCA\Social\Service\RemoteMediaQuotaService {
		$this->domainQuota = $this->createMock(\OCA\Social\Service\RemoteMediaQuotaService::class);
		$this->domainQuota->method('fits')->willReturn(true);

		return $this->domainQuota;
	}

	/** A real temporary file holding the given bytes, removed after the test. */
	private function tempFile(string $content): string {
		$path = tempnam(sys_get_temp_dir(), 'social-test-');
		file_put_contents($path, $content);
		$this->tempFiles[] = $path;

		return $path;
	}

	/** A small real PNG, so mime detection and the resizer see a genuine image. */
	private function pngBytes(int $width = 16, int $height = 10): string {
		$image = imagecreatetruecolor($width, $height);
		imagefill($image, 0, 0, imagecolorallocate($image, 20, 120, 220));
		ob_start();
		imagepng($image);

		return ob_get_clean();
	}

	/**
	 * The bundled gumlet/php-image-resize still calls finfo_close() and
	 * imagedestroy(), both deprecated in PHP 8.5. Those notices are the
	 * library's, not the service's: keep them out of the test output.
	 */
	/**
	 * GD and the resize library announce malformed input with an E_WARNING before
	 * returning false, and some codecs emit E_DEPRECATED on PHP 8.5. Both are the
	 * expected path here: the assertions are about what the service does with the
	 * failure, and PHPUnit 10 counts anything printed during a test as risky.
	 */
	private function quietly(callable $call): void {
		$previous = error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
		try {
			$call();
		} finally {
			error_reporting($previous);
		}
	}

	/**
	 * Wire appData so every new file lands in $written (path => [name => bytes]).
	 * Folders are reported missing first, so the service has to create them.
	 */
	/**
	 * One video already in storage, and a place for whatever is written next.
	 *
	 * @param string $content the stored video's bytes
	 * @param array<string, array<string, string>> $written filled with what is written
	 */
	private function storedVideo(string $content, array &$written): void {
		$this->appData->method('getFolder')->willReturnCallback(function (string $path) use ($content, &$written) {
			$folder = $this->createMock(ISimpleFolder::class);
			$folder->method('getFile')->willReturnCallback(function () use ($content) {
				$file = $this->createMock(ISimpleFile::class);
				$file->method('read')->willReturnCallback(static function () use ($content) {
					$stream = fopen('php://temp', 'r+');
					fwrite($stream, $content);
					rewind($stream);

					return $stream;
				});

				return $file;
			});
			$folder->method('newFile')->willReturnCallback(function (string $name) use ($path, &$written) {
				$file = $this->createMock(ISimpleFile::class);
				$file->method('putContent')->willReturnCallback(function (string $body) use ($path, $name, &$written) {
					$written[$path][$name] = $body;
				});

				return $file;
			});

			return $folder;
		});
	}

	private function captureWrites(array &$written): void {
		$this->appData->method('getFolder')->willThrowException(new NotFoundException());
		$this->appData->method('newFolder')->willReturnCallback(function (string $path) use (&$written) {
			$folder = $this->createMock(ISimpleFolder::class);
			$folder->method('newFile')->willReturnCallback(function (string $name) use ($path, &$written) {
				$file = $this->createMock(ISimpleFile::class);
				$file->method('putContent')->willReturnCallback(function (string $content) use ($path, $name, &$written) {
					$written[$path][$name] = $content;
				});
				// the streaming path asks for a handle rather than handing over
				// a string; what lands in storage has to be the same either way
				$file->method('write')->willReturnCallback(function () use ($path, $name, &$written) {
					$written[$path][$name] = '';
					$stream = fopen('php://temp', 'r+');
					stream_filter_register('socialcapture', StreamCapture::class);
					stream_filter_append($stream, 'socialcapture', STREAM_FILTER_WRITE, [
						'target' => function (string $chunk) use ($path, $name, &$written): void {
							$written[$path][$name] .= $chunk;
						},
					]);

					return $stream;
				});

				return $file;
			});

			return $folder;
		});
	}

	/** @return array<string, array{string}> */
	public static function allowedMimeProvider(): array {
		return [
			'jpeg' => ['image/jpeg'],
			'gif' => ['image/gif'],
			'png' => ['image/png'],
			'webp' => ['image/webp'],
			// converted on the way in rather than refused
			'avif' => ['image/avif'],
			'heic' => ['image/heic'],
			'heif' => ['image/heif'],
			'mp4 video' => ['video/mp4'],
			'webm' => ['video/webm'],
			'quicktime' => ['video/quicktime'],
			'mp3' => ['audio/mpeg'],
			'aac' => ['audio/mp4'],
			'ogg' => ['audio/ogg'],
			'opus' => ['audio/opus'],
			'wav' => ['audio/wav'],
			'flac' => ['audio/flac'],
			// files: what people on a Nextcloud actually have to share
			'pdf' => ['application/pdf'],
			'plain text' => ['text/plain'],
			'markdown' => ['text/markdown'],
			'csv' => ['text/csv'],
			'zip' => ['application/zip'],
			'odt' => ['application/vnd.oasis.opendocument.text'],
			'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
		];
	}

	#[DataProvider('allowedMimeProvider')]
	public function testFilterMimeTypesAcceptsImages(string $mime): void {
		$this->service->filterMimeTypes($mime);
		$this->addToAssertionCount(1);
	}

	/** @return array<string, array{string}> */
	public static function rejectedMimeProvider(): array {
		return [
			'svg' => ['image/svg+xml'],
			'html' => ['text/html'],
			'php' => ['application/x-httpd-php'],
			'mkv' => ['video/x-matroska'],
			// a file a browser would run is not a file a post may carry
			'exe' => ['application/x-msdownload'],
			'javascript' => ['text/javascript'],
			'empty' => [''],
		];
	}

	public function testADocumentMimeIsKnownAsOne(): void {
		$this->assertTrue(CacheDocumentService::isDocumentMime('application/pdf'));
		$this->assertFalse(CacheDocumentService::isDocumentMime('image/png'));
		$this->assertFalse(CacheDocumentService::isDocumentMime('text/html'));
	}

	#[DataProvider('rejectedMimeProvider')]
	public function testFilterMimeTypesRejectsEverythingElse(string $mime): void {
		$this->expectException(CacheContentMimeTypeException::class);
		$this->service->filterMimeTypes($mime);
	}

	/**
	 * Bytes, through the one funnel every upload and every fetched attachment
	 * takes.
	 */
	private function storeBytes(Document $document, string $content): void {
		$tmp = tempnam(sys_get_temp_dir(), 'social-test-');
		$this->tempFiles[] = $tmp;
		file_put_contents($tmp, $content);

		$this->service->saveFromTempToCache($document, $tmp);
	}

	public function testSaveContentToCacheStoresVideoAsIsWithoutResizeOrBlurhash(): void {
		$written = [];
		$this->captureWrites($written);
		$this->blurService->expects($this->never())->method('generateBlurHash');
		$document = new Document();
		// a minimal MP4: size + ftyp box is enough for content sniffing
		$mp4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x00", 64);

		$this->quietly(fn () => $this->storeBytes($document, $mp4));

		$this->assertSame('video/mp4', $document->getMediaType());
		$this->assertMatchesRegularExpression(self::UUID_PATTERN, $document->getLocalCopy());
		$this->assertSame('', $document->getResizedCopy(), 'video keeps no resized copy');
		$this->assertSame('', $document->getBlurHash());
	}

	public function testSaveContentToCacheResizesWebp(): void {
		if (!function_exists('imagewebp')) {
			$this->markTestSkipped('gd without webp');
		}
		$written = [];
		$this->captureWrites($written);
		$this->blurService->method('generateBlurHash')->willReturn('hash');
		$gd = imagecreatetruecolor(1200, 900);
		ob_start();
		imagewebp($gd);
		$webp = ob_get_clean();
		$document = new Document();

		$this->quietly(fn () => $this->storeBytes($document, $webp));

		$this->assertSame('image/webp', $document->getMediaType());
		$this->assertMatchesRegularExpression(self::UUID_PATTERN, $document->getResizedCopy());
	}

	public function testSaveContentToCacheStoresOriginalAndResizedCopiesInHashedFolders(): void {
		$written = [];
		$this->captureWrites($written);
		$this->blurService->expects($this->once())
			->method('generateBlurHash')
			->with($this->isInstanceOf(\GdImage::class))
			->willReturn('LKO2?U%2Tw=w]~RBVZRi};RPxuwH');
		$document = new Document();
		$png = $this->pngBytes();

		$this->quietly(fn () => $this->storeBytes($document, $png));

		$this->assertSame('image/png', $document->getMediaType());
		$this->assertMatchesRegularExpression(self::UUID_PATTERN, $document->getLocalCopy());
		$this->assertMatchesRegularExpression(self::UUID_PATTERN, $document->getResizedCopy());
		$this->assertNotSame($document->getLocalCopy(), $document->getResizedCopy());
		$this->assertSame([16, 10], $document->getLocalCopySize());
		$this->assertSame([16, 10], $document->getResizedCopySize(), 'small images are not enlarged');
		$this->assertSame('LKO2?U%2Tw=w]~RBVZRi};RPxuwH', $document->getBlurHash());

		$this->assertCount(2, $written);
		foreach ($written as $path => $files) {
			$name = array_key_first($files);
			// folder aa/bb/cc/dd/ derives from the first 8 chars of the file name
			$this->assertSame(chunk_split(substr($name, 0, 8), 2, '/'), $path);
			$this->assertSame('image/png', (new \finfo(FILEINFO_MIME_TYPE))->buffer($files[$name]));
		}
		$this->assertSame($png, $written[chunk_split(substr($document->getLocalCopy(), 0, 8), 2, '/')][$document->getLocalCopy()]);
	}

	public function testSaveContentToCacheReusesAnExistingFolder(): void {
		$folder = $this->createMock(ISimpleFolder::class);
		$file = $this->createMock(ISimpleFile::class);
		$folder->method('newFile')->willReturn($file);
		$this->appData->method('getFolder')->willReturn($folder);
		$this->appData->expects($this->never())->method('newFolder');
		$file->expects($this->exactly(2))->method('putContent');
		$this->blurService->method('generateBlurHash')->willReturn('hash');

		$this->quietly(fn () => $this->storeBytes(new Document(), $this->pngBytes()));
	}

	public function testSaveContentToCacheRefusesNonImagesBeforeTouchingStorage(): void {
		$this->appData->expects($this->never())->method($this->anything());
		$document = new Document();

		try {
			$this->storeBytes($document, '<html><body>not an image</body></html>');
			$this->fail('expected CacheContentMimeTypeException');
		} catch (CacheContentMimeTypeException) {
			$this->assertSame('', $document->getLocalCopy());
		}
	}

	public function testSaveFromTempToCacheReadsTheFileAndSetsTheMediaType(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'social-test-');
		$png = $this->pngBytes(12, 12);
		file_put_contents($tmp, $png);
		try {
			$written = [];
			$this->captureWrites($written);
			$this->blurService->method('generateBlurHash')->willReturn('hash');
			$document = new Document();
			$document->setId('https://remote.example/media/photo');
			$this->domainQuota->expects($this->once())
				->method('record')
				->with('remote.example', strlen($png));

			$this->quietly(fn () => $this->service->saveFromTempToCache($document, $tmp));

			$this->assertSame('image/png', $document->getMediaType());
			$this->assertSame('image/png', $document->getMimeType());
			$this->assertMatchesRegularExpression(self::UUID_PATTERN, $document->getLocalCopy());
			$this->assertSame([12, 12], $document->getLocalCopySize());
			$this->assertCount(2, $written);
		} finally {
			unlink($tmp);
		}
	}

	/**
	 * A Nextcloud Social or Mastodon attachment arrives without an id and is
	 * given one under this instance's address; it is charged to the server
	 * its url names, which is the one the bytes came from.
	 */
	public function testAnAttachmentWithAGeneratedIdIsChargedToTheServerItCameFrom(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'social-test-');
		$this->tempFiles[] = $tmp;
		$png = $this->pngBytes(12, 12);
		file_put_contents($tmp, $png);
		$written = [];
		$this->captureWrites($written);
		$this->blurService->method('generateBlurHash')->willReturn('hash');
		$document = new Document();
		$document->setId('https://cloud.example/documents/g/1d2c3b4a-0000-4000-8000-000000000000');
		$document->setUrl('https://social.b.example/apps/social/media/0bef598f-ef65-4857-a108-b3766689b78c.jpeg');
		$this->domainQuota->expects($this->once())
			->method('fits')->with('social.b.example', strlen($png))->willReturn(true);
		$this->domainQuota->expects($this->once())
			->method('record')->with('social.b.example', strlen($png));

		$this->quietly(fn () => $this->service->saveFromTempToCache($document, $tmp));
	}

	#[WithoutErrorHandler]
	public function testInvalidRemoteImageDoesNotConsumeItsDomainQuota(): void {
		$this->domainQuota->expects($this->never())->method('record');
		$document = new Document();
		$document->setId('https://remote.example/media/photo');
		$tmp = tempnam(sys_get_temp_dir(), 'social-test-');
		$this->tempFiles[] = $tmp;
		file_put_contents($tmp, $this->pngHeaderThenGarbage());

		try {
			$this->expectException(CacheContentDecodeException::class);
			$this->quietly(fn () => $this->service->saveFromTempToCache($document, $tmp));
		} finally {
			@unlink($tmp);
		}
	}

	/** A minimal but genuine mp4, so mime detection sees a real video. */
	private function mp4Bytes(int $padding = 4096): string {
		return "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41"
			. str_repeat("\x00", $padding);
	}

	/**
	 * A video is copied to storage a chunk at a time and never held whole:
	 * `fread()` of a two-gigabyte upload is two gigabytes of memory, and PHP's
	 * limit was the only thing that ever stopped it.
	 */
	public function testAVideoIsStoredWithoutBeingReadWhole(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'social-test-');
		file_put_contents($tmp, $this->mp4Bytes());
		try {
			$written = [];
			$this->captureWrites($written);
			$document = new Document();

			$this->service->saveFromTempToCache($document, $tmp);

			$this->assertSame('video/mp4', $document->getMediaType());
			$this->assertMatchesRegularExpression(self::UUID_PATTERN, $document->getLocalCopy());
			// one file, not two: no resize, and no poster from a server with
			// no ffmpeg
			$this->assertCount(1, $written);
			$this->assertSame($this->mp4Bytes(), reset($written)[$document->getLocalCopy()]);
			$this->assertSame('', $document->getResizedCopy());
		} finally {
			unlink($tmp);
		}
	}

	/**
	 * The poster is the video's *resized copy*, which is what that column
	 * means -- the small image standing in for the file.
	 */
	public function testAVideoPosterBecomesTheResizedCopy(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'social-test-');
		file_put_contents($tmp, $this->mp4Bytes());
		try {
			$written = [];
			$this->captureWrites($written);
			$this->videoThumbnailService->method('poster')->willReturn(
				['content' => 'jpeg-bytes', 'width' => 1280, 'height' => 720, 'duration' => 113]
			);
			$document = new Document();

			$this->service->saveFromTempToCache($document, $tmp);

			$this->assertMatchesRegularExpression(self::UUID_PATTERN, $document->getResizedCopy());
			// the two copies land in folders derived from their own uuids
			$this->assertSame('jpeg-bytes', array_merge(...array_values($written))[$document->getResizedCopy()]);
			// the poster is the video scaled down, so a client can size the
			// player from it before a frame has loaded
			$this->assertSame([1280, 720], $document->getLocalCopySize());
			$this->assertSame(113.0, $document->getMeta()?->getDuration());
		} finally {
			unlink($tmp);
		}
	}

	/**
	 * A cover the uploader chose -- Mastodon's `thumbnail`, and a short's
	 * cover frame -- replaces the frame ffmpeg picked. It is decoded and
	 * written out again as a JPEG, so nothing in the original survives, and
	 * the poster it replaces is removed.
	 */
	public function testAChosenCoverReplacesAVideosPoster(): void {
		$written = [];
		$this->captureWrites($written);
		$this->blurService->expects($this->once())->method('generateBlurHash')->willReturn('LEHV6nWB2yk8pyo0adR*.7kCMdnj');
		$cover = $this->tempFile($this->pngBytes(40, 70));
		$document = new Document();
		$document->setMediaType('video/mp4');
		$document->setLocalCopy('stored-video-uuid');
		$document->setResizedCopy('');

		$this->assertTrue($this->service->applyCustomPoster($document, $cover));

		$this->assertMatchesRegularExpression(self::UUID_PATTERN, $document->getResizedCopy());
		$stored = array_merge(...array_values($written))[$document->getResizedCopy()];
		$this->assertStringStartsWith("\xFF\xD8", $stored, 'written out again as a JPEG');
		$this->assertSame([40, 70], $document->getResizedCopySize());
		$this->assertSame('LEHV6nWB2yk8pyo0adR*.7kCMdnj', $document->getBlurHash());
	}

	public function testACoverIsOnlyForAVideo(): void {
		$document = new Document();
		$document->setMediaType('image/png');

		$this->assertFalse($this->service->applyCustomPoster($document, $this->tempFile($this->pngBytes())));
		$this->assertSame('', $document->getResizedCopy());
	}

	/** a cover that is not a picture costs the cover, never the upload */
	public function testACoverThatIsNotAPictureIsIgnored(): void {
		$document = new Document();
		$document->setMediaType('video/mp4');
		$document->setResizedCopy('ffmpeg-poster');

		$this->assertFalse($this->service->applyCustomPoster($document, $this->tempFile('<?php echo 1;')));
		$this->assertSame('ffmpeg-poster', $document->getResizedCopy());
	}

	/**
	 * The backfill: a video stored before posters existed gets one now.
	 *
	 * `occ social:media:posters` is the caller. The bytes go to a temporary
	 * file because ffmpeg wants a path, and the video itself is untouched —
	 * what changes is the resized copy, which is where a poster lives.
	 */
	public function testAStoredVideoCanBeGivenThePosterItNeverGot(): void {
		$written = [];
		$this->storedVideo($this->mp4Bytes(), $written);
		$this->videoThumbnailService->method('poster')->willReturn(
			['content' => 'jpeg-bytes', 'width' => 640, 'height' => 360, 'duration' => 4]
		);
		$document = new Document();
		$document->setLocalCopy('stored-video-uuid');

		$this->assertTrue($this->service->generatePoster($document));

		$this->assertMatchesRegularExpression(self::UUID_PATTERN, $document->getResizedCopy());
		$this->assertSame('jpeg-bytes', array_merge(...array_values($written))[$document->getResizedCopy()]);
		// written into `meta` rather than left on the object: the command that
		// made the poster is the last thing to hold this document
		$this->assertSame(4.0, $document->getMeta()?->getDuration());
		$this->assertSame(640, $document->getMeta()?->getOriginal()?->getWidth());
		$this->assertSame(360, $document->getMeta()?->getSmall()?->getHeight());
		// the video is not rewritten, only read
		$this->assertSame('stored-video-uuid', $document->getLocalCopy());
	}

	/** A video ffmpeg cannot read is left exactly as it was, to be tried again. */
	public function testAVideoThatYieldsNoFrameIsLeftAlone(): void {
		$written = [];
		$this->storedVideo($this->mp4Bytes(), $written);
		$this->videoThumbnailService->method('poster')->willReturn(null);
		$document = new Document();
		$document->setLocalCopy('stored-video-uuid');

		$this->assertFalse($this->service->generatePoster($document));

		$this->assertSame('', $document->getResizedCopy());
		$this->assertSame([], $written);
	}

	/** Nothing to read, nothing to do — and nothing thrown at the command. */
	public function testAPosterIsNotMadeTwiceAndNotMadeFromNothing(): void {
		$document = new Document();
		$document->setLocalCopy('stored-video-uuid');
		$document->setResizedCopy('already-there');
		$this->assertFalse($this->service->generatePoster($document));

		$this->assertFalse($this->service->generatePoster(new Document()));
	}

	/** No ffmpeg is not a reason to refuse the upload. */
	public function testAVideoWithNoPosterIsStillStored(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'social-test-');
		file_put_contents($tmp, $this->mp4Bytes());
		try {
			$written = [];
			$this->captureWrites($written);
			$this->videoThumbnailService->method('poster')->willReturn(null);
			$document = new Document();

			$this->service->saveFromTempToCache($document, $tmp);

			$this->assertNotSame('', $document->getLocalCopy());
			$this->assertSame('', $document->getResizedCopy());
		} finally {
			unlink($tmp);
		}
	}

	/**
	 * The request-time check can only go on the type the client declared, and
	 * video is allowed to be far larger than anything else. A file that said
	 * `video/mp4` to get past it and then sniffs as an image would be read
	 * whole by the image path, which is the one thing the split prevents.
	 */
	public function testAnImageOversizeForAnImageIsRefusedWhateverItClaimed(): void {
		$this->expectException(CacheContentSizeException::class);

		$this->service->filterSize('image/png', 11 * 1048576);
	}

	public function testAVideoMayBeLargerThanAnImageMay(): void {
		$this->service->filterSize('video/mp4', 500 * 1048576);

		$this->expectException(CacheContentSizeException::class);
		$this->service->filterSize('video/mp4', 3000 * 1048576);
	}

	public function testSaveFromTempToCacheRejectsWhatABrowserWouldRun(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'social-test-');
		// plain text is a file a post may carry now; a web page is not
		file_put_contents($tmp, "<!DOCTYPE html>\n<html><head><title>x</title></head><body><script>alert(1)</script></body></html>");
		try {
			$this->appData->expects($this->never())->method($this->anything());

			$this->expectException(CacheContentMimeTypeException::class);
			$this->service->saveFromTempToCache(new Document(), $tmp);
		} finally {
			unlink($tmp);
		}
	}

	public function testGetContentFromCacheReadsFromTheHashedFolder(): void {
		$file = $this->createMock(ISimpleFile::class);
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->expects($this->once())->method('getFile')->with('2b5a7a87-8db1-445f-a17b-405790f91c80')->willReturn($file);
		$this->appData->expects($this->once())->method('getFolder')->with('2b/5a/7a/87/')->willReturn($folder);

		$this->assertSame($file, $this->service->getContentFromCache('2b5a7a87-8db1-445f-a17b-405790f91c80'));
	}

	public function testGetContentFromCacheWithoutFilenameIsAMissingDocument(): void {
		$this->appData->expects($this->never())->method('getFolder');

		$this->expectException(CacheDocumentDoesNotExistException::class);
		$this->service->getContentFromCache('');
	}

	public function testGetContentFromCacheDoesNotServeLocalAvatarPlaceholders(): void {
		$this->appData->expects($this->never())->method('getFolder');

		$this->expectException(CacheContentException::class);
		$this->service->getContentFromCache('avatar');
	}

	public function testGetContentFromCacheWrapsStorageErrors(): void {
		$this->appData->method('getFolder')->willThrowException(new NotFoundException());

		$this->expectException(CacheContentException::class);
		$this->service->getContentFromCache('2b5a7a87-8db1-445f-a17b-405790f91c80');
	}

	public function testGetFromUuidReadsTheFile(): void {
		$file = $this->createMock(ISimpleFile::class);
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getFile')->with('2b5a7a87-8db1-445f-a17b-405790f91c80')->willReturn($file);
		$this->appData->method('getFolder')->with('2b/5a/7a/87/')->willReturn($folder);

		$this->assertSame($file, $this->service->getFromUuid('2b5a7a87-8db1-445f-a17b-405790f91c80'));
	}

	public function testGetFromUuidReportsAMissingDocument(): void {
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getFile')->willThrowException(new NotFoundException('no file'));
		$this->appData->method('getFolder')->willReturn($folder);

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('document not found');
		$this->service->getFromUuid('2b5a7a87-8db1-445f-a17b-405790f91c80');
	}

	public function testRetrieveContentIssuesAGetWithoutJsonHeaders(): void {
		$this->curlService->expects($this->once())
			->method('doRequest')
			->with('get', 'https://remote.example/files/pic.png', ['json_headers' => false])
			->willReturn('PNG-BYTES');

		$this->assertSame('PNG-BYTES', $this->service->retrieveContent('https://remote.example/files/pic.png'));
	}

	// content that only claims to be an image

	/** A PNG signature and IHDR that decode to nothing usable. */
	private function pngHeaderThenGarbage(): string {
		$ihdr = pack('N', 13) . 'IHDR' . pack('NN', 16, 16) . "\x08\x02\x00\x00\x00";
		$ihdr .= pack('N', crc32(substr($ihdr, 4)));

		return "\x89PNG\r\n\x1a\n" . $ihdr . str_repeat("\x41", 128);
	}

	/** A PNG header declaring dimensions no machine would decode. */
	private function hugePngHeader(): string {
		$ihdr = pack('N', 13) . 'IHDR' . pack('NN', 30000, 30000) . "\x08\x02\x00\x00\x00";
		$ihdr .= pack('N', crc32(substr($ihdr, 4)));

		return "\x89PNG\r\n\x1a\n" . $ihdr . str_repeat("\x00", 64);
	}

	#[WithoutErrorHandler]
	public function testUndecodableImageContentIsReportedNotFatal(): void {
		// this used to reach a method call on null and escape as an Error, which
		// abandoned document caching for every row queued behind it
		$written = [];
		$this->captureWrites($written);
		$document = new Document();

		$this->expectException(CacheContentDecodeException::class);
		$this->quietly(fn () => $this->storeBytes($document, $this->pngHeaderThenGarbage()));
	}

	/**
	 * The ceiling is read off the header, and it is read before the conversion
	 * -- which rotates a phone photo by decoding all of it, and which used to
	 * run first, so a hundred megapixels under the size limit was a fatal
	 * error rather than a refusal.
	 */
	public function testAnImageTooLargeToDecodeIsRefusedBeforeAnythingDecodesIt(): void {
		// 30000x30000 is a few hundred kilobytes on the wire and ~3.6 GB in GD
		$written = [];
		$this->captureWrites($written);
		$this->blurService->expects($this->never())->method('generateBlurHash');

		try {
			$this->quietly(fn () => $this->storeBytes(new Document(), $this->hugePngHeader()));
			$this->fail('expected CacheContentDecodeException');
		} catch (CacheContentDecodeException $e) {
			$this->assertStringContainsString('too large to decode', $e->getMessage());
			$this->assertSame(0, $this->converted, 'nothing decoded it first');
		}
	}

	/**
	 * A format PHP cannot read a header for is not refused by the ceiling: an
	 * HEIC is a picture the conversion knows how to turn into one a browser
	 * can draw, and the guard must not be what stops it.
	 */
	public function testAFormatWithNoReadableHeaderStillReachesTheConversion(): void {
		$written = [];
		$this->captureWrites($written);
		$this->blurService->method('generateBlurHash')->willReturn('hash');
		$png = $this->pngBytes();
		$this->conversion = static fn (): array => [$png, 'image/jpeg'];
		$document = new Document();

		// an ISO base-media file that sniffs as HEIC and that
		// getimagesizefromstring() knows nothing about
		$heic = "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00heicmif1" . str_repeat("\x00", 64);
		$this->quietly(fn () => $this->storeBytes($document, $heic));

		$this->assertSame(1, $this->converted);
		$this->assertSame('image/jpeg', $document->getMediaType(), 'stored as what it was converted to');
	}

	public function testAnImageWithinTheBudgetStillDecodes(): void {
		$written = [];
		$this->captureWrites($written);
		$this->blurService->method('generateBlurHash')->willReturn('hash');
		$document = new Document();

		$this->quietly(fn () => $this->storeBytes($document, $this->pngBytes(64, 48)));

		$this->assertSame('image/png', $document->getMediaType());
		$this->assertSame(64, $document->getLocalCopySize()[0]);
	}

	#[WithoutErrorHandler]
	public function testAnUploadedFileThatIsNotAnImageIsRefusedTheSameWay(): void {
		$written = [];
		$this->captureWrites($written);
		$tmp = tempnam(sys_get_temp_dir(), 'social_test_');
		file_put_contents($tmp, $this->pngHeaderThenGarbage());

		try {
			$this->expectException(CacheContentDecodeException::class);
			$this->quietly(function () use ($tmp) {
				$this->service->saveFromTempToCache(new Document(), $tmp);
			});
		} finally {
			@unlink($tmp);
		}
	}

	public function testRetrieveContentCarriesTheQueryString(): void {
		// a signed CDN link keeps its credentials there, byte for byte
		$this->curlService->expects($this->once())
			->method('doRequest')
			->with('get', 'https://remote.example/files/pic.png?sig=abc&exp=12', ['json_headers' => false])
			->willReturn('PNG-BYTES');

		$this->assertSame(
			'PNG-BYTES',
			$this->service->retrieveContent('https://remote.example/files/pic.png?sig=abc&exp=12')
		);
	}

	public function testRetrieveContentRejectsIncompleteUrls(): void {
		$this->curlService->expects($this->never())->method('doRequest');

		$this->expectException(RequestServerException::class);
		$this->service->retrieveContent('/files/pic.png');
	}

	#[DataProvider('provideNonWebUrls')]
	public function testRetrieveContentOnlyFetchesOverHttp(string $url): void {
		$this->curlService->expects($this->never())->method('doRequest');

		$this->expectException(RequestServerException::class);
		$this->service->retrieveContent($url);
	}

	public static function provideNonWebUrls(): iterable {
		yield 'file' => ['file:///etc/passwd'];
		yield 'gopher' => ['gopher://remote.example/1'];
		yield 'ftp' => ['ftp://remote.example/pic.png'];
	}

	public function testSniffingAStoredFileReadsItsTypeBackOffTheBytes(): void {
		$written = [];
		$this->storedVideo($this->pngBytes(), $written);

		$this->assertSame(
			'image/png', $this->service->sniffStored('2b5a7a87-8db1-445f-a17b-405790f91c80')
		);
	}

	/**
	 * The three names that are not filenames: the two placeholders of a local
	 * account, whose picture is Nextcloud's own avatar, and a streamed
	 * document, which is a pointer at bytes on another server.
	 */
	#[DataProvider('sentinelProvider')]
	public function testTheSentinelsNameNoFile(string $sentinel): void {
		$this->appData->expects($this->never())->method($this->anything());

		$this->service->removeFromCache($sentinel);

		$this->assertSame('', $this->service->sniffStored($sentinel));
	}

	public static function sentinelProvider(): array {
		return [
			'nothing' => [''],
			'a local avatar' => ['avatar'],
			'a local header' => ['header'],
			'somebody else\'s video' => [Document::COPY_STREAMED],
		];
	}

	/** What the origin answers with, as a stream the service reads. */
	private function origin(string $body): void {
		$this->curlService->method('openStream')->willReturnCallback(
			static function () use ($body): array {
				$stream = fopen('php://temp', 'r+');
				fwrite($stream, $body);
				rewind($stream);

				return ['stream' => $stream, 'status' => 200, 'headers' => []];
			}
		);
	}

	public function testSaveRemoteFileToCacheDownloadsThenStores(): void {
		$this->origin($this->pngBytes());
		$written = [];
		$this->captureWrites($written);
		$this->blurService->method('generateBlurHash')->willReturn('hash');
		$document = new Document();
		$document->setUrl('https://remote.example/files/pic.png');

		$mime = '';
		$this->quietly(function () use ($document, &$mime) {
			$this->service->saveRemoteFileToCache($document, $mime);
		});

		$this->assertSame('image/png', $mime);
		$this->assertSame('image/png', $document->getMediaType());
		$this->assertMatchesRegularExpression(self::UUID_PATTERN, $document->getLocalCopy());
	}

	/**
	 * A peer's `mediaType` describes bytes this instance has now read for
	 * itself. Stored as what it sniffed as, or `/media/{uuid}` serves a peer's
	 * word for it from this instance's own origin.
	 */
	public function testAPeersDeclaredTypeDoesNotSurviveTheFetch(): void {
		$this->origin($this->pngBytes());
		$written = [];
		$this->captureWrites($written);
		$this->blurService->method('generateBlurHash')->willReturn('hash');
		$document = new Document();
		$document->setUrl('https://remote.example/files/pic.png');
		$document->setMediaType('text/html');

		$this->quietly(fn () => $this->service->saveRemoteFileToCache($document));

		$this->assertSame('image/png', $document->getMediaType());
		$this->assertSame('image/png', $document->getMimeType());
	}

	/**
	 * The moderator's list of refused files says it covers "a fetched remote
	 * attachment"; until the fetch went through the same funnel as an upload,
	 * it did not.
	 */
	public function testARefusedPictureIsRefusedWhenItArrivesFromAPeer(): void {
		$png = $this->pngBytes();
		$this->origin($png);
		$this->mediaBlocksRequest->expects($this->once())->method('isBlocked')
			->with(hash('sha256', $png))
			->willReturn(true);
		$this->appData->expects($this->never())->method($this->anything());

		$document = new Document();
		$document->setUrl('https://remote.example/files/pic.png');

		$this->expectExceptionMessage('this file is not accepted on this instance');
		$this->service->saveRemoteFileToCache($document);
	}

	/** A remote HEIC is converted on the way in, exactly as an uploaded one is. */
	public function testARemoteHeicIsConvertedRatherThanStoredUndecodable(): void {
		$png = $this->pngBytes();
		$this->origin("\x00\x00\x00\x18ftypheic\x00\x00\x00\x00heicmif1" . str_repeat("\x00", 64));
		$written = [];
		$this->captureWrites($written);
		$this->blurService->method('generateBlurHash')->willReturn('hash');
		$this->conversion = static fn (): array => [$png, 'image/jpeg'];

		$document = new Document();
		$document->setUrl('https://remote.example/files/pic.heic');

		$this->quietly(fn () => $this->service->saveRemoteFileToCache($document));

		$this->assertSame(1, $this->converted);
		$this->assertSame('image/jpeg', $document->getMediaType());
		$this->assertMatchesRegularExpression(self::UUID_PATTERN, $document->getResizedCopy());
	}

	/**
	 * The download stops at the ceiling for what the document says it is,
	 * rather than at the one meant for an ActivityPub document -- which every
	 * federated video is larger than.
	 */
	public function testAnOversizedDownloadIsCutOffRatherThanRead(): void {
		$this->origin(str_repeat('x', 12 * 1048576));
		$this->appData->expects($this->never())->method($this->anything());

		$document = new Document();
		$document->setUrl('https://remote.example/files/pic.png');

		$this->expectException(RequestResultSizeException::class);
		$this->service->saveRemoteFileToCache($document);
	}

	public function testAVideoMayBeFetchedPastTheDocumentCeiling(): void {
		$body = $this->mp4Bytes(12 * 1048576);
		$this->origin($body);
		$written = [];
		$this->captureWrites($written);

		$document = new Document();
		$document->setUrl('https://remote.example/files/clip.mp4');
		$document->setMediaType('video/mp4');

		$this->service->saveRemoteFileToCache($document);

		$this->assertSame('video/mp4', $document->getMediaType());
		$this->assertMatchesRegularExpression(self::UUID_PATTERN, $document->getLocalCopy());
		$this->assertSame(strlen($body), $document->getSizeBytes());
	}

	public function testSaveRemoteFileToCacheRefusesANonWebUrlBeforeFetching(): void {
		$this->curlService->expects($this->never())->method('openStream');
		$document = new Document();
		$document->setUrl('file:///etc/passwd');

		$this->expectException(RequestServerException::class);
		$this->service->saveRemoteFileToCache($document);
	}
}

/** Captures what is written to a stream, so a streamed copy can be asserted. */
class StreamCapture extends \php_user_filter {
	public function filter($in, $out, &$consumed, bool $closing): int {
		while ($bucket = stream_bucket_make_writeable($in)) {
			($this->params['target'])($bucket->data);
			$consumed += $bucket->datalen;
			stream_bucket_append($out, $bucket);
		}

		return PSFS_PASS_ON;
	}
}
