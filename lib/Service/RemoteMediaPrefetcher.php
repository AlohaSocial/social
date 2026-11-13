<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\HostBreakerRequest;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\NotFoundException;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fetches the files of remote attachments in the background, before anybody
 * asks for them.
 *
 * Two ways in: the attachments of one post, queued when the post was stored
 * (`StreamQueue::TYPE_MEDIA`), and a sweep over every document still waiting,
 * newest first, which `Cron\Cache` runs as the backstop. Either way the files
 * are downloaded several at a time (`CurlService::downloadMany()`) and stored
 * one by one through `DocumentService::storeRemoteDocument()`, the same path
 * a reader's request takes, so the size and type limits, the pixel ceiling,
 * the moderator's refused files and the outbound address checks are those of
 * every other fetch.
 *
 * Everything runs under a deadline: a wave is started only while there is
 * time for it, and its downloads may not outlast what is left. A host behind
 * the shared circuit breaker is not asked, and a host whose downloads failed
 * at the network level is not asked again in the same run. A failed media
 * download does not trip the shared breaker itself: a large file running out
 * of time is not the host being down, and the breaker also holds back the
 * deliveries to that host. A `429` or `503`, which asks the host to be left
 * alone, does.
 */
class RemoteMediaPrefetcher {
	/** How many files one wave downloads at once. */
	public const WAVE = 8;

	/** How many of the files in one wave may come from the same host. */
	public const PER_HOST = 4;

	/** Seconds a wave needs at least: started with less, its downloads would run out of time. */
	public const MIN_WAVE_SECONDS = ConfigService::DEFAULT_REQUEST_TIMEOUT;

	/** How many pages of `CacheDocumentsRequest::CACHE_BATCH` one sweep looks through. */
	public const MAX_PAGES = 5;

	/** Seconds the queue gives the attachments of one post. */
	public const POST_SECONDS = DocumentService::BACKGROUND_FETCH_TIMEOUT;

	private HostBreaker $breaker;

	/** @var array<string, true> the hosts whose downloads failed at the network level in this run */
	private array $failing = [];

	public function __construct(
		private CacheDocumentsRequest $cacheDocumentsRequest,
		private CacheDocumentService $cacheService,
		private DocumentService $documentService,
		private CurlService $curlService,
		private ConfigService $configService,
		private ITempManager $tempManager,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		HostBreakerRequest $hostBreakerRequest,
	) {
		$this->breaker = new HostBreaker($hostBreakerRequest);
	}

	/**
	 * The attachments of one post that still wait for their file.
	 *
	 * @return int how many of them still wait afterwards: not stored, not
	 *             refused, and worth another try
	 */
	public function prefetchPost(string $postId, int $deadline): int {
		$this->startRun();

		return $this->prefetch($this->cacheDocumentsRequest->getByParent($postId), $deadline)['waiting'];
	}

	/**
	 * Every document still waiting for its file, newest first, until the
	 * deadline or `MAX_PAGES` pages. A page whose hosts are all held back
	 * costs one query, and the sweep goes on to older documents.
	 *
	 * @return int how many files were stored
	 */
	public function prefetchPending(int $deadline): int {
		$this->startRun();

		$stored = 0;
		$before = 0;
		for ($page = 0; $page < self::MAX_PAGES && $this->time->getTime() < $deadline; $page++) {
			$documents = $this->cacheDocumentsRequest->getNotCachedDocuments(CacheDocumentsRequest::CACHE_BATCH, $before);
			if ($documents === []) {
				break;
			}

			$before = (int)$documents[count($documents) - 1]->getNid();
			$stored += $this->prefetch($documents, $deadline)['stored'];
			if (count($documents) < CacheDocumentsRequest::CACHE_BATCH) {
				break;
			}
		}

		return $stored;
	}

	/** A run reads the breaker afresh and starts with no host held back of its own. */
	private function startRun(): void {
		$this->breaker->reset();
		$this->failing = [];
	}

	/**
	 * @param Document[] $documents
	 *
	 * @return array{stored: int, waiting: int}
	 */
	private function prefetch(array $documents, int $deadline): array {
		$stored = 0;
		$waiting = 0;
		$pending = [];
		foreach ($documents as $document) {
			if (!self::awaitsCopy($document)) {
				continue;
			}
			if ($this->isBeingCached($document)) {
				$waiting++;
				continue;
			}
			$pending[] = $document;
		}

		while ($pending !== []) {
			$left = $deadline - $this->time->getTime();
			if ($left < self::MIN_WAVE_SECONDS) {
				break;
			}

			$wave = $this->nextWave($pending, $waiting);
			if ($wave === []) {
				break;
			}

			foreach ($this->runWave($wave, min(DocumentService::BACKGROUND_FETCH_TIMEOUT, $left)) as $result) {
				if ($result === DocumentService::STORED) {
					$stored++;
				} elseif ($result === DocumentService::RETRY) {
					$waiting++;
				}
			}
		}

		return ['stored' => $stored, 'waiting' => $waiting + count($pending)];
	}

	/**
	 * A remote document with no copy and no recorded refusal. A local upload
	 * always has its copy; a streamed document, an avatar and a header name
	 * theirs with a marker and are never fetched here.
	 */
	private static function awaitsCopy(Document $document): bool {
		return $document->getLocalCopy() === ''
			&& $document->getError() === 0
			&& !$document->isLocalUpload()
			&& $document->getUrl() !== '';
	}

	/** Whether another worker took this document within `CacheDocumentsRequest::CACHING_TIMEOUT`. */
	private function isBeingCached(Document $document): bool {
		return $document->getCaching() > $this->time->getTime() - CacheDocumentsRequest::CACHING_TIMEOUT * 60;
	}

	/**
	 * Takes the next wave out of `$pending`: at most `WAVE` documents, at most
	 * `PER_HOST` of them from one host. A document whose host is held back is
	 * taken out and counted in `$waiting`; one that does not fit stays for a
	 * later wave.
	 *
	 * @param list<Document> $pending
	 *
	 * @return list<Document>
	 */
	private function nextWave(array &$pending, int &$waiting): array {
		$wave = [];
		$perHost = [];
		$rest = [];
		foreach ($pending as $document) {
			$host = self::hostOf($document->getUrl());
			if (isset($this->failing[$host]) || $this->breaker->openUntil($host) > 0) {
				$waiting++;
				continue;
			}
			if (count($wave) >= self::WAVE || ($perHost[$host] ?? 0) >= self::PER_HOST) {
				$rest[] = $document;
				continue;
			}

			$perHost[$host] = ($perHost[$host] ?? 0) + 1;
			$wave[] = $document;
		}
		$pending = $rest;

		return $wave;
	}

	/**
	 * Claims, downloads and stores one wave.
	 *
	 * @param list<Document> $wave
	 *
	 * @return array<int, string> what became of each document, as `DocumentService::storeRemoteDocument()` says
	 */
	private function runWave(array $wave, int $timeout): array {
		$results = [];
		$claimed = [];
		$paths = [];
		$downloads = [];
		$failures = [];
		foreach ($wave as $key => $document) {
			if (!$this->cacheDocumentsRequest->claimForCaching($document)) {
				// another worker took it between the read and now
				$results[$key] = DocumentService::RETRY;
				continue;
			}
			$claimed[$key] = $document;

			$path = $this->tempManager->getTemporaryFile('.media');
			if ($path === false) {
				$failures[$key] = new NotFoundException('could not open a temporary file');
				continue;
			}
			$paths[$key] = $path;

			try {
				$downloads[$key] = $this->cacheService->downloadPlan($document) + ['path' => $path];
			} catch (Throwable $e) {
				$failures[$key] = $e;
			}
		}

		$outcomes = $failures + $this->download($downloads, $timeout);
		foreach ($claimed as $key => $document) {
			try {
				$results[$key] = $this->store($document, $outcomes[$key] ?? null, $paths[$key] ?? '');
			} finally {
				if (isset($paths[$key])) {
					@unlink($paths[$key]);
				}
			}
		}

		return $results;
	}

	/**
	 * The downloads of one wave, together, none allowed more than `$timeout`
	 * seconds. Reaching the origin keeps the federation default, so a host
	 * that is down fails fast.
	 *
	 * @param array<int, array{url: string, path: string, max: int, on_behalf_of: string}> $downloads
	 *
	 * @return array<int, Throwable|null>
	 */
	private function download(array $downloads, int $timeout): array {
		if ($downloads === []) {
			return [];
		}

		try {
			/** @var array<int, Throwable|null> */
			return $this->configService->withRequestTimeout(
				$timeout,
				fn (): array => $this->curlService->downloadMany($downloads),
				ConfigService::DEFAULT_REQUEST_TIMEOUT
			);
		} catch (Throwable $e) {
			return array_fill_keys(array_keys($downloads), $e);
		}
	}

	/**
	 * Stores what one download brought, or records why there is nothing.
	 *
	 * @return string what became of the document, as `DocumentService::storeRemoteDocument()` says
	 */
	private function store(Document $document, ?Throwable $outcome, string $path): string {
		$host = self::hostOf($document->getUrl());
		if ($outcome instanceof RequestContentException && in_array($outcome->getCode(), [429, 503], true)) {
			// the host asked to be left alone, which says nothing about the
			// file: the row keeps its claim, so it waits out CACHING_TIMEOUT
			$this->failing[$host] = true;
			$this->breaker->open($host, $outcome->getRetryAfter());

			return DocumentService::RETRY;
		}
		if ($outcome instanceof RequestNetworkException) {
			$this->failing[$host] = true;
		}

		try {
			$result = $this->documentService->storeRemoteDocument(
				$document,
				function (Document $document) use ($outcome, $path): string {
					if ($outcome !== null) {
						throw $outcome;
					}
					$this->cacheService->saveFromTempToCache($document, $path);

					return $document->getMimeType();
				}
			);
		} catch (Throwable $e) {
			// one unusable row must not end the run; its claim runs out and a
			// later run tries it again
			$this->logger->warning('could not cache a remote document', [
				'document' => $document->getId(), 'exception' => $e,
			]);

			return DocumentService::RETRY;
		}

		if ($result === DocumentService::STORED) {
			$this->breaker->close($host);
		}

		return $result;
	}

	private static function hostOf(string $url): string {
		return (string)parse_url($url, PHP_URL_HOST);
	}
}
