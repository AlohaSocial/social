<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Counts;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Reader\BlueskyCountSource;
use OCA\Social\Atproto\Reader\BlueskyPublishedCountSource;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\RemoteFetchQueue;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The likes, boosts and replies of a post that lives elsewhere, kept current
 * wherever it lives.
 *
 * A post from another server arrives with the counts it had at that moment,
 * and nothing tells this server when they move. So the posts somebody looks
 * at are asked about again: a status, a conversation or a page of a timeline
 * hands its remote posts to `seen()`, which only queues — the page does no
 * network I/O — a background read (`Cron\RefreshCounts`) of the ones whose
 * counts are older than their interval: a quarter of an hour for a post less
 * than a day old, six hours after that. Each network the post may be on is
 * a `CountSource`; what it answers is stored in the post's row
 * (`CountWriter`), so every reader sees it, and `counts_at` says when it
 * was asked. The cron's pass over posts nobody looked at
 * (`RemoteCountService`) goes through `refreshPosts()` too.
 *
 * A post on a reader's screen is read sooner and inline
 * (`refreshVisibleIfDue()`, `refreshVisibleMany()`), on a window that grows
 * with the post's age (`VISIBLE_WINDOWS`).
 *
 * A post of this instance's own is counted here, except where it is also on
 * a network that keeps counts this instance may not have heard of: a post
 * published to Bluesky is asked about the same way
 * (`BlueskyPublishedCountSource`).
 */
class CountService {
	/** how old a post is while it is asked about often */
	public const YOUNG = 86400;
	public const YOUNG_INTERVAL = 900;
	public const OLD_INTERVAL = 21600;
	/**
	 * The shortest time between two reads of a post's counts while somebody
	 * is looking at it, and how soon a failed one is retried.
	 */
	public const VISIBLE_INTERVAL = 10;
	/**
	 * How long a post's counts stand while it is on screen, by its age:
	 * [younger than (seconds), interval (seconds)]. A new post moves fast and
	 * an old one hardly at all; past the last step `VISIBLE_OLD_INTERVAL`.
	 */
	public const VISIBLE_WINDOWS = [
		[900, self::VISIBLE_INTERVAL],
		[3600, 30],
		[21600, 60],
		[86400, 300],
		[604800, 900],
	];
	public const VISIBLE_OLD_INTERVAL = 3600;
	/** The most posts one counts request refreshes. */
	public const VISIBLE_BATCH = 20;
	private const VISIBLE_RETRY_INTERVAL = self::VISIBLE_INTERVAL;
	private const VISIBLE_CACHE = 'social.counts.visible';
	private const VISIBLE_PENDING = 'pending';
	private const VISIBLE_DONE = 'done';
	private const VISIBLE_LOCK = 'social-visible-count-';
	/** Serialize the short cache-check-and-enqueue window across feed requests. */
	private const QUEUE_LOCK = 'social-counts-queue';

	public function __construct(
		private StreamRequest $streams,
		private CountWriter $writer,
		private RemoteFetchQueue $queue,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		private ILockingProvider $locks,
		private DurableCache $durableCache,
		private ?ContainerInterface $container = null,
	) {
	}

	/**
	 * A page showed these posts: the ones whose counts are due (`isDue()`) are
	 * queued to be asked about (`RemoteFetchQueue::refreshCounts()`, which
	 * caps a call). A boost stands for the post it boosts.
	 *
	 * @param iterable<mixed> $posts
	 * @return bool whether any post still needs its remote counts refreshed
	 */
	public function seen(iterable $posts): bool {
		$now = $this->time->getTime();
		$due = [];
		foreach ($posts as $post) {
			if ($post instanceof Stream && $post->getType() === Announce::TYPE && $post->hasObject()) {
				$post = $post->getObject();
			}
			if ($post instanceof Stream && $this->isDue($post, $now)) {
				$due[$post->getId()] = self::interval($post, $now);
			}
		}

		if ($due === []) {
			return false;
		}

		// The durable-cache throttle and IJobList check are both read-then-write:
		// two feed requests arriving together could otherwise pass both checks
		// and enqueue the same remote read twice. Keep this lock only around the
		// short queue operation; network I/O remains in the background job.
		try {
			$this->locks->acquireLock(self::QUEUE_LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			// Another request is either checking the throttle or just queued this
			// work. Its page should keep polling until the worker refreshes counts.
			return true;
		} catch (Throwable $e) {
			// A lock service outage must not prevent counts from ever being queued.
			$this->logger->info('Could not lock the count refresh queue', ['exception' => $e]);
			$this->queue->refreshCounts($due);

			return true;
		}
		try {
			// A post is still filling until the worker stamps its new counts, so
			// callers must keep the refresh signal while the row remains due.
			$this->queue->refreshCounts($due);
		} finally {
			$this->locks->releaseLock(self::QUEUE_LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		}

		return true;
	}

	/**
	 * Refresh counts from the post's network while it is being opened. A queued
	 * job can take a full scheduler interval to run, which is too late for a
	 * post the reader is looking at now.
	 */
	public function refreshVisibleIfDue(Stream $post, bool $force = false): bool {
		$now = $this->time->getTime();
		$post = self::counted($post);
		if (!$force && !$this->isVisibleDue($post, $now)) {
			return false;
		}
		$visible = $this->visibleState($post->getId());
		if ($visible !== null && (!$force || $visible === self::VISIBLE_PENDING)) {
			return $visible === self::VISIBLE_PENDING;
		}

		$lock = self::VISIBLE_LOCK . md5($post->getId());
		if ($this->locks->isLocked($lock, ILockingProvider::LOCK_EXCLUSIVE)) {
			return true;
		}
		try {
			$this->locks->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			return true;
		}

		try {
			try {
				$post = $this->streams->getStreamById($post->getId());
			} catch (StreamNotFoundException) {
				return false;
			}
			if (!$force && !$this->isVisibleDue($post, $now)) {
				return false;
			}
			$visible = $this->visibleState($post->getId());
			if ($visible !== null && (!$force || $visible === self::VISIBLE_PENDING)) {
				return $visible === self::VISIBLE_PENDING;
			}

			foreach ($this->sources() as $source) {
				if (!$source->supports($post)) {
					continue;
				}
				try {
					$answered = $source->refresh([$post], AppViewClient::INTERACTIVE_TIMEOUT);
					if ($answered === 0) {
						$this->queueVisibleRetry($post);
						$this->setVisibleState($post->getId(), self::VISIBLE_PENDING, self::VISIBLE_RETRY_INTERVAL);
					} else {
						$this->setVisibleState($post->getId(), self::VISIBLE_DONE, self::visibleInterval($post, $now));
					}
				} catch (Throwable $e) {
					$this->logger->info('Post counts not read during display', ['post' => $post->getId(), 'exception' => $e]);
					$this->queueVisibleRetry($post);
					$this->setVisibleState($post->getId(), self::VISIBLE_PENDING, self::VISIBLE_RETRY_INTERVAL);
				}
				return true;
			}

			return false;
		} finally {
			$this->locks->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * Refresh the counts of posts on a reader's screen: the ones whose
	 * on-screen window (`visibleInterval()`) is out are asked of their
	 * networks together, one call per network, within the interactive
	 * timeout. A post another request is refreshing is left to it. Records
	 * nothing about the reader.
	 *
	 * Answers the ids whose counts were read, and whether any read is still
	 * running or queued.
	 *
	 * @param iterable<Stream> $posts
	 * @return array{refreshed: list<string>, pending: bool}
	 */
	public function refreshVisibleMany(iterable $posts): array {
		$now = $this->time->getTime();
		$pending = false;
		/** @var array<string, string> $locked post id => lock name */
		$locked = [];
		foreach ($posts as $post) {
			$post = self::counted($post);
			$id = $post->getId();
			if (isset($locked[$id]) || count($locked) >= self::VISIBLE_BATCH || !$this->isVisibleDue($post, $now)) {
				continue;
			}
			$visible = $this->visibleState($id);
			if ($visible !== null) {
				$pending = $pending || $visible === self::VISIBLE_PENDING;
				continue;
			}
			$lock = self::VISIBLE_LOCK . md5($id);
			try {
				$this->locks->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
			} catch (LockedException) {
				$pending = true;
				continue;
			} catch (Throwable $e) {
				$this->logger->info('Could not lock a visible count refresh', ['post' => $id, 'exception' => $e]);
				continue;
			}
			$locked[$id] = $lock;
		}
		if ($locked === []) {
			return ['refreshed' => [], 'pending' => $pending];
		}

		$refreshed = [];
		try {
			/** @var array<string, Stream> $due */
			$due = [];
			foreach (array_keys($locked) as $id) {
				try {
					$post = $this->streams->getStreamById((string)$id);
				} catch (StreamNotFoundException) {
					continue;
				}
				if ($this->isVisibleDue($post, $now) && $this->visibleState($post->getId()) === null) {
					$due[$post->getId()] = $post;
				}
			}
			foreach ($this->sources() as $source) {
				$mine = array_filter($due, static fn (Stream $post): bool => $source->supports($post));
				if ($mine === []) {
					continue;
				}
				$due = array_diff_key($due, $mine);
				try {
					$answered = $source->refresh(array_values($mine), AppViewClient::INTERACTIVE_TIMEOUT);
				} catch (Throwable $e) {
					$this->logger->info('Post counts not read during display', ['source' => $source::class, 'exception' => $e]);
					$answered = 0;
				}
				$unanswered = $answered >= count($mine) ? [] : $this->unanswered($mine, $now);
				foreach ($mine as $id => $post) {
					$id = (string)$id;
					$refreshed[] = $id;
					if (isset($unanswered[$id])) {
						$this->queueVisibleRetry($post);
						$this->setVisibleState($id, self::VISIBLE_PENDING, self::VISIBLE_RETRY_INTERVAL);
						$pending = true;
					} else {
						$this->setVisibleState($id, self::VISIBLE_DONE, self::visibleInterval($post, $now));
					}
				}
			}
		} finally {
			foreach ($locked as $lock) {
				$this->locks->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
			}
		}

		return ['refreshed' => $refreshed, 'pending' => $pending];
	}

	/**
	 * The posts of a batch whose counts were not stored by the read that just
	 * ran: a source answers with how many it stored, not which.
	 *
	 * @param array<string, Stream> $posts
	 * @return array<string, true>
	 */
	private function unanswered(array $posts, int $now): array {
		$unanswered = [];
		foreach (array_keys($posts) as $id) {
			try {
				$countsAt = $this->streams->getStreamById((string)$id)->getCountsAt();
			} catch (StreamNotFoundException) {
				continue;
			}
			if ($countsAt === null || $countsAt < $now) {
				$unanswered[(string)$id] = true;
			}
		}

		return $unanswered;
	}

	/** A boost's counts are those of the post it boosts. */
	private static function counted(Stream $post): Stream {
		if ($post->getType() === Announce::TYPE) {
			$object = $post->getObject();
			if ($object instanceof Stream) {
				return $object;
			}
		}

		return $post;
	}

	/** Whether a post on screen has counts kept elsewhere that are older than its on-screen window. */
	private function isVisibleDue(Stream $post, int $now): bool {
		if ($post->getId() === '' || $post->getVisibility() === Stream::TYPE_DIRECT) {
			return false;
		}
		$countsAt = $post->getCountsAt();

		return $countsAt === null || $now - $countsAt >= self::visibleInterval($post, $now);
	}

	/** How long a post's counts stand while it is on a reader's screen (`VISIBLE_WINDOWS`), in seconds. */
	public static function visibleInterval(Stream $post, int $now): int {
		$age = $now - $post->getPublishedTime();
		foreach (self::VISIBLE_WINDOWS as [$younger, $interval]) {
			if ($age < $younger) {
				return $interval;
			}
		}

		return self::VISIBLE_OLD_INTERVAL;
	}

	/** Whether a visible refresh of this post is still running or queued. */
	public function visibleRefreshPending(string $postId): bool {
		return $this->visibleState($postId) === self::VISIBLE_PENDING
			|| $this->locks->isLocked(self::VISIBLE_LOCK . md5($postId), ILockingProvider::LOCK_EXCLUSIVE);
	}

	/** A forced live read may fail even when the ordinary count interval is fresh. */
	private function queueVisibleRetry(Stream $post): void {
		$this->queue->refreshCounts([$post->getId() => self::VISIBLE_RETRY_INTERVAL], true);
	}

	/** How long a post's counts stand before it is asked about again, in seconds. */
	public static function interval(Stream $post, int $now): int {
		return ($now - $post->getPublishedTime() < self::YOUNG) ? self::YOUNG_INTERVAL : self::OLD_INTERVAL;
	}

	/**
	 * Whether a post is one whose counts are kept elsewhere and were last
	 * asked about longer ago than its interval. A direct message has no
	 * counts to speak of. A post of this instance's own is counted here,
	 * unless it is on another network too that keeps counts of its own.
	 */
	public function isDue(Stream $post, int $now): bool {
		if ($post->getId() === '' || $post->getVisibility() === Stream::TYPE_DIRECT) {
			return false;
		}
		if ($post->isLocal() && !$this->isSupported($post)) {
			return false;
		}
		$at = $post->getCountsAt();

		return $at === null || $now - $at >= self::interval($post, $now);
	}

	/**
	 * The background read (`Cron\RefreshCounts`) of posts that were seen:
	 * the ones still due — a page seen twice, or the cron, may have asked
	 * already.
	 *
	 * @param string[] $postIds
	 * @return array{asked: int, answered: int}
	 */
	public function refresh(array $postIds, bool $force = false): array {
		$now = $this->time->getTime();
		$posts = [];
		foreach (array_unique($postIds) as $id) {
			try {
				$post = $this->streams->getStreamById($id);
			} catch (StreamNotFoundException) {
				continue;
			}
			if ($force || $this->isDue($post, $now)) {
				$posts[] = $post;
			}
		}

		return $this->refreshPosts($posts);
	}

	/**
	 * Asks each post's network about it now, whatever was last asked. A post
	 * no network answers for is stamped all the same, so it is not handed
	 * over again before its interval is out.
	 *
	 * @param Stream[] $posts
	 * @return array{asked: int, answered: int}
	 */
	public function refreshPosts(array $posts): array {
		$left = [];
		foreach ($posts as $post) {
			$left[$post->getId()] = $post;
		}
		$asked = count($left);
		$answered = 0;
		foreach ($this->sources() as $source) {
			$mine = array_filter($left, static fn (Stream $post): bool => $source->supports($post));
			if ($mine === []) {
				continue;
			}
			$left = array_diff_key($left, $mine);
			try {
				$answered += $source->refresh(array_values($mine));
			} catch (Throwable $e) {
				$this->logger->info('Counts not read', ['source' => $source::class, 'exception' => $e]);
				$this->stampAll($mine);
			}
		}
		$this->stampAll($left);
		return ['asked' => $asked, 'answered' => $answered];
	}

	private function visibleState(string $postId): ?string {
		$value = $this->durableCache->getShared(self::VISIBLE_CACHE, md5($postId));

		return is_string($value) ? $value : null;
	}

	private function setVisibleState(string $postId, string $state, int $ttl): void {
		$this->durableCache->setShared(self::VISIBLE_CACHE, md5($postId), $state, $ttl);
	}

	/**
	 * @param array<string, Stream> $posts
	 */
	private function stampAll(array $posts): void {
		foreach (array_keys($posts) as $id) {
			try {
				$this->writer->stamp((string)$id);
			} catch (Throwable $e) {
				$this->logger->info('Counts not stamped', ['post' => $id, 'exception' => $e]);
			}
		}
	}

	private function isSupported(Stream $post): bool {
		foreach ($this->sources() as $source) {
			if ($source->supports($post)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return list<CountSource>
	 */
	private function sources(): array {
		$sources = [];
		foreach ([ActivityPubCountSource::class, BlueskyCountSource::class, BlueskyPublishedCountSource::class] as $class) {
			$source = $this->container?->get($class);
			if ($source instanceof CountSource) {
				$sources[] = $source;
			}
		}

		return $sources;
	}
}
