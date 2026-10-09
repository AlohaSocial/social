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
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\Thread\ThreadSource;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A conversation on Bluesky, for a post that is there — read from there, or
 * written here and published: the thread as the AppView has it
 * (`getPostThread`), each post this server does not hold stored the way any
 * Bluesky post is (`PostStore`), parents first, so every reply's parent is
 * there before it.
 */
class BlueskyThreadSource implements ThreadSource {
	/** how deep the replies, and how high the parents, are asked for */
	private const DEPTH = 6;
	private const PARENT_HEIGHT = 20;
	private const THREAD_VIEW = 'app.bsky.feed.defs#threadViewPost';

	public function __construct(
		private AtprotoConfig $config,
		private AppViewClient $appView,
		private PostRefs $refs,
		private PostStore $store,
		private LabelerService $labelers,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function supports(Stream $post): bool {
		return $this->config->isEnabled() && $this->refs->strongRef($post->getId()) !== null;
	}

	#[\Override]
	public function fill(Stream $post, int $budget): int {
		$uri = $this->refs->strongRef($post->getId())['uri'] ?? '';
		if ($uri === '' || $budget <= 0) {
			return 0;
		}
		try {
			$answer = $this->appView->query(
				'app.bsky.feed.getPostThread',
				['uri' => $uri, 'depth' => self::DEPTH, 'parentHeight' => self::PARENT_HEIGHT],
				['atproto-accept-labelers' => $this->labelers->acceptHeader()],
			);
		} catch (Throwable $e) {
			$this->logger->info('Bluesky thread not read', ['uri' => $uri, 'exception' => $e]);

			return 0;
		}

		$stored = 0;
		foreach (self::postsOf(is_array($answer['thread'] ?? null) ? $answer['thread'] : [], $budget) as $view) {
			if ($stored >= $budget) {
				break;
			}
			if ($this->store->storePost($view, false)) {
				$stored++;
			}
		}
		// the replies its author hid on Bluesky are hidden here too
		$this->store->rememberHiddenReplies(is_array($answer['threadgate'] ?? null) ? $answer['threadgate'] : null);

		return $stored;
	}

	/**
	 * The post views of a thread: the parents from the top, the post, then
	 * the replies level by level.
	 *
	 * @return list<array>
	 */
	public static function postsOf(array $thread, int $limit): array {
		$posts = [];
		for ($parent = $thread['parent'] ?? null; is_array($parent) && ($parent['$type'] ?? '') === self::THREAD_VIEW; $parent = $parent['parent'] ?? null) {
			array_unshift($posts, $parent['post'] ?? null);
		}
		if (($thread['$type'] ?? '') === self::THREAD_VIEW) {
			$posts[] = $thread['post'] ?? null;
		}
		$level = [$thread];
		while ($level !== [] && count($posts) < $limit * 2) {
			$next = [];
			foreach ($level as $node) {
				foreach (is_array($node['replies'] ?? null) ? $node['replies'] : [] as $reply) {
					if (is_array($reply) && ($reply['$type'] ?? '') === self::THREAD_VIEW) {
						$posts[] = $reply['post'] ?? null;
						$next[] = $reply;
					}
				}
			}
			$level = $next;
		}

		return array_values(array_filter($posts, 'is_array'));
	}
}
