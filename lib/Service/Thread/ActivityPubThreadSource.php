<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Thread;

use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\StreamQueueService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A conversation on the fediverse: the post's `replies` collection, page by
 * page, each reply not held here fetched from its own server
 * (`StreamQueueService::fetchNow()`), and their replies a few levels deep.
 * What a server does not list is not found.
 */
class ActivityPubThreadSource implements ThreadSource {
	private const DEPTH = 3;
	private const VISIBLE_TIMEOUT = 3;
	/** the most collection pages read for one post */
	private const PAGES = 4;
	private bool $readIncomplete = false;

	public function __construct(
		private StreamRequest $streams,
		private CurlService $curl,
		private StreamQueueService $queue,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function supports(Stream $post): bool {
		return !$post->isLocal() && !BlueskyIds::isPostId($post->getId()) && self::repliesOf($post) !== null;
	}

	#[\Override]
	public function fill(Stream $post, int $budget): int {
		$this->readIncomplete = false;
		return $this->fillWithin($post, $budget, self::DEPTH, null);
	}

	/** Read direct replies for an open thread within one shared network budget. */
	public function fillVisible(Stream $post, int $budget): int {
		$this->readIncomplete = false;
		return $this->fillWithin($post, min(20, $budget), 1, hrtime(true) + self::VISIBLE_TIMEOUT * 1_000_000_000);
	}

	/** A failed collection or reply fetch must not be cached as a complete read. */
	public function hasReadFailures(): bool {
		return $this->readIncomplete;
	}

	private function fillWithin(Stream $post, int $budget, int $depthLimit, ?int $deadline): int {
		$stored = 0;
		$level = [$post];
		for ($depth = 0; $depth < $depthLimit && $level !== [] && $stored < $budget; $depth++) {
			$next = [];
			foreach ($level as $parent) {
				foreach ($this->replyIds($parent, $deadline, $budget - $stored) as $id) {
					if ($stored >= $budget) {
						break 3;
					}
					if ($deadline !== null && self::remaining($deadline) < 1) {
						$this->readIncomplete = true;
						break 3;
					}
					$reply = $this->known($id) ?? $this->fetch($id, $deadline);
					if ($reply === null) {
						continue;
					}
					if ($reply['new']) {
						$stored++;
					}
					$next[] = $reply['post'];
				}
			}
			$level = $next;
		}

		return $stored;
	}

	/**
	 * The ids the post's `replies` collection lists, its first pages.
	 *
	 * @return list<string>
	 */
	private function replyIds(Stream $post, ?int $deadline = null, int $limit = 150): array {
		$collection = self::repliesOf($post);
		$ids = [];
		$seen = [];
		for ($page = 0; $collection !== null && $page < self::PAGES;) {
			if (is_array($collection) && !array_key_exists('orderedItems', $collection) && !array_key_exists('items', $collection)) {
				$collection = self::collectionReference($collection['first'] ?? $collection['next'] ?? $collection['id'] ?? $collection['@id'] ?? $collection['href'] ?? null);
				continue;
			}
			if (is_string($collection)) {
				if ($deadline !== null && self::remaining($deadline) < 1) {
					$this->readIncomplete = true;
					break;
				}
				if (isset($seen[$collection])) {
					break;
				}
				$seen[$collection] = true;
				try {
					$collection = $deadline === null
						? $this->curl->retrieveObject($collection)
						: $this->curl->retrieveObject($collection, true, self::remaining($deadline));
				} catch (Throwable $e) {
					$this->readIncomplete = true;
					$this->logger->info('A replies collection was not read', ['post' => $post->getId(), 'exception' => $e]);
					break;
				}
			}
			$items = $collection['orderedItems'] ?? $collection['items'] ?? [];
			foreach (is_array($items) ? $items : [] as $item) {
				if (count($ids) >= $limit) {
					break 2;
				}
				$id = self::idOf($item);
				if (preg_match('#^https?://#i', $id) === 1) {
					$ids[] = $id;
				}
			}
			$page++;
			$next = array_key_exists('orderedItems', $collection) || array_key_exists('items', $collection)
				? ($collection['next'] ?? null)
				: ($collection['first'] ?? $collection['next'] ?? null);
			$collection = self::collectionReference($next);
		}

		return array_values(array_unique($ids));
	}

	/**
	 * A collection item's id: the item itself, or the object's `id`.
	 */
	private static function idOf(mixed $item): string {
		if (is_array($item)) {
			return is_string($item['id'] ?? null) ? $item['id'] : '';
		}

		return is_string($item) ? $item : '';
	}

	/** A page link or an embedded collection page; malformed references are ignored. */
	private static function collectionReference(mixed $reference): string|array|null {
		if (is_array($reference)) {
			if (array_key_exists('orderedItems', $reference) || array_key_exists('items', $reference)) {
				return $reference;
			}
			$reference = $reference['id'] ?? $reference['@id'] ?? $reference['href'] ?? null;
		}

		return is_string($reference) && preg_match('#^https?://#i', $reference) === 1 ? $reference : null;
	}

	/**
	 * @return array{post: Stream, new: bool}|null
	 */
	private function known(string $id): ?array {
		try {
			return ['post' => $this->streams->getStreamById($id), 'new' => false];
		} catch (StreamNotFoundException) {
			return null;
		}
	}

	/**
	 * @return array{post: Stream, new: bool}|null
	 */
	private function fetch(string $id, ?int $deadline = null): ?array {
		try {
			if ($deadline === null) {
				$this->queue->fetchNow($id);
			} else {
				$this->queue->fetchNow($id, self::remaining($deadline));
			}

			return ['post' => $this->streams->getStreamById($id), 'new' => true];
		} catch (Throwable $e) {
			$this->readIncomplete = true;
			$this->logger->info('A reply was not fetched', ['id' => $id, 'exception' => $e]);

			return null;
		}
	}

	private static function remaining(int $deadline): int {
		return (int)floor(max(0, $deadline - hrtime(true)) / 1_000_000_000);
	}

	/**
	 * The post's `replies` collection as its own server described it: an
	 * address, or the collection inline.
	 */
	private static function repliesOf(Stream $post): string|array|null {
		$source = json_decode($post->getSource(), true);
		$replies = is_array($source) ? ($source['replies'] ?? null) : null;
		if (is_string($replies) && preg_match('#^https?://#i', $replies) === 1) {
			return $replies;
		}

		return is_array($replies) ? $replies : null;
	}
}
