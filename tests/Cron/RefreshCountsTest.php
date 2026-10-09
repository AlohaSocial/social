<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Cron\RefreshCounts;
use OCA\Social\Service\Counts\CountService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** The background half of a page that showed posts whose counts were due. */
class RefreshCountsTest extends TestCase {
	private CountService&MockObject $counts;
	private RefreshCounts $job;

	protected function setUp(): void {
		$this->counts = $this->createMock(CountService::class);
		$this->job = new RefreshCounts($this->createStub(ITimeFactory::class), $this->counts);
	}

	private function runJob(mixed $argument): void {
		(new \ReflectionMethod(RefreshCounts::class, 'run'))->invoke($this->job, $argument);
	}

	public function testThePostsTheJobNamesAreAskedAbout(): void {
		$this->counts->expects($this->once())->method('refresh')
			->with(['https://remote.example/notes/1', 'https://bsky.app/profile/did:plc:a/post/1'])
			->willReturn(['asked' => 2, 'answered' => 2]);

		$this->runJob(['posts' => ['https://remote.example/notes/1', '', 7, 'https://bsky.app/profile/did:plc:a/post/1']]);
	}

	public function testAJobNamingNoPostsDoesNothing(): void {
		$this->counts->expects($this->never())->method('refresh');

		$this->runJob(null);
		$this->runJob(['posts' => 'https://remote.example/notes/1']);
		$this->runJob(['posts' => []]);
	}
}
