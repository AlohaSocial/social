<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\AlohaDirectory\DirectoryConfig;
use OCA\Social\AlohaDirectory\DirectoryLists;
use OCA\Social\AlohaDirectory\DirectoryParticipation;
use OCA\Social\Cron\AlohaDirectory;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class AlohaDirectoryTest extends TestCase {
	private function runJob(AlohaDirectory $job): void {
		$method = new \ReflectionMethod($job, 'run');
		$method->invoke($job, null);
	}

	public function testAServerThatUsesNeitherDoesNothing(): void {
		$config = $this->createStub(DirectoryConfig::class);
		$config->method('usesAlohaList')->willReturn(false);
		$config->method('advertises')->willReturn(false);
		$lists = $this->createMock(DirectoryLists::class);
		$lists->expects($this->never())->method('refresh');
		$participation = $this->createMock(DirectoryParticipation::class);
		$participation->method('phase')->willReturn(DirectoryParticipation::PHASE_NONE);
		$participation->expects($this->never())->method('sync');

		$this->runJob(new AlohaDirectory($this->createStub(ITimeFactory::class), $config, $lists, $participation, new NullLogger()));
	}

	public function testTheListsAreReadWhenDueAndTheParticipationIsSynced(): void {
		$config = $this->createStub(DirectoryConfig::class);
		$config->method('usesAlohaList')->willReturn(true);
		$lists = $this->createMock(DirectoryLists::class);
		$lists->method('refreshDue')->willReturn(true);
		$lists->expects($this->once())->method('refresh')->willReturn(['ok' => true, 'error' => '', 'changed' => []]);
		$participation = $this->createMock(DirectoryParticipation::class);
		$participation->method('phase')->willReturn(DirectoryParticipation::PHASE_ENROLLED);
		$participation->expects($this->once())->method('sync');

		$this->runJob(new AlohaDirectory($this->createStub(ITimeFactory::class), $config, $lists, $participation, new NullLogger()));
	}

	public function testAFailureIsLoggedAndDoesNotEscape(): void {
		$config = $this->createStub(DirectoryConfig::class);
		$config->method('usesAlohaList')->willReturn(true);
		$lists = $this->createStub(DirectoryLists::class);
		$lists->method('refreshDue')->willReturn(true);
		$lists->method('refresh')->willThrowException(new \RuntimeException('boom'));
		$participation = $this->createMock(DirectoryParticipation::class);
		$participation->expects($this->never())->method('sync');

		$this->runJob(new AlohaDirectory($this->createStub(ITimeFactory::class), $config, $lists, $participation, new NullLogger()));
	}
}
