<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Service\MediaPurgeService;
use OCP\Util;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Removes cached copies of other servers' media now, rather than waiting for
 * the daily purge in `Cron\MediaSweep`.
 *
 * Without `--older-than`, `--max-size` or `--all` it applies the two limits
 * the administrator configured, which is what the job does. With any of them
 * it applies only what was given. A purged copy is fetched again when somebody
 * next looks at it; local uploads, local avatars and banners, the GIF pack and
 * custom emoji are never touched. See `MediaPurgeService::purgeCache()`.
 */
class MediaPurge extends SocialCommand {
	public function __construct(
		private MediaPurgeService $mediaPurgeService,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:media:purge')
			->setDescription(
				'Delete cached copies of other servers\' media (fetched again when next viewed;'
				. ' local uploads are never touched)'
			)
			->addOption(
				'older-than', null, InputOption::VALUE_REQUIRED,
				'delete copies cached more than this many days ago'
			)
			->addOption(
				'max-size', null, InputOption::VALUE_REQUIRED,
				'delete the oldest copies until the cache fits in this size (e.g. 5G, 500M)'
			)
			->addOption('all', null, InputOption::VALUE_NONE, 'delete every cached copy')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'only count what would be deleted');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$olderThan = $input->getOption('older-than');
		$maxSize = $input->getOption('max-size');
		$all = (bool)$input->getOption('all');
		$dryRun = (bool)$input->getOption('dry-run');

		$days = 0;
		if ($olderThan !== null) {
			if (!ctype_digit((string)$olderThan) || (int)$olderThan < 1) {
				$output->writeln('<error>--older-than takes a whole number of days, 1 or more</error>');

				return 1;
			}
			$days = (int)$olderThan;
		}

		$bytes = 0;
		if ($maxSize !== null) {
			$bytes = self::parseSize((string)$maxSize);
			if ($bytes === null) {
				$output->writeln('<error>--max-size takes a size such as 5G, 500M or 1024 (bytes), more than 0</error>');

				return 1;
			}
		}

		if ($all && ($olderThan !== null || $maxSize !== null)) {
			$output->writeln('<error>--all removes every cached copy; it does not combine with --older-than or --max-size</error>');

			return 1;
		}

		try {
			if ($all || $olderThan !== null || $maxSize !== null) {
				$result = $this->mediaPurgeService->purgeCache($days, $bytes, $all, $dryRun);
			} else {
				$result = $this->mediaPurgeService->purgeCacheAsConfigured($dryRun);
				if ($result === null) {
					$output->writeln(
						'Both media cache limits are off (media_cache_days and media_cache_mb are 0):'
						. ' pass --older-than, --max-size or --all'
					);

					return 0;
				}
			}
		} catch (SocialAppConfigException $e) {
			$output->writeln(
				'<error>The Aloha Social app is not configured, so a local document cannot be told from'
				. ' a remote one: ' . $e->getMessage() . '</error>'
			);

			return 1;
		}

		if ($input->getOption('output') !== self::OUTPUT_FORMAT_PLAIN) {
			$this->writeArrayInOutputFormat($input, $output, $result, '');

			return 0;
		}

		$this->report($output, $result);

		return 0;
	}

	/**
	 * A size as an administrator types it: bytes, or a number with K, M, G or
	 * T (binary, with or without a trailing B or iB).
	 *
	 * @return int|null null for anything else, and for 0
	 */
	public static function parseSize(string $size): ?int {
		$size = preg_replace('/ib$/i', 'b', trim($size)) ?? '';
		if (preg_match('/^\d+(\.\d+)?\s*([kmgt]b?|b)?$/i', $size) !== 1) {
			return null;
		}

		$bytes = Util::computerFileSize(str_replace(' ', '', $size));
		if ($bytes === false || $bytes < 1) {
			return null;
		}

		return (int)$bytes;
	}

	/**
	 * @param array<string, mixed> $result
	 */
	private function report(OutputInterface $output, array $result): void {
		$output->writeln(sprintf(
			'<info>%s %d file(s), %s</info>',
			$result['dry_run'] ? 'Would delete' : 'Deleted',
			$result['files'], MediaUsage::human((int)$result['bytes'])
		));
		foreach (['attachments' => 'attachments', 'avatars' => 'avatars and headers'] as $kind => $title) {
			$output->writeln(sprintf(
				'  %-26s %6d files  %10s', $title,
				$result[$kind]['files'], MediaUsage::human((int)$result[$kind]['bytes'])
			));
		}

		if ($result['gone'] > 0) {
			$output->writeln(sprintf(
				'%d cached cop(y/ies) named in the database %s already gone from disk;'
				. ' %s marked to be fetched again when next viewed',
				$result['gone'], $result['gone'] === 1 ? 'was' : 'were',
				$result['dry_run'] ? 'they would be' : 'now'
			));
		}

		if ($result['cache_bytes'] !== null) {
			$output->writeln('Cache before: ' . MediaUsage::human((int)$result['cache_bytes'])
				. ($result['remaining_bytes'] !== null
					? ', after: ' . MediaUsage::human((int)$result['remaining_bytes']) : ''));
		}

		if (!$result['complete']) {
			$output->writeln('<comment>Stopped before the end; run it again to carry on</comment>');
		}
	}
}
