<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\FollowList;

use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\RemoteFetchQueue;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCP\AppFramework\Http;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Who follows an account on the fediverse, and whom it follows: its
 * `followers` and `following` collections, a few pages of them, as its
 * server describes them. A server can keep them to itself — Mastodon's
 * "hide your social graph" answers the collection and refuses its pages,
 * others answer only the number — and then the list is hidden. The
 * accounts not cached here are fetched in the background
 * (`RemoteFetchQueue::resolveActors()`).
 */
class ActivityPubFollowListSource implements FollowListSource {
	/** the most documents one read fetches: the collection and its first pages */
	private const PAGES = 4;

	public function __construct(
		private CurlService $curl,
		private CacheActorService $cacheActors,
		private RemoteFetchQueue $queue,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function supports(Person $account): bool {
		return !$account->isLocal() && $account->getId() !== '' && !BlueskyIds::isActorId($account->getId());
	}

	#[\Override]
	public function accounts(Person $account, string $direction, int $limit): ?array {
		$url = $direction === FollowListService::FOLLOWING ? $account->getFollowing() : $account->getFollowers();
		if (preg_match('#^https?://#i', $url) !== 1) {
			return [];
		}
		$collection = $url;
		$ids = [];
		for ($read = 0; $read < self::PAGES && count($ids) < $limit; $read++) {
			if (is_string($collection)) {
				if (preg_match('#^https?://#i', $collection) !== 1) {
					break;
				}
				try {
					$collection = $this->curl->retrieveObject($collection);
				} catch (RequestContentException $e) {
					if ($read > 0 && in_array($e->getCode(), [Http::STATUS_UNAUTHORIZED, Http::STATUS_FORBIDDEN], true)) {
						return null;
					}
					$this->logger->info('A follow list was not read', ['account' => $account->getId(), 'exception' => $e]);
					break;
				} catch (Throwable $e) {
					$this->logger->info('A follow list was not read', ['account' => $account->getId(), 'exception' => $e]);
					break;
				}
			}
			if (!is_array($collection)) {
				break;
			}
			$items = $collection['orderedItems'] ?? $collection['items'] ?? null;
			if ($read === 0 && $items === null && !isset($collection['first'])) {
				return null;
			}
			foreach (is_array($items) ? $items : [] as $item) {
				$id = self::actorOf($item);
				if ($id !== '' && $id !== $account->getId()) {
					$ids[] = $id;
				}
			}
			$collection = $collection['first'] ?? $collection['next'] ?? null;
			if (is_array($collection) && !isset($collection['orderedItems']) && !isset($collection['items']) && is_string($collection['id'] ?? null)) {
				$collection = $collection['id'];
			}
		}
		$ids = array_slice(array_values(array_unique($ids)), 0, $limit);
		$cached = $this->cacheActors->getCachedFromIds($ids);
		$this->queue->resolveActors(array_values(array_filter($ids, static fn (string $id): bool => !isset($cached[$id]))));

		return $ids;
	}

	/**
	 * The account behind one entry: its id, or the account itself inline.
	 */
	private static function actorOf(mixed $item): string {
		$id = is_array($item) ? ($item['id'] ?? '') : $item;
		if (!is_string($id)) {
			return '';
		}
		$anchor = strpos($id, '#');
		if ($anchor !== false) {
			$id = substr($id, 0, $anchor);
		}

		return preg_match('#^https?://#i', $id) === 1 ? $id : '';
	}
}
