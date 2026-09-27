<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Command;

use OCA\Social\Command\Reset;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\ExternalUsersRequest;
use OCA\Social\Service\CheckService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** A reset that would leave Nextcloud users nobody can log in as does not run. */
class ResetExternalUsersTest extends TestCase {
	public function testResetRefusesWhileExternalUsersExist(): void {
		$builder = $this->createMock(CoreRequestBuilder::class);
		$builder->expects($this->never())->method('emptyAll');
		$builder->expects($this->never())->method('uninstallSocialTables');
		$externals = $this->createStub(ExternalUsersRequest::class);
		$externals->method('count')->willReturn(2);

		$tester = new CommandTester(new Reset(
			$builder,
			$this->createStub(CheckService::class),
			$this->createStub(ConfigService::class),
			$this->createStub(MiscService::class),
			$externals,
		));

		$this->assertSame(1, $tester->execute(['--force' => true]));
		$this->assertStringContainsString('2 self-registered external user(s)', $tester->getDisplay());
	}
}
