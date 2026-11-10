<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Interaction;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Atproto\Reader\BlueskyInteractionSource;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\ActionService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\Counts\CountService;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\RemoteFetchQueue;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Who liked, boosted or quoted a post, wherever they did: the reactions
 * this server received, and the ones the networks the post is on list
 * (`InteractionSource`), merged into one list with nothing to tell them
 * apart. A visible reaction list asks its network inline with a short timeout;
 * background fills keep lists current when nobody is reading them. Both are
 * kept for an hour in the table, where web requests can share them. Counts on
 * the post are refreshed separately by `CountService`.
 */
class InteractionService {
	public const QUOTES = 'Quote';
	/** how long what a network listed is kept */
	private const KEPT = 3600;
	private const CACHE = 'social.reactors';
	/**
	 * A reaction list stands after a visible read for as long as the post's
	 * counts do on screen (`CountService::visibleInterval()`).
	 */
	private const VISIBLE = 'social.reactors.visible';
	/** Retry a failed visible read on the same cadence as visible post refreshes. */
	private const VISIBLE_RETRY_INTERVAL = CountService::VISIBLE_INTERVAL;
	/** Resolve a few uncached ActivityPub profiles while the reaction list is open. */
	private const VISIBLE_ACTOR_LIMIT = 5;
	private const VISIBLE_DONE = 'done';
	private const VISIBLE_PENDING = 'pending';
	private const VISIBLE_LOCK = 'social-visible-reactions-';
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
		private ?ILockingProvider $locks = null,
		private ?ITimeFactory $time = null,
	) {
	}

	/**
	 * The accounts that liked or boosted the post, the ones from here first.
	 *
	 * @return array{accounts: Person[], filling: bool}
	 */
	public function reactedBy(Stream $post, string $type, int $limit): array {
		$filling = $this->refreshVisible($post, $type);
		if ($filling === null) {
			$filling = $this->queue->fillInteractions($post, $type);
		}
		$accounts = $this->actions->reactedBy($post, $type, $limit);
		$seen = array_map(static fn (Person $p): string => $p->getId(), $accounts);
		$listed = $this->durableCache->getShared(self::CACHE, self::key($post, $type));
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
			$missing = array_values(array_diff($wanted, array_keys($cached)));
			if ($missing !== [] && count($accounts) < $limit) {
				$this->queue->resolveActors(array_slice($missing, 0, $limit - count($accounts)));
				$filling = true;
			}
		}
		$incompleteBlueskyProfiles = [];
		foreach ($accounts as $account) {
			if (BlueskyActorService::isBluesky($account) && !$account->hasIcon()) {
				$incompleteBlueskyProfiles[] = $account->getId();
			}
		}
		if ($incompleteBlueskyProfiles !== []) {
			$this->queue->refreshActors($incompleteBlueskyProfiles);
		}

		return ['accounts' => $accounts, 'filling' => $filling];
	}

	/**
	 * Read the reaction list inline when a reader opens it. The regular
	 * background refresh can wait for a scheduled job; the visible read is
	 * bounded to the AppView's short timeout and shares one lock per post and
	 * reaction kind, so simultaneous viewers do not duplicate the request.
	 *
	 * @return ?bool null when no inline source applies, otherwise whether the
	 *               list is still being filled (a lock holder or fallback job)
	 */
	private function refreshVisible(Stream $post, string $type): ?bool {
		if ($post->getId() === '' || ($post->isLocal() && $post->getVisibility() !== Stream::TYPE_PUBLIC)) {
			return null;
		}
		$key = self::key($post, $type);
		$visible = $this->durableCache->getShared(self::VISIBLE, $key);
		if ($visible !== null) {
			$pending = $visible === self::VISIBLE_PENDING;
			if ($pending) {
				// If the last fill stopped at an unvisited page, its job releases
				// the throttle when it ends. The next detail poll can then resume it.
				$this->queue->fillInteractions($post, $type);
			}

			return $pending;
		}

		$lock = self::VISIBLE_LOCK . $key;
		if ($this->locks !== null) {
			if ($this->locks->isLocked($lock, ILockingProvider::LOCK_EXCLUSIVE)) {
				return true;
			}
			try {
				$this->locks->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
			} catch (LockedException) {
				return true;
			}
		}

		try {
			// Another request may have finished between the first cache check and
			// acquiring the lock.
			$visible = $this->durableCache->getShared(self::VISIBLE, $key);
			if ($visible !== null) {
				$pending = $visible === self::VISIBLE_PENDING;
				if ($pending) {
					$this->queue->fillInteractions($post, $type);
				}

				return $pending;
			}
			// A post published to both networks can have more than one source.
			// Bound all of their visible reads together so each protocol cannot
			// independently consume the full interactive timeout.
			$deadline = hrtime(true) + (AppViewClient::INTERACTIVE_TIMEOUT * 1_000_000_000);
			$supported = false;
			$failed = false;
			$ids = [];
			$activityPubIds = [];
			$firstSource = true;
			foreach ($this->sources() as $source) {
				if (!$source->supports($post)) {
					continue;
				}
				$supported = true;
				$timeout = $firstSource
					? AppViewClient::INTERACTIVE_TIMEOUT
					: (int)floor(($deadline - hrtime(true)) / 1_000_000_000);
				if ($timeout < 1) {
					$failed = true;
					continue;
				}
				$firstSource = false;
				try {
					if ($type === self::QUOTES) {
						$source->quotes($post, self::LIMIT, $timeout);
						if ($source instanceof BlueskyInteractionSource && $source->hasMoreQuotes($post)) {
							// The visible read stored its first page and saved the next
							// cursor; finish paging off-request without holding the view.
							$failed = true;
						}
					} else {
						$sourceIds = $source->actors($post, $type, self::LIMIT, $timeout);
						$ids = array_merge($ids, $sourceIds);
						if ($source instanceof ActivityPubInteractionSource && $source->hasMoreActors($post, $type)) {
							// Keep the successful collection pages visible while the
							// background fill retries the incomplete list.
							$failed = true;
						}
						if ($source instanceof ActivityPubInteractionSource) {
							$activityPubIds = array_merge($activityPubIds, $sourceIds);
						}
					}
				} catch (Throwable $e) {
					$failed = true;
					$this->logger->info('A visible reaction source was not read', [
						'post' => $post->getId(),
						'source' => $source::class,
						'type' => $type,
						'exception' => $e,
					]);
				}
			}
			if (!$supported) {
				return null;
			}
			if ($activityPubIds !== []) {
				$this->resolveVisibleActivityPubActors($activityPubIds, $deadline);
			}
			if ($type !== self::QUOTES) {
				$previous = $failed ? $this->durableCache->getShared(self::CACHE, $key) : [];
				$actors = $failed && is_array($previous) ? array_merge($previous, $ids) : $ids;
				$this->durableCache->setShared(self::CACHE, $key, array_values(array_unique($actors)), self::KEPT);
			}
			if ($failed) {
				$this->queue->fillInteractions($post, $type);
				$this->durableCache->setShared(self::VISIBLE, $key, self::VISIBLE_PENDING, self::VISIBLE_RETRY_INTERVAL);

				return true;
			}
			$this->durableCache->setShared(self::VISIBLE, $key, self::VISIBLE_DONE, $this->visibleInterval($post));

			return false;
		} catch (Throwable $e) {
			$this->logger->info('Visible reactions not read; queued for retry', ['post' => $post->getId(), 'type' => $type, 'exception' => $e]);
			$this->queue->fillInteractions($post, $type);
			$this->durableCache->setShared(self::VISIBLE, $key, self::VISIBLE_PENDING, self::VISIBLE_RETRY_INTERVAL);

			return true;
		} finally {
			if ($this->locks !== null) {
				$this->locks->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
			}
		}
	}

	/**
	 * Resolve a small number of uncached Fediverse reaction profiles before
	 * returning the visible list. Any actor not resolved within the same
	 * three-second budget remains queued for the normal background fill.
	 *
	 * @param list<string> $ids
	 */
	private function resolveVisibleActivityPubActors(array $ids, int $deadline): void {
		$ids = array_values(array_unique(array_filter($ids, static fn (string $id): bool
			=> preg_match('#^https?://#i', $id) === 1 && !BlueskyIds::isActorId($id)
		)));
		if ($ids === []) {
			return;
		}

		$cached = $this->cacheActors->getCachedFromIds($ids);
		$missing = array_values(array_diff($ids, array_keys($cached)));
		foreach (array_slice($missing, 0, self::VISIBLE_ACTOR_LIMIT) as $id) {
			$remaining = (int)floor(($deadline - hrtime(true)) / 1_000_000_000);
			if ($remaining < 1) {
				break;
			}
			try {
				$this->cacheActors->getActivityPubActorWithTimeout($id, $remaining);
			} catch (Throwable $e) {
				$this->logger->debug('Visible reaction actor was not resolved inline', [
					'actor' => $id,
					'exception' => $e,
				]);
			}
		}
	}

	/**
	 * Has the posts quoting this one read from the networks it is on.
	 *
	 * @return bool whether a read was asked for
	 */
	public function askForQuotes(Stream $post): bool {
		$filling = $this->refreshVisible($post, self::QUOTES);

		return $filling ?? $this->queue->fillInteractions($post, self::QUOTES);
	}

	/**
	 * The background read (`Cron\FillInteractions`): who the networks list
	 * for the post, or the posts quoting it stored. Returns whether any source
	 * remains incomplete, so its throttle can be released for the next poll.
	 */
	public function fill(string $postId, string $type): bool {
		try {
			$post = $this->streams->getStreamById($postId);
		} catch (StreamNotFoundException) {
			return false;
		}
		$ids = [];
		$supported = false;
		$failed = false;
		foreach ($this->sources() as $source) {
			if (!$source->supports($post)) {
				continue;
			}
			$supported = true;
			try {
				if ($type === self::QUOTES) {
					$source->quotes($post, self::LIMIT);
				} else {
					$ids = array_merge($ids, $source->actors($post, $type, self::LIMIT));
					if ($source instanceof ActivityPubInteractionSource && $source->hasMoreActors($post, $type)) {
						$failed = true;
					}
				}
			} catch (Throwable $e) {
				$failed = true;
				$this->logger->info('Reactions not read', ['post' => $postId, 'source' => $source::class, 'exception' => $e]);
			}
		}
		if ($type !== self::QUOTES) {
			$key = self::key($post, $type);
			// A failed source may have omitted actors that were present in the
			// last complete read. Keep those while adding any new cursor page; a
			// final complete pass replaces the accumulated list and drops stale actors.
			$previous = $failed ? $this->durableCache->getShared(self::CACHE, $key) : [];
			$actors = $failed && is_array($previous) ? array_merge($previous, $ids) : $ids;
			$this->durableCache->setShared(self::CACHE, $key, array_values(array_unique($actors)), self::KEPT);
		}
		if ($supported) {
			$this->durableCache->setShared(
				self::VISIBLE,
				self::key($post, $type),
				$failed ? self::VISIBLE_PENDING : self::VISIBLE_DONE,
				$failed ? self::VISIBLE_RETRY_INTERVAL : $this->visibleInterval($post),
			);
		}

		return $failed;
	}

	private function visibleInterval(Stream $post): int {
		return CountService::visibleInterval($post, $this->time?->getTime() ?? time());
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
