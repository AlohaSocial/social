<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\UserMigration;

use OCP\Files\Folder;
use OCP\ITempManager;
use OCP\UserMigration\IExportDestination;
use OCP\UserMigration\UserMigrationException;
use ZipArchive;

/**
 * A zip file that `SocialMigrator` can export into, so an archive from the
 * Migration section of Settings and one from `occ user:export` hold the
 * Social data in the same shape. Only what the migrator asks for is
 * implemented; `copyFolder()` throws rather than silently doing nothing.
 */
class ZipExportDestination implements IExportDestination {
	/** @var array<string, int> */
	private array $versions = [];

	public function __construct(
		private ZipArchive $zip,
		private ITempManager $tempManager,
	) {
	}

	#[\Override]
	public function addFileContents(string $path, string $content): void {
		if ($this->zip->addFromString($path, $content) === false) {
			throw new UserMigrationException('could not write ' . $path . ' to the archive');
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * Copied a chunk at a time to a temporary file the zip reads at `close()`,
	 * as `ZipArchive` needs a stream open until then and this one is the
	 * caller's; an attachment may be a video. `ITempManager` removes the file
	 * at the end of the request, after the archive is handed over.
	 */
	#[\Override]
	public function addFileAsStream(string $path, $stream): void {
		$tmpPath = $this->tempManager->getTemporaryFile();
		if ($tmpPath === false) {
			throw new UserMigrationException('could not make a temporary file for ' . $path);
		}

		$target = fopen($tmpPath, 'w');
		if (!is_resource($target)) {
			throw new UserMigrationException('could not open a temporary file for ' . $path);
		}

		try {
			if (stream_copy_to_stream($stream, $target) === false) {
				throw new UserMigrationException('could not read the contents for ' . $path);
			}
		} finally {
			fclose($target);
		}

		if ($this->zip->addFile($tmpPath, $path) === false) {
			throw new UserMigrationException('could not write ' . $path . ' to the archive');
		}
	}

	#[\Override]
	public function copyFolder(Folder $folder, string $destinationPath, ?callable $nodeFilter = null): void {
		throw new UserMigrationException(
			'this archive carries only the app\'s own data; there is no folder to copy'
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Kept until the archive is closed, and written then: the versions are the
	 * last thing the migrator reports, and a reader has to find them whether
	 * or not it knows this app.
	 */
	#[\Override]
	public function setMigratorVersions(array $versions): void {
		$this->versions = $versions;
	}

	/** @return array<string, int> what the migrators reported */
	public function getMigratorVersions(): array {
		return $this->versions;
	}

	#[\Override]
	public function close(): void {
		// nothing: the service that opened the zip closes it, because it is
		// the one that has to hand the finished file to the browser
	}
}
