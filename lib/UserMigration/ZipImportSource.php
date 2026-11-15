<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\UserMigration;

use OCP\Files\Folder;
use OCP\UserMigration\IImportSource;
use OCP\UserMigration\UserMigrationException;
use ZipArchive;

/**
 * A zip file that `SocialMigrator` can import from.
 *
 * The counterpart of `ZipExportDestination`, and deliberately forgiving about
 * where the archive came from: the file names are the migrator's own, so an
 * archive written by `occ user:export` holds the Social data at the same paths
 * as one downloaded from the Migration page, and both are read here.
 *
 * The versions are read from the manifest this app writes. An archive that has
 * Social data but no manifest — Nextcloud's own export, whose versions live in
 * a file this class does not pretend to know — is read as version 1, which is
 * what such an archive was written by. Refusing it instead would mean telling
 * somebody that the file the server itself produced is not importable.
 */
class ZipImportSource implements IImportSource {
	public const MANIFEST = 'social/export.json';

	/**
	 * The largest file of the archive read whole: the profile, the lists
	 * and the posts are text, and 128 MB of it is far more than any account
	 * writes. Media is read as a stream (`getFileAsStream()`) and bounded
	 * where it is stored.
	 */
	public const MAX_FILE_BYTES = 128 * 1024 * 1024;

	/** @var array<string, int>|null */
	private ?array $versions = null;

	public function __construct(
		private ZipArchive $zip,
		private string $originalUid = '',
		private int $maxFileBytes = self::MAX_FILE_BYTES,
	) {
	}

	/**
	 * The file, read no further than `MAX_FILE_BYTES`: the size the archive
	 * states is checked first, and the read is bounded as well, since that
	 * statement is the archive's own.
	 */
	#[\Override]
	public function getFileContents(string $path): string {
		$stat = $this->zip->statName($path);
		if ($stat === false) {
			throw new UserMigrationException($path . ' is not in this archive');
		}
		if ((int)$stat['size'] > $this->maxFileBytes) {
			throw $this->tooLarge($path);
		}

		$contents = $this->zip->getFromName($path);
		if ($contents === false) {
			throw new UserMigrationException($path . ' is not in this archive');
		}
		if (strlen($contents) > $this->maxFileBytes) {
			throw $this->tooLarge($path);
		}

		return $contents;
	}

	private function tooLarge(string $path): UserMigrationException {
		return new UserMigrationException($path . ' is larger than the ' . $this->maxFileBytes . ' bytes an archive file may be');
	}

	#[\Override]
	public function getFileAsStream(string $path) {
		$stream = $this->zip->getStream($path);
		if ($stream === false) {
			throw new UserMigrationException($path . ' is not in this archive');
		}

		return $stream;
	}

	/** @return string[] */
	#[\Override]
	public function getFolderListing(string $path): array {
		$prefix = rtrim($path, '/') . '/';
		$names = [];
		for ($i = 0; $i < $this->zip->numFiles; $i++) {
			$name = $this->zip->getNameIndex($i);
			if ($name === false || !str_starts_with($name, $prefix)) {
				continue;
			}

			// one level down only, which is what the interface asks for
			$rest = substr($name, strlen($prefix));
			if ($rest !== '' && !str_contains($rest, '/')) {
				$names[] = $rest;
			}
		}

		return $names;
	}

	#[\Override]
	public function pathExists(string $path): bool {
		return $this->zip->locateName($path) !== false;
	}

	#[\Override]
	public function copyToFolder(Folder $destination, string $sourcePath): void {
		throw new UserMigrationException(
			'this archive carries only the app\'s own data; there is no folder to copy'
		);
	}

	/** @return array<string, int> */
	#[\Override]
	public function getMigratorVersions(): array {
		if ($this->versions !== null) {
			return $this->versions;
		}

		$this->versions = [];
		$manifest = $this->zip->getFromName(self::MANIFEST);
		if ($manifest !== false) {
			/** @var array<string, mixed>|null $data */
			$data = json_decode($manifest, true);
			$versions = is_array($data) ? ($data['versions'] ?? []) : [];
			if (is_array($versions)) {
				foreach ($versions as $migrator => $version) {
					if (is_string($migrator) && is_numeric($version)) {
						$this->versions[$migrator] = (int)$version;
					}
				}
			}
		}

		return $this->versions;
	}

	#[\Override]
	public function getMigratorVersion(string $migrator): ?int {
		$versions = $this->getMigratorVersions();
		if (array_key_exists($migrator, $versions)) {
			return $versions[$migrator];
		}

		// an archive that holds the data but names no version was written by
		// something that did not write this manifest — the server's own
		// exporter — and that is version 1 of this migrator
		return $this->pathExists(SocialMigrator::PATH_ACTOR_PUBLIC) ? 1 : null;
	}

	#[\Override]
	public function getOriginalUid(): string {
		if ($this->originalUid !== '') {
			return $this->originalUid;
		}

		$manifest = $this->zip->getFromName(self::MANIFEST);
		if ($manifest !== false) {
			/** @var array<string, mixed>|null $data */
			$data = json_decode($manifest, true);
			$uid = is_array($data) ? ($data['uid'] ?? '') : '';
			if (is_string($uid)) {
				return $uid;
			}
		}

		return '';
	}

	#[\Override]
	public function close(): void {
		// the service that opened the zip closes it
	}
}
