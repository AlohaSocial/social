<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Cron\FillFollowedTags;
use OCA\Social\Service\Discovery\FollowedTagsFill;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/** The timed half of following a hashtag. */
class FillFollowedTagsTest extends TestCase {
	public function testEachRunReadsTheFollowedTagsThatAreDueEveryQuarterOfAnHour(): void {
		$fill = $this->createMock(FollowedTagsFill::class);
		$fill->expects($this->once())->method('run')->willReturn(4);
		$job = new FillFollowedTags($this->createStub(ITimeFactory::class), $fill);

		(new \ReflectionMethod(FillFollowedTags::class, 'run'))->invoke($job, null);

		$this->assertSame(900, $job->getInterval());
	}
}
