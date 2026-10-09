<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Discovery;

use OCA\Social\Atproto\Reader\BlueskyPostSource;
use OCA\Social\Service\CacheActorService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A hashtag's timeline and a search of posts, beyond what reached this
 * server: each network is asked (`PostSource`) in the background
 * (`Cron\FillPosts`), what it finds stored, and the timeline and the search
 * then read it as they read any post — nothing tells a reader where a post
 * was written.
 */
class PostDiscoveryService {
	public const TAG = 'tag';
	public const SEARCH = 'search';
	/** the most posts one read stores, per network */
	public const LIMIT = 40;

	public function __construct(
		private CacheActorService $cacheActors,
		private LoggerInterface $logger,
		private ?ContainerInterface $container = null,
	) {
	}

	/**
	 * @param string $viewerId whose search it is, '' for nobody
	 * @return int how many posts were stored
	 */
	public function fill(string $kind, string $term, string $viewerId = ''): int {
		$viewer = null;
		if ($viewerId !== '') {
			try {
				$viewer = $this->cacheActors->getFromId($viewerId);
			} catch (Throwable) {
			}
		}

		return $this->ask($kind, static fn (PostSource $source): int => $kind === self::TAG
			? $source->tagged($term, self::LIMIT, $viewer)
			: $source->matching($term, self::LIMIT, $viewer));
	}

	/**
	 * The newest posts with a hashtag, from every network, as nobody in
	 * particular: at most `$limit` from each, none written before `$since`.
	 *
	 * @return int how many posts were stored
	 */
	public function fillTag(string $tag, int $limit, int $since): int {
		return $this->ask(self::TAG, static fn (PostSource $source): int => $source->tagged($tag, $limit, null, $since));
	}

	/**
	 * @param callable(PostSource): int $read
	 */
	private function ask(string $kind, callable $read): int {
		$stored = 0;
		foreach ($this->sources() as $source) {
			try {
				$stored += $read($source);
			} catch (Throwable $e) {
				$this->logger->info('Posts not read', ['kind' => $kind, 'source' => $source::class, 'exception' => $e]);
			}
		}

		return $stored;
	}

	/**
	 * @return list<PostSource>
	 */
	private function sources(): array {
		$sources = [];
		foreach ([FediversePostSource::class, BlueskyPostSource::class] as $class) {
			$source = $this->container?->get($class);
			if ($source instanceof PostSource) {
				$sources[] = $source;
			}
		}

		return $sources;
	}
}
