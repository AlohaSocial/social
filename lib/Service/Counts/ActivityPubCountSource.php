<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Counts;

use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\CurlService;
use OCP\AppFramework\Http;

/**
 * The counts of a post on the fediverse, from the document its own server
 * serves at the post's `id`: the `totalItems` of its `likes`, `shares` and
 * `replies` (`Stream::statedCount()`). Counts are never pushed, so they must
 * be asked for. An answer naming another document leaves the stored counts
 * alone; a 404 or 410 stops asking for a long while
 * (`CountWriter::stampGone()`).
 */
class ActivityPubCountSource implements CountSource {
	/** Documents asked for in one round of requests. */
	public const PARALLEL = 20;
	private const ORIGIN_TIMEOUT = 5;
	private const COUNT_KEYS = [
		'likes' => 'likes',
		'shares' => 'boosts',
		'replies' => 'replies',
		'quotes' => 'quotes',
	];

	public function __construct(
		private CurlService $curl,
		private CountWriter $writer,
	) {
	}

	#[\Override]
	public function supports(Stream $post): bool {
		return !$post->isLocal()
			&& BlueskyIds::didOf($post->getId()) === ''
			&& preg_match('#^https?://#i', $post->getId()) === 1;
	}

	#[\Override]
	public function refresh(array $posts, ?int $timeout = null): int {
		$answered = 0;
		// One timeout budget across up to three dependent rounds.
		$deadline = $timeout === null ? null : hrtime(true) + ($timeout * 1_000_000_000);
		$firstRound = true;
		foreach (array_chunk($posts, self::PARALLEL) as $chunk) {
			$requestTimeout = $firstRound ? ($timeout ?? self::ORIGIN_TIMEOUT) : self::remainingTimeout($deadline, $timeout);
			if ($requestTimeout === 0) {
				break;
			}
			$statuses = [];
			$answers = $this->curl->retrieveObjectsMany(
				array_map(static fn (Stream $post): string => $post->getId(), $chunk), ['timeout' => $requestTimeout], $statuses
			);
			$firstRound = false;
			$counts = [];
			$collections = [];
			foreach ($chunk as $post) {
				$data = $answers[$post->getId()] ?? null;
				if (!is_array($data) || ($data['id'] ?? '') !== $post->getId()) {
					continue;
				}
				foreach (self::COUNT_KEYS as $property => $counter) {
					$counts[$post->getId()][$counter] = self::count($data, $property);
					if ($counts[$post->getId()][$counter] === null) {
						$url = self::collectionUrl($data[$property] ?? null);
						if ($url !== '') {
							$collections[$url][$post->getId()][$counter] = true;
						}
					}
				}
			}

			// Collections given only by URL are fetched together.
			if ($collections !== []) {
				$requestTimeout = self::remainingTimeout($deadline, $timeout);
				$collectionAnswers = $requestTimeout === 0 ? [] : $this->curl->retrieveObjectsMany(array_keys($collections), ['timeout' => $requestTimeout]);
				$firstPages = [];
				foreach ($collections as $url => $refs) {
					$collection = $collectionAnswers[$url] ?? null;
					if (!is_array($collection) || ($collection['id'] ?? $collection['@id'] ?? '') !== $url) {
						continue;
					}
					foreach ($refs as $postId => $counters) {
						foreach (array_keys($counters) as $counter) {
							$property = array_search($counter, self::COUNT_KEYS, true);
							if (is_string($property)) {
								$count = Stream::statedCount([$property => $collection], $property);
								if ($count !== null) {
									$counts[$postId][$counter] = $count;
									continue;
								}
								$first = $collection['first'] ?? null;
								$count = is_array($first) ? Stream::statedCount([$property => $first], $property) : null;
								if ($count !== null) {
									$counts[$postId][$counter] = $count;
									continue;
								}
								$pageUrl = self::collectionUrl($first);
								if ($pageUrl !== '') {
									$firstPages[$pageUrl][$postId][$counter] = $property;
								}
							}
						}
					}
				}
				// Some servers state `totalItems` only on the first page; counting `items`
				// would understate it.
				if ($firstPages !== []) {
					$requestTimeout = self::remainingTimeout($deadline, $timeout);
					$pageAnswers = $requestTimeout === 0 ? [] : $this->curl->retrieveObjectsMany(array_keys($firstPages), ['timeout' => $requestTimeout]);
					foreach ($firstPages as $pageUrl => $refs) {
						$page = $pageAnswers[$pageUrl] ?? null;
						if (!is_array($page) || ($page['id'] ?? $page['@id'] ?? '') !== $pageUrl) {
							continue;
						}
						foreach ($refs as $postId => $counters) {
							foreach ($counters as $counter => $property) {
								$counts[$postId][$counter] = Stream::statedCount([$property => $page], $property);
							}
						}
					}
				}
			}

			foreach ($chunk as $post) {
				if (in_array($statuses[$post->getId()] ?? 0, [Http::STATUS_NOT_FOUND, Http::STATUS_GONE], true)) {
					$this->writer->stampGone($post->getId());
					continue;
				}
				$data = $answers[$post->getId()] ?? null;
				if (!is_array($data) || ($data['id'] ?? '') !== $post->getId()) {
					if ($timeout === null) {
						$this->writer->stamp($post->getId());
					}
					continue;
				}
				if (!array_filter($counts[$post->getId()] ?? [], static fn (?int $count): bool => $count !== null)) {
					if ($timeout === null) {
						$this->writer->stamp($post->getId());
					}
					continue;
				}
				if ($this->writer->write(
					$post->getId(),
					$counts[$post->getId()]['likes'] ?? null,
					$counts[$post->getId()]['boosts'] ?? null,
					$counts[$post->getId()]['replies'] ?? null,
					quotes: $counts[$post->getId()]['quotes'] ?? null,
				)) {
					$answered++;
				}
			}
		}

		return $answered;
	}

	/** Seconds still available for one parallel round; zero means do not start another. */
	private static function remainingTimeout(?int $deadline, ?int $timeout): int {
		if ($deadline === null) {
			return self::ORIGIN_TIMEOUT;
		}

		return min($timeout ?? self::ORIGIN_TIMEOUT, (int)floor(max(0, $deadline - hrtime(true)) / 1_000_000_000));
	}

	/** A count from an embedded collection or Mastodon's quote extension. */
	private static function count(array $data, string $property): ?int {
		if ($property === 'quotes') {
			$quoteCount = $data['quoteCount'] ?? null;
			if (is_int($quoteCount) || (is_string($quoteCount) && ctype_digit($quoteCount))) {
				$quoteCount = (int)$quoteCount;
				if ($quoteCount >= 0 && $quoteCount <= Stream::REMOTE_COUNT_CEILING) {
					return $quoteCount;
				}
			}
		}

		return Stream::statedCount($data, $property);
	}

	/** The URL of a collection that names no total inline. */
	private static function collectionUrl(mixed $collection): string {
		if (is_string($collection)) {
			$url = $collection;
		} elseif (is_array($collection)) {
			$url = $collection['id'] ?? $collection['@id'] ?? '';
		} else {
			return '';
		}

		if (!is_string($url) || preg_match('#^https?://#i', $url) !== 1) {
			return '';
		}

		return $url;
	}
}
