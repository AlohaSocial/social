<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Discovery;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\DirectorySource;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\FediverseDirectoryService;
use OCA\Social\Service\HostBreaker;
use OCA\Social\Service\StreamQueueService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Posts with a hashtag on the fediverse: the public hashtag timelines of the
 * servers this one talks to (`FediverseDirectoryService::sources()`), each
 * post fetched from its own server (`StreamQueueService::fetchNow()`) within
 * a time budget. The fediverse has no public post search, so searches are
 * not asked of it.
 */
class FediversePostSource implements PostSource {
	/** how many servers are asked */
	private const SERVERS = 5;
	private const TIMEOUT = 4;
	private const FETCH_BUDGET_SECONDS = 8;
	private const FETCH_TIMEOUT_SECONDS = 2;

	public function __construct(
		private FediverseDirectoryService $directory,
		private CurlService $curl,
		private StreamRequest $streams,
		private StreamQueueService $queue,
		private LoggerInterface $logger,
		private HostBreaker $hostBreaker,
		private RemotePostDiscoveryBudget $budget,
	) {
	}

	#[\Override]
	public function tagged(string $tag, int $limit, ?Person $viewer, int $since = 0): PostSourceStats {
		$startedAt = hrtime(true);
		$requests = [];
		foreach ($this->directory->sources() as $source) {
			$host = $source->getHost();
			if ($source->getKind() !== DirectorySource::KIND_MASTODON || $source->isLocal() || count($requests) >= self::SERVERS || $host === '') {
				continue;
			}
			if ($this->hostBreaker->openUntil($host) > 0) {
				continue;
			}
			$requests[$host] = 'https://' . $host . '/api/v1/timelines/tag/' . rawurlencode($tag) . '?limit=20';
		}
		$authorizedRequests = [];
		foreach ($requests as $host => $url) {
			if (!$this->budget->reserve()) {
				break;
			}
			$authorizedRequests[$host] = $url;
		}
		$requests = $authorizedRequests;
		if ($requests === []) {
			return PostSourceStats::empty();
		}

		$httpStatuses = null;
		$failed = null;
		$retryAfters = null;
		try {
			$answers = $this->curl->retrieveJsonMany(
				array_values($requests),
				['timeout' => self::TIMEOUT, 'json_headers' => false, 'headers' => ['Accept' => 'application/json']],
				[],
				$httpStatuses,
				$failed,
				$retryAfters,
			);
		} catch (Throwable $e) {
			foreach ($requests as $host => $_url) {
				$this->hostBreaker->open($host);
			}
			$this->logger->warning('Fediverse hashtag provider batch failed', ['exception' => $e]);

			return new PostSourceStats(count($requests), count($requests), 0, 0, 0, self::durationMs($startedAt));
		}
		$httpStatuses = $httpStatuses ?? [];
		$failed = $failed ?? [];
		$retryAfters = $retryAfters ?? [];
		$uris = [];
		foreach ($requests as $host => $url) {
			if (in_array($url, $failed, true)) {
				$statusCode = $httpStatuses[$url] ?? 0;
				if (self::shouldOpenCircuit($statusCode)) {
					$this->hostBreaker->open($host, $retryAfters[$url] ?? 0);
				}
				$this->logger->debug('A server did not answer for a hashtag', ['host' => $host, 'status' => $statusCode]);
				continue;
			}
			if (isset($httpStatuses[$url])) {
				$this->hostBreaker->close($host);
			}
			foreach (is_array($answers[$url] ?? null) ? $answers[$url] : [] as $status) {
				$uri = is_array($status) ? (string)($status['uri'] ?? '') : '';
				if ($since > 0 && self::writtenAt($status) < $since) {
					continue;
				}
				if (preg_match('#^https?://#i', $uri) === 1) {
					$uris[$uri] = true;
				}
			}
		}

		$stored = 0;
		$requestCount = count($requests);
		$failedRequests = count($failed);
		$seen = count($uris);
		$deadline = hrtime(true) + self::FETCH_BUDGET_SECONDS * 1_000_000_000;
		foreach (array_keys($uris) as $uri) {
			if ($stored >= $limit) {
				break;
			}
			try {
				$this->streams->getStreamById($uri);
				continue;
			} catch (StreamNotFoundException) {
			}
			$remainingSeconds = (int)floor(($deadline - hrtime(true)) / 1_000_000_000);
			if ($remainingSeconds < 1) {
				break;
			}
			if (!$this->budget->reserve()) {
				break;
			}
			try {
				$requestCount++;
				$this->queue->fetchNow($uri, min(self::FETCH_TIMEOUT_SECONDS, $remainingSeconds));
				$stored++;
			} catch (Throwable $e) {
				$failedRequests++;
				$this->logger->debug('A post with a hashtag was not fetched', ['uri' => $uri, 'exception' => $e]);
			}
		}

		return new PostSourceStats(
			$requestCount,
			$failedRequests,
			count(array_filter($httpStatuses, static fn (int $status): bool => $status === 429)),
			$seen,
			$stored,
			self::durationMs($startedAt),
		);
	}

	/**
	 * When a Mastodon status says it was written, 0 when it does not say.
	 */
	private static function writtenAt(mixed $status): int {
		$time = is_array($status) ? strtotime((string)($status['created_at'] ?? '')) : false;

		return $time === false ? 0 : $time;
	}

	private static function shouldOpenCircuit(int $status): bool {
		return $status === 0 || in_array($status, [408, 429], true) || $status >= 500;
	}

	#[\Override]
	public function matching(string $query, int $limit, ?Person $viewer): PostSourceStats {
		return PostSourceStats::empty();
	}

	private static function durationMs(int $startedAt): int {
		return max(0, (int)((hrtime(true) - $startedAt) / 1_000_000));
	}
}
