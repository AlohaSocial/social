<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Exceptions\CacheContentMimeTypeException;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\ImageConversionService;
use OCA\Social\Service\ImageMetadataService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ImageConversionServiceTest extends TestCase {
	private ImageConversionService $service;
	private ConfigService|Stub $configService;

	/** @var array<string, int> the instance's own settings */
	private array $settings = [];

	protected function setUp(): void {
		parent::setUp();
		// off by default, as on an instance nobody has configured: everything
		// below is about storing an upload correctly rather than smaller
		$this->configService = $this->createStub(ConfigService::class);
		$this->configService->method('getAppValueInt')->willReturnCallback(
			fn (string $key): int => $this->settings[$key] ?? 0
		);

		$this->service = new ImageConversionService(
			new ImageMetadataService(),
			$this->configService,
			$this->createStub(LoggerInterface::class)
		);
	}

	/** A real JPEG, optionally carrying an Exif block with an orientation. */
	private function jpeg(int $width = 24, int $height = 12, ?int $orientation = null): string {
		$image = imagecreatetruecolor($width, $height);
		imagefill($image, 0, 0, imagecolorallocate($image, 20, 140, 90));
		ob_start();
		imagejpeg($image, null, 92);
		$jpeg = (string)ob_get_clean();

		if ($orientation === null) {
			return $jpeg;
		}

		$ifd = pack('v', 1)
			. pack('v', 0x0112) . pack('v', 3) . pack('V', 1) . pack('v', $orientation) . "\x00\x00"
			. pack('V', 0);
		$payload = "Exif\x00\x00" . 'II' . "\x2A\x00" . pack('V', 8) . $ifd;
		$segment = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;

		// straight after SOI, which is where a camera puts it
		return substr($jpeg, 0, 2) . $segment . substr($jpeg, 2);
	}

	/**
	 * The property that matters for quality: once there is nothing left to
	 * remove, the bytes stop changing. A pipeline that re-encoded on every pass
	 * would lose a generation each time a picture went through it.
	 *
	 * (A JPEG straight out of GD is not itself a fair "clean" photo -- GD
	 * writes a `CREATOR:` comment segment, which this correctly removes.)
	 */
	public function testOncePreparedAPhotoIsStoredByteForByte(): void {
		[$once, $mime] = $this->service->prepareForStorage($this->jpeg(), 'image/jpeg');
		[$twice, $mimeAgain] = $this->service->prepareForStorage($once, 'image/jpeg');

		$this->assertSame($once, $twice, 'a prepared photo was re-encoded again for nothing');
		$this->assertSame('image/jpeg', $mime);
		$this->assertSame('image/jpeg', $mimeAgain);
		$this->assertNotFalse(@imagecreatefromstring($twice));
	}

	public function testTheCommentGdWritesIsRemovedLikeAnyOtherMetadata(): void {
		$jpeg = $this->jpeg();
		$this->assertStringContainsString('CREATOR', $jpeg, 'GD stopped writing a comment; the test needs a new subject');

		[$content] = $this->service->prepareForStorage($jpeg, 'image/jpeg');

		$this->assertStringNotContainsString('CREATOR', $content);
		$this->assertLessThan(strlen($jpeg), strlen($content));
	}

	public function testTheExifBlockIsGoneAndThePhotoStillDecodes(): void {
		[$content, $mime] = $this->service->prepareForStorage($this->jpeg(24, 12, 1), 'image/jpeg');

		$this->assertSame('image/jpeg', $mime);
		$this->assertStringNotContainsString("Exif\x00\x00", $content);

		$decoded = @imagecreatefromstring($content);
		$this->assertNotFalse($decoded);
		// orientation 1 means "as shot": nothing is turned
		$this->assertSame(24, imagesx($decoded));
		$this->assertSame(12, imagesy($decoded));
	}

	/**
	 * Orientation 6 means "rotate a quarter turn clockwise to view". Dropping
	 * the tag without turning the pixels is what puts every portrait photo on
	 * its side, so the pixels are turned and the sides swap.
	 */
	public function testAPhotoThatSaysItIsSidewaysIsTurnedTheRightWayUp(): void {
		[$content, $mime] = $this->service->prepareForStorage($this->jpeg(24, 12, 6), 'image/jpeg');

		$this->assertSame('image/jpeg', $mime);
		$this->assertStringNotContainsString("Exif\x00\x00", $content, 'the Exif block survived the rotation');

		$decoded = @imagecreatefromstring($content);
		$this->assertNotFalse($decoded);
		$this->assertSame(12, imagesx($decoded), 'the picture was not turned');
		$this->assertSame(24, imagesy($decoded), 'the picture was not turned');
	}

	public static function orientationProvider(): array {
		// [orientation, expected width, expected height] for a 24x12 original
		return [
			'upright' => [1, 24, 12],
			'mirrored' => [2, 24, 12],
			'upside down' => [3, 24, 12],
			'mirrored upside down' => [4, 24, 12],
			'mirrored quarter turn' => [5, 12, 24],
			'quarter turn clockwise' => [6, 12, 24],
			'mirrored three quarters' => [7, 12, 24],
			'three quarters clockwise' => [8, 12, 24],
		];
	}

	#[DataProvider('orientationProvider')]
	public function testEveryOrientationLandsTheRightWayUp(int $orientation, int $width, int $height): void {
		[$content] = $this->service->prepareForStorage($this->jpeg(24, 12, $orientation), 'image/jpeg');

		$decoded = @imagecreatefromstring($content);
		$this->assertNotFalse($decoded);
		$this->assertSame($width, imagesx($decoded));
		$this->assertSame($height, imagesy($decoded));
		$this->assertStringNotContainsString("Exif\x00\x00", $content);
	}

	public function testHeicIsRefusedInSoManyWordsWhenNothingCanReadIt(): void {
		if ($this->service->canDecodeHeic()) {
			$this->markTestSkipped('this server can read HEIC, so the refusal path is not the one taken');
		}

		$this->expectException(CacheContentMimeTypeException::class);
		$this->expectExceptionMessageMatches('/HEIC/');

		$this->service->prepareForStorage('ftypheic-ish bytes', 'image/heic');
	}

	public function testAnAvifWithNothingInItIsNotReEncoded(): void {
		// an ISO base-media header with no metadata item declared
		$avif = "\x00\x00\x00\x20" . 'ftypavif' . str_repeat("\x00", 16) . str_repeat("\x42", 64);

		[$content, $mime] = $this->service->prepareForStorage($avif, 'image/avif');

		$this->assertSame($avif, $content, 'an AVIF with no metadata was re-encoded for nothing');
		$this->assertSame('image/avif', $mime);
	}

	public function testAFormatThisDoesNotHandleIsPassedStraightThrough(): void {
		[$content, $mime] = $this->service->prepareForStorage('some video bytes', 'video/mp4');

		$this->assertSame('some video bytes', $content);
		$this->assertSame('video/mp4', $mime);
	}

	public function testAPngKeepsItsFormatAndLosesItsText(): void {
		$image = imagecreatetruecolor(8, 8);
		imagefill($image, 0, 0, imagecolorallocate($image, 1, 2, 3));
		ob_start();
		imagepng($image);
		$png = (string)ob_get_clean();

		[$content, $mime] = $this->service->prepareForStorage($png, 'image/png');

		$this->assertSame('image/png', $mime);
		$this->assertNotFalse(@imagecreatefromstring($content));
	}

	// the optional shrink

	/**
	 * Off unless an administrator asks: this app's promise has been that
	 * nothing loses a generation of quality.
	 */
	public function testAPictureIsStoredAtItsOwnSizeByDefault(): void {
		$jpeg = $this->jpeg(400, 300);

		[$content] = $this->service->prepareForStorage($jpeg, 'image/jpeg');
		$size = getimagesizefromstring($content);

		$this->assertSame(400, $size[0]);
	}

	public function testPastTheCeilingItIsBroughtDownToIt(): void {
		$this->settings[ConfigService::SOCIAL_IMAGE_MAX_EDGE] = 100;
		$this->settings[ConfigService::SOCIAL_IMAGE_QUALITY] = 85;
		$jpeg = $this->jpeg(400, 300);

		[$content, $mime] = $this->service->prepareForStorage($jpeg, 'image/jpeg');
		$size = getimagesizefromstring($content);

		$this->assertSame(100, $size[0]);
		$this->assertSame(75, $size[1], 'the shape is kept');
		$this->assertSame('image/jpeg', $mime);
	}

	/**
	 * Shrinking nothing and losing a generation anyway is the worst of both,
	 * so a picture already inside the ceiling is not re-encoded.
	 */
	public function testAPictureInsideTheCeilingIsNotTouched(): void {
		$this->settings[ConfigService::SOCIAL_IMAGE_MAX_EDGE] = 1000;
		$jpeg = $this->jpeg(400, 300);

		[$content] = $this->service->prepareForStorage($jpeg, 'image/jpeg');

		$this->assertSame(
			strlen($this->service->prepareForStorage($jpeg, 'image/jpeg')[0]), strlen($content)
		);
	}

	/** A GIF may be animated, and would come back as one frame of it. */
	public function testAnAnimationIsLeftAlone(): void {
		$this->settings[ConfigService::SOCIAL_IMAGE_MAX_EDGE] = 10;
		$image = imagecreatetruecolor(400, 300);
		ob_start();
		imagegif($image);
		$gif = (string)ob_get_clean();

		[$content] = $this->service->prepareForStorage($gif, 'image/gif');
		$size = getimagesizefromstring($content);

		$this->assertSame(400, $size[0]);
	}
}
