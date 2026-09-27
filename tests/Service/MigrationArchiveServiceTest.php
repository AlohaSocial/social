<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MigrationArchiveService;
use OCA\Social\UserMigration\SocialMigrator;
use OCP\ITempManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\UserMigration\IExportDestination;
use OCP\UserMigration\IImportSource;
use OCP\UserMigration\UserMigrationException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Output\OutputInterface;
use ZipArchive;

/**
 * The Migration page's export and import, around the real migrator's contract.
 *
 * The migrator itself is doubled — what it writes is its own test's business —
 * but the archive is a real zip, because the point of this class is the file
 * somebody downloads and hands back.
 */
#[AllowMockObjectsWithoutExpectations]
class MigrationArchiveServiceTest extends TestCase {
	private SocialMigrator|Stub $migrator;
	private IUserManager|Stub $userManager;
	private ITempManager|Stub $tempManager;
	private MigrationArchiveService $service;
	/** @var string[] */
	private array $temporary = [];

	protected function setUp(): void {
		parent::setUp();
		$this->migrator = $this->createStub(SocialMigrator::class);
		$this->migrator->method('getId')->willReturn('social');
		$this->migrator->method('getVersion')->willReturn(1);

		$this->userManager = $this->createStub(IUserManager::class);
		$this->tempManager = $this->createStub(ITempManager::class);
		$this->tempManager->method('getTemporaryFile')->willReturnCallback(function (): string {
			$path = tempnam(sys_get_temp_dir(), 'social-archive-test') . '.zip';
			$this->temporary[] = $path;

			return $path;
		});

		$config = $this->createStub(ConfigService::class);
		$config->method('getAppValue')->willReturn('0.19.7');

		$this->service = new MigrationArchiveService(
			$this->migrator,
			$this->userManager,
			$this->tempManager,
			$config,
			new NullLogger(),
		);
	}

	protected function tearDown(): void {
		foreach ($this->temporary as $path) {
			@unlink($path);
		}
		parent::tearDown();
	}

	private function alice(): IUser|MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userManager->method('get')->willReturnCallback(
			static fn (string $uid): ?IUser => $uid === 'alice' ? $user : null
		);

		return $user;
	}

	// export

	public function testTheArchiveHoldsWhatTheMigratorWrote(): void {
		$this->alice();
		$this->migrator->method('export')->willReturnCallback(
			static function (IUser $user, IExportDestination $destination, OutputInterface $output): void {
				$destination->addFileContents('social/actor.json', '{"preferredUsername":"alice"}');
				$destination->addFileContents('social/following_accounts.csv', "Account address\nbob@remote.example\n");
			}
		);

		$path = $this->service->export('alice');

		$zip = new ZipArchive();
		$this->assertTrue($zip->open($path) === true);
		$this->assertSame('{"preferredUsername":"alice"}', $zip->getFromName('social/actor.json'));
		$this->assertNotFalse($zip->getFromName('social/following_accounts.csv'));
		$zip->close();
	}

	/** Without it, an archive says nothing about what wrote it or for whom. */
	public function testTheArchiveCarriesAManifest(): void {
		$this->alice();
		$this->migrator->method('export')->willReturnCallback(
			static function (IUser $user, IExportDestination $destination): void {
				$destination->addFileContents('social/actor.json', '{}');
			}
		);

		$path = $this->service->export('alice');

		$zip = new ZipArchive();
		$zip->open($path);
		$manifest = json_decode((string)$zip->getFromName(MigrationArchiveService::MANIFEST), true);
		$zip->close();

		$this->assertSame('social', $manifest['app']);
		$this->assertSame('0.19.7', $manifest['appVersion']);
		$this->assertSame('alice', $manifest['uid']);
		$this->assertSame(['social' => 1], $manifest['versions']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $manifest['exportedAt']);
	}

	public function testExportingForAnAccountThatIsNotThereIsRefused(): void {
		$this->userManager->method('get')->willReturn(null);

		$this->expectException(ItemUnknownException::class);
		$this->service->export('nobody');
	}

	public function testAFailingMigratorIsReportedRatherThanLeavingAHalfArchive(): void {
		$this->alice();
		$this->migrator->method('export')->willThrowException(new \RuntimeException('the database went away'));

		$this->expectException(UserMigrationException::class);
		$this->expectExceptionMessage('the database went away');
		$this->service->export('alice');
	}

	/**
	 * These land in a downloads folder next to each other, and `export.zip`
	 * says nothing about which account or which week it holds.
	 */
	public function testTheFileIsNamedAfterTheAccountAndTheDay(): void {
		$this->assertSame('social-alice-' . date('Y-m-d') . '.zip', $this->service->filename('alice'));
	}

	public function testAFileNameCannotBeTalkedIntoAPath(): void {
		$this->assertSame(
			'social-..-..-etc-passwd-' . date('Y-m-d') . '.zip',
			$this->service->filename('../../etc/passwd')
		);
	}

	// import

	public function testImportingRunsTheMigratorAndReportsWhatItSaid(): void {
		$this->alice();
		$path = $this->archiveWith(['social/actor.json' => '{}']);
		$this->migrator->method('import')->willReturnCallback(
			static function (IUser $user, IImportSource $source, OutputInterface $output): void {
				$output->writeln('Importing the Social profile…');
				$output->writeln('');
				$output->writeln('Importing 3 follows…');
			}
		);

		$log = $this->service->import('alice', $path);

		$this->assertSame(['Importing the Social profile…', 'Importing 3 follows…'], $log);
	}

	/**
	 * The likeliest mistake is picking the wrong zip, and only naming what was
	 * missing tells somebody that.
	 */
	public function testAnArchiveWithNoSocialDataSaysWhatWasMissing(): void {
		$this->alice();
		$path = $this->archiveWith(['photos/holiday.jpg' => 'not really a photo']);

		$this->expectException(UserMigrationException::class);
		$this->expectExceptionMessage('social/actor.json');
		$this->service->import('alice', $path);
	}

	public function testSomethingThatIsNotAnArchiveIsRefused(): void {
		$this->alice();
		$path = tempnam(sys_get_temp_dir(), 'social-not-a-zip');
		$this->temporary[] = $path;
		file_put_contents($path, 'this is a text file');

		$this->expectException(UserMigrationException::class);
		$this->expectExceptionMessage('not a readable archive');
		$this->service->import('alice', $path);
	}

	public function testAFailingImportIsReported(): void {
		$this->alice();
		$path = $this->archiveWith(['social/actor.json' => '{}']);
		$this->migrator->method('import')->willThrowException(new \RuntimeException('half of it was unreadable'));

		$this->expectException(UserMigrationException::class);
		$this->expectExceptionMessage('half of it was unreadable');
		$this->service->import('alice', $path);
	}

	/**
	 * @param array<string, string> $files what to put in it
	 * @return string the path of the archive
	 */
	private function archiveWith(array $files): string {
		$path = tempnam(sys_get_temp_dir(), 'social-import-test') . '.zip';
		$this->temporary[] = $path;

		$zip = new ZipArchive();
		$zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
		foreach ($files as $name => $contents) {
			$zip->addFromString($name, $contents);
		}
		$zip->close();

		return $path;
	}
}
