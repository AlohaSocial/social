<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Cron\InterestHides;
use OCA\Social\Db\RecommendationSignalRequest;
use OCA\Social\Service\InterestService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

class InterestHidesTest extends TestCase {
	public function testTheDailyRunTrimsTheRecommendationLogsEvenWhenHidesFail(): void {
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(2_000_000_000);
		$interests = $this->createStub(InterestService::class);
		$interests->method('purgeHides')->willThrowException(new RuntimeException('down'));
		$signals = $this->createMock(RecommendationSignalRequest::class);
		$signals->expects($this->once())->method('trim')->with(2_000_000_000)->willReturn(3);

		$job = new InterestHides($time, $interests, new NullLogger(), $signals);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}
}
