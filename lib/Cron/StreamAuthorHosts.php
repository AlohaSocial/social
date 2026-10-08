<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\DomainBlocksRequestBuilder;
use OCA\Social\Service\ConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Fills in `social_stream.author_host` for the posts stored before it.
 *
 * Queued by `Version1000Date20261008000503` rather than run inside it:
 * `social_stream` is the largest table this app has, and an upgrade that
 * walks all of it keeps the instance in maintenance mode for as long as that
 * takes. Every post stored since writes its own host, so one walk of the
 * table by `nid` reaches every row that has none.
 *
 * **Why it re-queues itself.** Like `DomainPurge`: a cron run is a slot
 * shared with every other job, so it does a bounded amount, then puts itself
 * back on the list with the `nid` it got to. When a page comes back short it
 * has reached the end, and it sets `stream_author_hosts_filled`, which is
 * what lets the domain-block and silenced-instance filters stop matching a
 * row by its actor id as well as by its host.
 */
class StreamAuthorHosts extends QueuedJob {
	/** Rows read per page. */
	public const PAGE = 1000;

	/** Pages per run: a hundred thousand rows, a few seconds of work. */
	public const PAGES_PER_RUN = 100;

	public function __construct(
		ITimeFactory $time,
		private IDBConnection $connection,
		private ConfigService $configService,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run($argument): void {
		$after = is_array($argument) ? (string)($argument['after'] ?? '0') : '0';

		try {
			$next = $this->fill($after, self::PAGES_PER_RUN);
		} catch (\Throwable $e) {
			// a failed pass is retried from where it started: what it wrote is
			// kept, and a row written twice is written with the same host
			$this->logger->warning('[Cron\StreamAuthorHosts] could not fill in author hosts', [
				'after' => $after, 'exception' => $e,
			]);
			$this->jobList->add(self::class, ['after' => $after]);

			return;
		}

		if ($next === null) {
			$this->configService->setAppValue(ConfigService::SOCIAL_STREAM_AUTHOR_HOSTS_FILLED, '1');

			return;
		}

		$this->jobList->add(self::class, ['after' => $next]);
	}

	/**
	 * Fills in up to `$pages` pages of rows after a nid.
	 *
	 * @return ?string the nid to resume after, or null once the end is reached
	 */
	public function fill(string $after, int $pages): ?string {
		// `*PREFIX*` is what the connection rewrites; there is no public API
		// that hands the prefix over as a string
		$table = '*PREFIX*' . CoreRequestBuilder::TABLE_STREAM;

		for ($page = 0; $page < $pages; $page++) {
			// a plain range on the primary key: asking for `author_host IS
			// NULL` here as well would invite the planner to read the host
			// index instead and sort every NULL row of the table for one page
			$rows = $this->connection->executeQuery(
				'SELECT `nid`, `attributed_to`, `author_host` FROM `' . $table . '`'
				. ' WHERE `nid` > ?'
				. ' ORDER BY `nid` ASC LIMIT ' . self::PAGE,
				[$after]
			)->fetchAll();

			// one statement per host rather than per row: a page is mostly a
			// few instances. An id with no host is written as '' rather than
			// left NULL, which the filters read as not reached yet
			$byHost = [];
			foreach ($rows as $row) {
				$after = (string)$row['nid'];
				if ($row['author_host'] !== null) {
					continue;
				}
				$byHost[DomainBlocksRequestBuilder::authorHostOf((string)($row['attributed_to'] ?? ''))][] = $after;
			}

			foreach ($byHost as $host => $nids) {
				$this->connection->executeStatement(
					'UPDATE `' . $table . '` SET `author_host` = ?'
					. ' WHERE `author_host` IS NULL AND `nid` IN (' . implode(', ', array_fill(0, count($nids), '?')) . ')',
					array_merge([strval($host)], $nids)
				);
			}

			if (count($rows) < self::PAGE) {
				return null;
			}
		}

		return $after;
	}
}
