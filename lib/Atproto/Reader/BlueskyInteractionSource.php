<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Moderation\LabelerService;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Publisher\PostRefs;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\Interaction\InteractionSource;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Who liked, reposted or quoted a post that is on Bluesky, as the AppView
 * lists them (`getLikes`, `getRepostedBy`, `getQuotes`). Accounts are cached
 * as they are found; quoting posts are stored as any Bluesky post is.
 */
class BlueskyInteractionSource implements InteractionSource {
	private const QUOTE_CURSOR = 'social.atproto.quotes.cursor';
	private const QUOTE_CURSOR_KEPT = 3600;
	private const MAX_QUOTE_PAGES = 100;

	public function __construct(
		private AtprotoConfig $config,
		private AppViewClient $appView,
		private PostRefs $refs,
		private PostStore $store,
		private ActorMapper $mapper,
		private BlueskyActorService $actors,
		private LabelerService $labelers,
		private LoggerInterface $logger,
		private DurableCache $durableCache,
	) {
	}

	#[\Override]
	public function supports(Stream $post): bool {
		return $this->config->isEnabled() && $this->refs->strongRef($post->getId()) !== null;
	}

	#[\Override]
	public function actors(Stream $post, string $type, int $limit, ?int $timeout = null): array {
		$uri = $this->refs->strongRef($post->getId())['uri'] ?? '';
		[$method, $key] = $type === Like::TYPE ? ['app.bsky.feed.getLikes', 'likes'] : ['app.bsky.feed.getRepostedBy', 'repostedBy'];
		try {
			$params = ['uri' => $uri, 'limit' => max(1, min(100, $limit))];
			$answer = $timeout === null
				? $this->appView->query($method, $params)
				: $this->appView->query($method, $params, timeout: $timeout);
		} catch (Throwable $e) {
			$this->logger->info('Bluesky reactions not read', ['uri' => $uri, 'exception' => $e]);

			throw $e;
		}
		if (($answer['uri'] ?? null) !== $uri) {
			throw new UnexpectedValueException('Bluesky reaction response names a different post');
		}
		if (!is_array($answer[$key] ?? null)) {
			throw new UnexpectedValueException('Bluesky reaction response has no ' . $key . ' list');
		}
		$ids = [];
		foreach ($answer[$key] as $entry) {
			if (!is_array($entry)) {
				throw new UnexpectedValueException('Bluesky reaction response contains an invalid entry');
			}
			$profile = is_array($entry['actor'] ?? null) ? $entry['actor'] : $entry;
			$did = is_array($profile) ? (string)($profile['did'] ?? '') : '';
			if (!Syntax::isDid($did) || !is_string($profile['handle'] ?? null) || trim($profile['handle']) === '') {
				throw new UnexpectedValueException('Bluesky reaction response contains an incomplete actor');
			}
			// Keep the identity even when caching its profile fails, so the
			// interaction service can queue an actor resolution.
			$ids[] = BlueskyIds::actorId($did);
			try {
				$cached = $this->actors->cached($did);
				$avatarUrl = trim((string)($profile['avatar'] ?? ''));
				if ($cached === null) {
					$this->actors->store($this->mapper->person($profile));
				} elseif (!$cached->isLocal() && $avatarUrl !== '') {
					// Reaction views carry only an avatar: refresh that without replacing the
					// cached actor's fuller profile.
					$cachedAvatar = $cached->getIcon();
					if ($cachedAvatar === null || $cachedAvatar->getUrl() !== $avatarUrl) {
						$mapped = $this->mapper->person($profile);
						$avatar = $mapped->getIcon();
						if ($avatar !== null) {
							$cached->setIcon($avatar);
							$this->actors->store($cached);
						}
					}
				}
			} catch (Throwable $e) {
				$this->logger->debug('A Bluesky account was not cached', ['did' => $did, 'exception' => $e]);
			}
		}

		return $ids;
	}

	#[\Override]
	public function quotes(Stream $post, int $limit, ?int $timeout = null): int {
		$uri = $this->refs->strongRef($post->getId())['uri'] ?? '';
		$key = hash('sha256', $uri);
		$savedCursor = $this->durableCache->getShared(self::QUOTE_CURSOR, $key);
		$cursor = is_string($savedCursor) ? $savedCursor : '';
		$seen = $cursor === '' ? [] : [$cursor => true];
		$deadline = $timeout === null ? null : hrtime(true) + ($timeout * 1_000_000_000);
		$stored = 0;
		for ($page = 0; $page < self::MAX_QUOTE_PAGES; $page++) {
			$requestTimeout = $timeout;
			if ($deadline !== null) {
				$requestTimeout = (int)floor(max(0, $deadline - hrtime(true)) / 1_000_000_000);
				if ($requestTimeout < 1) {
					throw new \RuntimeException('Bluesky quote read exceeded its time budget');
				}
			}
			$params = ['uri' => $uri, 'limit' => max(1, min(100, $limit))] + ($cursor !== '' ? ['cursor' => $cursor] : []);
			try {
				$answer = $requestTimeout === null
					? $this->appView->query('app.bsky.feed.getQuotes', $params, ['atproto-accept-labelers' => $this->labelers->acceptHeader()])
					: $this->appView->query('app.bsky.feed.getQuotes', $params, ['atproto-accept-labelers' => $this->labelers->acceptHeader()], $requestTimeout);
			} catch (Throwable $e) {
				$this->logger->info('Bluesky quotes not read', ['uri' => $uri, 'exception' => $e]);

				throw $e;
			}
			if (!is_array($answer['posts'] ?? null) || ($answer['uri'] ?? null) !== $uri) {
				throw new UnexpectedValueException('Bluesky quote response has no valid target or posts list');
			}
			foreach ($answer['posts'] as $view) {
				if (!is_array($view) || !is_string($view['uri'] ?? null) || $view['uri'] === '') {
					throw new UnexpectedValueException('Bluesky quote response contains an invalid post');
				}
				if ($this->store->storePost($view, false)) {
					$stored++;
				} else {
					$this->store->refreshKnownPostCountsFromView($view);
				}
			}
			if (array_key_exists('cursor', $answer) && !is_string($answer['cursor'])) {
				throw new UnexpectedValueException('Bluesky quote response has an invalid cursor');
			}
			$next = $answer['cursor'] ?? '';
			if ($next === '') {
				$this->durableCache->removeShared(self::QUOTE_CURSOR, $key);

				return $stored;
			}
			if (isset($seen[$next])) {
				throw new UnexpectedValueException('Bluesky quote cursor did not advance');
			}
			$this->durableCache->setShared(self::QUOTE_CURSOR, $key, $next, self::QUOTE_CURSOR_KEPT);
			$seen[$next] = true;
			$cursor = $next;
			// An open post needs only its first page now; FillInteractions fetches the
			// rest, since a long quote history cannot finish within the deadline.
			if ($timeout !== null) {
				return $stored;
			}
		}

		throw new UnexpectedValueException('Bluesky quotes have too many pages');
	}

	/** Whether a visible quote read left a cursor for the background fill. */
	public function hasMoreQuotes(Stream $post): bool {
		$uri = $this->refs->strongRef($post->getId())['uri'] ?? '';
		if ($uri === '') {
			return false;
		}
		$cursor = $this->durableCache->getShared(self::QUOTE_CURSOR, hash('sha256', $uri));

		return is_string($cursor) && $cursor !== '';
	}
}
