<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Command;

use OCA\Social\Command\QueueStatus;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FederationHealthService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\RequestQueueService;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * What `occ social:queue:status` prints without a token.
 *
 * The report is the only place an administrator learns that a peer stopped
 * receiving anything, so the half it was missing — what has been given up on —
 * is asserted here rather than left to the settings page.
 */
class QueueStatusReportTest extends TestCase {
	private FederationHealthService|Stub $federationHealthService;
	private CommandTester $tester;

	protected function setUp(): void {
		$this->federationHealthService = $this->createStub(FederationHealthService::class);
		$this->tester = new CommandTester(new QueueStatus(
			$this->createStub(RequestQueueService::class),
			$this->createStub(ConfigService::class),
			$this->createStub(MiscService::class),
			$this->federationHealthService
		));
	}

	private function report(array $overrides = []): string {
		$this->federationHealthService->method('summary')->willReturn(array_merge([
			'waiting' => 0, 'running' => 0, 'failing' => 0, 'atRisk' => 0, 'abandoned' => 0,
			'maxTries' => 16, 'truncated' => false, 'abandonedTruncated' => false,
			'retentionDays' => 7, 'instances' => [], 'givenUp' => [],
		], $overrides));

		$this->assertSame(0, $this->tester->execute([]));

		return $this->tester->getDisplay();
	}

	public function testAHealthyQueueSaysBothThingsAreFine(): void {
		$report = $this->report(['waiting' => 4]);

		$this->assertStringContainsString('4 deliveries waiting', $report);
		$this->assertStringContainsString('nothing is failing to deliver', $report);
		$this->assertStringContainsString('nothing has been given up on in the last 7 days', $report);
	}

	public function testTheInstancesGivenUpOnAreNamedWithTheirAttempts(): void {
		$report = $this->report([
			'abandoned' => 12,
			'givenUp' => [['host' => 'gone.example', 'requests' => 12, 'tries' => 16, 'last' => 1_700_000_000]],
		]);

		$this->assertStringContainsString('12 deliveries were given up on in the last 7 days', $report);
		$this->assertStringContainsString('gone.example', $report);
		$this->assertStringContainsString('16/16', $report);
		$this->assertStringContainsString('2023-11-14 22:13', $report);
	}

	/**
	 * An empty "failing" section on an instance that gave up on a peer
	 * yesterday is the reassuring half of a bad answer, so the given-up
	 * section is printed either way.
	 */
	public function testWhatWasGivenUpOnIsReportedEvenWhenNothingIsFailingNow(): void {
		$report = $this->report([
			'abandoned' => 3,
			'givenUp' => [['host' => 'gone.example', 'requests' => 3, 'tries' => 16, 'last' => 0]],
		]);

		$this->assertStringContainsString('nothing is failing to deliver', $report);
		$this->assertStringContainsString('3 deliveries were given up on', $report);
		$this->assertStringContainsString('never', $report);
	}

	public function testTheReportSaysWhatToDoAboutIt(): void {
		$report = $this->report([
			'abandoned' => 1,
			'givenUp' => [['host' => 'gone.example', 'requests' => 1, 'tries' => 16, 'last' => 0]],
		]);

		$this->assertStringContainsString('social:queue:retry --instance', $report);
	}

	public function testACountThatStoppedShortSaysSo(): void {
		$report = $this->report(['abandoned' => 500, 'abandonedTruncated' => true]);

		$this->assertStringContainsString('500+', $report);
	}
}
