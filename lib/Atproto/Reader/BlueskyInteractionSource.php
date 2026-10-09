<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Moderation\LabelerService;
use OCA\Social\Atproto\Publisher\PostRefs;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\Interaction\InteractionSource;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Who liked, reposted or quoted a post on Bluesky, for a post that is there
 * — read from there, or written here and published: as the AppView lists
 * them (`getLikes`, `getRepostedBy`, `getQuotes`). Each account is the one
 * cached here, or is cached as it is found; each quoting post is stored as
 * any Bluesky post is.
 */
class BlueskyInteractionSource implements InteractionSource {
	public function __construct(
		private AtprotoConfig $config,
		private AppViewClient $appView,
		private PostRefs $refs,
		private PostStore $store,
		private ActorMapper $mapper,
		private BlueskyActorService $actors,
		private LabelerService $labelers,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function supports(Stream $post): bool {
		return $this->config->isEnabled() && $this->refs->strongRef($post->getId()) !== null;
	}

	#[\Override]
	public function actors(Stream $post, string $type, int $limit): array {
		$uri = $this->refs->strongRef($post->getId())['uri'] ?? '';
		[$method, $key] = $type === Like::TYPE ? ['app.bsky.feed.getLikes', 'likes'] : ['app.bsky.feed.getRepostedBy', 'repostedBy'];
		try {
			$answer = $this->appView->query($method, ['uri' => $uri, 'limit' => max(1, min(100, $limit))]);
		} catch (Throwable $e) {
			$this->logger->info('Bluesky reactions not read', ['uri' => $uri, 'exception' => $e]);

			return [];
		}
		$ids = [];
		foreach (is_array($answer[$key] ?? null) ? $answer[$key] : [] as $entry) {
			$profile = is_array($entry['actor'] ?? null) ? $entry['actor'] : $entry;
			$did = is_array($profile) ? (string)($profile['did'] ?? '') : '';
			if ($did === '' || ($profile['handle'] ?? '') === '') {
				continue;
			}
			try {
				if ($this->actors->cached($did) === null) {
					$this->actors->store($this->mapper->person($profile));
				}
				$ids[] = BlueskyIds::actorId($did);
			} catch (Throwable $e) {
				$this->logger->debug('A Bluesky account was not cached', ['did' => $did, 'exception' => $e]);
			}
		}

		return $ids;
	}

	#[\Override]
	public function quotes(Stream $post, int $limit): int {
		$uri = $this->refs->strongRef($post->getId())['uri'] ?? '';
		try {
			$answer = $this->appView->query('app.bsky.feed.getQuotes', ['uri' => $uri, 'limit' => max(1, min(100, $limit))], ['atproto-accept-labelers' => $this->labelers->acceptHeader()]);
		} catch (Throwable $e) {
			$this->logger->info('Bluesky quotes not read', ['uri' => $uri, 'exception' => $e]);

			return 0;
		}
		$stored = 0;
		foreach (is_array($answer['posts'] ?? null) ? $answer['posts'] : [] as $view) {
			if (is_array($view) && $this->store->storePost($view, false)) {
				$stored++;
			}
		}

		return $stored;
	}
}
