<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\Counts\CountSource;
use OCA\Social\Service\Counts\CountWriter;

/**
 * Refreshes the counts of posts read from Bluesky via `getPosts` (25 a call).
 * A stored post is not touched by later feed reads, so this is the only way
 * its numbers move. Posts the AppView no longer has are deleted
 * (`PostStore::postViews()`).
 */
class BlueskyCountSource implements CountSource {
	/** the most uris one `getPosts` takes */
	public const BATCH = 25;

	public function __construct(
		private AtprotoConfig $config,
		private PostStore $store,
		private CountWriter $writer,
	) {
	}

	#[\Override]
	public function supports(Stream $post): bool {
		return !$post->isLocal() && BlueskyIds::isPostId($post->getId()) && $this->config->isEnabled();
	}

	#[\Override]
	public function refresh(array $posts, ?int $timeout = null): int {
		$answered = 0;
		foreach (array_chunk($posts, self::BATCH) as $chunk) {
			$ids = array_map(static fn (Stream $post): string => $post->getId(), $chunk);
			$views = $this->store->postViews($ids, $timeout);
			foreach ($ids as $id) {
				$view = $views['posts'][$id] ?? null;
				if ($view === null) {
					// posts the AppView no longer has are deleted by now; posts of
					// an unanswered call are asked again later
					if ($views === null && $timeout === null) {
						$this->writer->stamp($id);
					}
					continue;
				}
				$likes = self::count($view, 'likeCount');
				$reposts = self::count($view, 'repostCount');
				$replies = self::count($view, 'replyCount');
				$quotes = self::count($view, 'quoteCount');
				$block = array_filter([
					'likes' => $likes,
					'reposts' => $reposts,
					'replies' => $replies,
					'quotes' => $quotes,
				], static fn (?int $count): bool => $count !== null);
				if ($block === []) {
					if ($timeout === null) {
						$this->writer->stamp($id);
					}
					continue;
				}
				if ($this->writer->write($id, $likes, $reposts, $replies, [PostMapper::DETAIL => $block], $quotes)) {
					$answered++;
				}
			}
		}

		return $answered;
	}

	/** A count the view states, null where it states none. */
	public static function count(array $view, string $key): ?int {
		$count = $view[$key] ?? null;

		return is_int($count) && $count >= 0 && $count <= Stream::REMOTE_COUNT_CEILING ? $count : null;
	}
}
