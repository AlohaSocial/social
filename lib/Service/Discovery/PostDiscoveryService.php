<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Discovery;

use OCA\Social\Atproto\Reader\BlueskyPostSource;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\RecommendationDiscoveryHealthService;
use OCA\Social\Service\RecommendationPreferenceService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A hashtag's timeline and a post search beyond what reached this server:
 * each network (`PostSource`) is asked in the background (`Cron\FillPosts`),
 * and what it finds is stored and read like any other post.
 */
class PostDiscoveryService {
	public const TAG = 'tag';
	public const SEARCH = 'search';
	/** the most posts one read stores, per network */
	public const LIMIT = 40;

	public function __construct(
		private CacheActorService $cacheActors,
		private LoggerInterface $logger,
		private RecommendationDiscoveryHealthService $health,
		private RecommendationPreferenceService $preferences,
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

		return $this->ask($kind, static fn (PostSource $source): PostSourceStats => $kind === self::TAG
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
		return $this->ask(self::TAG, static fn (PostSource $source): PostSourceStats => $source->tagged($tag, $limit, null, $since));
	}

	/**
	 * @param callable(PostSource): PostSourceStats $read
	 */
	private function ask(string $kind, callable $read): int {
		if ($this->preferences->remoteDiscoveryPaused()) {
			return 0;
		}

		$stored = 0;
		foreach ($this->sources() as $source) {
			try {
				$stats = $read($source);
				$stored += $stats->postsStored;
			} catch (Throwable $e) {
				try {
					$this->health->record(self::provider($source), new PostSourceStats(1, 1, 0, 0, 0, 0));
				} catch (Throwable $metricsError) {
					$this->logger->warning('Discovery failure metrics could not be saved', ['exception' => $metricsError]);
				}
				$this->logger->info('Posts not read', ['kind' => $kind, 'source' => $source::class, 'exception' => $e]);
				continue;
			}
			try {
				$this->health->record(self::provider($source), $stats);
			} catch (Throwable $e) {
				$this->logger->warning('Discovery health metrics could not be saved', ['source' => $source::class, 'exception' => $e]);
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

	private static function provider(PostSource $source): string {
		return $source instanceof FediversePostSource ? 'activitypub' : 'atproto';
	}
}
