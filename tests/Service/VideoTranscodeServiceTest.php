<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Service\ConfigService;
use OCA\Social\Service\VideoTranscodeService;
use OCP\IBinaryFinder;
use OCP\ITempManager;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * What is worth converting, and whether anybody asked.
 *
 * The ffmpeg run itself is exercised on devel rather than here: a unit test
 * that shelled out would be testing the server it happened to be on.
 */
class VideoTranscodeServiceTest extends TestCase {
	private IBinaryFinder|Stub $binaryFinder;
	private ConfigService|Stub $configService;
	private VideoTranscodeService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->binaryFinder = $this->createStub(IBinaryFinder::class);
		$this->configService = $this->createStub(ConfigService::class);

		$this->service = new VideoTranscodeService(
			$this->binaryFinder,
			$this->createStub(ITempManager::class),
			$this->configService,
			new NullLogger(),
		);
	}

	/**
	 * Pixelfed's default `media_types` accepts `video/mp4` and nothing else,
	 * so a `.mov` straight off a phone is dropped by its inbox in silence.
	 */
	public function testTheFormatsWorthConvertingAreTheOnesThatTravelBadly(): void {
		$this->assertTrue($this->service->shouldConvert('video/quicktime'));
		$this->assertTrue($this->service->shouldConvert('video/webm'));
		$this->assertTrue($this->service->shouldConvert('video/x-matroska'));
	}

	/** Re-encoding an MP4 into an MP4 is a second generation of loss for nothing. */
	public function testWhatIsAlreadyTheTargetIsNotConverted(): void {
		$this->assertFalse($this->service->shouldConvert('video/mp4'));
		$this->assertFalse($this->service->shouldConvert('VIDEO/MP4'));
	}

	public function testAPictureIsNotAVideo(): void {
		$this->assertFalse($this->service->shouldConvert('image/jpeg'));
		$this->assertFalse($this->service->shouldConvert(''));
	}

	/** Re-encoding is lossy and it is somebody's file. */
	public function testItIsOffUntilAnAdministratorAsksForIt(): void {
		$this->binaryFinder->method('findBinaryPath')->willReturn('/usr/bin/ffmpeg');
		$this->configService->method('getAppValueBool')->willReturn(false);

		$this->assertTrue($this->service->isAvailable());
		$this->assertFalse($this->service->isEnabled());
	}

	/** And it stays off on a server that could not do it anyway. */
	public function testAskingForItOnAServerWithNoFfmpegChangesNothing(): void {
		$this->binaryFinder->method('findBinaryPath')->willReturn(false);
		$this->configService->method('getAppValueBool')->willReturn(true);

		$this->assertFalse($this->service->isAvailable());
		$this->assertFalse($this->service->isEnabled());
	}

	public function testTheHeightIsWhatWasSet(): void {
		$this->configService->method('getAppValueInt')->willReturn(720);

		$this->assertSame(720, $this->service->maxHeight());
	}

	/** An unset height is the default rather than a video scaled to nothing. */
	public function testNoHeightMeansTheDefaultRatherThanZero(): void {
		$this->configService->method('getAppValueInt')->willReturn(0);

		$this->assertSame(VideoTranscodeService::DEFAULT_MAX_HEIGHT, $this->service->maxHeight());
	}

	public function testThereIsNothingToConvertWithoutFfmpeg(): void {
		$this->binaryFinder->method('findBinaryPath')->willReturn(false);

		$this->assertNull($this->service->convert('/tmp/nothing.mov'));
	}
}
