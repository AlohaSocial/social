<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use InvalidArgumentException;
use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Client\ClientSession;
use OCA\Social\Atproto\Client\Preferences;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\DurableCache;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Bluesky custom feeds and lists, read here (§9.6). The ones a person keeps
 * are their `savedFeedsPrefV2` preference, shared with a Bluesky app signed in
 * here. Feeds are read from the AppView as the person, so a feed that ranks
 * for its reader ranks for them; the opaque AppView cursor is kept for the
 * last post of each page.
 */
class BlueskyFeeds {
	public const SAVED = 'app.bsky.actor.defs#savedFeedsPrefV2';
	public const FEED = 'feed';
	public const LIST = 'list';
	/** how long the cursor after a page is kept */
	private const CURSOR_KEPT = 3600;
	/** Bound empty pages while preserving the cursor for the next request. */
	private const MAX_EMPTY_PAGES = 10;
	/** Keep a profile request bounded while filling a short AppView page. */
	private const MAX_PROFILE_PAGES = 2;
	private const CURSORS = 'social-atproto-feed-cursors';
	private const CURSOR_RETRIES = 'social-atproto-feed-retries';
	private const PROFILE_CURSORS = 'social-atproto-profile-cursors';
	private const PROFILE_CURSOR_PAGES = 'social-atproto-profile-cursor-pages';
	private const PROFILE_RETRIES = 'social-atproto-profile-retries';
	private const PROFILE_PAGE_LOCK = 'social-atproto-profile-page-';
	private const FOR_YOU_FEED_POSTS = 'social-atproto-for-you-feed-posts';
	private const FOR_YOU_FEED_POSTS_KEPT = 604800;
	private const MAX_FOR_YOU_FEED_POSTS = 100;
	private const MAX_FOR_YOU_SAVED_FEEDS = 10;
	private const COLLECTIONS = [self::FEED => 'app.bsky.feed.generator', self::LIST => 'app.bsky.graph.list'];
	private bool $feedPagePending = false;

	public function __construct(
		private AppViewClient $appView,
		private IdentityService $identities,
		private Preferences $preferences,
		private PostStore $postStore,
		private StreamRequest $streams,
		private DurableCache $durableCache,
		private ILockingProvider $locks,
		private LoggerInterface $logger,
		private ?BlueskyBlockedBy $blockedBy = null,
	) {
	}

	/**
	 * The feeds and lists the person keeps, pinned first, as the AppView
	 * describes them; one it no longer knows is left out.
	 *
	 * @return list<array{uri: string, type: string, name: string, description: string, avatar: string, creator: string, pinned: bool}>
	 */
	public function saved(Person $actor): array {
		$items = array_values(array_filter($this->savedItems($this->session($actor)), static fn (array $item): bool => in_array($item['type'] ?? '', [self::FEED, self::LIST], true)));
		usort($items, static fn (array $a, array $b): int => ($b['pinned'] ?? false) <=> ($a['pinned'] ?? false));
		$described = $this->describe(array_map(static fn (array $item): string => (string)$item['value'], $items));
		$saved = [];
		foreach ($items as $item) {
			$view = $described[(string)$item['value']] ?? null;
			if ($view !== null) {
				$saved[] = $view + ['pinned' => (bool)($item['pinned'] ?? false)];
			}
		}

		return $saved;
	}

	/**
	 * Keeps a feed or list: its `at://` URI or its bsky.app address.
	 *
	 * @return array{uri: string, type: string, name: string, description: string, avatar: string, creator: string, pinned: bool}
	 * @throws InvalidArgumentException with what to tell the person
	 */
	public function add(Person $actor, string $typed): array {
		$uri = $this->uriOf($typed);
		$view = $this->describe([$uri])[$uri] ?? throw new InvalidArgumentException('Bluesky does not know that feed or list');
		$session = $this->session($actor);
		$items = $this->savedItems($session);
		if (array_filter($items, static fn (array $item): bool => ($item['value'] ?? '') === $uri) === []) {
			$items[] = ['type' => $view['type'], 'value' => $uri, 'pinned' => true, 'id' => bin2hex(random_bytes(6))];
			$this->writeSaved($session, $items);
		}

		return $view + ['pinned' => true];
	}

	/**
	 * Stops keeping a feed or list.
	 */
	public function remove(Person $actor, string $uri): void {
		$session = $this->session($actor);
		$items = $this->savedItems($session);
		$kept = array_values(array_filter($items, static fn (array $item): bool => ($item['value'] ?? '') !== $uri));
		if (count($kept) !== count($items)) {
			$this->writeSaved($session, $kept);
		}
		$this->durableCache->remove(self::FOR_YOU_FEED_POSTS, $this->forYouFeedKey($actor, $uri));
	}

	/**
	 * The feeds Bluesky suggests to the person, or to anybody while the
	 * person is not on Bluesky.
	 *
	 * @return list<array{uri: string, type: string, name: string, description: string, avatar: string, creator: string}>
	 */
	public function suggested(Person $actor): array {
		try {
			$identity = $this->identities->activeForActor($actor);
			$answer = $identity !== null
				? $this->appView->queryAs($identity->did, $this->identities->signingKey($identity), 'app.bsky.feed.getSuggestedFeeds', ['limit' => 25])
				: $this->appView->query('app.bsky.feed.getSuggestedFeeds', ['limit' => 25]);
		} catch (Throwable $e) {
			$this->logger->info('Suggested Bluesky feeds not read', ['exception' => $e]);

			return [];
		}

		return array_values(array_filter(array_map(
			static fn ($view): ?array => is_array($view) ? self::describeFeed($view) : null,
			is_array($answer['feeds'] ?? null) ? $answer['feeds'] : [],
		)));
	}

	/**
	 * A page of a feed or list, in its own order: the first page without
	 * `$after`, else the page after the post of that id.
	 *
	 * @return Stream[] the posts, as the viewer may see them
	 * @throws InvalidArgumentException when the URI is not a feed's or a list's
	 */
	public function page(Person $actor, string $uri, string $after, int $limit): array {
		$this->feedPagePending = false;
		$type = self::typeOf($uri) ?? throw new InvalidArgumentException('Not a Bluesky feed or list');
		$key = $this->cursorKey($actor, $uri, $after);
		$retryCursor = $this->durableCache->get(self::CURSOR_RETRIES, $key);
		$cursor = is_string($retryCursor) ? $retryCursor : '';
		if ($cursor === '' && $after !== '') {
			$cursor = $this->durableCache->get(self::CURSORS, $key);
			if (!is_string($cursor) || $cursor === '') {
				// the end, or a cursor kept too long ago to be had
				return [];
			}
		}
		$identity = $this->identities->activeForActor($actor);
		$method = $type === self::FEED ? 'app.bsky.feed.getFeed' : 'app.bsky.feed.getListFeed';
		$seen = $cursor === '' ? [] : [$cursor => true];
		$emptyPages = 0;
		$feedPages = 0;
		$deadline = hrtime(true) + (AppViewClient::INTERACTIVE_TIMEOUT * 1_000_000_000);
		$this->streams->setViewer($actor);

		while (true) {
			$timeout = $feedPages === 0
				? AppViewClient::INTERACTIVE_TIMEOUT
				: (int)floor(($deadline - hrtime(true)) / 1_000_000_000);
			if ($timeout < 1) {
				$this->durableCache->set(self::CURSOR_RETRIES, $key, $cursor, self::CURSOR_KEPT);
				$this->feedPagePending = true;

				return [];
			}
			$params = [$type === self::FEED ? 'feed' : 'list' => $uri, 'limit' => max(1, min(100, $limit))] + ($cursor !== '' ? ['cursor' => $cursor] : []);
			$answer = $identity !== null
				? $this->appView->queryAs($identity->did, $this->identities->signingKey($identity), $method, $params, $timeout)
				: $this->appView->query($method, $params, [], $timeout);
			$feedPages++;
			if (!is_array($answer['feed'] ?? null)) {
				throw new UnexpectedValueException('Bluesky feed response has no feed array');
			}
			if ($identity !== null) {
				// before the page is read back, so a blocker's posts leave it
				$this->blockedBy?->learn($actor, $answer);
			}

			$page = [];
			foreach ($answer['feed'] as $item) {
				if (!is_array($item) || !is_array($item['post'] ?? null)) {
					continue;
				}
				$this->postStore->storeFeedItem($item);
				$postId = BlueskyIds::postIdOfUri((string)($item['post']['uri'] ?? ''));
				try {
					$page[] = $this->streams->getStreamById($postId, true, ACore::FORMAT_LOCAL);
				} catch (StreamNotFoundException) {
					// hidden here: a label, a block, a muted account
				}
			}

			if (array_key_exists('cursor', $answer) && !is_string($answer['cursor'])) {
				throw new UnexpectedValueException('Bluesky feed response has an invalid cursor');
			}
			$next = $answer['cursor'] ?? '';
			$last = end($page);
			if ($last instanceof Stream) {
				if ($type === self::FEED) {
					$this->rememberForYouFeedPage($actor, $uri, $page);
				}
				if ($next !== '') {
					$this->durableCache->set(self::CURSORS, $this->cursorKey($actor, $uri, (string)$last->getNid()), $next, self::CURSOR_KEPT);
				}
				$this->durableCache->remove(self::CURSOR_RETRIES, $key);

				return $page;
			}
			if ($next === '') {
				$this->durableCache->remove(self::CURSOR_RETRIES, $key);

				return [];
			}
			if (isset($seen[$next])) {
				throw new UnexpectedValueException('Bluesky feed pagination cursor did not advance');
			}
			$seen[$next] = true;
			if (++$emptyPages >= self::MAX_EMPTY_PAGES) {
				$this->durableCache->set(self::CURSOR_RETRIES, $key, $next, self::CURSOR_KEPT);
				$this->feedPagePending = true;

				return [];
			}
			$cursor = $next;
		}
	}

	/** Whether a hidden-page walk stopped at the interactive request budget. */
	public function hasFeedPagePending(): bool {
		return $this->feedPagePending;
	}

	/**
	 * Recent post IDs already read from the reader's saved custom feeds.
	 * This is cache-only: it never calls an AppView while building For You.
	 *
	 * @return list<string>
	 */
	public function recommendationFeedPostNids(Person $actor, int $limit): array {
		$limit = max(0, min(self::MAX_FOR_YOU_FEED_POSTS, $limit));
		if ($limit === 0) {
			return [];
		}
		try {
			$items = $this->savedItems($this->session($actor));
		} catch (InvalidArgumentException) {
			return [];
		}

		$nids = [];
		$feeds = 0;
		foreach ($items as $item) {
			$uri = is_string($item['value'] ?? null) ? $item['value'] : '';
			if (self::typeOf($uri) !== self::FEED) {
				continue;
			}
			$feeds++;
			$cached = $this->durableCache->get(self::FOR_YOU_FEED_POSTS, $this->forYouFeedKey($actor, $uri));
			if (is_array($cached)) {
				foreach ($cached as $nid) {
					if (is_string($nid) && $nid !== '' && !in_array($nid, $nids, true)) {
						$nids[] = $nid;
						if (count($nids) >= $limit) {
							return $nids;
						}
					}
				}
			}
			if ($feeds >= self::MAX_FOR_YOU_SAVED_FEEDS) {
				break;
			}
		}

		return $nids;
	}

	/**
	 * Reads one public author-feed page into the shared stream, for the ordinary
	 * account-status API. The AppView cursor is kept for the last local post id;
	 * a missing or expired one ends paging instead of restarting at the top.
	 *
	 * @return bool whether the client should ask again to continue filling this page
	 */
	public function authorPage(Person $actor, string $after, int $limit, ?Person $viewer = null): bool {
		if (!BlueskyActorService::isBluesky($actor)) {
			return false;
		}

		$key = $this->profileCursorKey($actor, $after);
		$lock = self::PROFILE_PAGE_LOCK . $key;
		$acquired = false;
		try {
			$this->locks->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
			$acquired = true;
		} catch (LockedException) {
			// The request holding this cursor's lock will persist its AppView
			// progress. Tell the profile page to poll instead of fetching it twice.
			return true;
		} catch (Throwable $e) {
			// Profiles remain readable during a lock-provider outage; at worst,
			// concurrent requests may repeat the short bounded AppView read.
			$this->logger->info('Could not lock a Bluesky profile page', ['actor' => $actor->getId(), 'exception' => $e]);
		}

		try {
			return $this->readAuthorPage($actor, $after, $limit, $viewer, $key);
		} finally {
			if ($acquired) {
				$this->locks->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
			}
		}
	}

	private function readAuthorPage(Person $actor, string $after, int $limit, ?Person $viewer, string $key): bool {
		$cursor = '';
		$retry = $this->durableCache->get(self::PROFILE_RETRIES, $key);
		if (is_string($retry) && $retry !== '') {
			// Continue a short or failed page from the cursor the last attempt
			// reached, whether this is the initial page or a later max_id page.
			$cursor = $retry;
		} elseif ($after !== '') {
			$stored = $this->durableCache->get(self::PROFILE_CURSORS, $key);
			if (!is_string($stored) || $stored === '') {
				return false;
			}
			$cursor = $stored;
			if ($this->durableCache->get(self::PROFILE_CURSOR_PAGES, $this->profileCursorKey($actor, $cursor)) === true) {
				return false;
			}
		}

		$seen = $cursor === '' ? [] : [$cursor => true];
		$emptyPages = 0;
		$profilePages = 0;
		$visibleCount = 0;
		$visibleSeen = [];
		if ($viewer === null) {
			$this->streams->resetViewer();
		} else {
			$this->streams->setViewer($viewer);
		}
		$identity = null;
		$signingKey = null;
		if ($viewer !== null) {
			try {
				$identity = $this->identities->activeForActor($viewer);
				$signingKey = $identity !== null ? $this->identities->signingKey($identity) : null;
			} catch (Throwable $e) {
				$this->logger->info('Bluesky profile feed could not use the linked identity', ['actor' => $viewer->getId(), 'exception' => $e]);
				$identity = null;
				$signingKey = null;
			}
		}

		// A profile request may need several AppView pages when locally hidden
		// posts are interleaved. Keep the whole refill within one interactive
		// network budget instead of paying the full timeout for every page.
		$deadline = hrtime(true) + (AppViewClient::INTERACTIVE_TIMEOUT * 1_000_000_000);
		try {
			while (true) {
				$timeout = $profilePages === 0
					? AppViewClient::INTERACTIVE_TIMEOUT
					: (int)floor(($deadline - hrtime(true)) / 1_000_000_000);
				if ($timeout < 1) {
					if ($cursor !== '') {
						$this->durableCache->set(self::PROFILE_RETRIES, $key, $cursor, self::CURSOR_KEPT);
					}

					return true;
				}
				$params = [
					'actor' => BlueskyIds::didOf($actor->getId()),
					'filter' => 'posts_with_replies',
					// Include one post beyond the API page so max_id can continue
					// when firehose rows and AppView rows differ at the page edge.
					'limit' => min(100, max(1, $limit + 1)),
				] + ($cursor !== '' ? ['cursor' => $cursor] : []);
				$answer = $identity !== null && $signingKey !== null
					? $this->appView->queryAs($identity->did, $signingKey, 'app.bsky.feed.getAuthorFeed', $params, $timeout)
					: $this->appView->query('app.bsky.feed.getAuthorFeed', $params, [], $timeout);
				if (!is_array($answer['feed'] ?? null)) {
					throw new UnexpectedValueException('Bluesky author feed response has no feed array');
				}

				$visibleIds = [];
				foreach ($answer['feed'] as $item) {
					if (!is_array($item) || !is_array($item['post'] ?? null)) {
						continue;
					}
					// A profile page does not need every reply's parent beforehand;
					// the thread endpoint reads that context when somebody opens it.
					// Avoid making one AppView request per missing parent in this page.
					$this->postStore->storeFeedItem($item, false);
					$id = BlueskyIds::postIdOfUri((string)($item['post']['uri'] ?? ''));
					try {
						$stream = $this->streams->getStreamById($id, true, ACore::FORMAT_LOCAL);
						$visibleIds[] = (string)$stream->getNid();
					} catch (StreamNotFoundException) {
						// A locally hidden post does not define the client's next page.
					}
					$republisher = (string)($item['reason']['by']['did'] ?? '');
					if ($republisher === BlueskyIds::didOf($actor->getId())) {
						try {
							$announce = $this->streams->getAnnounceBy($id, $actor->getId());
							$visibleIds[] = (string)$announce->getNid();
						} catch (StreamNotFoundException) {
						}
					}
				}

				if (array_key_exists('cursor', $answer) && !is_string($answer['cursor'])) {
					throw new UnexpectedValueException('Bluesky author feed response has an invalid cursor');
				}
				$next = $answer['cursor'] ?? '';
				if ($cursor !== '') {
					$this->durableCache->set(self::PROFILE_CURSOR_PAGES, $this->profileCursorKey($actor, $cursor), true, self::CURSOR_KEPT);
				}
				if ($visibleIds !== []) {
					$emptyPages = 0;
					if ($next !== '') {
						foreach (array_unique($visibleIds) as $visibleId) {
							$this->durableCache->set(self::PROFILE_CURSORS, $this->profileCursorKey($actor, $visibleId), $next, self::CURSOR_KEPT);
						}
					}
					foreach (array_unique($visibleIds) as $visibleId) {
						if (!isset($visibleSeen[$visibleId])) {
							$visibleSeen[$visibleId] = true;
							$visibleCount++;
						}
					}
					if ($visibleCount >= max(1, $limit + 1) || $next === '') {
						$this->durableCache->remove(self::PROFILE_RETRIES, $key);

						return false;
					}
				}
				if ($next === '') {
					$this->durableCache->remove(self::PROFILE_RETRIES, $key);

					return false;
				}
				if (isset($seen[$next])) {
					throw new UnexpectedValueException('Bluesky author-feed cursor did not advance');
				}
				$seen[$next] = true;
				if ($visibleIds === []) {
					if (++$emptyPages >= self::MAX_EMPTY_PAGES) {
						throw new UnexpectedValueException('Bluesky author feed has too many consecutive pages with no visible posts');
					}
				}
				if (++$profilePages >= self::MAX_PROFILE_PAGES) {
					$this->durableCache->set(self::PROFILE_RETRIES, $key, $next, self::CURSOR_KEPT);

					return true;
				}
				$cursor = $next;
			}
		} catch (Throwable $e) {
			$this->logger->info('Bluesky profile feed not read', ['actor' => $actor->getId(), 'exception' => $e]);
			// Keep the progress made past hidden or unavailable records. Without
			// this, a failed page retries from the previous visible post's cursor
			// and asks the AppView for the same empty pages again.
			if ($cursor !== '') {
				$this->durableCache->set(self::PROFILE_RETRIES, $key, $cursor, self::CURSOR_KEPT);
			}

			return true;
		}
	}

	private function profileCursorKey(Person $actor, string $after): string {
		return hash('sha256', $actor->getId() . "\0" . $after);
	}

	/**
	 * What kind of thing an `at://` URI names: a feed, a list, or neither.
	 */
	public static function typeOf(string $uri): ?string {
		$parsed = Syntax::parseAtUri($uri);
		if ($parsed === null || !Syntax::isDid($parsed['authority']) || $parsed['rkey'] === '') {
			return null;
		}
		$type = array_search($parsed['collection'], self::COLLECTIONS, true);

		return is_string($type) ? $type : null;
	}

	/**
	 * The `at://` URI a person typed or pasted: one already, or a bsky.app
	 * address of a feed or a list.
	 *
	 * @throws InvalidArgumentException
	 */
	private function uriOf(string $typed): string {
		$typed = trim($typed);
		if (self::typeOf($typed) !== null) {
			return $typed;
		}
		if (preg_match('#^https://bsky\.app/profile/([^/?\#]+)/(feed|lists)/([^/?\#]+)#', $typed, $m) !== 1) {
			throw new InvalidArgumentException('That is not the address of a Bluesky feed or list');
		}
		$actor = strtolower(rawurldecode($m[1]));
		if (!Syntax::isDid($actor)) {
			try {
				$actor = (string)($this->appView->query('com.atproto.identity.resolveHandle', ['handle' => $actor])['did'] ?? '');
			} catch (Throwable) {
				$actor = '';
			}
		}
		$uri = 'at://' . $actor . '/' . self::COLLECTIONS[$m[2] === 'feed' ? self::FEED : self::LIST] . '/' . rawurldecode($m[3]);
		if (self::typeOf($uri) === null) {
			throw new InvalidArgumentException('Bluesky does not know whose feed or list that is');
		}

		return $uri;
	}

	/**
	 * What the AppView says of feeds and lists, by URI.
	 *
	 * @param string[] $uris
	 * @return array<string, array{uri: string, type: string, name: string, description: string, avatar: string, creator: string}>
	 */
	private function describe(array $uris): array {
		$described = [];
		$feeds = array_values(array_filter($uris, static fn (string $uri): bool => self::typeOf($uri) === self::FEED));
		foreach (array_chunk($feeds, 25) as $chunk) {
			try {
				$answer = $this->appView->query('app.bsky.feed.getFeedGenerators', ['feeds' => $chunk]);
			} catch (Throwable $e) {
				$this->logger->info('Bluesky feeds not described', ['exception' => $e]);
				continue;
			}
			foreach (is_array($answer['feeds'] ?? null) ? $answer['feeds'] : [] as $view) {
				$mapped = is_array($view) ? self::describeFeed($view) : null;
				if ($mapped !== null) {
					$described[$mapped['uri']] = $mapped;
				}
			}
		}
		foreach ($uris as $uri) {
			if (self::typeOf($uri) !== self::LIST) {
				continue;
			}
			try {
				$list = $this->appView->query('app.bsky.graph.getList', ['list' => $uri, 'limit' => 1])['list'] ?? null;
			} catch (Throwable) {
				continue;
			}
			if (is_array($list) && ($list['uri'] ?? '') === $uri) {
				$described[$uri] = [
					'uri' => $uri,
					'type' => self::LIST,
					'name' => (string)($list['name'] ?? ''),
					'description' => (string)($list['description'] ?? ''),
					'avatar' => (string)($list['avatar'] ?? ''),
					'creator' => (string)($list['creator']['handle'] ?? ''),
				];
			}
		}

		return $described;
	}

	/**
	 * A feed generator's view, as this app describes a feed.
	 *
	 * @return array{uri: string, type: string, name: string, description: string, avatar: string, creator: string}|null
	 */
	public static function describeFeed(array $view): ?array {
		$uri = (string)($view['uri'] ?? '');
		if (self::typeOf($uri) !== self::FEED) {
			return null;
		}

		return [
			'uri' => $uri,
			'type' => self::FEED,
			'name' => (string)($view['displayName'] ?? ''),
			'description' => (string)($view['description'] ?? ''),
			'avatar' => (string)($view['avatar'] ?? ''),
			'creator' => (string)($view['creator']['handle'] ?? ''),
		];
	}

	/**
	 * The items of the saved-feeds preference.
	 *
	 * @return list<array>
	 */
	private function savedItems(ClientSession $session): array {
		$items = [];
		foreach ($this->preferences->get($session)['preferences'] as $preference) {
			if (is_array($preference) && ($preference['$type'] ?? '') === self::SAVED && is_array($preference['items'] ?? null)) {
				foreach ($preference['items'] as $item) {
					if (is_array($item)) {
						$items[] = $item;
					}
				}
				break;
			}
		}

		return $items;
	}

	/**
	 * Writes the saved-feeds preference, the others as they were.
	 *
	 * @param list<array> $items
	 */
	private function writeSaved(ClientSession $session, array $items): void {
		$preferences = array_values(array_filter(
			$this->preferences->get($session)['preferences'],
			static fn ($preference): bool => !is_array($preference) || ($preference['$type'] ?? '') !== self::SAVED,
		));
		$preferences[] = ['$type' => self::SAVED, 'items' => $items];
		$this->preferences->put($session, ['preferences' => $preferences]);
	}

	/**
	 * @throws InvalidArgumentException
	 */
	private function identityOf(Person $actor): Identity {
		return $this->identities->forActor($actor, false) ?? throw new InvalidArgumentException('This account is not on Bluesky');
	}

	/**
	 * @throws InvalidArgumentException
	 */
	private function session(Person $actor): ClientSession {
		return new ClientSession($actor->getUserId(), $this->identityOf($actor), '');
	}

	private function cursorKey(Person $actor, string $uri, string $after): string {
		return md5($actor->getId() . '|' . $uri . '|' . $after);
	}

	/** @param list<Stream> $page */
	private function rememberForYouFeedPage(Person $actor, string $uri, array $page): void {
		$key = $this->forYouFeedKey($actor, $uri);
		$cached = $this->durableCache->get(self::FOR_YOU_FEED_POSTS, $key);
		$previous = is_array($cached) ? array_values(array_filter($cached, 'is_string')) : [];
		$current = array_values(array_filter(array_map(
			static fn (Stream $post): string => (string)$post->getNid(),
			$page,
		), static fn (string $nid): bool => $nid !== '' && $nid !== '0'));
		$nids = array_slice(array_values(array_unique(array_merge($current, $previous))), 0, self::MAX_FOR_YOU_FEED_POSTS);
		if ($nids !== []) {
			$this->durableCache->set(self::FOR_YOU_FEED_POSTS, $key, $nids, self::FOR_YOU_FEED_POSTS_KEPT);
		}
	}

	private function forYouFeedKey(Person $actor, string $uri): string {
		return hash('sha256', $actor->getId() . '|' . $uri);
	}
}
