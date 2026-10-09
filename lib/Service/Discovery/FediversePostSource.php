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
use OCA\Social\Service\StreamQueueService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Posts with a hashtag on the fediverse: the public hashtag timelines of the
 * servers this one talks to (`FediverseDirectoryService::sources()`, the
 * same peers the people search and the trending hashtags ask), each post
 * fetched from its own server (`StreamQueueService::fetchNow()`), so only
 * what its author's server says is stored. The fediverse has no public
 * search of posts — Mastodon's needs an account there — so a search is not
 * asked of it.
 */
class FediversePostSource implements PostSource {
	/** how many servers are asked */
	private const SERVERS = 5;
	private const TIMEOUT = 4;

	public function __construct(
		private FediverseDirectoryService $directory,
		private CurlService $curl,
		private StreamRequest $streams,
		private StreamQueueService $queue,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function tagged(string $tag, int $limit, ?Person $viewer, int $since = 0): int {
		$uris = [];
		$asked = 0;
		foreach ($this->directory->sources() as $source) {
			if ($source->getKind() !== DirectorySource::KIND_MASTODON || $source->isLocal() || $asked >= self::SERVERS) {
				continue;
			}
			$asked++;
			try {
				$statuses = $this->curl->retrieveJson(
					'get',
					'https://' . $source->getHost() . '/api/v1/timelines/tag/' . rawurlencode($tag) . '?limit=20',
					['timeout' => self::TIMEOUT, 'json_headers' => false, 'headers' => ['Accept' => 'application/json']],
				);
			} catch (Throwable $e) {
				$this->logger->debug('A server did not answer for a hashtag', ['host' => $source->getHost(), 'exception' => $e]);
				continue;
			}
			foreach ($statuses as $status) {
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
		foreach (array_keys($uris) as $uri) {
			if ($stored >= $limit) {
				break;
			}
			try {
				$this->streams->getStreamById($uri);
				continue;
			} catch (StreamNotFoundException) {
			}
			try {
				$this->queue->fetchNow($uri);
				$stored++;
			} catch (Throwable $e) {
				$this->logger->debug('A post with a hashtag was not fetched', ['uri' => $uri, 'exception' => $e]);
			}
		}

		return $stored;
	}

	/**
	 * When a Mastodon status says it was written, 0 when it does not say.
	 */
	private static function writtenAt(mixed $status): int {
		$time = is_array($status) ? strtotime((string)($status['created_at'] ?? '')) : false;

		return $time === false ? 0 : $time;
	}

	#[\Override]
	public function matching(string $query, int $limit, ?Person $viewer): int {
		return 0;
	}
}
