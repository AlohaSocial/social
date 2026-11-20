<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\Details;
use OCA\Social\Tools\Model\Cache;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Removes from older rows the copies nothing reads back: the decoded `object`
 * beside `content` in `cache`, and the whole post or actor in a notification's
 * `details`. Newer rows are written without them (`CacheItem::jsonSerialize()`,
 * `TDetails::setDetailItem()`). Queued by `Version1000Date20261012000100`, it
 * walks `social_stream` by `nid` a bounded amount per run and re-queues itself
 * until a page comes back short.
 */
class CompactStoredCopies extends QueuedJob {
	/** Rows read per page; the columns read are the two large ones. */
	public const PAGE = 500;

	/** Pages per run. */
	public const PAGES_PER_RUN = 40;

	public function __construct(
		ITimeFactory $time,
		private IDBConnection $connection,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run($argument): void {
		$after = is_array($argument) ? (string)($argument['after'] ?? '0') : '0';

		try {
			$next = $this->compact($after, self::PAGES_PER_RUN);
		} catch (\Throwable $e) {
			// retried from where it started: a row compacted twice is
			// written the same the second time
			$this->logger->warning('[Cron\CompactStoredCopies] could not compact stored copies', [
				'after' => $after, 'exception' => $e,
			]);
			$this->jobList->add(self::class, ['after' => $after]);

			return;
		}

		if ($next !== null) {
			$this->jobList->add(self::class, ['after' => $next]);
		}
	}

	/**
	 * Compacts up to `$pages` pages of rows after a nid.
	 *
	 * @return ?string the nid to resume after, or null once the end is reached
	 */
	public function compact(string $after, int $pages): ?string {
		$table = '*PREFIX*' . CoreRequestBuilder::TABLE_STREAM;

		for ($page = 0; $page < $pages; $page++) {
			$rows = $this->connection->executeQuery(
				'SELECT `nid`, `type`, `cache`, `details` FROM `' . $table . '`'
				. ' WHERE `nid` > ?'
				. ' ORDER BY `nid` ASC LIMIT ' . self::PAGE,
				[$after]
			)->fetchAll();

			foreach ($rows as $row) {
				$after = (string)$row['nid'];
				$cache = self::compactCache((string)($row['cache'] ?? ''));
				$details = ($row['type'] === SocialAppNotification::TYPE)
					? self::compactNotificationDetails((string)($row['details'] ?? ''))
					: null;
				if ($cache === null && $details === null) {
					continue;
				}

				$set = [];
				$params = [];
				if ($cache !== null) {
					$set[] = '`cache` = ?';
					$params[] = $cache;
				}
				if ($details !== null) {
					$set[] = '`details` = ?';
					$params[] = $details;
				}
				$params[] = $after;
				$this->connection->executeStatement(
					'UPDATE `' . $table . '` SET ' . implode(', ', $set) . ' WHERE `nid` = ?',
					$params
				);
			}

			if (count($rows) < self::PAGE) {
				return null;
			}
		}

		return $after;
	}

	/**
	 * The cache written again as it is written now, or null when it holds no
	 * decoded copy to drop.
	 */
	public static function compactCache(string $stored): ?string {
		if (!str_contains($stored, '"object"')) {
			return null;
		}
		$data = json_decode($stored, true);
		if (!is_array($data)) {
			return null;
		}

		$cache = new Cache();
		$cache->import($data);

		$compact = json_encode($cache, JSON_UNESCAPED_SLASHES);

		return is_string($compact) ? $compact : null;
	}

	/**
	 * A notification's details without the post or actor copied into them,
	 * or null when there is neither.
	 */
	public static function compactNotificationDetails(string $stored): ?string {
		$data = json_decode($stored, true);
		if (!is_array($data)
			|| (!array_key_exists(Details::POST, $data) && !array_key_exists(Details::ACTOR, $data))) {
			return null;
		}
		unset($data[Details::POST], $data[Details::ACTOR]);
		$compact = json_encode($data);

		return is_string($compact) ? $compact : null;
	}
}
