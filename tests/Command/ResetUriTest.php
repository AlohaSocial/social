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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** `--uri` follows the setup screen's rules, and is refused before anything is deleted. */
class ResetUriTest extends TestCase {
	/** @var CoreRequestBuilder&MockObject */
	private $builder;
	/** @var ConfigService&MockObject */
	private $configService;

	private function tester(): CommandTester {
		$this->builder = $this->createMock(CoreRequestBuilder::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->configService->method('getCloudUrl')->willReturn('https://old.example');

		return new CommandTester(new Reset(
			$this->builder,
			$this->createStub(CheckService::class),
			$this->configService,
			$this->createStub(MiscService::class),
			$this->createStub(ExternalUsersRequest::class),
		));
	}

	public function testAnUnsafeUriIsRefusedBeforeAnythingIsDeleted(): void {
		$tester = $this->tester();
		$this->builder->expects($this->never())->method('emptyAll');
		$this->configService->expects($this->never())->method('setCloudUrl');

		$this->assertSame(1, $tester->execute(['--force' => true, '--uri' => 'http://localhost']));
		$this->assertStringContainsString('--allow-unsafe-uri', $tester->getDisplay());
	}

	public function testATestInstanceMayAcceptOneExplicitly(): void {
		$tester = $this->tester();
		$this->builder->expects($this->once())->method('emptyAll');
		$this->configService->expects($this->once())->method('setCloudUrl')->with('http://localhost:8080');

		$this->assertSame(0, $tester->execute(['--force' => true, '--uri' => 'http://localhost:8080', '--allow-unsafe-uri' => true]));
	}

	public function testSomethingThatIsNoAddressIsRefusedEvenThen(): void {
		$tester = $this->tester();
		$this->builder->expects($this->never())->method('emptyAll');
		$this->configService->expects($this->never())->method('setCloudUrl');

		$this->assertSame(1, $tester->execute(['--force' => true, '--uri' => 'old.example', '--allow-unsafe-uri' => true]));
	}

	public function testAPublicHttpsUriIsTaken(): void {
		$tester = $this->tester();
		$this->builder->expects($this->once())->method('emptyAll');
		$this->configService->expects($this->once())->method('setCloudUrl')->with('https://new.example');

		$this->assertSame(0, $tester->execute(['--force' => true, '--uri' => 'https://new.example']));
	}
}
