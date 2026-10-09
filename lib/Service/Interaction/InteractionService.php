<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Interaction;

use OCA\Social\Atproto\Reader\BlueskyInteractionSource;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\ActionService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\RemoteFetchQueue;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Who liked, boosted or quoted a post, wherever they did: the reactions
 * this server received, and the ones the networks the post is on list
 * (`InteractionSource`), merged into one list with nothing to tell them
 * apart. What the networks list is read in the background (`Cron\FillInteractions`)
 * and kept for an hour; the counts on the post are not touched by it.
 */
class InteractionService {
	public const QUOTES = 'Quote';
	/** how long what a network listed is kept */
	private const KEPT = 3600;
	private const CACHE = 'social.reactors';
	/** the most a network is asked for */
	public const LIMIT = 80;

	public function __construct(
		private ActionService $actions,
		private CacheActorService $cacheActors,
		private StreamRequest $streams,
		private DurableCache $durableCache,
		private RemoteFetchQueue $queue,
		private LoggerInterface $logger,
		private ?ContainerInterface $container = null,
	) {
	}

	/**
	 * The accounts that liked or boosted the post, the ones from here first.
	 *
	 * @return array{accounts: Person[], filling: bool}
	 */
	public function reactedBy(Stream $post, string $type, int $limit): array {
		$accounts = $this->actions->reactedBy($post, $type, $limit);
		$seen = array_map(static fn (Person $p): string => $p->getId(), $accounts);
		$listed = $this->durableCache->get(self::CACHE, self::key($post, $type));
		$wanted = array_values(array_diff(is_array($listed) ? array_filter($listed, 'is_string') : [], $seen));
		if ($wanted !== [] && count($accounts) < $limit) {
			$cached = $this->cacheActors->getCachedFromIds($wanted);
			foreach ($wanted as $id) {
				if (count($accounts) >= $limit) {
					break;
				}
				if (isset($cached[$id])) {
					$cached[$id]->setExportFormat(ACore::FORMAT_LOCAL);
					$accounts[] = $cached[$id];
				}
			}
		}

		return ['accounts' => $accounts, 'filling' => $this->queue->fillInteractions($post, $type)];
	}

	/**
	 * Has the posts quoting this one read from the networks it is on.
	 *
	 * @return bool whether a read was asked for
	 */
	public function askForQuotes(Stream $post): bool {
		return $this->queue->fillInteractions($post, self::QUOTES);
	}

	/**
	 * The background read (`Cron\FillInteractions`): who the networks list
	 * for the post, or the posts quoting it stored.
	 */
	public function fill(string $postId, string $type): void {
		try {
			$post = $this->streams->getStreamById($postId);
		} catch (StreamNotFoundException) {
			return;
		}
		$ids = [];
		foreach ($this->sources() as $source) {
			if (!$source->supports($post)) {
				continue;
			}
			try {
				if ($type === self::QUOTES) {
					$source->quotes($post, self::LIMIT);
				} else {
					$ids = array_merge($ids, $source->actors($post, $type, self::LIMIT));
				}
			} catch (Throwable $e) {
				$this->logger->info('Reactions not read', ['post' => $postId, 'source' => $source::class, 'exception' => $e]);
			}
		}
		if ($type !== self::QUOTES) {
			$this->durableCache->set(self::CACHE, self::key($post, $type), array_values(array_unique($ids)), self::KEPT);
		}
	}

	private static function key(Stream $post, string $type): string {
		return md5($type . "\0" . $post->getId());
	}

	/**
	 * @return list<InteractionSource>
	 */
	private function sources(): array {
		$sources = [];
		foreach ([ActivityPubInteractionSource::class, BlueskyInteractionSource::class] as $class) {
			$source = $this->container?->get($class);
			if ($source instanceof InteractionSource) {
				$sources[] = $source;
			}
		}

		return $sources;
	}
}
