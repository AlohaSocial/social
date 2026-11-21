<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Stream;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** Restore media from a remote post's stored, already received ActivityPub object. */
class MediaRecover extends SocialCommand {
	private const PAGE = 50;

	public function __construct(
		private StreamRequest $streamRequest,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:media:recover')
			->setDescription('Recover missing attachments from stored remote posts')
			->addOption('dry-run', '', InputOption::VALUE_NONE, 'show affected posts without fetching media')
			->addOption('limit', '', InputOption::VALUE_REQUIRED, 'maximum posts to examine (0 means all)', '0');
	}

	/**
	 * The attachments a stored post's source names. ActivityPub sends one
	 * attachment either as a list of one or as the object itself; both are read.
	 *
	 * @param string $source the post as its sender sent it
	 *
	 * @return array<mixed> the attachments, always as a list
	 */
	private function attachmentsOf(string $source): array {
		$decoded = json_decode($source, true);
		if (!is_array($decoded)) {
			return [];
		}

		$items = $decoded['attachment'] ?? [];
		if (!is_array($items) || $items === []) {
			return [];
		}

		return array_is_list($items) ? $items : [$items];
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$dryRun = (bool)$input->getOption('dry-run');
		$limit = max(0, (int)$input->getOption('limit'));
		$examined = 0;
		$affected = 0;
		$recovered = 0;
		$skipped = 0;
		$failed = 0;
		$after = '0';

		while ($limit === 0 || $examined < $limit) {
			$page = $this->streamRequest->getMissingRemoteAttachments(
				$limit === 0 ? self::PAGE : min(self::PAGE, $limit - $examined),
				$after,
			);
			if ($page === []) {
				break;
			}

			foreach ($page as $row) {
				$after = $row['nid'];
				$examined++;
				$items = $this->attachmentsOf($row['source']);
				if ($items === []) {
					continue;
				}
				$affected++;
				if ($dryRun) {
					$output->writeln('would recover ' . $row['id'] . ' (' . count($items) . ' attachment(s))');
					continue;
				}

				try {
					$stream = new Stream();
					$stream->setId($row['id']);
					$stream->importAttachments($items);
					$attachments = array_map(
						static fn ($attachment) => $attachment->asLocal(),
						$stream->getAttachments(),
					);
					if ($attachments === []) {
						$failed++;
						$output->writeln('<comment>No usable media in ' . $row['id'] . '</comment>');
						continue;
					}
					$encoded = json_encode($attachments, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
					if ($this->streamRequest->setRecoveredRemoteAttachments($row['id'], $encoded, $row['subtype'])) {
						$recovered++;
						$output->writeln('<info>Recovered ' . count($attachments) . ' attachment(s): ' . $row['id'] . '</info>');
					} else {
						// the write only touches a post whose attachments are
						// still empty, so nothing changed means something else
						// filled them meanwhile; counted apart from failures
						$skipped++;
						$output->writeln('<comment>Already filled, left alone: ' . $row['id'] . '</comment>');
					}
				} catch (Throwable $e) {
					$failed++;
					$output->writeln('<comment>Could not recover ' . $row['id'] . ': ' . $e->getMessage() . '</comment>');
				}
			}
		}

		$output->writeln(
			$affected . ' affected post(s), ' . $recovered . ' recovered, '
			. $skipped . ' already filled, ' . $failed . ' failed'
		);

		return $failed === 0 ? 0 : 1;
	}
}
