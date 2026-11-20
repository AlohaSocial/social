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
 * How much disk this app is using, and on whose behalf: this instance's own
 * uploads versus cached copies of other servers' media.
 *
 * Measured by a `stat` per file, so the walk runs in the cron (and as
 * `occ social:media:usage`), and the result is stored in app config with the
 * moment it was taken for the administration page to read.
 */
class MediaUsageService {
	/** Rows read per query; the work is a file lookup per row, not the read. */
	private const PAGE = 500;

	/**
	 * The three ids this app gives its own documents. Another Social instance's
	 * documents have the same shape, so the cloud url decides.
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
			'evicted' => 0,
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

				$side = self::isLocalDocumentId($row['id'], $cloudUrl) ? 'local' : 'remote';
				// a cached actor parent makes a document that actor's picture; a
				// local avatar has no parent and is recognised by its id
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
		$this->configService->setLazyAppValue(
			ConfigService::SOCIAL_MEDIA_USAGE, (string)json_encode($usage)
		);

		// the per-domain quota's counters are now part of the figure just
		// written; leaving them would count the same bytes twice
		$this->remoteMediaQuotaService->forgetAll(array_keys($usage['domains']));

		return $usage;
	}

	/**
	 * The last measurement, or null where none has been taken (not zeroes,
	 * which would claim nothing is stored).
	 *
	 * @return array<string, mixed>|null
	 */
	public function lastMeasured(): ?array {
		$stored = $this->configService->getLazyAppValue(ConfigService::SOCIAL_MEDIA_USAGE);
		if ($stored === '') {
			return null;
		}

		$usage = json_decode($stored, true);

		return is_array($usage) ? $usage : null;
	}

	/**
	 * Fills in the size of a video row that has none, since the per-account
	 * quota and the PeerTube file link read it and older rows carry 0. Videos
	 * only, and never overwriting a stored size.
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
			// a cached remote copy that is not on disk is fetched again when
			// it is next asked for, and the purge removes them on purpose; an
			// upload from here that is not there is lost
			$usage[($side === 'remote') ? 'evicted' : 'missing']++;

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

	/**
	 * Whether a document id is one this instance gave its own upload, avatar
	 * or banner. The cloud url decides it: another Social instance's
	 * documents carry the same path shape.
	 */
	public static function isLocalDocumentId(string $id, string $cloudUrl): bool {
		if (!str_starts_with($id, $cloudUrl)) {
			return false;
		}

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
