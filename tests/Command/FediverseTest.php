<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Command;

use OCA\Social\Command\Fediverse;
use OCA\Social\Db\ServerBlockRequest;
use OCA\Social\Service\BlocklistImportService;
use OCA\Social\Service\FediverseService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class FediverseTest extends TestCase {
	private FediverseService|MockObject $fediverseService;
	private Fediverse $command;
	private CommandTester $tester;
	private string $csvPath;

	protected function setUp(): void {
		$this->fediverseService = $this->createMock(FediverseService::class);
		$this->command = new Fediverse(
			$this->fediverseService,
			new BlocklistImportService($this->fediverseService, $this->createStub(ServerBlockRequest::class)),
		);
		$this->tester = new CommandTester($this->command);
		$csvPath = tempnam(sys_get_temp_dir(), 'social-fediverse-');
		if ($csvPath === false) {
			$this->fail('Could not create the temporary CSV fixture.');
		}
		$this->csvPath = $csvPath;
	}

	protected function tearDown(): void {
		if (isset($this->csvPath) && is_file($this->csvPath)) {
			unlink($this->csvPath);
		}
	}

	/**
	 * A published list says what it wants done about each server, and a
	 * silence is not a block: it keeps the server out of the public timelines
	 * and leaves what this one holds of it alone. Reading the column and
	 * ignoring it deleted that instead — thirty of mastodon.social's two
	 * hundred and seventy-six entries are silences.
	 */
	public function testTheSeverityColumnDecidesWhatHappensToEachRow(): void {
		file_put_contents($this->csvPath, "#domain,#severity,#public_comment\n"
			. "first.example,suspend,\"reason, with comma\"\n"
			. "FIRST.EXAMPLE.,suspend,duplicate\nsecond.example,silence,\"another, reason\"\n");
		$this->fediverseService->method('getAccessType')->willReturn('all_but');
		$this->fediverseService->expects($this->once())
			->method('addAddresses')
			->with(['first.example'])
			->willReturn(1);
		$this->fediverseService->expects($this->once())
			->method('silenceAddresses')
			->with(['second.example']);

		$this->assertSame(0, $this->tester->execute(['action' => 'import', 'address' => $this->csvPath, '--force' => true]));
		$this->assertStringContainsString('Blocked 1 domains and silenced 1', $this->tester->getDisplay());
	}

	public function testAInvalidDomainAbortsBeforeAnyChange(): void {
		file_put_contents($this->csvPath, "#domain\nvalid.example\nhttps://invalid.example\n");
		$this->fediverseService->method('getAccessType')->willReturn('all_but');
		$this->fediverseService->expects($this->never())->method('addAddresses');

		$this->assertSame(1, $this->tester->execute(['action' => 'import', 'address' => $this->csvPath, '--force' => true]));
		$this->assertStringContainsString('No domains were imported.', $this->tester->getDisplay());
	}

	public function testImportRefusesToChangeAnAllowList(): void {
		file_put_contents($this->csvPath, "blocked.example\n");
		$this->fediverseService->method('getAccessType')->willReturn('none_but');
		$this->fediverseService->expects($this->never())->method('addAddresses');

		$this->assertSame(1, $this->tester->execute(['action' => 'import', 'address' => $this->csvPath, '--force' => true]));
		$this->assertStringContainsString('access mode was not changed', $this->tester->getDisplay());
	}

	public function testAnEmptyCsvDoesNotReplaceOrClearTheCurrentList(): void {
		file_put_contents($this->csvPath, "#domain,#severity\n\n");
		$this->fediverseService->method('getAccessType')->willReturn('all_but');
		$this->fediverseService->expects($this->never())->method('addAddresses');

		$this->assertSame(1, $this->tester->execute(['action' => 'import', 'address' => $this->csvPath, '--force' => true]));
		$this->assertStringContainsString('did not contain any domains', $this->tester->getDisplay());
	}

	/**
	 * A single-label row blocks a whole top-level domain.
	 *
	 * An entry covers itself and everything under it — `isListed()` matches a
	 * suffix — so `com` in a reviewed third-party list refuses every `.com`
	 * server this instance has ever met, and queues a purge for each.
	 */
	public function testASingleLabelRowIsRefusedBeforeAnythingIsWritten(): void {
		file_put_contents($this->csvPath, "#domain\nfirst.example\ncom\n");
		$this->fediverseService->method('getAccessType')->willReturn('all_but');
		$this->fediverseService->expects($this->never())->method('addAddresses');

		$this->assertSame(1, $this->tester->execute(['action' => 'import', 'address' => $this->csvPath, '--force' => true]));
		$this->assertStringContainsString('No domains were imported.', $this->tester->getDisplay());
	}

	public function testABareHostnameWithNoDotIsRefusedToo(): void {
		file_put_contents($this->csvPath, "#domain\nlocalhost\n");
		$this->fediverseService->method('getAccessType')->willReturn('all_but');
		$this->fediverseService->expects($this->never())->method('addAddresses');

		$this->assertSame(1, $this->tester->execute(['action' => 'import', 'address' => $this->csvPath, '--force' => true]));
	}

	/** Blocking your own instance is not a policy, it is an accident. */
	public function testThisInstancesOwnHostIsRefused(): void {
		file_put_contents($this->csvPath, "#domain\nfirst.example\ncloud.example.org\n");
		$this->fediverseService->method('getAccessType')->willReturn('all_but');
		$this->fediverseService->method('isLocal')
			->willReturnCallback(fn (string $domain): bool => $domain === 'cloud.example.org');
		$this->fediverseService->expects($this->never())->method('addAddresses');

		$this->assertSame(1, $this->tester->execute(['action' => 'import', 'address' => $this->csvPath, '--force' => true]));
		$this->assertStringContainsString('cloud.example.org', $this->tester->getDisplay());
		$this->assertStringContainsString('No domains were imported.', $this->tester->getDisplay());
	}

	/**
	 * The unique cap is reached by parsing the whole file, so a file of any
	 * size at all was read row by row before it could be refused.
	 */
	public function testAnOversizedFileIsNotReadAtAll(): void {
		file_put_contents($this->csvPath, "#domain\n" . str_repeat("padding.example\n", 600_000));
		$this->fediverseService->method('getAccessType')->willReturn('all_but');
		$this->fediverseService->expects($this->never())->method('addAddresses');

		$this->assertSame(1, $this->tester->execute(['action' => 'import', 'address' => $this->csvPath, '--force' => true]));
		$this->assertStringContainsString('larger than', $this->tester->getDisplay());
	}

	/**
	 * Every domain added queues a `DomainPurge`, which deletes what this
	 * instance holds of that server. Reading the list first is the point.
	 */
	public function testADryRunPrintsWhatItWouldAddAndChangesNothing(): void {
		file_put_contents($this->csvPath, "#domain\nfirst.example\nsecond.example\n");
		$this->fediverseService->method('getAccessType')->willReturn('all_but');
		$this->fediverseService->expects($this->never())->method('addAddresses');

		$this->assertSame(0, $this->tester->execute([
			'action' => 'import', 'address' => $this->csvPath, '--dry-run' => true,
		]));

		$display = $this->tester->getDisplay();
		$this->assertStringContainsString('2 domains would be blocked', $display);
		$this->assertStringContainsString('first.example', $display);
		$this->assertStringContainsString('Nothing was changed', $display);
	}

	/** And answering no at the prompt writes nothing either. */
	public function testAnsweringNoImportsNothing(): void {
		file_put_contents($this->csvPath, "#domain\nfirst.example\n");
		$this->fediverseService->method('getAccessType')->willReturn('all_but');
		$this->fediverseService->expects($this->never())->method('addAddresses');

		$application = new Application();
		$application->add($this->command);
		$tester = new CommandTester($this->command);
		$tester->setInputs(['no']);

		$this->assertSame(1, $tester->execute(
			['action' => 'import', 'address' => $this->csvPath],
			['interactive' => true]
		));
		$this->assertStringContainsString('Nothing was imported.', $tester->getDisplay());
	}

	/**
	 * A script that has not said --force is told so, rather than being handed
	 * a "nothing happened" it cannot tell from success.
	 */
	public function testANonInteractiveRunWithoutForceRefuses(): void {
		file_put_contents($this->csvPath, "#domain\nfirst.example\n");
		$this->fediverseService->method('getAccessType')->willReturn('all_but');
		$this->fediverseService->expects($this->never())->method('addAddresses');

		$application = new Application();
		$application->add($this->command);
		$tester = new CommandTester($this->command);

		$this->assertSame(1, $tester->execute(
			['action' => 'import', 'address' => $this->csvPath],
			['interactive' => false]
		));
		$this->assertStringContainsString('without --force', $tester->getDisplay());
	}

	public function testANonInteractiveRunWithForceImports(): void {
		file_put_contents($this->csvPath, "#domain\nfirst.example\n");
		$this->fediverseService->method('getAccessType')->willReturn('all_but');
		$this->fediverseService->expects($this->once())->method('addAddresses')->willReturn(1);

		$application = new Application();
		$application->add($this->command);
		$tester = new CommandTester($this->command);

		$this->assertSame(0, $tester->execute(
			['action' => 'import', 'address' => $this->csvPath, '--force' => true],
			['interactive' => false]
		));
	}
}
