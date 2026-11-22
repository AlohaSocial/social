<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\AP;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Reader\BlueskySearch;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tools\Traits\TArrayTools;
use Psr\Log\LoggerInterface;

/**
 * Class SearchService
 *
 * @package OCA\Social\Service
 */
class SearchService {
	use TArrayTools;

	public const SEARCH_URI = 1;
	public const SEARCH_ACCOUNTS = 2;
	public const SEARCH_HASHTAGS = 4;
	public const SEARCH_CONTENT = 8;
	public const SEARCH_ALL = 15;

	public function __construct(
		private CacheActorService $cacheActorService,
		private HashtagService $hashtagService,
		private StreamRequest $streamRequest,
		private LoggerInterface $logger,
		private CurlService $curlService,
		private SignatureService $signatureService,
		private ?BlueskySearch $bluesky = null,
		private ?RemoteFetchQueue $remoteFetchQueue = null,
	) {
	}

	/**
	 * Full-text search over the statuses the viewer can see. The viewer bound
	 * comes from StreamRequest::setViewer(), set by the caller.
	 *
	 * $limit is how many rows a caller that pages its results needs; null asks
	 * for as many as the request returns on its own. `$authorId` narrows the
	 * answers to one account's posts; `$offset`, `$maxId` and `$minId` page
	 * them, see StreamRequest::searchContent().
	 *
	 * @return \OCA\Social\Model\ActivityPub\Stream[]
	 */
	public function searchStreamContent(
		string $search, ?int $limit = null, int $offset = 0, string $authorId = '',
		int|string $maxId = 0, int|string $minId = 0,
	): array {
		$type = $this->getTypeFromSearch($search);
		if ($search === '' || !($type & self::SEARCH_CONTENT)) {
			return [];
		}

		return $this->streamRequest->searchContent($search, $limit ?? 20, $offset, $authorId, $maxId, $minId);
	}

	/**
	 * @param string $search
	 *
	 * @return Person[]
	 */
	/**
	 * A post named by its address, fetched from the server that holds it, for
	 * `resolve=true` ("I have a link, go and get it").
	 *
	 * Guards against an open proxy: the route is rate-limited, `CurlService`
	 * enforces the access list and pins the resolved address, and the
	 * document must claim the id it was fetched from (as
	 * `StreamQueueService::cacheItem()` checks). A post with an id on this
	 * instance is never taken from another server's answer.
	 *
	 * A post already held is returned without fetching. `$asViewer` is `true`
	 * for the search route, so only posts the reader may see are returned;
	 * `RelayService` passes `false`, asking on nobody's behalf.
	 */
	public function resolveStatus(string $uri, bool $asViewer = false): ?Stream {
		// `getTypeFromSearch()` is no use here: it answers SEARCH_ALL for plain
		// text, and SEARCH_ALL has the URI bit set, so every search term would
		// look like an address worth fetching
		if (!str_starts_with($uri, 'https://') && !str_starts_with($uri, 'http://')) {
			return null;
		}

		try {
			return $this->streamRequest->getStreamById($uri, $asViewer);
		} catch (Exception $e) {
		}

		try {
			$data = $this->curlService->retrieveObject($uri);
			$object = AP::instance()->getItemFromData($data);

			if ($object->getId() !== $uri) {
				// A document is only evidence about itself, with one exception:
				// a PeerTube watch page (`/w/{shortUUID}`) is not the object's
				// id, so the document is trusted when it lists the fetched
				// address among its own `url` links and its id is on the server
				// that served it.
				if (strtolower(ConfigService::authorityOf($object->getId())) !== strtolower(ConfigService::authorityOf($uri))
					|| !$this->claimsUrl($data, $uri)) {
					throw new InvalidOriginException('the document does not claim the address it came from');
				}
			}

			// this instance's own posts are in its database, never fetched
			if ($this->signatureService->isLocalActor($object->getId())) {
				throw new InvalidOriginException('a fetched document claims a local id');
			}

			// every type another server `Create`s into a timeline, as the inbox
			// accepts, PeerTube videos included
			if (!in_array($object->getType(), array_merge([Note::TYPE, Question::TYPE], AP::NOTE_LIKE_TYPES), true)) {
				throw new InvalidResourceException('not a post');
			}

			$object->setOrigin(
				ConfigService::authorityOf($uri), SignatureService::ORIGIN_REQUEST, time()
			);

			/** @var Stream $object */
			// its author has to be known before the post can be shown as theirs
			$this->cacheActorService->getFromId($object->getAttributedTo());

			AP::instance()->getInterfaceForItem($object)->save($object);

			return $this->streamRequest->getStreamById($object->getId(), $asViewer);
		} catch (Exception $e) {
			$this->logger->info('could not resolve a post by its address', [
				'uri' => $uri,
				'exception' => $e,
			]);

			return null;
		}
	}

	/**
	 * Whether a fetched document names the address it was fetched from: for a
	 * PeerTube `Video`, the `text/html` watch page among its `url` links.
	 *
	 * @param array<string, mixed> $data
	 */
	private function claimsUrl(array $data, string $uri): bool {
		$urls = $data['url'] ?? [];
		if (is_string($urls)) {
			return $urls === $uri;
		}

		if (!is_array($urls)) {
			return false;
		}

		foreach ($urls as $link) {
			if (is_string($link) && $link === $uri) {
				return true;
			}

			if (is_array($link) && ((string)($link['href'] ?? $link['url'] ?? '')) === $uri) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The actor at the address searched for, when the search is one.
	 *
	 * @param bool $retrieve whether an actor this instance does not hold is
	 *                       fetched now; without, it is answered from the
	 *                       cache and an unknown one is queued for
	 *                       `Cron\ResolveActor`, so a search repeated a moment
	 *                       later finds it
	 * @param bool $queue whether an unknown actor is queued at all when not
	 *                    retrieved; false answers from the cache and makes
	 *                    no request to any other server, now or later
	 *
	 * @return Person[]
	 */
	public function searchUri(string $search, bool $retrieve = true, bool $queue = true): array {
		$type = $this->getTypeFromSearch($search);

		if ($search === '' || !($type & self::SEARCH_URI)) {
			return [];
		}

		if (!$retrieve) {
			try {
				$actor = $this->cacheActorService->getCachedFromId($search);
			} catch (CacheActorDoesNotExistException $e) {
				if ($queue) {
					$this->remoteFetchQueue?->resolveActors([$search]);
				}

				return [];
			}

			// a Bluesky account its labels limit is kept and not offered, as
			// in `CacheActorService::searchCachedAccounts()`
			return BlueskyActorService::isLimited($actor) ? [] : [$actor];
		}

		try {
			return [$this->cacheActorService->getFromId($search)];
		} catch (Exception $e) {
		}

		return [];
	}

	/**
	 * The accounts known here by a name or handle.
	 *
	 * With Mastodon's `$resolve`, an exact unknown handle is looked up on its
	 * server and Bluesky's typeahead results are added (stored, so each has an
	 * id). Without it, as autocomplete sends, only the cache answers, and an
	 * unknown complete `user@host` is queued for `Cron\ResolveActor`.
	 *
	 * @param int|null $limit how many accounts a paging caller needs, null for all of them
	 * @param string $followedBy when set, only the accounts this actor follows
	 * @param bool $queue whether a complete handle not held here is queued
	 *                    when not resolved; false makes no request to any
	 *                    other server, now or later
	 *
	 * @return Person[]
	 */
	public function searchAccounts(string $search, ?int $limit = null, string $followedBy = '', bool $resolve = false, bool $queue = true): array {
		$type = $this->getTypeFromSearch($search);

		if ($search === '' || !($type & self::SEARCH_ACCOUNTS)) {
			return [];
		}

		$search = ltrim($search, '@');

		// an account this instance has never seen is not one anybody here
		// follows, so fetching it would be a request to another server whose
		// answer is filtered straight back out
		if ($followedBy === '' && $resolve) {
			try {
				// search and cache eventual exact account first
				$this->cacheActorService->getFromAccount($search);
			} catch (Exception $e) {
			}
		}

		$found = $this->cacheActorService->searchCachedAccounts($search, $limit, $followedBy);
		if ($followedBy !== '') {
			return $found;
		}

		if ($resolve) {
			if ($this->bluesky !== null) {
				$found = self::withBluesky($found, $this->bluesky->keep($this->bluesky->typeahead($search)));
			}
		} elseif ($queue && self::isCompleteHandle($search) && !self::holds($found, $search)) {
			$this->remoteFetchQueue?->resolveAccounts([$search]);
		}

		return $found;
	}

	/**
	 * Whether a search is a whole `user@host` handle rather than the start
	 * of one: a host with a dot and something after it.
	 */
	private static function isCompleteHandle(string $search): bool {
		return preg_match('/^[^@\s\/]+@[^@\s\/]+\.[^@\s\/.]{2,}$/', $search) === 1;
	}

	/**
	 * Whether the accounts found include the one a handle names.
	 *
	 * @param Person[] $found
	 */
	private static function holds(array $found, string $handle): bool {
		foreach ($found as $person) {
			if (strcasecmp($person->getAccount(), $handle) === 0) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The Bluesky accounts the typeahead found, after what is cached here,
	 * without repeating an account the cache already answered.
	 *
	 * @param Person[] $found
	 * @param Person[] $bluesky
	 * @return Person[]
	 */
	public static function withBluesky(array $found, array $bluesky): array {
		$ids = array_map(static fn (Person $p): string => $p->getId(), $found);
		foreach ($bluesky as $person) {
			if (!in_array($person->getId(), $ids, true)) {
				$found[] = $person;
			}
		}

		return $found;
	}

	/**
	 * @param string $search
	 * @param int|null $limit how many hashtags a paging caller needs, null for all of them
	 *
	 * @return array
	 */
	public function searchHashtags(string $search, ?int $limit = null): array {
		$result = [];
		$type = $this->getTypeFromSearch($search);
		if ($search === '' || !($type & self::SEARCH_HASHTAGS)) {
			return $result;
		}

		if (substr($search, 0, 1) === '#') {
			$search = substr($search, 1);
		}

		return $this->hashtagService->searchHashtags($search, true, $limit);
	}

	/**
	 * @param string $search
	 *
	 * @return array
	 */

	/**
	 * @param string $search
	 *
	 * @return int
	 */
	private function getTypeFromSearch(string $search): int {
		$char = substr($search, 0, 1);
		switch ($char) {
			case '@':
				return self::SEARCH_ACCOUNTS;
			case '#':
				return self::SEARCH_HASHTAGS;
			default:
				if (substr($search, 0, 4) === 'http') {
					return self::SEARCH_URI;
				}

				return self::SEARCH_ALL;
		}
	}
}
