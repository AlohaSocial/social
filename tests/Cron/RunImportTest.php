<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Cron\RunImport;
use OCA\Social\Service\ImportQueueService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** The job hands the service the row's id and nothing else. */
#[AllowMockObjectsWithoutExpectations]
class RunImportTest extends TestCase {
	private ImportQueueService|MockObject $service;
	private RunImport $job;

	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(ImportQueueService::class);
		$this->job = new RunImport($this->createStub(ITimeFactory::class), $this->service);
	}

	private function runJob(mixed $argument): void {
		(new \ReflectionMethod(RunImport::class, 'run'))->invoke($this->job, $argument);
	}

	public function testTheImportNamedInTheArgumentIsRun(): void {
		$this->service->expects($this->once())->method('run')->with(42);

		$this->runJob(['import' => 42]);
	}

	public function testAnArgumentNamingNoImportRunsNothing(): void {
		$this->service->expects($this->never())->method('run');

		$this->runJob(['import' => 0]);
		$this->runJob('42');
		$this->runJob(null);
	}
}
