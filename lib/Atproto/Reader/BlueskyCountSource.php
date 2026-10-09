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
 * The counts of a post read from Bluesky, as the AppView has them now:
 * `getPosts`, 25 posts a call, each answering with its `likeCount`,
 * `repostCount`, `replyCount` and `quoteCount`. The post is stored once and
 * no later feed read touches it again, so this is the only way its numbers
 * move. A post the AppView no longer has is deleted as `DeletionSweep`
 * deletes it (`PostStore::postViews()`).
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
	public function refresh(array $posts): int {
		$answered = 0;
		foreach (array_chunk($posts, self::BATCH) as $chunk) {
			$ids = array_map(static fn (Stream $post): string => $post->getId(), $chunk);
			$views = $this->store->postViews($ids);
			foreach ($ids as $id) {
				$view = $views['posts'][$id] ?? null;
				if ($view === null) {
					// a post the AppView no longer has is deleted by now; the
					// posts of a call it did not answer are asked again later
					if ($views === null) {
						$this->writer->stamp($id);
					}
					continue;
				}
				$likes = self::count($view, 'likeCount');
				$reposts = self::count($view, 'repostCount');
				$replies = self::count($view, 'replyCount');
				$block = array_filter([
					'likes' => $likes,
					'reposts' => $reposts,
					'replies' => $replies,
					'quotes' => self::count($view, 'quoteCount'),
				], static fn (?int $count): bool => $count !== null);
				if ($this->writer->write($id, $likes, $reposts, $replies, [PostMapper::DETAIL => $block])) {
					$answered++;
				}
			}
		}

		return $answered;
	}

	/** A count the view states, null where it states none. */
	private static function count(array $view, string $key): ?int {
		$count = $view[$key] ?? null;

		return is_int($count) && $count >= 0 && $count <= Stream::REMOTE_COUNT_CEILING ? $count : null;
	}
}
