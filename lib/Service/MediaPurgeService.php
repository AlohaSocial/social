<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\ActivityPub\Object\Document;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Taking stored media away again.
 *
 * A document is two things: a row in `social_cache_doc`, and one or two files
 * in appdata that only that row knows the names of. Deleting the row first
 * leaves the files behind for good, which is why every caller has to read,
 * remove and only then delete — and why the sequence is here rather than
 * repeated at each caller. It used to be repeated: the remote-actor sweep did
 * it correctly and the `Delete` of a remote account did not, so every
 * federated account deletion left an avatar and a header in appdata.
 *
 * @psalm-type CachedRow = array{nid: string, id: string, local_copy: string, resized_copy: string, cached: string, actor: bool}
 */
class MediaPurgeService {
	/** Rows read per page by the media cache purge. */
	public const CACHE_PAGE = 500;

	public function __construct(
		private CacheDocumentsRequest $cacheDocumentsRequest,
		private CacheDocumentService $cacheDocumentService,
		private ConfigService $configService,
		private LoggerInterface $logger,
	) {
	}

	/** The configured age limit of the media cache in days; 0 is none. */
	public function cacheDays(): int {
		return max(0, $this->configService->getAppValueInt(ConfigService::SOCIAL_MEDIA_CACHE_DAYS));
	}

	/** The configured size limit of the media cache in bytes; 0 is none. */
	public function cacheBytes(): int {
		return max(0, $this->configService->getAppValueInt(ConfigService::SOCIAL_MEDIA_CACHE_MB)) * 1024 * 1024;
	}

	/**
	 * The last purge's result, or null where none has run.
	 *
	 * @return array<string, mixed>|null
	 */
	public function lastCachePurge(): ?array {
		$stored = $this->configService->getLazyAppValue(ConfigService::SOCIAL_MEDIA_CACHE_PURGED);
		$purge = ($stored === '') ? null : json_decode($stored, true);

		return is_array($purge) ? $purge : null;
	}

	/**
	 * Removes cached copies of other servers' media, oldest copy first.
	 *
	 * Only what can be fetched again: rows nobody here uploaded, that name
	 * the file at its origin, are not marked as refused, and whose id is not
	 * one this instance gave its own documents. Local uploads, local avatars
	 * and banners, the GIF pack and custom emoji are not rows of this kind or
	 * not rows at all, and are never looked at.
	 *
	 * The files go through appdata, so Nextcloud's file cache stays right;
	 * the row stays, names and all, with `cached` cleared. The names are in
	 * every `/media/{uuid}` link already served, and `DocumentService` fetches
	 * the file again under them when it is next asked for. A row whose files
	 * were already gone is marked the same way and counted as such.
	 *
	 * A copy goes when any limit asks for it: `$all`, older than
	 * `$olderThanDays`, or while the whole cache is over `$maxBytes`. The size
	 * is the files' own, read from appdata, because a row's `size` covers
	 * neither its preview nor the rows written before the column existed: one
	 * pass adds the cache up, a second removes from the oldest end. Both read
	 * a page at a time.
	 *
	 * @param int $olderThanDays 0 for no age limit
	 * @param int $maxBytes 0 for no size limit
	 * @param int $deadline a timestamp past which no further page is read; 0 for none
	 *
	 * @return array{
	 *     dry_run: bool, complete: bool,
	 *     attachments: array{files: int, bytes: int}, avatars: array{files: int, bytes: int},
	 *     files: int, bytes: int, gone: int, cache_bytes: ?int, remaining_bytes: ?int
	 * }
	 *
	 * @throws SocialAppConfigException the cloud url is not configured, so a
	 *                                  local document cannot be told apart
	 */
	public function purgeCache(
		int $olderThanDays, int $maxBytes, bool $all = false, bool $dryRun = false, int $deadline = 0,
	): array {
		$cloudUrl = $this->configService->getCloudUrl();
		$cutoff = ($olderThanDays > 0) ? time() - $olderThanDays * 86400 : null;

		$result = [
			'dry_run' => $dryRun,
			'complete' => true,
			'attachments' => ['files' => 0, 'bytes' => 0],
			'avatars' => ['files' => 0, 'bytes' => 0],
			'files' => 0,
			'bytes' => 0,
			'gone' => 0,
			'cache_bytes' => null,
			'remaining_bytes' => null,
		];

		$total = null;
		if ($maxBytes > 0 && !$all) {
			$total = $this->measureCache($cloudUrl, $dryRun, $deadline, $result);
			$result['cache_bytes'] = $total;
		}

		if ($all || $cutoff !== null || ($total !== null && $total > $maxBytes)) {
			$this->trimCache($cloudUrl, $cutoff, $maxBytes, $total, $all, $dryRun, $deadline, $result);
		}

		if ($result['complete'] && $total !== null) {
			$result['remaining_bytes'] = max(0, $total - $result['bytes']);
		}

		if (!$dryRun) {
			$this->configService->setLazyAppValue(
				ConfigService::SOCIAL_MEDIA_CACHE_PURGED,
				(string)json_encode(['purged' => time()] + $result)
			);
			if ($result['files'] > 0 || $result['gone'] > 0) {
				$this->logger->info('purged cached remote media', $result);
			}
		}

		return $result;
	}

	/**
	 * `purgeCache()` with the limits an administrator configured.
	 *
	 * @return array<string, mixed>|null null when both limits are off
	 *
	 * @throws SocialAppConfigException
	 */
	public function purgeCacheAsConfigured(bool $dryRun = false, int $deadline = 0): ?array {
		$days = $this->cacheDays();
		$bytes = $this->cacheBytes();
		if ($days === 0 && $bytes === 0) {
			return null;
		}

		return $this->purgeCache($days, $bytes, false, $dryRun, $deadline);
	}

	/**
	 * Adds up the bytes the cached copies occupy, and marks the rows whose
	 * files are already gone. Null when the deadline cut it short: a size
	 * limit applied to half a sum would remove what should stay.
	 *
	 * @param array<string, mixed> $result
	 */
	private function measureCache(string $cloudUrl, bool $dryRun, int $deadline, array &$result): ?int {
		$total = 0;
		$afterCached = '';
		$afterNid = '0';
		do {
			$page = $this->cacheDocumentsRequest->getCachedRemotePage(self::CACHE_PAGE, $afterCached, $afterNid);
			foreach ($page as $row) {
				$afterCached = $row['cached'];
				$afterNid = $row['nid'];
				if (MediaUsageService::isLocalDocumentId($row['id'], $cloudUrl)) {
					continue;
				}

				$sizes = $this->copySizes($row);
				if ($sizes === []) {
					$this->forgetGone($row, $dryRun, $result);
					continue;
				}

				$total += array_sum($sizes);
			}

			if ($deadline > 0 && time() >= $deadline && count($page) >= self::CACHE_PAGE) {
				$result['complete'] = false;

				return null;
			}
		} while (count($page) >= self::CACHE_PAGE);

		return $total;
	}

	/**
	 * Removes copies from the oldest end for as long as a limit asks for it.
	 *
	 * @param array<string, mixed> $result
	 */
	private function trimCache(
		string $cloudUrl, ?int $cutoff, int $maxBytes, ?int $total, bool $all, bool $dryRun, int $deadline,
		array &$result,
	): void {
		$afterCached = '';
		$afterNid = '0';
		do {
			$page = $this->cacheDocumentsRequest->getCachedRemotePage(self::CACHE_PAGE, $afterCached, $afterNid);
			foreach ($page as $row) {
				$afterCached = $row['cached'];
				$afterNid = $row['nid'];

				$tooOld = $cutoff !== null && $this->cachedAt($row['cached']) < $cutoff;
				$tooBig = $total !== null && $total > $maxBytes;
				if (!$all && !$tooOld && !$tooBig) {
					// oldest first: nothing after this row is older, and the
					// cache already fits
					return;
				}

				if (MediaUsageService::isLocalDocumentId($row['id'], $cloudUrl)) {
					continue;
				}

				$sizes = $this->copySizes($row);
				if ($sizes === []) {
					// the size pass has marked and counted these already
					if ($total === null) {
						$this->forgetGone($row, $dryRun, $result);
					}
					continue;
				}

				try {
					if (!$dryRun) {
						foreach (array_keys($sizes) as $name) {
							$this->cacheDocumentService->removeFromCache($name);
						}
						$this->cacheDocumentsRequest->markEvicted($row['nid']);
					}
				} catch (Throwable $e) {
					$this->logger->warning('could not purge a cached copy', [
						'document' => $row['id'], 'exception' => $e,
					]);
					continue;
				}

				$kind = $row['actor'] ? 'avatars' : 'attachments';
				$bytes = array_sum($sizes);
				$result[$kind]['files'] += count($sizes);
				$result[$kind]['bytes'] += $bytes;
				$result['files'] += count($sizes);
				$result['bytes'] += $bytes;
				if ($total !== null) {
					$total -= $bytes;
				}
			}

			if ($deadline > 0 && time() >= $deadline && count($page) >= self::CACHE_PAGE) {
				$result['complete'] = false;

				return;
			}
		} while (count($page) >= self::CACHE_PAGE);
	}

	/**
	 * The copies of one row that are on disk, by name.
	 *
	 * @param CachedRow $row
	 *
	 * @return array<string, int>
	 */
	private function copySizes(array $row): array {
		$sizes = [];
		foreach ([$row['local_copy'], $row['resized_copy']] as $name) {
			if (!Document::isStoredName($name) || isset($sizes[$name])) {
				continue;
			}

			$size = $this->cacheDocumentService->cachedFileSize($name);
			if ($size !== null) {
				$sizes[$name] = $size;
			}
		}

		return $sizes;
	}

	/**
	 * A row whose files are already gone: marked like a purged one, so it is
	 * fetched again on demand and not read by the next purge.
	 *
	 * @param CachedRow $row
	 * @param array<string, mixed> $result
	 */
	private function forgetGone(array $row, bool $dryRun, array &$result): void {
		$result['gone']++;
		if ($dryRun) {
			return;
		}

		try {
			$this->cacheDocumentsRequest->markEvicted($row['nid']);
		} catch (Throwable $e) {
			$this->logger->warning('could not mark a missing cached copy', [
				'document' => $row['id'], 'exception' => $e,
			]);
		}
	}

	/** A `cached` value as a timestamp, in the server's own zone like the value written. */
	private function cachedAt(string $cached): int {
		$time = strtotime($cached);

		return ($time === false) ? 0 : $time;
	}

	/** One document: its files, then its row. */
	public function purge(Document $document): void {
		$this->forgetFiles($document);
		$this->cacheDocumentsRequest->deleteById($document->getId());
	}

	/** One document by its ActivityPub id, when there is a row for it. */
	public function purgeById(string $id): bool {
		if ($id === '') {
			return false;
		}

		try {
			$document = $this->cacheDocumentsRequest->getById($id);
		} catch (CacheDocumentDoesNotExistException $e) {
			return false;
		}

		$this->purge($document);

		return true;
	}

	/**
	 * One of an account's own documents, by the url it is served under.
	 *
	 * The account is checked here rather than by the caller: the only thing a
	 * url identifies is a row, and a banner's url is the one handle its owner
	 * has on it.
	 */
	public function purgeLocalByUrl(string $url, string $account): bool {
		if ($url === '' || $account === '') {
			return false;
		}

		try {
			$document = $this->cacheDocumentsRequest->getByUrl($url);
		} catch (CacheDocumentDoesNotExistException $e) {
			return false;
		}

		if ($document->getAccount() !== $account) {
			return false;
		}

		$this->purge($document);

		return true;
	}

	/**
	 * Everything hanging off one parent -- for an actor, its avatar and header.
	 *
	 * @return int how many rows went
	 */
	public function purgeByParent(string $parentId): int {
		if ($parentId === '') {
			return 0;
		}

		$documents = $this->cacheDocumentsRequest->getByParent($parentId);
		foreach ($documents as $document) {
			$this->forgetFiles($document);
		}

		$this->cacheDocumentsRequest->deleteByParent($parentId);

		return count($documents);
	}

	/**
	 * The documents whose parent no longer exists.
	 *
	 * A row that names a parent names a post, a cached actor or a story, and
	 * when none of the three is there any more nothing can ever refer to the
	 * file again: the attachments of a post that was deleted or never stored,
	 * the avatar of an actor evicted before this sweep existed, the picture of
	 * a story whose day was up.
	 *
	 * Rows with *no* parent are deliberately left alone. For a local upload
	 * that is not evidence of anything: nothing in the schema points from a
	 * post back at the attachment it shows, so "parentless" would sweep away
	 * the pictures of live posts.
	 *
	 * Page after page of `$limit`, each read after the last row of the one
	 * before, until a page comes back short or `$deadline` has passed: a
	 * retention prune, a domain purge or an account deletion orphans far more
	 * than one page a day, and one page a day never caught up.
	 *
	 * @param int $deadline a timestamp past which no further page is read; 0
	 *                      for one page
	 *
	 * @return int how many rows went
	 */
	public function sweepOrphans(int $days, int $limit, int $deadline = 0): int {
		$swept = 0;
		$after = 0;
		do {
			$page = $this->cacheDocumentsRequest->getOrphanedByParent($days, $limit, $after);
			foreach ($page as $document) {
				$after = max($after, (int)$document->getNid());
				try {
					$this->purge($document);
					$swept++;
				} catch (Throwable $e) {
					// one row that will not go is not a reason to stop: the rest
					// of the batch is still worth reclaiming
					$this->logger->warning('could not remove an orphaned document', [
						'document' => $document->getId(), 'exception' => $e,
					]);
				}
			}
		} while (count($page) >= $limit && $after > 0 && time() < $deadline);

		if ($swept > 0) {
			$this->logger->info('swept documents whose parent is gone', [
				'days' => $days, 'documents' => $swept,
			]);
		}

		return $swept;
	}

	/**
	 * The files, which only the row knows the names of.
	 *
	 * A file that cannot be removed is not a reason to keep the row: the row
	 * is what makes the bytes reachable, and what is left on disk is what
	 * `occ social:media:usage` reports.
	 */
	private function forgetFiles(Document $document): void {
		try {
			$this->cacheDocumentService->removeFromCache($document->getLocalCopy());
			$this->cacheDocumentService->removeFromCache($document->getResizedCopy());
		} catch (Throwable $e) {
			$this->logger->warning('could not remove the cached copy of ' . $document->getId(), [
				'exception' => $e,
			]);
		}
	}
}
