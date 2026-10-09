<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Thread;

use OCA\Social\Atproto\Reader\BlueskyThreadSource;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The rest of a conversation, from wherever it lives: a reader opening a
 * post sees the replies its own server knows of, not only the ones somebody
 * here happened to follow. Each network the post is on is asked
 * (`ThreadSource`) — a post written here and published to Bluesky is asked
 * of both — in a background job (`Cron\FillThread`), so the page never waits
 * on another server; the next look has them.
 */
class ThreadService {
	/** the most replies one fill stores */
	public const BUDGET = 150;

	public function __construct(
		private StreamRequest $streams,
		private LoggerInterface $logger,
		private ?ContainerInterface $container = null,
	) {
	}

	/**
	 * @return int how many replies were stored
	 */
	public function fill(string $postId): int {
		try {
			$post = $this->streams->getStreamById($postId);
		} catch (StreamNotFoundException) {
			return 0;
		}
		$stored = 0;
		foreach ($this->sources() as $source) {
			if (!$source->supports($post)) {
				continue;
			}
			try {
				$stored += $source->fill($post, self::BUDGET - $stored);
			} catch (Throwable $e) {
				$this->logger->info('A conversation was not read', ['post' => $postId, 'source' => $source::class, 'exception' => $e]);
			}
		}

		return $stored;
	}

	/**
	 * @return list<ThreadSource>
	 */
	private function sources(): array {
		$sources = [];
		foreach ([ActivityPubThreadSource::class, BlueskyThreadSource::class] as $class) {
			$source = $this->container?->get($class);
			if ($source instanceof ThreadSource) {
				$sources[] = $source;
			}
		}

		return $sources;
	}
}
