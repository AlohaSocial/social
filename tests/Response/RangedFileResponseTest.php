<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Response;

use OCA\Social\Response\RangedFileResponse;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\IOutput;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The byte-range rules, which are the whole reason this class exists: a video
 * a reader cannot seek is a video with a scrub bar that does nothing.
 */
#[AllowMockObjectsWithoutExpectations]
class RangedFileResponseTest extends TestCase {
	private const BODY = '0123456789';

	/** @var string what the response wrote */
	private string $written = '';

	protected function setUp(): void {
		// `Response::getHeaders()` resolves the request for its id
		\OC::$server->register(IRequest::class, $this->createStub(IRequest::class));
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	private function file(string $content = self::BODY): ISimpleFile {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getSize')->willReturn(strlen($content));
		$file->method('read')->willReturnCallback(static function () use ($content) {
			$stream = fopen('php://temp', 'r+');
			fwrite($stream, $content);
			rewind($stream);

			return $stream;
		});

		return $file;
	}

	private function collector(): IOutput {
		$this->written = '';
		$output = $this->createMock(IOutput::class);
		$output->method('setOutput')->willReturnCallback(function (string $chunk): void {
			$this->written .= $chunk;
		});

		return $output;
	}

	private function body(RangedFileResponse $response): string {
		$response->callback($this->collector());

		return $this->written;
	}

	public function testWithoutARangeTheWholeFileIsSent(): void {
		$response = new RangedFileResponse($this->file(), 'video/mp4');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('bytes', $response->getHeaders()['Accept-Ranges']);
		$this->assertSame('10', $response->getHeaders()['Content-Length']);
		$this->assertArrayNotHasKey('Content-Range', $response->getHeaders());
		$this->assertSame(self::BODY, $this->body($response));
	}

	/**
	 * These bytes are somebody else's file, served from this instance's own
	 * origin. A peer that declared `text/html` over content beginning
	 * `GIF89a` had the file stored -- libmagic says image/gif -- and then
	 * served back as a page from here.
	 */
	#[DataProvider('renderableTypeProvider')]
	public function testMediaIsServedInlineButNeverSniffed(string $type): void {
		$response = new RangedFileResponse($this->file(), $type);

		$this->assertSame('nosniff', $response->getHeaders()['X-Content-Type-Options']);
		$this->assertArrayNotHasKey('Content-Disposition', $response->getHeaders());
	}

	public static function renderableTypeProvider(): array {
		return [
			'picture' => ['image/png'],
			'video' => ['video/mp4'],
			'sound' => ['audio/mpeg'],
			'upper case' => ['Image/JPEG'],
		];
	}

	#[DataProvider('downloadTypeProvider')]
	public function testAnythingElseIsHandedOverAsADownload(string $type): void {
		$response = new RangedFileResponse($this->file(), $type);

		$this->assertSame('nosniff', $response->getHeaders()['X-Content-Type-Options']);
		$this->assertSame('attachment', $response->getHeaders()['Content-Disposition']);
	}

	public static function downloadTypeProvider(): array {
		return [
			'a page' => ['text/html'],
			'a document' => ['application/pdf'],
			'a drawing a browser would run' => ['image/svg+xml; charset=utf-8'],
			// a row written before the sniffed type was recorded
			'nothing at all' => [''],
		];
	}

	public static function rangeProvider(): array {
		return [
			'a closed range' => ['bytes=2-5', '2345', 'bytes 2-5/10'],
			'from an offset to the end' => ['bytes=7-', '789', 'bytes 7-9/10'],
			'the whole file, stated' => ['bytes=0-9', self::BODY, 'bytes 0-9/10'],
			'one byte' => ['bytes=4-4', '4', 'bytes 4-4/10'],
			// a suffix range is the last N bytes, counted from the end
			'a suffix range' => ['bytes=-3', '789', 'bytes 7-9/10'],
			// asking for more of the end than exists is the whole file
			'a suffix longer than the file' => ['bytes=-99', self::BODY, 'bytes 0-9/10'],
			// an end past the last byte is clamped rather than refused
			'an end past the end' => ['bytes=8-99', '89', 'bytes 8-9/10'],
			'whitespace around it' => [' bytes=2-3 ', '23', 'bytes 2-3/10'],
		];
	}

	#[DataProvider('rangeProvider')]
	public function testARangeIsAnsweredWithThatRange(string $range, string $expected, string $contentRange): void {
		$response = new RangedFileResponse($this->file(), 'video/mp4', $range);

		$this->assertSame(Http::STATUS_PARTIAL_CONTENT, $response->getStatus());
		$this->assertSame($contentRange, $response->getHeaders()['Content-Range']);
		$this->assertSame((string)strlen($expected), $response->getHeaders()['Content-Length']);
		$this->assertSame($expected, $this->body($response));
	}

	/**
	 * RFC 9110 §15.5.17: the answer says how long the file really is, so the
	 * client can ask again for something that exists.
	 */
	public static function unsatisfiableProvider(): array {
		return [
			'starting past the end' => ['bytes=10-'],
			'entirely past the end' => ['bytes=50-60'],
			'backwards' => ['bytes=6-2'],
			'a zero-length suffix' => ['bytes=-0'],
		];
	}

	#[DataProvider('unsatisfiableProvider')]
	public function testAnUnsatisfiableRangeIsRefusedWithTheLength(string $range): void {
		$response = new RangedFileResponse($this->file(), 'video/mp4', $range);

		$this->assertSame(Http::STATUS_REQUEST_RANGE_NOT_SATISFIABLE, $response->getStatus());
		$this->assertSame('bytes */10', $response->getHeaders()['Content-Range']);
		$this->assertSame('', $this->body($response));
	}

	/**
	 * The whole file is always a correct answer to a range request, so anything
	 * unparseable is ignored rather than refused -- including the multipart
	 * form, which no media element sends and this deliberately does not
	 * implement.
	 */
	public static function ignoredProvider(): array {
		return [
			'not a range at all' => ['rubbish'],
			'another unit' => ['items=0-5'],
			'no numbers' => ['bytes=-'],
			'multipart' => ['bytes=0-1,4-5'],
			'empty' => [''],
		];
	}

	#[DataProvider('ignoredProvider')]
	public function testAnUnreadableRangeIsIgnored(string $range): void {
		$response = new RangedFileResponse($this->file(), 'video/mp4', $range);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(self::BODY, $this->body($response));
	}

	public function testAnEmptyFileIsNeverAPartialAnswer(): void {
		$response = new RangedFileResponse($this->file(''), 'video/mp4', 'bytes=0-10');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('', $this->body($response));
	}

	/**
	 * Object storage hands back a stream that cannot be seeked, so the offset
	 * has to be reached by reading up to it instead of jumping. A file large
	 * enough to cross the read chunk proves both the skip and the loop.
	 */
	public function testAnUnseekableStreamStillAnswersFromTheRightOffset(): void {
		UnseekableStream::$content = str_repeat('ab', 400000);
		stream_wrapper_register('rangedtest', UnseekableStream::class);

		try {
			$file = $this->createStub(ISimpleFile::class);
			$file->method('getSize')->willReturn(strlen(UnseekableStream::$content));
			$file->method('read')->willReturnCallback(static fn () => fopen('rangedtest://x', 'r'));

			$response = new RangedFileResponse($file, 'video/mp4', 'bytes=500000-500009');

			$this->assertSame(Http::STATUS_PARTIAL_CONTENT, $response->getStatus());
			$this->assertSame(substr(UnseekableStream::$content, 500000, 10), $this->body($response));
		} finally {
			stream_wrapper_unregister('rangedtest');
		}
	}
}

/** A readable stream that refuses to seek, as object storage does. */
class UnseekableStream {
	public static string $content = '';

	/** @var resource */
	public $context;

	private int $position = 0;

	public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool {
		$this->position = 0;

		return true;
	}

	public function stream_read(int $count): string {
		$chunk = substr(self::$content, $this->position, $count);
		$this->position += strlen($chunk);

		return $chunk;
	}

	public function stream_eof(): bool {
		return $this->position >= strlen(self::$content);
	}

	public function stream_seek(int $offset, int $whence): bool {
		return false;
	}

	public function stream_tell(): int {
		return $this->position;
	}

	public function stream_stat(): array {
		return [];
	}

	public function stream_close(): void {
	}
}
