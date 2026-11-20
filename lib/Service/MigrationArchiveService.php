<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\MoveErrorException;
use OCA\Social\UserMigration\SocialMigrator;
use OCA\Social\UserMigration\ZipExportDestination;
use OCA\Social\UserMigration\ZipImportSource;
use OCP\ITempManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\UserMigration\UserMigrationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;
use ZipArchive;

/**
 * The account's own data, out of the app and back into it: drives
 * `SocialMigrator` into a zip file the user can download, and reads one back,
 * without needing the user migration app. The migrator decides what travels
 * (never the private key); this adds only the manifest.
 */
class MigrationArchiveService {
	public const MANIFEST = ZipImportSource::MANIFEST;

	public function __construct(
		private SocialMigrator $migrator,
		private IUserManager $userManager,
		private ITempManager $tempManager,
		private ConfigService $configService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Writes one account's Social data to a zip file.
	 *
	 * @param string $userId whose data
	 * @return string the path of the finished archive, in the temp directory
	 * @throws UserMigrationException|ItemUnknownException
	 */
	public function export(string $userId): string {
		$user = $this->user($userId);
		$path = $this->tempManager->getTemporaryFile('.zip');
		if ($path === false) {
			throw new UserMigrationException('could not make a file for the archive');
		}

		$zip = new ZipArchive();
		// OVERWRITE as well as CREATE: getTemporaryFile() has already made the
		// file, and CREATE alone on an existing empty file is fine — but a
		// second export into a reused path must not append to the first
		if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
			throw new UserMigrationException('could not open the archive for writing');
		}

		$destination = new ZipExportDestination($zip, $this->tempManager);
		$output = new BufferedOutput();

		try {
			$this->migrator->export($user, $destination, $output);
			$destination->setMigratorVersions([$this->migrator->getId() => $this->migrator->getVersion()]);
			$zip->addFromString(self::MANIFEST, $this->manifest($userId, $destination->getMigratorVersions()));
		} catch (Throwable $e) {
			$zip->close();
			$this->logger->warning('Social export failed', ['userId' => $userId, 'exception' => $e]);

			throw new UserMigrationException('the export failed: ' . $e->getMessage(), 0, $e);
		}

		$zip->close();

		return $path;
	}

	/**
	 * Reads an archive back into one account.
	 *
	 * @param string $userId who is importing
	 * @param string $path the uploaded archive
	 * @return string[] what the migrator said it did, line by line
	 * @throws MoveErrorException archive-unreadable or archive-not-social for a file that is not this app's archive
	 * @throws UserMigrationException|ItemUnknownException
	 */
	public function import(string $userId, string $path): array {
		$user = $this->user($userId);

		$zip = new ZipArchive();
		if ($zip->open($path, ZipArchive::RDONLY) !== true) {
			throw new MoveErrorException('this file is not a readable archive', 'archive-unreadable');
		}

		$source = new ZipImportSource($zip, $userId);
		if (!$source->pathExists(SocialMigrator::PATH_ACTOR_PUBLIC)) {
			$zip->close();

			// said plainly: the likeliest mistake is picking the wrong zip
			throw new MoveErrorException(
				'this archive holds no Aloha Social data (' . SocialMigrator::PATH_ACTOR_PUBLIC . ' is not in it)',
				'archive-not-social'
			);
		}

		$output = new BufferedOutput();
		try {
			$this->migrator->import($user, $source, $output);
		} catch (Throwable $e) {
			$zip->close();
			$this->logger->warning('Social import failed', ['userId' => $userId, 'exception' => $e]);

			throw new UserMigrationException('the import failed: ' . $e->getMessage(), 0, $e);
		}

		$zip->close();

		return array_values(array_filter(
			array_map('trim', explode("\n", $output->fetch())),
			static fn (string $line): bool => $line !== ''
		));
	}

	/**
	 * What the downloaded file is called, with the account and the day.
	 *
	 * @param string $userId whose archive
	 * @return string the file name
	 */
	public function filename(string $userId): string {
		$safe = preg_replace('/[^A-Za-z0-9._-]/', '-', $userId) ?? 'account';

		return 'social-' . $safe . '-' . date('Y-m-d') . '.zip';
	}

	/**
	 * @param string $userId whose archive
	 * @param array<string, int> $versions what the migrators reported
	 * @return string the manifest, as JSON
	 */
	private function manifest(string $userId, array $versions): string {
		return json_encode([
			'app' => 'social',
			'appVersion' => $this->configService->getAppValue('installed_version'),
			'uid' => $userId,
			'versions' => $versions,
			'exportedAt' => gmdate('Y-m-d\TH:i:s\Z'),
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
	}

	/**
	 * @param string $userId the account
	 * @return IUser the user, which the migrator needs rather than an id
	 * @throws ItemUnknownException
	 */
	private function user(string $userId): IUser {
		$user = $this->userManager->get($userId);
		if ($user === null) {
			throw new ItemUnknownException('unknown account');
		}

		return $user;
	}
}
