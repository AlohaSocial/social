<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Model\ActivityPub\Object\Document;

/**
 * How much disk this app is using, and on whose behalf.
 *
 * An administrator finds out that Social is filling the disk from the disk.
 * There is nothing in the app that says how much of it is this instance's own
 * uploads — which are somebody's posts and are not going anywhere — and how
 * much is the cached copies of other servers' pictures, which the retention
 * sweep exists to remove.
 *
 * **Measured on a schedule, never on page load.** The only honest number here
 * is the size of the files, and that is a `stat` per file: tens of thousands
 * of them on an instance that has been federating for a year. So the walk
 * happens in the cron, the answer is written to app config with the moment it
 * was taken, and the administration page reads that and says when it was
 * measured. A number that is a day old and cheap is worth more than one that
 * is exact and makes the settings page take a minute to open.
 *
 * The walk itself was `occ social:media:usage`, which is now this class with a
 * command and a cron job on top of it.
 */
class MediaUsageService {
	/** Rows read per query; the work is a file lookup per row, not the read. */
	private const PAGE = 500;

	/**
	 * The three ids this app gives its own documents. A cached copy of another
	 * Social instance's picture carries the same shape — hence the cloud url,
	 * which is what actually decides it.
	 */
	private const LOCAL_MARKERS = ['/documents/local/', '/documents/avatar/', '/documents/header/'];

	public function __construct(
		private CacheDocumentsRequest $cacheDocumentsRequest,
		private CacheDocumentService $cacheDocumentService,
		private ConfigService $configService,
		private RemoteMediaQuotaService $remoteMediaQuotaService,
	) {
	}

	/**
	 * Walks every stored document and adds up what is on disk.
	 *
	 * @return array<string, mixed>
	 */
	public function measure(): array {
		$cloudUrl = $this->configService->getCloudUrl();
		$empty = ['attachments' => ['files' => 0, 'bytes' => 0], 'avatars' => ['files' => 0, 'bytes' => 0]];
		$usage = [
			'local' => $empty,
			'remote' => $empty,
			// bytes per remote host, which is what the per-domain quota is
			// enforced against; this walk is already asking how big every file
			// is, and nothing else could answer the question without asking
			// again
			'domains' => [],
			'rows' => 0,
			'streamed' => 0,
			'missing' => 0,
			'elsewhere' => 0,
			'files' => 0,
			'bytes' => 0,
		];

		$after = 0;
		while (true) {
			$rows = $this->cacheDocumentsRequest->getUsagePage(self::PAGE, $after);
			if ($rows === []) {
				break;
			}

			foreach ($rows as $row) {
				$after = \OCA\Social\Tools\Nid::compare($after, $row['nid']) > 0 ? $after : $row['nid'];
				$usage['rows']++;

				$side = str_starts_with($row['id'], $cloudUrl) && $this->looksLocal($row['id'])
					? 'local' : 'remote';
				// the parent being a cached actor is what makes a document that
				// actor's picture; a local avatar has no parent at all, and is
				// recognised by the id this app gave it
				$kind = ($row['actor_local'] !== null || $this->looksLikeAvatar($row['id']))
					? 'avatars' : 'attachments';

				$host = ($side === 'remote')
					? RemoteMediaQuotaService::chargedHost((string)$row['url'], (string)$row['id']) : '';
				foreach ([$row['local_copy'], $row['resized_copy']] as $copy) {
					$this->addCopy($usage, $side, $kind, $copy, $host);
				}

				$this->recordSize($row);
			}
		}

		return $usage;
	}

	/**
	 * Measures, and remembers the answer with the moment it was taken.
	 *
	 * @return array<string, mixed>
	 */
	public function measureAndStore(): array {
		$usage = $this->measure();
		$usage['measured'] = time();
		$this->configService->setAppValue(
			ConfigService::SOCIAL_MEDIA_USAGE, (string)json_encode($usage)
		);

		// everything the per-domain quota was counting since the last walk is
		// in the figure just written; leaving those counters would charge the
		// same bytes twice and shrink the quota with every pass
		$this->remoteMediaQuotaService->forgetAll(array_keys($usage['domains']));

		return $usage;
	}

	/**
	 * The last measurement, or null where none has been taken.
	 *
	 * Null rather than zeroes: "nothing has been measured yet" and "this
	 * instance stores nothing" are different things to show an administrator,
	 * and a page that cannot tell them apart says the second when it means the
	 * first.
	 *
	 * @return array<string, mixed>|null
	 */
	public function lastMeasured(): ?array {
		$stored = (string)$this->configService->getAppValue(ConfigService::SOCIAL_MEDIA_USAGE);
		if ($stored === '') {
			return null;
		}

		$usage = json_decode($stored, true);

		return is_array($usage) ? $usage : null;
	}

	/**
	 * Fills in the size of a video row that has none.
	 *
	 * `social_cache_doc.size` arrived with the video work; every row written
	 * before it carries 0, which is what the per-account quota and the
	 * PeerTube file link both read. This walk is already asking the store how
	 * big each file is, so it writes the answer down as it passes — and the
	 * quota becomes accurate after one pass rather than never.
	 *
	 * Videos only, and only where the row says nothing: an image's size is not
	 * read anywhere, and overwriting a size that is already there would mean
	 * this run could disagree with what was published.
	 *
	 * @param array<string, mixed> $row
	 */
	private function recordSize(array $row): void {
		$copy = (string)($row['local_copy'] ?? '');
		if ((int)($row['size'] ?? 0) > 0
			|| !str_starts_with((string)($row['media_type'] ?? ''), 'video/')
			|| $copy === ''
			|| $copy === Document::COPY_STREAMED) {
			return;
		}

		$size = $this->cacheDocumentService->cachedFileSize($copy);
		if ($size !== null && $size > 0) {
			$this->cacheDocumentsRequest->setSize(\OCA\Social\Tools\Nid::fromStorage($row['nid']), $size);
		}
	}

	/**
	 * @param array<string, mixed> $usage
	 */
	private function addCopy(
		array &$usage, string $side, string $kind, string $copy, string $host = '',
	): void {
		if ($copy === '') {
			return;
		}

		if ($copy === Document::COPY_STREAMED) {
			// a pointer at a file on the server that hosts it; no bytes here
			$usage['streamed']++;

			return;
		}

		if ($copy === Document::COPY_LOCAL_AVATAR || $copy === 'header') {
			// served straight out of Nextcloud's own avatar store, never copied
			$usage['elsewhere']++;

			return;
		}

		$size = $this->cacheDocumentService->cachedFileSize($copy);
		if ($size === null) {
			$usage['missing']++;

			return;
		}

		$usage[$side][$kind]['files']++;
		$usage[$side][$kind]['bytes'] += $size;
		$usage['files']++;
		$usage['bytes'] += $size;

		if ($host !== '') {
			$usage['domains'][$host] = ($usage['domains'][$host] ?? 0) + $size;
		}
	}

	private function looksLocal(string $id): bool {
		foreach (self::LOCAL_MARKERS as $marker) {
			if (str_contains($id, $marker)) {
				return true;
			}
		}

		return false;
	}

	private function looksLikeAvatar(string $id): bool {
		return str_contains($id, '/documents/avatar/') || str_contains($id, '/documents/header/');
	}
}
