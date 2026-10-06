<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\AP;
use OCA\Social\Db\StreamRequest;
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
	 * A post named by its address, fetched from the server that holds it.
	 *
	 * `resolve=true` is a reader saying "I have a link, go and get it" — it is
	 * how somebody replies to or boosts a post they found in a browser, and
	 * without it that is impossible: the post is on another server and nothing
	 * here has ever had a reason to ask for it.
	 *
	 * Fetching an address a reader supplies is the part that needs care, and
	 * three things already stand between this and an open proxy: the route is
	 * rate-limited per user, `CurlService` refuses an instance the access list
	 * bars and pins the address it resolved across redirects, and the document
	 * that comes back has to *claim the id it was fetched from*. That last one
	 * is what stops somebody hosting a document that says it is a post of
	 * yours; it is the same check `StreamQueueService::cacheItem()` makes when
	 * it walks up a thread.
	 *
	 * A post this instance already holds is returned without asking anybody.
	 *
	 * `$asViewer` decides whether that last sentence is subject to who is
	 * asking, and it has to, because the two callers are asking different
	 * questions. A reader pasting an address wants a post *they* may read, so
	 * the search route passes `true`: without it, an address typed into the
	 * search box answered with a followers-only or direct post the reader was
	 * never sent — the content search above it is viewer-scoped and finds
	 * nothing, which is exactly what makes this run. `RelayService` is asking
	 * whether the *instance* already holds an object at all, on nobody's
	 * behalf, and passes `false`.
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
				// A document is only evidence about itself — with one
				// exception this app has to make: a PeerTube watch page is
				// `/w/{shortUUID}`, which is the address a person copies out
				// of their browser and *not* the object's own id. PeerTube
				// answers an ActivityPub request there with the video whose id
				// is the long form, so the document is trusted when it names
				// the address it was fetched from among its own `url` links —
				// which is the same evidence, one level in.
				if (!$this->claimsUrl($data, $uri)) {
					throw new InvalidOriginException('the document does not claim the address it came from');
				}
			}

			// Every type another server `Create`s into a timeline, not only the
			// two this app writes itself. Pasting a PeerTube video's address
			// found nothing at all, although the very same object would have
			// been stored had it arrived by following the channel — the inbox
			// and the search disagreed about what a post is.
			if (!in_array($object->getType(), array_merge([Note::TYPE, Question::TYPE], AP::NOTE_LIKE_TYPES), true)) {
				throw new InvalidResourceException('not a post');
			}

			$object->setOrigin(
				(string)parse_url($uri, PHP_URL_HOST), SignatureService::ORIGIN_REQUEST, time()
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
	 * Whether a fetched document names the address it was fetched from.
	 *
	 * For a PeerTube `Video` that is the `text/html` link in its `url` list —
	 * the short `/w/xxx` watch page a person copies out of their browser,
	 * which is not the object's id. Checked against the links the document
	 * itself publishes, so what is trusted is still only the document's own
	 * word about itself.
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

	public function searchUri(string $search): array {
		$type = $this->getTypeFromSearch($search);

		if ($search !== '' && $type & self::SEARCH_URI) {
			try {
				return [$this->cacheActorService->getFromId($search)];
			} catch (Exception $e) {
			}
		}

		return [];
	}

	/**
	 * @param string $search
	 * @param int|null $limit how many accounts a paging caller needs, null for all of them
	 * @param string $followedBy when set, only the accounts this actor follows
	 *
	 * @return Person[]
	 */
	public function searchAccounts(string $search, ?int $limit = null, string $followedBy = ''): array {
		$type = $this->getTypeFromSearch($search);

		if ($search === '' || !($type & self::SEARCH_ACCOUNTS)) {
			return [];
		}

		$search = ltrim($search, '@');

		// an account this instance has never seen is not one anybody here
		// follows, so fetching it would be a request to another server whose
		// answer is filtered straight back out
		if ($followedBy === '') {
			try {
				// search and cache eventual exact account first
				$this->cacheActorService->getFromAccount($search);
			} catch (Exception $e) {
			}
		}

		return $this->cacheActorService->searchCachedAccounts($search, $limit, $followedBy);
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
