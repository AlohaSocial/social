<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Moderation\LabelerService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\Discovery\PostSource;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Posts on Bluesky with a hashtag, or matching a search: the AppView's
 * `searchPosts`, newest first, asked as the person searching where they are
 * on Bluesky, and each post stored as any Bluesky post is.
 */
class BlueskyPostSource implements PostSource {
	public function __construct(
		private AtprotoConfig $config,
		private AppViewClient $appView,
		private IdentityService $identities,
		private PostStore $store,
		private LabelerService $labelers,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function tagged(string $tag, int $limit, ?Person $viewer): int {
		return $this->search(['q' => '#' . $tag, 'tag' => [$tag]], $limit, $viewer);
	}

	#[\Override]
	public function matching(string $query, int $limit, ?Person $viewer): int {
		return $this->search(['q' => $query], $limit, $viewer);
	}

	private function search(array $params, int $limit, ?Person $viewer): int {
		if (!$this->config->isEnabled()) {
			return 0;
		}
		$params += ['sort' => 'latest', 'limit' => max(1, min(100, $limit))];
		try {
			$identity = $viewer === null ? null : $this->identities->forActor($viewer, false);
			$answer = $identity !== null
				? $this->appView->queryAs($identity->did, $this->identities->signingKey($identity), 'app.bsky.feed.searchPosts', $params)
				: $this->appView->query('app.bsky.feed.searchPosts', $params, ['atproto-accept-labelers' => $this->labelers->acceptHeader()]);
		} catch (Throwable $e) {
			$this->logger->info('Bluesky posts not searched', ['exception' => $e]);

			return 0;
		}
		$stored = 0;
		foreach (is_array($answer['posts'] ?? null) ? $answer['posts'] : [] as $post) {
			if (is_array($post) && $this->store->storePost($post, false)) {
				$stored++;
			}
		}

		return $stored;
	}
}
