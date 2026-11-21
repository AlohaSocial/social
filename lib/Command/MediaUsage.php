<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MediaUsageService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What the app's media is costing, and how much of it is somebody else's:
 * uploads here are the only copy, cached copies of other servers' media can
 * be fetched again (see `cache_actor_days` and `CacheActorSweepService`).
 *
 * Rows come from `social_cache_doc` and sizes from the files in appdata; a
 * row whose file is gone is reported as such. Appdata is not walked for
 * files no row names, since nothing could safely delete them. No quota
 * accounting: this is what is on disk.
 */
class MediaUsage extends SocialCommand {
	/** Rows read per query; the work is a file lookup per row, not the read. */
	private const PAGE = 500;

	public function __construct(
		private MediaUsageService $mediaUsageService,
		private ConfigService $configService,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:media:usage')
			->setDescription('Report what local uploads and cached remote media occupy on disk');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$this->configService->getCloudUrl();
		} catch (SocialAppConfigException $e) {
			$output->writeln(
				'<error>The Aloha Social app is not configured, so a document id cannot be told from'
				. ' a remote one: ' . $e->getMessage() . '</error>'
			);

			return 1;
		}

		$usage = $this->mediaUsageService->measureAndStore();

		if ($input->getOption('output') !== self::OUTPUT_FORMAT_PLAIN) {
			$this->writeArrayInOutputFormat($input, $output, $usage, '');

			return 0;
		}

		$this->report($output, $usage);

		return 0;
	}

	/**
	 * Walks every document row and adds its copies up.
	 *
	 * @return array{
	 *     local: array{attachments: array{files: int, bytes: int}, avatars: array{files: int, bytes: int}},
	 *     remote: array{attachments: array{files: int, bytes: int}, avatars: array{files: int, bytes: int}},
	 *     rows: int, streamed: int, missing: int, elsewhere: int,
	 *     files: int, bytes: int
	 * }
	 */

	/**
	 * @param array<string, mixed> $usage
	 */
	private function report(OutputInterface $output, array $usage): void {
		$output->writeln($usage['rows'] . ' document row(s) in social_cache_doc');
		$output->writeln('');

		foreach (['local' => 'Uploaded here', 'remote' => 'Cached from other servers'] as $side => $title) {
			$files = $usage[$side]['attachments']['files'] + $usage[$side]['avatars']['files'];
			$bytes = $usage[$side]['attachments']['bytes'] + $usage[$side]['avatars']['bytes'];

			$output->writeln(sprintf('<info>%-28s</info> %6d files  %10s', $title, $files, self::human($bytes)));
			$output->writeln(sprintf(
				'  %-26s %6d files  %10s', 'attachments',
				$usage[$side]['attachments']['files'], self::human($usage[$side]['attachments']['bytes'])
			));
			$output->writeln(sprintf(
				'  %-26s %6d files  %10s', 'avatars and headers',
				$usage[$side]['avatars']['files'], self::human($usage[$side]['avatars']['bytes'])
			));
		}

		$output->writeln('');
		$output->writeln(sprintf(
			'<info>%-28s</info> %6d files  %10s', 'Total on disk', $usage['files'], self::human($usage['bytes'])
		));

		if ($usage['streamed'] > 0) {
			$output->writeln(
				$usage['streamed'] . ' copy/copies are streamed from the server that holds them, so they cost nothing here'
			);
		}
		if ($usage['elsewhere'] > 0) {
			$output->writeln(
				$usage['elsewhere'] . ' copy/copies are served from Nextcloud\'s own avatar store rather than from this app'
			);
		}
		if (($usage['evicted'] ?? 0) > 0) {
			$output->writeln(
				$usage['evicted'] . ' cached remote copy/copies are not on disk (purged, or gone); each is'
				. ' fetched again when it is next viewed'
			);
		}
		if ($usage['missing'] > 0) {
			$output->writeln(
				'<comment>' . $usage['missing'] . ' copy/copies of uploads from here are named by a row but are'
				. ' not in appdata</comment>'
			);
		}
	}

	/**
	 * Bytes as an administrator reads them, in powers of two like `du` and
	 * the Files app.
	 */
	public static function human(int $bytes): string {
		$units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
		$value = (float)$bytes;
		$unit = 0;
		while ($value >= 1024.0 && $unit < 4) {
			$value = $value / 1024.0;
			$unit++;
		}

		return ($unit === 0)
			? $bytes . ' B'
			: sprintf('%.1f %s', $value, $units[$unit]);
	}
}
