<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\UserMigration;

use OCA\Social\UserMigration\SocialMigrator;
use OCA\Social\UserMigration\ZipExportDestination;
use OCA\Social\UserMigration\ZipImportSource;
use OCP\Files\Folder;
use OCP\ITempManager;
use OCP\UserMigration\UserMigrationException;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * The two ends of the Migration page's archive, against a real zip file.
 *
 * A doubled `ZipArchive` would agree with anything; what is worth proving is
 * that what the export writes is what the import reads, because that round
 * trip is the whole promise the page makes.
 */
class ZipArchiveTest extends TestCase {
	private string $path;

	/** @var string[] what the temp manager handed out, to clean up after */
	private array $tempFiles = [];

	protected function setUp(): void {
		parent::setUp();
		$path = tempnam(sys_get_temp_dir(), 'social-zip-test');
		$this->assertIsString($path);
		$this->path = $path . '.zip';
		@unlink($path);
	}

	protected function tearDown(): void {
		@unlink($this->path);
		foreach ($this->tempFiles as $path) {
			@unlink($path);
		}
		$this->tempFiles = [];
		parent::tearDown();
	}

	private function writing(): array {
		$zip = new ZipArchive();
		$this->assertTrue($zip->open($this->path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);

		$tempManager = $this->createStub(ITempManager::class);
		$tempManager->method('getTemporaryFile')->willReturnCallback(function (): string {
			$path = (string)tempnam(sys_get_temp_dir(), 'social-zip-stream');
			$this->tempFiles[] = $path;

			return $path;
		});

		return [$zip, new ZipExportDestination($zip, $tempManager)];
	}

	private function reading(string $uid = ''): array {
		$zip = new ZipArchive();
		$this->assertTrue($zip->open($this->path, ZipArchive::RDONLY) === true);

		return [$zip, new ZipImportSource($zip, $uid)];
	}

	public function testWhatWasExportedIsWhatIsImported(): void {
		[$zip, $destination] = $this->writing();
		$destination->addFileContents(SocialMigrator::PATH_ACTOR_PUBLIC, '{"preferredUsername":"alice"}');
		$destination->addFileContents('social/following_accounts.csv', "Account address\nbob@remote.example\n");
		$zip->close();

		[$zip, $source] = $this->reading();
		$this->assertSame('{"preferredUsername":"alice"}', $source->getFileContents(SocialMigrator::PATH_ACTOR_PUBLIC));
		$this->assertSame("Account address\nbob@remote.example\n", $source->getFileContents('social/following_accounts.csv'));
		$this->assertTrue($source->pathExists(SocialMigrator::PATH_ACTOR_PUBLIC));
		$this->assertFalse($source->pathExists('social/outbox.json'));
		$zip->close();
	}

	/** The outbox is written as a stream, being the one file that can be large. */
	public function testAStreamIsStoredAsItsContents(): void {
		$stream = fopen('php://temp', 'r+');
		$this->assertIsResource($stream);
		fwrite($stream, '[{"id":"1"}]');
		rewind($stream);

		[$zip, $destination] = $this->writing();
		$destination->addFileAsStream('social/outbox.json', $stream);
		$zip->close();
		fclose($stream);

		[$zip, $source] = $this->reading();
		$this->assertSame('[{"id":"1"}]', $source->getFileContents('social/outbox.json'));
		$zip->close();
	}

	public function testAMissingFileIsSaidPlainly(): void {
		[$zip, $destination] = $this->writing();
		$destination->addFileContents('social/actor.json', '{}');
		$zip->close();

		[$zip, $source] = $this->reading();
		$this->expectException(UserMigrationException::class);
		$this->expectExceptionMessage('social/likes.csv is not in this archive');
		try {
			$source->getFileContents('social/likes.csv');
		} finally {
			$zip->close();
		}
	}

	public function testTheFolderListingIsOneLevelDeep(): void {
		[$zip, $destination] = $this->writing();
		$destination->addFileContents('social/actor.json', '{}');
		$destination->addFileContents('social/likes.csv', '');
		$destination->addFileContents('social/deeper/thing.json', '{}');
		$destination->addFileContents('other/actor.json', '{}');
		$zip->close();

		[$zip, $source] = $this->reading();
		$listing = $source->getFolderListing('social');
		sort($listing);
		$this->assertSame(['actor.json', 'likes.csv'], $listing);
		$zip->close();
	}

	// what version the archive claims

	public function testTheVersionComesFromTheManifest(): void {
		[$zip, $destination] = $this->writing();
		$destination->addFileContents('social/actor.json', '{}');
		$zip->addFromString(ZipImportSource::MANIFEST, json_encode(['uid' => 'alice', 'versions' => ['social' => 3]]));
		$zip->close();

		[$zip, $source] = $this->reading();
		$this->assertSame(3, $source->getMigratorVersion('social'));
		$this->assertSame(['social' => 3], $source->getMigratorVersions());
		$this->assertSame('alice', $source->getOriginalUid());
		$zip->close();
	}

	/**
	 * An archive from `occ user:export` holds the data at the same paths and
	 * keeps its versions in a file this class does not read. Refusing it would
	 * mean telling somebody that what the server itself produced is not
	 * importable.
	 */
	public function testAnArchiveWithDataButNoManifestReadsAsVersionOne(): void {
		[$zip, $destination] = $this->writing();
		$destination->addFileContents(SocialMigrator::PATH_ACTOR_PUBLIC, '{}');
		$zip->close();

		[$zip, $source] = $this->reading();
		$this->assertSame(1, $source->getMigratorVersion('social'));
		$zip->close();
	}

	/** An archive of something else entirely claims no version at all. */
	public function testAnArchiveWithoutSocialDataClaimsNoVersion(): void {
		[$zip, $destination] = $this->writing();
		$destination->addFileContents('photos/holiday.jpg', 'not really a photo');
		$zip->close();

		[$zip, $source] = $this->reading();
		$this->assertNull($source->getMigratorVersion('social'));
		$zip->close();
	}

	public function testTheUidGivenBeatsTheManifest(): void {
		[$zip, $destination] = $this->writing();
		$destination->addFileContents('social/actor.json', '{}');
		$zip->addFromString(ZipImportSource::MANIFEST, json_encode(['uid' => 'alice']));
		$zip->close();

		[$zip, $source] = $this->reading('bob');
		$this->assertSame('bob', $source->getOriginalUid());
		$zip->close();
	}

	// what these archives are not

	/**
	 * This app puts nothing from the user's files in the archive. A no-op
	 * would make an archive that says it holds something it does not.
	 */
	public function testCopyingAFolderIsRefusedAtBothEnds(): void {
		[$zip, $destination] = $this->writing();
		$folder = $this->createStub(Folder::class);

		try {
			$this->expectException(UserMigrationException::class);
			$destination->copyFolder($folder, 'social');
		} finally {
			$zip->close();
		}
	}

	public function testCopyingIntoAFolderIsRefused(): void {
		[$zip, $destination] = $this->writing();
		$destination->addFileContents('social/actor.json', '{}');
		$zip->close();

		[$zip, $source] = $this->reading();
		try {
			$this->expectException(UserMigrationException::class);
			$source->copyToFolder($this->createStub(Folder::class), 'social');
		} finally {
			$zip->close();
		}
	}

	/** The versions are the last thing a migrator reports, not a file it writes. */
	public function testTheVersionsAreKeptForWhoeverWritesTheManifest(): void {
		[$zip, $destination] = $this->writing();
		$destination->setMigratorVersions(['social' => 2]);
		$zip->close();

		$this->assertSame(['social' => 2], $destination->getMigratorVersions());
	}
}
