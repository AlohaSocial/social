<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Service\MultipartBodyService;
use OCP\IRequest;
use OCP\ITempManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * A multipart body PHP did not parse: Mastodon clients send
 * `update_credentials` as a multipart PATCH whenever a picture is in it.
 *
 * `parse()` is what PHP 8.3 runs; PHP 8.4 and later hand the body to
 * `request_parse_body()`, which only works inside a real request.
 */
#[AllowMockObjectsWithoutExpectations]
class MultipartBodyServiceTest extends TestCase {
	private const TYPE = 'multipart/form-data; boundary=----aloha';

	/** @var string[] files to clean up */
	private array $files = [];

	protected function tearDown(): void {
		foreach ($this->files as $file) {
			@unlink($file);
		}
		parent::tearDown();
	}

	private function service(): MultipartBodyService {
		$tempManager = $this->createStub(ITempManager::class);
		$tempManager->method('getTemporaryFile')->willReturnCallback(function (): string {
			$path = (string)tempnam(sys_get_temp_dir(), 'multipart');
			$this->files[] = $path;

			return $path;
		});

		return new MultipartBodyService($tempManager);
	}

	/** @param list<array{0: string, 1: string, 2?: string}> $parts name, value and an optional filename */
	private function body(array $parts): string {
		$body = '';
		foreach ($parts as $part) {
			$body .= "------aloha\r\nContent-Disposition: form-data; name=\"" . $part[0] . '"';
			if (isset($part[2])) {
				$body .= '; filename="' . $part[2] . "\"\r\nContent-Type: image/png";
			}
			$body .= "\r\n\r\n" . $part[1] . "\r\n";
		}

		return $body . "------aloha--\r\n";
	}

	private function request(string $method, string $contentType): IRequest {
		$request = $this->createStub(IRequest::class);
		$request->method('getMethod')->willReturn($method);
		$request->method('getHeader')->willReturnCallback(
			fn (string $name): string => ($name === 'Content-Type') ? $contentType : ''
		);

		return $request;
	}

	public function testTheFieldsNestByTheirBracketedNamesAsTheyWouldInAPost(): void {
		[$fields, $files] = $this->service()->parse($this->body([
			['display_name', 'Alice of Wonderland'],
			['source[privacy]', 'unlisted'],
			['fields_attributes[0][name]', 'Web'],
			['fields_attributes[0][value]', 'https://alice.example'],
			['note', "two\r\nlines"],
		]), self::TYPE);

		$this->assertSame([
			'display_name' => 'Alice of Wonderland',
			'source' => ['privacy' => 'unlisted'],
			'fields_attributes' => [['name' => 'Web', 'value' => 'https://alice.example']],
			'note' => "two\r\nlines",
		], $fields);
		$this->assertSame([], $files);
	}

	public function testAFileIsWrittenOutAndVouchedForAsAnUpload(): void {
		$service = $this->service();
		$bytes = "\x89PNG\r\n\x1a\n binary \r\n------not-the-boundary";

		[$fields, $files] = $service->parse($this->body([
			['display_name', 'Alice'],
			['avatar', $bytes, 'C:\\pictures\\me.png'],
		]), self::TYPE);

		$this->assertSame(['display_name' => 'Alice'], $fields);
		$avatar = $files['avatar'];
		$this->assertSame(UPLOAD_ERR_OK, $avatar['error']);
		$this->assertSame('me.png', $avatar['name']);
		$this->assertSame('image/png', $avatar['type']);
		$this->assertSame(strlen($bytes), $avatar['size']);
		$this->assertSame($bytes, file_get_contents($avatar['tmp_name']));
		$this->assertTrue($service->isUpload($avatar['tmp_name']));
	}

	/** Only what this request's parse wrote is vouched for. */
	public function testAnyOtherPathIsNotAnUpload(): void {
		$path = (string)tempnam(sys_get_temp_dir(), 'multipart');
		$this->files[] = $path;

		$this->assertFalse($this->service()->isUpload($path));
	}

	/** A file input left empty is sent as a part with an empty filename. */
	public function testAnEmptyFileInputIsNoFile(): void {
		[, $files] = $this->service()->parse($this->body([['header', '', '']]), self::TYPE);

		$this->assertSame(UPLOAD_ERR_NO_FILE, $files['header']['error']);
		$this->assertSame('', $files['header']['tmp_name']);
	}

	public function testAQuotedBoundaryIsUnderstood(): void {
		[$fields] = $this->service()->parse(
			$this->body([['bot', 'true']]), 'multipart/form-data; boundary="----aloha"'
		);

		$this->assertSame(['bot' => 'true'], $fields);
	}

	public function testABodyWithoutABoundaryIsRefused(): void {
		$this->expectException(InvalidActionException::class);
		$this->expectExceptionMessage('boundary');
		$this->service()->parse($this->body([['bot', 'true']]), 'multipart/form-data');
	}

	/** A body cut off on the way must not be applied as far as it got. */
	public function testATruncatedBodyIsRefused(): void {
		$body = $this->body([['display_name', 'Alice'], ['note', 'a bio']]);

		$this->expectException(InvalidActionException::class);
		$this->expectExceptionMessage('incomplete');
		$this->service()->parse(substr($body, 0, -20), self::TYPE);
	}

	public function testAPostIsLeftToPhp(): void {
		$this->assertNull($this->service()->read($this->request('POST', self::TYPE)));
	}

	public function testABodyThatIsNotMultipartIsLeftToTheController(): void {
		$this->assertNull($this->service()->read($this->request('PATCH', 'application/json')));
		$this->assertNull($this->service()->read($this->request('PATCH', 'application/x-www-form-urlencoded')));
		$this->assertNull($this->service()->read($this->request('PATCH', '')));
	}
}
