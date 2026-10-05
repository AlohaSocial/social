<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Cron\RunImport;
use OCA\Social\Db\ImportsRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ImportJob;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ImportQueueService;
use OCA\Social\Service\MigrationService;
use OCA\Social\Service\MoveInService;
use OCA\Social\Service\PostImportService;
use OCP\BackgroundJob\IJobList;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\ITempManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * An import kept, queued, run in the background and counted as it goes.
 */
#[AllowMockObjectsWithoutExpectations]
class ImportQueueServiceTest extends TestCase {
	private ImportsRequest|MockObject $importsRequest;
	private IAppData|MockObject $appData;
	private ISimpleFolder|MockObject $folder;
	private IJobList|MockObject $jobList;
	private ITempManager|MockObject $tempManager;
	private MigrationService|MockObject $migrationService;
	private PostImportService|MockObject $postImportService;
	private MoveInService|MockObject $moveInService;
	private AccountService|MockObject $accountService;
	private ImportQueueService $service;

	/** @var array<string, string> the kept uploads, by name */
	private array $kept = [];
	/** @var string[] the uploads deleted again */
	private array $deleted = [];
	/** @var ImportJob[] every state the row was written in */
	private array $writes = [];

	protected function setUp(): void {
		parent::setUp();
		$this->importsRequest = $this->createMock(ImportsRequest::class);
		$this->appData = $this->createMock(IAppData::class);
		$this->folder = $this->createMock(ISimpleFolder::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->tempManager = $this->createMock(ITempManager::class);
		$this->migrationService = $this->createMock(MigrationService::class);
		$this->postImportService = $this->createMock(PostImportService::class);
		$this->moveInService = $this->createMock(MoveInService::class);
		$this->accountService = $this->createMock(AccountService::class);

		$this->appData->method('getFolder')->willReturn($this->folder);
		$this->folder->method('newFile')->willReturnCallback(function (string $name, $content): ISimpleFile {
			$this->kept[$name] = is_resource($content) ? (string)stream_get_contents($content) : (string)$content;

			return $this->file($name);
		});
		$this->folder->method('getFile')->willReturnCallback(fn (string $name): ISimpleFile => $this->file($name));
		$this->importsRequest->method('create')->willReturnCallback(function (ImportJob $job): void {
			$job->setId(42);
		});
		$this->importsRequest->method('update')->willReturnCallback(function (ImportJob $job): void {
			$this->writes[] = clone $job;
		});

		$this->service = new ImportQueueService(
			$this->importsRequest,
			$this->appData,
			$this->jobList,
			$this->tempManager,
			$this->migrationService,
			$this->postImportService,
			$this->moveInService,
			$this->accountService,
			new NullLogger(),
		);
	}

	private function file(string $name): ISimpleFile {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturnCallback(fn (): string => $this->kept[$name] ?? '');
		$file->method('read')->willReturnCallback(function () use ($name) {
			$stream = fopen('php://memory', 'r+');
			fwrite($stream, $this->kept[$name] ?? '');
			rewind($stream);

			return $stream;
		});
		$file->method('delete')->willReturnCallback(function () use ($name): void {
			$this->deleted[] = $name;
			unset($this->kept[$name]);
		});

		return $file;
	}

	private function upload(string $contents, string $extension = 'csv'): string {
		$path = tempnam(sys_get_temp_dir(), 'social-import-test') . '.' . $extension;
		file_put_contents($path, $contents);

		return $path;
	}

	private function queuedRow(string $kind, string $file = '', array $options = []): ImportJob {
		$job = new ImportJob();
		$job->setId(42)->setUserId('alice')->setKind($kind)->setFile($file)->setOptions($options);
		$this->importsRequest->method('getById')->with(42)->willReturn($job);

		return $job;
	}

	// queue()

	/** The upload is copied into appdata before the request ends, and the job is given the row. */
	public function testQueueKeepsTheUploadAndAddsTheJob(): void {
		$path = $this->upload("Account address\nbob@remote.example\n");
		$this->importsRequest->method('hasActive')->willReturn(false);
		$this->jobList->expects($this->once())->method('add')->with(RunImport::class, ['import' => 42]);

		$job = $this->service->queue('alice', ImportJob::KIND_FOLLOWS, $path, []);

		$this->assertSame(42, $job->getId());
		$this->assertSame(ImportJob::STATUS_QUEUED, $job->getStatus());
		$this->assertStringEndsWith('.csv', $job->getFile());
		$this->assertSame("Account address\nbob@remote.example\n", $this->kept[$job->getFile()]);
		@unlink($path);
	}

	public function testQueueRefusesASecondImportOfAKindWhileOneRuns(): void {
		$this->importsRequest->method('hasActive')->with('alice', ImportJob::KIND_POSTS)->willReturn(true);
		$this->jobList->expects($this->never())->method('add');

		$this->expectException(InvalidResourceException::class);
		$this->service->queue('alice', ImportJob::KIND_POSTS, null, []);
	}

	public function testQueueRefusesAKindItDoesNotRun(): void {
		$this->expectException(InvalidResourceException::class);
		$this->service->queue('alice', 'everything', null, []);
	}

	// run()

	public function testRunHandsTheKeptFollowsToTheImporterAndRecordsTheCount(): void {
		$job = $this->queuedRow(ImportJob::KIND_FOLLOWS, 'f.csv');
		$this->kept['f.csv'] = "Account address\nbob@remote.example\ncarol@remote.example\n";
		$this->migrationService->expects($this->once())->method('importFollows')
			->with('alice', $this->kept['f.csv'], $this->isCallable())
			->willReturnCallback(function (string $userId, string $csv, callable $progress): array {
				$progress(0, 2);
				$progress(2, 2);

				return ['followed' => 1, 'skipped' => 0, 'failed' => ['carol@remote.example' => 'gone']];
			});

		$this->service->run(42);

		$this->assertSame(ImportJob::STATUS_DONE, $job->getStatus());
		$this->assertSame(2, $job->getTotal());
		$this->assertSame(1, $job->getDone());
		$this->assertSame(1, $job->getFailed());
		$this->assertSame(['carol@remote.example' => 'gone'], $job->getReport()['failed']);
		$this->assertSame(['f.csv'], $this->deleted, 'the kept upload is deleted once read');
		$this->assertSame('', $job->getFile());
		$this->assertSame(ImportJob::STATUS_RUNNING, $this->writes[0]->getStatus(), 'marked running first');
		$this->assertSame(ImportJob::STATUS_DONE, end($this->writes)->getStatus());
	}

	public function testRunHandsBookmarksToTheirImporter(): void {
		$job = $this->queuedRow(ImportJob::KIND_BOOKMARKS, 'b.csv');
		$this->kept['b.csv'] = "https://remote.example/users/carol/statuses/1\n";
		$this->migrationService->expects($this->once())->method('importBookmarks')
			->with('alice', $this->kept['b.csv'], $this->isCallable())
			->willReturn(['bookmarked' => 1, 'skipped' => 0, 'failed' => []]);

		$this->service->run(42);

		$this->assertSame(ImportJob::STATUS_DONE, $job->getStatus());
		$this->assertSame(1, $job->getDone());
	}

	public function testRunHandsBlockedDomainsToTheirImporter(): void {
		$job = $this->queuedRow(ImportJob::KIND_DOMAIN_BLOCKS, 'd.csv');
		$this->kept['d.csv'] = "spam.example\n";
		$this->migrationService->expects($this->once())->method('importDomainBlocks')
			->with('alice', $this->kept['d.csv'], $this->isCallable())
			->willReturn(['blocked' => 1, 'skipped' => 0, 'failed' => []]);

		$this->service->run(42);

		$this->assertSame(1, $job->getDone());
	}

	public function testRunLeavesARowThatIsNotQueuedAlone(): void {
		$job = $this->queuedRow(ImportJob::KIND_FOLLOWS, 'f.csv');
		$job->setStatus(ImportJob::STATUS_DONE);
		$this->migrationService->expects($this->never())->method('importFollows');

		$this->service->run(42);

		$this->assertSame([], $this->writes);
	}

	/** A failure is a status and a reason on the row, never an exception out of a cron job. */
	public function testAFailedRunIsRecordedWithItsReasonAndTheUploadStillDeleted(): void {
		$job = $this->queuedRow(ImportJob::KIND_BLOCKS, 'b.csv');
		$this->kept['b.csv'] = "carol@remote.example\n";
		$this->migrationService->method('importBlocks')->willThrowException(new \RuntimeException('database went away'));

		$this->service->run(42);

		$this->assertSame(ImportJob::STATUS_FAILED, $job->getStatus());
		$this->assertSame('database went away', $job->getReport()['error']);
		$this->assertSame(['b.csv'], $this->deleted);
	}

	/**
	 * The archive importer opens files with ZipArchive, and appdata may be
	 * object storage with no path: the kept upload is copied to a temporary
	 * file for it, and the run is uncapped — the cap kept a request within
	 * its time limit, and a job has none.
	 */
	public function testRunCopiesAnArchiveToDiskAndImportsItWithoutACap(): void {
		$job = $this->queuedRow(ImportJob::KIND_POSTS, 'a.zip', ['fetch_media' => false]);
		$this->kept['a.zip'] = 'PK zip bytes';
		$temp = tempnam(sys_get_temp_dir(), 'social-import-copy');
		$this->tempManager->method('getTemporaryFile')->with('.zip')->willReturn($temp);
		$this->accountService->method('getActorFromUserId')->with('alice')->willReturn(new Person());
		$this->postImportService->expects($this->once())->method('import')
			->with($this->isInstanceOf(Person::class), $temp, false, 0, $this->isCallable())
			->willReturnCallback(function (Person $actor, string $path): array {
				$this->assertSame('PK zip bytes', file_get_contents($path));

				return ['imported' => 12, 'skipped' => 2, 'already' => 3, 'media' => 5, 'failed' => 1, 'total' => 18, 'capped' => false];
			});

		$this->service->run(42);

		$this->assertSame(ImportJob::STATUS_DONE, $job->getStatus());
		$this->assertSame(12, $job->getDone());
		$this->assertSame(5, $job->getSkipped(), 'skipped and already-here are both nothing new');
		$this->assertSame(1, $job->getFailed());
		$this->assertSame(5, $job->getReport()['media']);
		@unlink($temp);
	}

	/** Progress is written every few items, not on every one. */
	public function testProgressIsWrittenInBatches(): void {
		$job = $this->queuedRow(ImportJob::KIND_MUTES, 'm.csv');
		$this->kept['m.csv'] = 'x';
		$this->migrationService->method('importMutes')
			->willReturnCallback(function (string $userId, string $csv, callable $progress): array {
				for ($i = 0; $i <= 25; $i++) {
					$progress($i, 25);
				}

				return ['muted' => 25, 'skipped' => 0, 'failed' => []];
			});

		$this->service->run(42);

		// running + two batches of ten + done: not twenty-six writes
		$this->assertLessThanOrEqual(5, count($this->writes));
		$this->assertSame(25, $job->getTotal());
	}

	/** A move-in has no file: the run reads the old server, and the report is the two tallies in one. */
	public function testRunHandsAMoveInToItsServiceAndSumsTheTwoTallies(): void {
		$job = $this->queuedRow(ImportJob::KIND_MOVE_IN, '', ['source' => 'https://old.example/users/alice', 'follows' => true, 'posts' => true]);
		$this->accountService->method('getActorFromUserId')->with('alice')->willReturn(new Person());
		$this->moveInService->expects($this->once())->method('run')
			->with($this->isInstanceOf(Person::class), $job->getOptions(), $this->isCallable())
			->willReturn([
				'followed' => 40, 'skipped' => 2, 'failed' => 1, 'failures' => ['x' => 'gone'],
				'imported' => 300, 'already' => 10, 'posts_skipped' => 5, 'posts_failed' => 2, 'media' => 120,
				'following_readable' => true, 'posts_readable' => true,
			]);

		$this->service->run(42);

		$this->assertSame(ImportJob::STATUS_DONE, $job->getStatus());
		$this->assertSame(340, $job->getDone());
		$this->assertSame(17, $job->getSkipped());
		$this->assertSame(3, $job->getFailed());
		$this->assertSame(120, $job->getReport()['media']);
		$this->assertSame([], $this->deleted, 'nothing was kept, so nothing is deleted');
	}

	// dismiss()

	public function testDismissTakesOnlyTheOwnersFinishedImports(): void {
		$done = (new ImportJob())->setId(1)->setUserId('alice')->setStatus(ImportJob::STATUS_DONE);
		$running = (new ImportJob())->setId(2)->setUserId('alice')->setStatus(ImportJob::STATUS_RUNNING);
		$bobs = (new ImportJob())->setId(3)->setUserId('bob')->setStatus(ImportJob::STATUS_DONE);
		$this->importsRequest->method('getById')->willReturnMap([[1, $done], [2, $running], [3, $bobs], [4, null]]);
		$this->importsRequest->method('delete')->with(1)->willReturn(true);

		$this->assertTrue($this->service->dismiss('alice', 1));
		$this->assertFalse($this->service->dismiss('alice', 2));
		$this->assertFalse($this->service->dismiss('alice', 3));
		$this->assertFalse($this->service->dismiss('alice', 4));
	}
}
