<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Service\VideoThumbnailService;
use OCP\IBinaryFinder;
use OCP\ITempManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class VideoThumbnailServiceTest extends TestCase {
	private IBinaryFinder|MockObject $binaryFinder;
	private ITempManager|Stub $tempManager;
	/** @var string[] */
	private array $tempFiles = [];
	private VideoThumbnailService $service;

	protected function setUp(): void {
		$this->binaryFinder = $this->createMock(IBinaryFinder::class);
		$this->tempManager = $this->createStub(ITempManager::class);
		$this->tempManager->method('getTemporaryFile')->willReturnCallback(
			function (string $suffix = ''): string {
				$path = tempnam(sys_get_temp_dir(), 'social-test-') . $suffix;
				$this->tempFiles[] = $path;

				return $path;
			}
		);

		$this->service = new VideoThumbnailService(
			$this->binaryFinder, $this->tempManager, new NullLogger()
		);
	}

	#[\Override]
	protected function tearDown(): void {
		foreach ($this->tempFiles as $path) {
			@unlink($path);
		}
		$this->tempFiles = [];
		parent::tearDown();
	}

	/**
	 * A stand-in for one of the two binaries that writes more to stderr than a
	 * pipe holds before it produces anything.
	 *
	 * That is what a damaged file makes ffmpeg do -- one decoder warning per
	 * frame -- and a runner that never reads the pipes leaves the child
	 * blocked on a full one until the deadline kills it.
	 *
	 * @return string the path to the script
	 */
	private function chattyBinary(string $writes): string {
		$path = tempnam(sys_get_temp_dir(), 'social-ffprobe-');
		$this->tempFiles[] = $path;

		file_put_contents($path, <<<SH
			#!/bin/sh
			out=""
			while [ \$# -gt 0 ]; do
				if [ "\$1" = "-o" ]; then out="\$2"; fi
				shift
			done
			dd if=/dev/zero bs=1000 count=512 2>/dev/null | tr '\\000' 'x' >&2
			if [ -n "\$out" ]; then printf '$writes' > "\$out"; fi
			exit 0
			SH);
		chmod($path, 0o755);

		return $path;
	}

	public function testDurationSurvivesAToolThatFillsItsStderrPipe(): void {
		if (!function_exists('proc_open') || !is_executable('/bin/sh')) {
			$this->markTestSkipped('no shell to stand in for ffprobe');
		}

		$this->binaryFinder->method('findBinaryPath')
			->with('ffprobe')
			->willReturn($this->chattyBinary('12.6'));

		$started = time();
		$duration = $this->service->duration(__FILE__);

		$this->assertSame(13, $duration, 'the tool ran to completion and its answer was read');
		$this->assertLessThan(
			10, time() - $started, 'a drained pipe means the tool is not waiting on the deadline'
		);
	}

	public function testDurationIsUnknownWithoutFfprobe(): void {
		$this->binaryFinder->method('findBinaryPath')->willReturn(false);

		$this->assertSame(0, $this->service->duration(__FILE__));
	}

	public function testNoPosterWithoutFfmpeg(): void {
		$this->binaryFinder->method('findBinaryPath')->willReturn(false);

		$this->assertFalse($this->service->isAvailable());
		$this->assertNull($this->service->poster(__FILE__));
	}
}
