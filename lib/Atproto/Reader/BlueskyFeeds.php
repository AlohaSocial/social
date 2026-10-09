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
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Bluesky's custom feeds and lists, read here (§9.6). Which ones a person
 * keeps is their Bluesky preference (`savedFeedsPrefV2`), the one a Bluesky
 * app signed in here reads and writes too — a feed saved in either shows in
 * both, and comes along when the account moves. A feed or list is read from
 * the AppView as the person, so a feed that ranks for its reader ranks for
 * them; its posts are stored as any Bluesky post is and answered in the
 * feed's own order. The AppView's cursor is opaque: it is kept for the last
 * post of each page, so a client pages with the post it last saw.
 */
class BlueskyFeeds {
	public const SAVED = 'app.bsky.actor.defs#savedFeedsPrefV2';
	public const FEED = 'feed';
	public const LIST = 'list';
	/** how long the cursor after a page is kept */
	private const CURSOR_KEPT = 3600;
	private const COLLECTIONS = [self::FEED => 'app.bsky.feed.generator', self::LIST => 'app.bsky.graph.list'];

	private ?ICache $cursors = null;

	public function __construct(
		private AppViewClient $appView,
		private IdentityService $identities,
		private Preferences $preferences,
		private PostStore $postStore,
		private StreamRequest $streams,
		private ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
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
	}

	/**
	 * The feeds Bluesky suggests to the person.
	 *
	 * @return list<array{uri: string, type: string, name: string, description: string, avatar: string, creator: string}>
	 */
	public function suggested(Person $actor): array {
		try {
			$identity = $this->identityOf($actor);
			$answer = $this->appView->queryAs($identity->did, $this->identities->signingKey($identity), 'app.bsky.feed.getSuggestedFeeds', ['limit' => 25]);
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
		$type = self::typeOf($uri) ?? throw new InvalidArgumentException('Not a Bluesky feed or list');
		$cursor = '';
		if ($after !== '') {
			$cursor = $this->cursors()->get($this->cursorKey($actor, $uri, $after));
			if (!is_string($cursor) || $cursor === '') {
				// the end, or a cursor kept too long ago to be had
				return [];
			}
		}
		$method = $type === self::FEED ? 'app.bsky.feed.getFeed' : 'app.bsky.feed.getListFeed';
		$params = [$type === self::FEED ? 'feed' : 'list' => $uri, 'limit' => max(1, min(100, $limit))] + ($cursor !== '' ? ['cursor' => $cursor] : []);
		$identity = $this->identities->forActor($actor, false);
		$answer = $identity !== null
			? $this->appView->queryAs($identity->did, $this->identities->signingKey($identity), $method, $params)
			: $this->appView->query($method, $params);

		$this->streams->setViewer($actor);
		$page = [];
		foreach (is_array($answer['feed'] ?? null) ? $answer['feed'] : [] as $item) {
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
		$next = is_string($answer['cursor'] ?? null) ? $answer['cursor'] : '';
		$last = end($page);
		if ($last instanceof Stream && $next !== '') {
			$this->cursors()->set($this->cursorKey($actor, $uri, (string)$last->getNid()), $next, self::CURSOR_KEPT);
		}

		return $page;
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

	private function cursors(): ICache {
		return $this->cursors ??= $this->cacheFactory->createDistributed('social-atproto-feed-cursors');
	}
}
