<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Command;

use OCA\Social\Command\CheckInstall;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Db\StreamTagsRequest;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CheckService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCA\Social\SetupChecks\ClientApiAtRoot;
use OCA\Social\SetupChecks\CloudAddressMatches;
use OCA\Social\SetupChecks\CronRanRecently;
use OCA\Social\SetupChecks\OutboundQueueNotStuck;
use OCA\Social\SetupChecks\WebFingerReachable;
use OCP\IDBConnection;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ social:check:install` is a health check: the docs send administrators
 * to it after a restore. It used to delete every follow and note whose
 * account was not cached before printing anything, and to dump the whole
 * configuration. It now only reads unless --repair is given, and prints the
 * configuration only with --config.
 */
#[AllowMockObjectsWithoutExpectations]
class CheckInstallTest extends TestCase {
	private CheckService&MockObject $checkService;
	private ConfigService&MockObject $configService;
	private StreamDestRequest&MockObject $streamDestRequest;
	private StreamTagsRequest&MockObject $streamTagsRequest;
	private CommandTester $tester;

	protected function setUp(): void {
		$this->checkService = $this->createMock(CheckService::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->streamDestRequest = $this->createMock(StreamDestRequest::class);
		$this->streamTagsRequest = $this->createMock(StreamTagsRequest::class);

		$command = new CheckInstall(
			$this->createMock(StreamRequest::class),
			$this->streamDestRequest,
			$this->streamTagsRequest,
			$this->createMock(CacheActorService::class),
			$this->checkService,
			$this->configService,
			$this->createMock(MiscService::class),
			$this->createMock(IDBConnection::class),
			$this->passing(WebFingerReachable::class),
			$this->passing(CloudAddressMatches::class),
			$this->passing(CronRanRecently::class),
			$this->passing(OutboundQueueNotStuck::class),
			$this->passing(ClientApiAtRoot::class),
		);
		// added to an application for the question helper behind --index
		$application = new Application();
		$application->addCommand($command);
		$this->tester = new CommandTester($application->find('social:check:install'));
	}

	/**
	 * @template T of ISetupCheck
	 * @param class-string<T> $class
	 * @return T&MockObject
	 */
	private function passing(string $class): ISetupCheck&MockObject {
		$check = $this->createMock($class);
		$check->method('getName')->willReturn($class);
		$check->method('run')->willReturn(SetupResult::success('fine'));

		return $check;
	}

	public function testWithoutRepairNothingIsDeletedAndWhatWouldBeIsCounted(): void {
		$this->checkService->expects($this->never())->method('checkInstallationStatus');
		$this->checkService->expects($this->once())->method('removeInvalidFollows')->with(true)->willReturn(3);
		$this->checkService->expects($this->once())->method('removeInvalidNotes')->with(true)->willReturn(12);
		$this->configService->expects($this->never())->method('getConfig');

		$exit = $this->tester->execute([]);

		$display = $this->tester->getDisplay();
		$this->assertSame(0, $exit);
		$this->assertStringContainsString('3 follows and 12 notes point to an account that is no longer cached', $display);
		$this->assertStringContainsString('occ social:check:install --repair removes them; nothing was changed', $display);
		$this->assertStringContainsString('all 5 checks passed', $display);
		$this->assertStringNotContainsString('configuration', $display);
	}

	public function testANothingToRepairRunSaysSo(): void {
		$this->checkService->method('removeInvalidFollows')->willReturn(0);
		$this->checkService->method('removeInvalidNotes')->willReturn(0);

		$this->tester->execute(['--offline' => true]);

		$this->assertStringContainsString('no follows or notes point to an account that is no longer cached', $this->tester->getDisplay());
		$this->assertStringContainsString('all 3 checks passed', $this->tester->getDisplay());
	}

	public function testRepairRemovesThemAndSaysHowMany(): void {
		$this->checkService->expects($this->once())->method('checkInstallationStatus')->with(false)
			->willReturn(['invalidFollows' => 3, 'invalidNotes' => 12]);
		$this->checkService->expects($this->never())->method('removeInvalidFollows');

		$this->tester->execute(['--repair' => true, '--offline' => true]);

		$display = $this->tester->getDisplay();
		$this->assertStringContainsString('3 invalid follows removed', $display);
		$this->assertStringContainsString('12 invalid notes removed', $display);
	}

	public function testTheConfigurationIsPrintedOnlyWhenAskedFor(): void {
		$this->configService->expects($this->once())->method('getConfig')->willReturn(['social_url' => 'https://cloud.example/apps/social/']);

		$this->tester->execute(['--config' => true, '--offline' => true]);

		$this->assertStringContainsString('"social_url": "https:\/\/cloud.example\/apps\/social\/"', $this->tester->getDisplay());
	}

	/** The index tables are emptied before the rebuild, so a script must say --force. */
	public function testTheIndexIsNotRebuiltUnattendedWithoutForce(): void {
		$this->streamDestRequest->expects($this->never())->method('emptyStreamDest');
		$this->streamTagsRequest->expects($this->never())->method('emptyStreamTags');
		$this->checkService->expects($this->never())->method('removeInvalidFollows');

		$exit = $this->tester->execute(['--index' => true], ['interactive' => false]);

		$this->assertSame(1, $exit);
		$this->assertStringContainsString('Refusing to rebuild the index non-interactively without --force', $this->tester->getDisplay());
	}

	public function testANoToTheIndexQuestionLeavesItAlone(): void {
		$this->streamDestRequest->expects($this->never())->method('emptyStreamDest');
		$this->tester->setInputs(['n']);

		$exit = $this->tester->execute(['--index' => true], ['interactive' => true]);

		$this->assertSame(0, $exit);
		$this->assertStringContainsString('cancelled, the index was left alone.', $this->tester->getDisplay());
	}
}
