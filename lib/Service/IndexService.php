<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\AppInfo\Application;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Db\StreamTagsRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Stream;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repairs missing timeline and hashtag side-index rows in a resumable walk.
 *
 * New and edited posts maintain these tables during their normal write path.
 * This bounded pass is for legacy rows and any gaps left by an older version;
 * unlike the administrator's explicit full rebuild, it never empties either
 * table. It is a one-shot repair: once a page comes back short the walk has
 * reached the newest post, every post after that was indexed by its own save,
 * and the pass marks itself done and costs one config read from then on.
 *
 * The cursor advances only past streams whose indexes were written, so a
 * partial failure is safe to retry: both writers ignore duplicate keys. A
 * stream that keeps failing is skipped after MAX_FAILURES passes rather than
 * holding the cursor, and every stream after it, for good.
 */
class IndexService {
	public const CHUNK_SIZE = 500;
	public const MAX_FAILURES = 5;
	private const CURSOR_KEY = 'index_nid';
	private const DONE_KEY = 'index_done';
	/** `nid:count` of the stream the cursor is held before. */
	private const FAILING_KEY = 'index_failing';
	/** How many streams the walk gave up on; the setup check shows it. */
	public const SKIPPED_KEY = 'index_skipped';

	public function __construct(
		private StreamRequest $streamRequest,
		private StreamDestRequest $streamDestRequest,
		private StreamTagsRequest $streamTagsRequest,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/** Process one bounded page and return the number of completely indexed streams. */
	public function repairNextChunk(): int {
		if ($this->config->getAppValue(Application::APP_ID, self::DONE_KEY, '') === '1') {
			return 0;
		}

		$afterNid = $this->config->getAppValue(Application::APP_ID, self::CURSOR_KEY, '0');
		$rows = $this->streamRequest->getIndexChunk($afterNid, self::CHUNK_SIZE);
		$streams = $this->loadStreams($rows);

		$cursor = $afterNid;
		$processed = 0;
		$held = false;
		foreach ($rows as $row) {
			try {
				$stream = $streams[$row['nid']] ?? $this->streamRequest->getStream($row['id_prim']);
				$this->streamDestRequest->generateStreamDest($stream);
				$this->streamTagsRequest->generateStreamTags($stream);
				// a repaired tag row changes what its hour's trend bucket counts
				$this->streamRequest->markTrendHour($stream->getPublishedTime());
				$processed++;
			} catch (StreamNotFoundException $e) {
				// deleted since the page was read: nothing left to index
			} catch (Throwable $e) {
				if (!$this->skipAfterFailure($row, $e)) {
					$held = true;
					break;
				}
			}

			$cursor = $row['nid'];
		}

		// once per page: a killed process repeats at most one page of work,
		// which both writers take as a no-op
		if ($cursor !== $afterNid) {
			$this->config->setAppValue(Application::APP_ID, self::CURSOR_KEY, $cursor);
		}

		if (!$held && count($rows) < self::CHUNK_SIZE) {
			$this->config->setAppValue(Application::APP_ID, self::DONE_KEY, '1');
			$this->logger->info('[Cron\\Index] stream side indexes are complete', ['nid' => $cursor]);
		}

		return $processed;
	}

	/**
	 * The page hydrated in one query, or nothing when that fails — one stream
	 * that cannot be read must not cost the page, so the rows are then read
	 * one at a time and the bad one is isolated.
	 *
	 * @param list<array{nid: string, id_prim: string, type: string}> $rows
	 *
	 * @return array<string, Stream> nid => stream
	 */
	private function loadStreams(array $rows): array {
		try {
			return $this->streamRequest->getIndexStreams(array_column($rows, 'nid'));
		} catch (Throwable $e) {
			$this->logger->warning('[Cron\\Index] could not load a page of streams, reading them one by one', [
				'exception' => $e,
			]);

			return [];
		}
	}

	/**
	 * Counts a failure of one stream across passes, and says whether it has
	 * failed often enough to be skipped. Until then the cursor stays before it
	 * and the next pass retries it. A skipped stream is named with its
	 * primary key and type, which is what says why it cannot be read, and
	 * counted under SKIPPED_KEY for the setup check.
	 *
	 * @param array{nid: string, id_prim: string, type: string} $row
	 */
	private function skipAfterFailure(array $row, Throwable $e): bool {
		$nid = $row['nid'];
		[$failingNid, $count] = array_pad(
			explode(':', $this->config->getAppValue(Application::APP_ID, self::FAILING_KEY, '')), 2, '0'
		);
		$count = ($failingNid === $nid) ? (int)$count + 1 : 1;

		if ($count >= self::MAX_FAILURES) {
			$this->config->deleteAppValue(Application::APP_ID, self::FAILING_KEY);
			$skipped = (int)$this->config->getAppValue(Application::APP_ID, self::SKIPPED_KEY, '0') + 1;
			$this->config->setAppValue(Application::APP_ID, self::SKIPPED_KEY, (string)$skipped);
			$this->logger->warning('[Cron\\Index] skipping stream ' . $nid . ' (' . $row['type'] . ' '
				. $row['id_prim'] . ') after ' . $count . ' failed attempts', [
					'nid' => $nid,
					'id_prim' => $row['id_prim'],
					'type' => $row['type'],
					'exception' => $e,
				]);

			return true;
		}

		$this->config->setAppValue(Application::APP_ID, self::FAILING_KEY, $nid . ':' . $count);
		$this->logger->error('[Cron\\Index] could not index stream ' . $nid, [
			'nid' => $nid,
			'exception' => $e,
		]);

		return false;
	}
}
