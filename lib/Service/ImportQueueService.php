<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Cron\RunImport;
use OCA\Social\Db\ImportsRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ImportJob;
use OCP\BackgroundJob\IJobList;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The imports an account asks for, run in the background and watched.
 *
 * Every import used to run inside the request that uploaded the file. Each
 * follow in a `following_accounts.csv` is a WebFinger lookup, an actor fetch
 * and a delivery; a few hundred of them ran into the web server's timeout,
 * and the rate limit that kept the server safe from that meant a timed-out
 * upload could not be tried again for an hour. A post import was the same
 * with a picture fetched per post, capped at two thousand a run and "upload
 * the same file again to carry on".
 *
 * Now the upload is kept in appdata, a row says what was asked, and
 * `Cron\RunImport` works it off on the next cron run with no request to
 * outlive, writing where it got to as it goes. The page polls the row. One
 * import of a kind at a time per account: a second press of the same button
 * while the first runs is refused rather than queued twice.
 *
 * The importers themselves are unchanged — the same `MigrationService` and
 * `PostImportService` calls, handed a progress callback — so what an import
 * does, and what it federates, is decided where it always was.
 */
class ImportQueueService {
	/** Where the uploads wait, under this app's appdata. */
	private const FOLDER = 'imports';
	/** How many items between two writes of the progress, at most. */
	private const PROGRESS_EVERY = 10;
	/** How many seconds between two writes of the progress, at most. */
	private const PROGRESS_SECONDS = 2;
	/** How many failed entries a report keeps by name; the count is kept whole. */
	public const REPORT_FAILURES = 50;

	public function __construct(
		private ImportsRequest $importsRequest,
		private IAppData $appData,
		private IJobList $jobList,
		private ITempManager $tempManager,
		private MigrationService $migrationService,
		private PostImportService $postImportService,
		private AccountService $accountService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Queues one import: the uploaded file, if there is one, is kept until the
	 * run has read it, and the job that runs it is added for the next cron.
	 *
	 * @param string|null $uploadPath the uploaded file as PHP left it, or null for an import with no file
	 * @param array<string, mixed> $options what the run should know: `fetch_media`, a source account
	 *
	 * @throws InvalidResourceException for a kind this does not run, or one already under way
	 */
	public function queue(string $userId, string $kind, ?string $uploadPath, array $options = []): ImportJob {
		if (!in_array($kind, ImportJob::KINDS, true)) {
			throw new InvalidResourceException('"' . $kind . '" is not something that can be imported');
		}
		if ($this->importsRequest->hasActive($userId, $kind)) {
			throw new InvalidResourceException('an import of this kind is already running; wait for it to finish');
		}

		$job = new ImportJob();
		$job->setUserId($userId)
			->setKind($kind)
			->setStatus(ImportJob::STATUS_QUEUED)
			->setOptions($options);

		if ($uploadPath !== null) {
			$job->setFile($this->keep($uploadPath));
		}

		$this->importsRequest->create($job);
		$this->jobList->add(RunImport::class, ['import' => $job->getId()]);

		return $job;
	}

	public function hasActive(string $userId, string $kind): bool {
		return $this->importsRequest->hasActive($userId, $kind);
	}

	/** @return ImportJob[] one account's imports, newest first */
	public function listFor(string $userId): array {
		return $this->importsRequest->getByUser($userId);
	}

	/**
	 * Takes a finished import off the list. One that is still running stays:
	 * its job would write to a row that is gone.
	 */
	public function dismiss(string $userId, int $id): bool {
		$job = $this->importsRequest->getById($id);
		if ($job === null || $job->getUserId() !== $userId || $job->isActive()) {
			return false;
		}

		$this->forget($job);

		return $this->importsRequest->delete($id);
	}

	/**
	 * Runs one queued import to its end, whatever that is, and records it.
	 *
	 * A row that is not queued is left alone: the job ran already, or
	 * somebody dismissed it. A failure is a status and a reason on the row,
	 * never an exception out of a cron job.
	 */
	public function run(int $id): void {
		$job = $this->importsRequest->getById($id);
		if ($job === null || $job->getStatus() !== ImportJob::STATUS_QUEUED) {
			return;
		}

		$job->setStatus(ImportJob::STATUS_RUNNING);
		$this->importsRequest->update($job);

		try {
			$this->execute($job);
			$job->setStatus(ImportJob::STATUS_DONE);
		} catch (Throwable $e) {
			$this->logger->warning('[ImportQueueService] an import failed', [
				'import' => $id, 'kind' => $job->getKind(), 'user' => $job->getUserId(), 'exception' => $e,
			]);
			$job->setStatus(ImportJob::STATUS_FAILED);
			$job->setReport(array_merge($job->getReport(), ['error' => $e->getMessage()]));
		} finally {
			$this->forget($job);
			$this->importsRequest->update($job);
		}
	}

	private function execute(ImportJob $job): void {
		$userId = $job->getUserId();
		$progress = $this->progressOf($job);

		switch ($job->getKind()) {
			case ImportJob::KIND_FOLLOWS:
				$result = $this->migrationService->importFollows($userId, $this->contents($job), $progress);
				$this->tally($job, $result['followed'], $result['skipped'], $result['failed']);
				break;

			case ImportJob::KIND_BLOCKS:
				$result = $this->migrationService->importBlocks($userId, $this->contents($job), $progress);
				$this->tally($job, $result['blocked'], $result['skipped'], $result['failed']);
				break;

			case ImportJob::KIND_MUTES:
				$result = $this->migrationService->importMutes($userId, $this->contents($job), $progress);
				$this->tally($job, $result['muted'], $result['skipped'], $result['failed']);
				break;

			case ImportJob::KIND_LISTS:
				$result = $this->migrationService->importLists($userId, $this->contents($job), $progress);
				$this->tally($job, $result['added'], $result['skipped'], $result['failed']);
				$job->setReport(array_merge($job->getReport(), ['lists' => $result['lists']]));
				break;

			case ImportJob::KIND_POSTS:
				$actor = $this->accountService->getActorFromUserId($userId);
				$path = $this->temporaryCopy($job);
				// no cap: the cap kept a request within its time limit, and a
				// job has none; the whole archive is one run
				$tally = $this->postImportService->import(
					$actor, $path, $this->fetchMedia($job), 0, $progress
				);
				$job->setDone($tally['imported'])
					->setSkipped($tally['skipped'] + $tally['already'])
					->setFailed($tally['failed'])
					->setReport($tally);
				break;

			default:
				throw new InvalidResourceException('"' . $job->getKind() . '" is not something that can be imported');
		}
	}

	/**
	 * @param array<string, string> $failed handle => reason
	 */
	private function tally(ImportJob $job, int $done, int $skipped, array $failed): void {
		$job->setDone($done)->setSkipped($skipped)->setFailed(count($failed));
		$job->setReport(array_merge($job->getReport(), [
			'failed' => array_slice($failed, 0, self::REPORT_FAILURES, true),
		]));
	}

	/**
	 * The callback an importer reports to after each item: the row is written
	 * every few items or every couple of seconds, whichever comes first, so a
	 * page polling it sees movement without the database seeing a write per
	 * follow.
	 *
	 * @return callable(int, int): void
	 */
	private function progressOf(ImportJob $job): callable {
		$lastCount = 0;
		$lastTime = time();

		return function (int $done, int $total) use ($job, &$lastCount, &$lastTime): void {
			$job->setTotal($total)->setDone($done);
			if ($done - $lastCount >= self::PROGRESS_EVERY || time() - $lastTime >= self::PROGRESS_SECONDS) {
				$this->importsRequest->update($job);
				$lastCount = $done;
				$lastTime = time();
			}
		};
	}

	private function fetchMedia(ImportJob $job): bool {
		return (bool)($job->getOptions()['fetch_media'] ?? true);
	}

	/** Copies the upload into appdata; the name it gets there is what the row keeps. */
	private function keep(string $uploadPath): string {
		$extension = strtolower(pathinfo($uploadPath, PATHINFO_EXTENSION));
		$name = bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . $extension : '');

		$stream = fopen($uploadPath, 'rb');
		if ($stream === false) {
			throw new InvalidResourceException('the uploaded file could not be read');
		}
		try {
			$this->folder()->newFile($name, $stream);
		} finally {
			fclose($stream);
		}

		return $name;
	}

	/** The kept upload, read whole: the CSV and JSON lists are small. */
	private function contents(ImportJob $job): string {
		if ($job->getFile() === '') {
			throw new InvalidResourceException('this import has no file to read');
		}

		return $this->folder()->getFile($job->getFile())->getContent();
	}

	/**
	 * The kept upload as a file on disk, for the importer that opens archives
	 * with `ZipArchive`: appdata may be object storage, which has no path.
	 */
	private function temporaryCopy(ImportJob $job): string {
		if ($job->getFile() === '') {
			throw new InvalidResourceException('this import has no file to read');
		}

		$extension = pathinfo($job->getFile(), PATHINFO_EXTENSION);
		$path = $this->tempManager->getTemporaryFile($extension !== '' ? '.' . $extension : '');
		if ($path === false) {
			throw new InvalidResourceException('no room for a temporary copy of the upload');
		}

		$source = $this->folder()->getFile($job->getFile())->read();
		$target = fopen($path, 'wb');
		if ($source === false || $target === false) {
			throw new InvalidResourceException('the kept upload could not be read');
		}
		try {
			stream_copy_to_stream($source, $target);
		} finally {
			fclose($source);
			fclose($target);
		}

		return $path;
	}

	/** Deletes the kept upload, once the run has read it or nobody will. */
	private function forget(ImportJob $job): void {
		if ($job->getFile() === '') {
			return;
		}

		try {
			$this->folder()->getFile($job->getFile())->delete();
		} catch (Throwable $e) {
			// already gone, or appdata is having a moment: the row still says
			// what happened, and a stray file is the only cost
			$this->logger->debug('[ImportQueueService] could not delete a kept upload', [
				'file' => $job->getFile(), 'exception' => $e,
			]);
		}
		$job->setFile('');
	}

	private function folder(): ISimpleFolder {
		try {
			return $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException $e) {
			return $this->appData->newFolder(self::FOLDER);
		}
	}
}
