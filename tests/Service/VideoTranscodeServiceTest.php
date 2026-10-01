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
 * that shelled out to it would be testing the server it happened to be on.
 * The codec probe is tested against a stand-in ffprobe — a shell script that
 * answers the way the real one does — so what is tested is how the answer is
 * read, not which ffprobe is installed.
 */
class VideoTranscodeServiceTest extends TestCase {
	private IBinaryFinder|Stub $binaryFinder;
	private ConfigService|Stub $configService;
	private VideoTranscodeService $service;

	/** paths made during a test, removed afterwards */
	private array $temporary = [];

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

	protected function tearDown(): void {
		foreach ($this->temporary as $path) {
			@unlink($path);
		}
		parent::tearDown();
	}

	/**
	 * A stand-in for a binary: a script that prints `$stdout` and exits with
	 * `$exit`, found under the name `$name` and no other.
	 */
	private function fakeBinary(string $name, string $stdout, int $exit = 0): void {
		$script = tempnam(sys_get_temp_dir(), $name);
		file_put_contents($script, "#!/bin/sh\nprintf '%s' " . escapeshellarg($stdout) . "\nexit " . $exit . "\n");
		chmod($script, 0700);
		$this->temporary[] = $script;

		$this->binaryFinder->method('findBinaryPath')->willReturnCallback(
			static fn (string $binary): string|false => ($binary === $name) ? $script : false
		);
	}

	private function video(): string {
		$path = tempnam(sys_get_temp_dir(), 'video');
		file_put_contents($path, 'not really a video');
		$this->temporary[] = $path;

		return $path;
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

	/** An administrator who switched it off has it off, ffmpeg or not. */
	public function testItIsOffWhenAnAdministratorSwitchedItOff(): void {
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

	/**
	 * HEVC in an MP4 is what Android phones write, and it plays in Safari
	 * alone: an MP4 is converted when its video is not H.264.
	 */
	public function testAnHevcMp4IsConverted(): void {
		$this->fakeBinary('ffprobe', "hevc\n");

		$this->assertSame('hevc', $this->service->videoCodec($this->video()));
		$this->assertTrue($this->service->needsConversion('video/mp4', $this->video()));
	}

	/** A plain H.264 MP4 is never re-encoded. */
	public function testAnH264Mp4IsLeftAlone(): void {
		$this->fakeBinary('ffprobe', "h264\n");

		$this->assertSame('h264', $this->service->videoCodec($this->video()));
		$this->assertFalse($this->service->needsConversion('video/mp4', $this->video()));
		$this->assertFalse($this->service->needsConversion('VIDEO/MP4', $this->video()));
	}

	/**
	 * An MP4 ffprobe cannot read, or that has no video stream, cannot be said
	 * to be H.264, and is converted rather than trusted.
	 */
	public function testAnMp4ThatCannotBeProbedAsH264IsConverted(): void {
		$this->fakeBinary('ffprobe', '', 1);

		$this->assertNull($this->service->videoCodec($this->video()));
		$this->assertTrue($this->service->needsConversion('video/mp4', $this->video()));
	}

	public function testAnMp4WithNoVideoStreamIsConverted(): void {
		$this->fakeBinary('ffprobe', '');

		$this->assertTrue($this->service->needsConversion('video/mp4', $this->video()));
	}

	/**
	 * Without ffprobe nothing can be said about an MP4, and it is left as it
	 * is rather than re-encoded on a guess.
	 */
	public function testWithoutFfprobeAnMp4IsLeftAsItIs(): void {
		$this->fakeBinary('ffmpeg', '');

		$this->assertFalse($this->service->canProbe());
		$this->assertNull($this->service->videoCodec($this->video()));
		$this->assertFalse($this->service->needsConversion('video/mp4', $this->video()));
		$this->assertFalse($this->service->mayNeedConversion('video/mp4'));
	}

	/** A `.mov` is converted on its type alone; its file is never read for it. */
	public function testAMovNeedsNoProbe(): void {
		$this->binaryFinder->method('findBinaryPath')->willReturn(false);

		$this->assertTrue($this->service->needsConversion('video/quicktime', '/nonexistent'));
		$this->assertTrue($this->service->mayNeedConversion('video/quicktime'));
	}

	/**
	 * What a new post asks before it is queued, from the type alone: an MP4
	 * might need converting where it can be probed.
	 */
	public function testAnMp4MayNeedConversionOnlyWhereItCanBeProbed(): void {
		$this->fakeBinary('ffprobe', 'h264');

		$this->assertTrue($this->service->mayNeedConversion('video/mp4'));
		$this->assertFalse($this->service->mayNeedConversion('image/jpeg'));
		$this->assertFalse($this->service->mayNeedConversion('audio/mpeg'));
	}

	public function testThereIsNothingToConvertWithoutFfmpeg(): void {
		$this->binaryFinder->method('findBinaryPath')->willReturn(false);

		$this->assertNull($this->service->convert('/tmp/nothing.mov'));
	}
}
