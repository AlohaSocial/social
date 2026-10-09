<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Interaction;

use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\RemoteFetchQueue;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Who liked or boosted a post on the fediverse: its `likes` and `shares`
 * collections, where its server lists them — many give only the number,
 * and then there is nobody to name. The accounts not cached here are
 * fetched in the background (`RemoteFetchQueue::resolveActors()`). The
 * fediverse has no collection of the posts quoting one.
 */
class ActivityPubInteractionSource implements InteractionSource {
	private const PAGES = 2;

	public function __construct(
		private CurlService $curl,
		private RemoteFetchQueue $queue,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function supports(Stream $post): bool {
		return !$post->isLocal() && !BlueskyIds::isPostId($post->getId());
	}

	#[\Override]
	public function actors(Stream $post, string $type, int $limit): array {
		$source = json_decode($post->getSource(), true);
		$collection = is_array($source) ? ($source[$type === Like::TYPE ? 'likes' : 'shares'] ?? null) : null;
		$ids = [];
		for ($page = 0; (is_string($collection) || is_array($collection)) && $page < self::PAGES && count($ids) < $limit; $page++) {
			if (is_string($collection)) {
				if (preg_match('#^https?://#i', $collection) !== 1) {
					break;
				}
				try {
					$collection = $this->curl->retrieveObject($collection);
				} catch (Throwable $e) {
					$this->logger->info('A collection of reactions was not read', ['post' => $post->getId(), 'exception' => $e]);
					break;
				}
			}
			$items = $collection['orderedItems'] ?? $collection['items'] ?? [];
			foreach (is_array($items) ? $items : [] as $item) {
				$id = self::actorOf($item, $type);
				if ($id !== '') {
					$ids[] = $id;
				}
			}
			$collection = $collection['first'] ?? $collection['next'] ?? null;
		}
		$ids = array_slice(array_values(array_unique($ids)), 0, $limit);
		$this->queue->resolveActors($ids);

		return $ids;
	}

	#[\Override]
	public function quotes(Stream $post, int $limit): int {
		return 0;
	}

	/**
	 * The account behind one item: a `Like` or an `Announce` names its
	 * actor; a bare address is taken to be the account.
	 */
	private static function actorOf(mixed $item, string $type): string {
		if (is_array($item)) {
			$actor = $item['actor'] ?? (in_array($item['type'] ?? '', ['Person', 'Service', 'Group', 'Organization', 'Application'], true) ? ($item['id'] ?? '') : '');
			if (is_array($actor)) {
				$actor = $actor['id'] ?? '';
			}
			$id = is_string($actor) ? $actor : '';
		} else {
			$id = is_string($item) && in_array($type, [Like::TYPE, Announce::TYPE], true) ? $item : '';
		}

		return preg_match('#^https?://#i', $id) === 1 ? $id : '';
	}
}
