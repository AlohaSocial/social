<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Cron\Resanitize;
use OCA\Social\Service\SanitizerUpgrade;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/** The timed half of skipping the sanitizer on read. */
class ResanitizeTest extends TestCase {
	public function testEachRunTakesABoundedShareOfTheWalkEveryFiveMinutes(): void {
		$upgrade = $this->createMock(SanitizerUpgrade::class);
		$upgrade->expects($this->once())->method('run')->with(20)->willReturn(3);
		$job = new Resanitize($this->createStub(ITimeFactory::class), $upgrade);

		(new \ReflectionMethod(Resanitize::class, 'run'))->invoke($job, null);

		$this->assertSame(300, $job->getInterval());
	}
}
