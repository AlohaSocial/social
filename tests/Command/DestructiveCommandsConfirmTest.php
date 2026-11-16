<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Command;

use OCA\Social\Command\AccountDelete;
use OCA\Social\Command\DomainPurge;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DomainPurgeService;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Deleting an account and purging a domain cannot be undone, so both ask
 * first, and refuse under `--no-interaction` without `--force` — the way
 * `social:reset` does.
 */
#[AllowMockObjectsWithoutExpectations]
class DestructiveCommandsConfirmTest extends TestCase {
	private AccountService&MockObject $accountService;
	private DomainPurgeService&MockObject $domainPurgeService;

	protected function setUp(): void {
		$this->accountService = $this->createMock(AccountService::class);
		$this->domainPurgeService = $this->createMock(DomainPurgeService::class);
	}

	private function tester(Command $command): CommandTester {
		$application = new Application();
		$application->addCommand($command);

		return new CommandTester($application->find((string)$command->getName()));
	}

	private function accountDelete(): CommandTester {
		return $this->tester(new AccountDelete(
			$this->createStub(IUserManager::class),
			$this->accountService,
			$this->createStub(CacheActorService::class),
			$this->createStub(ConfigService::class),
		));
	}

	private function alice(): Person {
		$actor = new Person();
		$actor->setPreferredUsername('alice');

		return $actor;
	}

	public function testAnAccountIsDeletedOnlyOnceTheAdminSaysYes(): void {
		$this->accountService->method('getActor')->willReturn($this->alice());
		$this->accountService->expects($this->once())->method('deleteActor')->with('alice');

		$tester = $this->accountDelete();
		$tester->setInputs(['y']);

		$this->assertSame(0, $tester->execute(['account' => 'alice'], ['interactive' => true]));
		$this->assertStringContainsString('@alice deleted', $tester->getDisplay());
	}

	public function testANoDeletesNothing(): void {
		$this->accountService->method('getActor')->willReturn($this->alice());
		$this->accountService->expects($this->never())->method('deleteActor');

		$tester = $this->accountDelete();
		$tester->setInputs(['n']);

		$this->assertSame(0, $tester->execute(['account' => 'alice'], ['interactive' => true]));
		$this->assertStringContainsString('nothing was deleted', $tester->getDisplay());
	}

	public function testWithoutATerminalItTakesForceToDelete(): void {
		$this->accountService->method('getActor')->willReturn($this->alice());
		$this->accountService->expects($this->once())->method('deleteActor');

		$this->assertSame(1, $this->accountDelete()->execute(['account' => 'alice'], ['interactive' => false]));
		$this->assertSame(0, $this->accountDelete()->execute(['account' => 'alice', '--force' => true], ['interactive' => false]));
	}

	public function testAHandleWithNoAccountIsAnError(): void {
		$this->accountService->method('getActor')->willThrowException(new ActorDoesNotExistException());
		$this->accountService->expects($this->never())->method('deleteActor');

		$tester = $this->accountDelete();

		$this->assertSame(1, $tester->execute(['account' => 'nobody', '--force' => true]));
		$this->assertStringContainsString('no local account @nobody', $tester->getDisplay());
	}

	public function testADomainIsPurgedOnlyOnceTheAdminSaysYes(): void {
		$this->domainPurgeService->expects($this->once())->method('purge')->with('spam.example', 0)->willReturn(3);

		$tester = $this->tester(new DomainPurge($this->domainPurgeService));
		$tester->setInputs(['y']);

		$this->assertSame(0, $tester->execute(['domain' => 'spam.example'], ['interactive' => true]));
		$this->assertStringContainsString('does not restore', $tester->getDisplay(), 'said before, not after');
		$this->assertStringContainsString('3 accounts of spam.example purged', $tester->getDisplay());
	}

	public function testADomainPurgeWithoutATerminalTakesForce(): void {
		$this->domainPurgeService->expects($this->never())->method('purge');

		$tester = $this->tester(new DomainPurge($this->domainPurgeService));

		$this->assertSame(1, $tester->execute(['domain' => 'spam.example'], ['interactive' => false]));
	}

	public function testCheckingADomainAsksNothing(): void {
		$this->domainPurgeService->method('hasRemains')->willReturn(false);
		$this->domainPurgeService->expects($this->never())->method('purge');

		$tester = $this->tester(new DomainPurge($this->domainPurgeService));

		$this->assertSame(0, $tester->execute(['domain' => 'spam.example', '--check' => true], ['interactive' => false]));
	}
}
