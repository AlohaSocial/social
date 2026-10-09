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
 * page, each reply this server does not hold fetched from its own server
 * (`StreamQueueService::fetchNow()`), and the replies of those in turn, a
 * few levels deep. What a server does not list is not found, as on any
 * fediverse server reading the same post.
 */
class ActivityPubThreadSource implements ThreadSource {
	private const DEPTH = 3;
	/** the most collection pages read for one post */
	private const PAGES = 4;

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
		$stored = 0;
		$level = [$post];
		for ($depth = 0; $depth < self::DEPTH && $level !== [] && $stored < $budget; $depth++) {
			$next = [];
			foreach ($level as $parent) {
				foreach ($this->replyIds($parent) as $id) {
					if ($stored >= $budget) {
						break 3;
					}
					$reply = $this->known($id) ?? $this->fetch($id);
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
	private function replyIds(Stream $post): array {
		$collection = self::repliesOf($post);
		$ids = [];
		for ($page = 0; $collection !== null && $page < self::PAGES; $page++) {
			if (is_string($collection)) {
				try {
					$collection = $this->curl->retrieveObject($collection);
				} catch (Throwable $e) {
					$this->logger->info('A replies collection was not read', ['post' => $post->getId(), 'exception' => $e]);
					break;
				}
			}
			$items = $collection['orderedItems'] ?? $collection['items'] ?? [];
			foreach (is_array($items) ? $items : [] as $item) {
				$id = self::idOf($item);
				if (preg_match('#^https?://#i', $id) === 1) {
					$ids[] = $id;
				}
			}
			$next = $collection['first'] ?? $collection['next'] ?? null;
			$collection = (is_string($next) || is_array($next)) ? $next : null;
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
	private function fetch(string $id): ?array {
		try {
			$this->queue->fetchNow($id);

			return ['post' => $this->streams->getStreamById($id), 'new' => true];
		} catch (Throwable $e) {
			$this->logger->info('A reply was not fetched', ['id' => $id, 'exception' => $e]);

			return null;
		}
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
