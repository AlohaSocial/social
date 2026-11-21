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
use OCA\Social\Service\DurableCache;
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
	/** Enough 20-account collection pages to fill the public API's 80-actor cap. */
	private const PAGES = 4;
	private const CURSOR = 'social.interactions.activitypub.cursor';
	private const CURSOR_TTL = 3600;
	private const CURSOR_VERSION = 1;
	private ?string $incompleteRead = null;

	public function __construct(
		private CurlService $curl,
		private RemoteFetchQueue $queue,
		private LoggerInterface $logger,
		private DurableCache $durableCache,
	) {
	}

	#[\Override]
	public function supports(Stream $post): bool {
		return !$post->isLocal() && !BlueskyIds::isPostId($post->getId());
	}

	#[\Override]
	public function actors(Stream $post, string $type, int $limit, ?int $timeout = null): array {
		$readKey = self::readKey($post, $type);
		$this->incompleteRead = null;
		$source = json_decode($post->getSource(), true);
		$collection = is_array($source) ? ($source[$type === Like::TYPE ? 'likes' : 'shares'] ?? null) : null;
		$savedCursor = $this->durableCache->getShared(self::CURSOR, $readKey);
		$ids = [];
		if (is_array($savedCursor) && ($savedCursor['version'] ?? null) === self::CURSOR_VERSION) {
			$collection = $savedCursor['collection'] ?? null;
			$ids = is_array($savedCursor['actors'] ?? null)
				? array_values(array_filter($savedCursor['actors'], 'is_string'))
				: [];
		} elseif (is_string($savedCursor) || is_array($savedCursor)) {
			$collection = $savedCursor;
		}
		$knownActors = array_fill_keys($ids, true);
		$seen = [];
		$requests = 0;
		$deadline = $timeout === null ? null : hrtime(true) + ($timeout * 1_000_000_000);
		for ($page = 0; (is_string($collection) || is_array($collection)) && $page < self::PAGES && count($ids) < $limit;) {
			// `first` and `next` may be link objects (`{"id":"https://…"}`); a
			// page-shaped object is already the page, a link must be dereferenced.
			if (is_array($collection) && !array_key_exists('orderedItems', $collection) && !array_key_exists('items', $collection)) {
				$reference = $collection['first'] ?? $collection['next'] ?? $collection['id'] ?? $collection['@id'] ?? $collection['href'] ?? null;
				$collection = self::collectionReference($reference);
				continue;
			}
			if (is_string($collection)) {
				if (preg_match('#^https?://#i', $collection) !== 1 || isset($seen[$collection]) || $requests >= self::PAGES) {
					break;
				}
				$seen[$collection] = true;
				$requests++;
				try {
					$requestTimeout = $timeout;
					if ($deadline !== null && $requests > 1) {
						$remaining = (int)floor(max(0, $deadline - hrtime(true)) / 1_000_000_000);
						if ($remaining < 1) {
							if ($ids === []) {
								throw new \RuntimeException('visible reaction read exceeded its time budget');
							}
							$this->incompleteRead = $readKey;

							break;
						}
						$requestTimeout = min($timeout ?? $remaining, $remaining);
					}
					$collection = $requestTimeout === null
						? $this->curl->retrieveObject($collection)
						: $this->curl->retrieveObject($collection, true, $requestTimeout);
				} catch (Throwable $e) {
					$this->logger->info('A collection of reactions was not read', ['post' => $post->getId(), 'exception' => $e]);
					if ($ids !== []) {
						$this->incompleteRead = $readKey;

						break;
					}
					throw $e;
				}
			}
			$items = $collection['orderedItems'] ?? $collection['items'] ?? [];
			foreach (is_array($items) ? $items : [] as $item) {
				$id = self::actorOf($item, $type);
				if ($id !== '' && !isset($knownActors[$id])) {
					$knownActors[$id] = true;
					$ids[] = $id;
				}
			}
			$page++;
			$reference = array_key_exists('orderedItems', $collection) || array_key_exists('items', $collection)
				? ($collection['next'] ?? null)
				: ($collection['first'] ?? $collection['next'] ?? null);
			$collection = self::collectionReference($reference);
			if ($collection === null || count($ids) >= $limit) {
				$this->durableCache->removeShared(self::CURSOR, $readKey);
			} else {
				$this->durableCache->setShared(self::CURSOR, $readKey, [
					'version' => self::CURSOR_VERSION,
					'collection' => $collection,
					'actors' => $ids,
				], self::CURSOR_TTL);
			}
		}
		if (count($ids) < $limit && (is_string($collection) || is_array($collection))) {
			// Four full pages cover the normal 80 accounts; with shorter pages, keep the
			// next link for the next fill.
			$this->incompleteRead = $readKey;
		}
		$ids = array_slice(array_values(array_unique($ids)), 0, $limit);
		$this->queue->resolveActors($ids);

		return $ids;
	}

	/** Whether the last read stopped before exhausting the collection. */
	public function hasMoreActors(Stream $post, string $type): bool {
		return $this->incompleteRead === self::readKey($post, $type);
	}

	private static function readKey(Stream $post, string $type): string {
		return hash('sha256', $post->getId() . "\0" . $type);
	}

	#[\Override]
	public function quotes(Stream $post, int $limit, ?int $timeout = null): int {
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

	/** A collection link, or an embedded page; malformed links are ignored. */
	private static function collectionReference(mixed $reference): string|array|null {
		if (is_array($reference)) {
			if (array_key_exists('orderedItems', $reference) || array_key_exists('items', $reference)) {
				return $reference;
			}
			$reference = $reference['id'] ?? $reference['@id'] ?? $reference['href'] ?? null;
		}

		return is_string($reference) && preg_match('#^https?://#i', $reference) === 1 ? $reference : null;
	}
}
