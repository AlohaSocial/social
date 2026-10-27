<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Cron\FillFollowLists;
use OCA\Social\Cron\FillInteractions;
use OCA\Social\Cron\FillPosts;
use OCA\Social\Cron\FillThread;
use OCA\Social\Cron\RefreshCounts;
use OCA\Social\Cron\ResolveActor;
use OCA\Social\Cron\SyncRemoteTimeline;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The remote fetches a page would like done and must not wait for.
 *
 * Rendering a page used to fetch what was missing from it over HTTP, one
 * actor at a time and at the federation timeout each, so one slow server
 * held a reader's whole timeline. A page now shows what is known here and
 * hands the rest to this, which queues a background job per missing piece;
 * the next look at the page has it.
 *
 * A job is queued once per argument (`IJobList::has()`), so a busy page asked
 * for a hundred times before cron runs is still one job per actor, and one
 * call queues at most `MAX_PER_CALL`, so a page cannot fill the job list.
 */
class RemoteFetchQueue {
	/** The most jobs one call queues; more than one page of anything names. */
	public const MAX_PER_CALL = 20;
	/** How often an incomplete cached actor profile may be refreshed. */
	public const ACTOR_REFRESH_INTERVAL = 900;

	/** Seconds between two syncs of one account's outbox asked for by its profile. */
	public const TIMELINE_SYNC_INTERVAL = 900;

	private const TIMELINE_SYNCED = 'social.outboxsync';
	private const TIMELINE_CURSOR = 'social.outbox.cursor';
	private const TIMELINE_PENDING = 'social.outbox.pending';
	private const TIMELINE_FAILED = 'social.outbox.failed';
	/** Keep failed outbox reads visible and avoid re-queuing them on every poll. */
	public const TIMELINE_RETRY_INTERVAL = 60;
	private const TIMELINE_CURSOR_TTL = 31536000;
	private const TIMELINE_PENDING_TTL = 300;

	/** Seconds between two reads of one conversation asked for by opening it. */
	public const THREAD_FILL_INTERVAL = 600;

	private const THREAD_FILLED = 'social.threadfill';
	private const THREAD_PENDING = 'social.threadfill.pending';

	/** Seconds between two reads of who reacted to one post. */
	public const INTERACTIONS_INTERVAL = 600;

	private const INTERACTIONS_FILLED = 'social.reactfill';

	/** Seconds between two reads of one account's followers, or of whom it follows. */
	public const FOLLOW_LISTS_INTERVAL = 600;

	private const FOLLOW_LISTS_FILLED = 'social.followfill';

	/** Seconds between two reads of one hashtag, or of one search. */
	public const POSTS_INTERVAL = 600;

	private const POSTS_FILLED = 'social.postsfill';

	private const COUNTS_ASKED = 'social.countsfill';
	private const VISIBLE_COUNTS_ASKED = 'social.countsfill.visible';
	private const ACTORS_REFRESHED = 'social.actors.refresh';

	/**
	 * The longest a job's list of posts is, JSON-encoded: `IJobList` refuses
	 * an argument over 4000 characters.
	 */
	private const ARGUMENT_LENGTH = 3500;

	public function __construct(
		private IJobList $jobList,
		private DurableCache $durableCache,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Queues a sync of a remote account's outbox, at most once per
	 * `TIMELINE_SYNC_INTERVAL` per account.
	 *
	 * The interval is stamped when the job is queued rather than when it ran:
	 * what it bounds is how often a profile being looked at makes this
	 * instance read somebody's outbox, however many people look.
	 *
	 * @return bool whether a sync was asked for
	 */
	public function syncTimeline(Person $actor): bool {
		if ($actor->isLocal() || $actor->getId() === '') {
			return false;
		}

		$key = md5($actor->getId());
		try {
			if ($this->isTimelinePending($actor->getId()) || $this->timelineSyncFailed($actor->getId())) {
				return false;
			}
		} catch (Throwable $e) {
			$this->logger->info('[RemoteFetchQueue] could not read timeline sync state', [
				'actor' => $actor->getId(), 'exception' => $e,
			]);
		}

		try {
			if ($this->durableCache->getShared(self::TIMELINE_SYNCED, $key) !== null) {
				return false;
			}
			$this->durableCache->setShared(self::TIMELINE_SYNCED, $key, 1, self::TIMELINE_SYNC_INTERVAL);
		} catch (Throwable $e) {
			// without the stamp the job list's own dedupe still holds: one
			// pending sync per account
		}

		$cursor = $this->timelineCursor($actor->getId());
		$argument = ['actor' => $actor->getId()];
		if ($cursor !== null) {
			$argument['refresh_only'] = '1';
		}
		try {
			$this->durableCache->setShared(self::TIMELINE_PENDING, $key, 1, self::TIMELINE_PENDING_TTL);
		} catch (Throwable $e) {
			$this->clearSharedThrottle(self::TIMELINE_SYNCED, $key, $actor->getId(), 'timeline sync');
			$this->logger->info('[RemoteFetchQueue] could not mark timeline sync pending', [
				'actor' => $actor->getId(), 'exception' => $e,
			]);
			return false;
		}
		if (!$this->queue(SyncRemoteTimeline::class, $argument)) {
			$this->finishTimelinePage($actor->getId(), false, null, false);

			return false;
		}

		return true;
	}

	/**
	 * Ask for the next page of an account's outbox when the local profile page
	 * ran out of posts. Every queue item reads only one bounded page.
	 */
	public function fillTimeline(Person $actor): bool {
		if ($actor->isLocal() || $actor->getId() === '') {
			return false;
		}

		$key = md5($actor->getId());
		try {
			if ($this->isTimelinePending($actor->getId())) {
				return true;
			}
			if ($this->timelineSyncFailed($actor->getId())) {
				return false;
			}

			$cursor = $this->timelineCursor($actor->getId());
			if (!is_array($cursor) || !is_string($cursor['url'] ?? null) || $cursor['url'] === '') {
				return false;
			}

			$argument = ['actor' => $actor->getId(), 'continuation' => '1'];
			$this->durableCache->setShared(self::TIMELINE_PENDING, $key, 1, self::TIMELINE_PENDING_TTL);
			if (!$this->queue(SyncRemoteTimeline::class, $argument)) {
				$this->finishTimelinePage($actor->getId(), false, null, false);
				return false;
			}

			return true;
		} catch (Throwable $e) {
			$this->logger->info('[RemoteFetchQueue] could not request the next timeline page', [
				'actor' => $actor->getId(), 'exception' => $e,
			]);
			return false;
		}
	}

	/** Whether the queued profile outbox read is still expected to finish. */
	public function isTimelinePending(string $actorId): bool {
		if ($actorId === '') {
			return false;
		}

		return $this->durableCache->getShared(self::TIMELINE_PENDING, md5($actorId)) !== null;
	}

	/** A recent background failure, without exposing network errors to readers. */
	public function timelineSyncFailed(string $actorId): bool {
		return $actorId !== '' && $this->durableCache->getShared(self::TIMELINE_FAILED, md5($actorId)) !== null;
	}

	/** @return array{url: string, offset: int}|array{complete: true}|null */
	public function timelineCursor(string $actorId): ?array {
		$cursor = $this->durableCache->getShared(self::TIMELINE_CURSOR, md5($actorId));

		return is_array($cursor) ? $cursor : null;
	}

	/** @param array{url: string, offset: int}|null $cursor */
	public function finishTimelinePage(string $actorId, bool $success, ?array $cursor, bool $updateCursor = true): void {
		$key = md5($actorId);
		if (!$success) {
			$this->durableCache->setShared(self::TIMELINE_FAILED, $key, 1, self::TIMELINE_RETRY_INTERVAL);
			$this->clearSharedThrottle(self::TIMELINE_SYNCED, $key, $actorId, 'timeline sync');
			$this->durableCache->removeShared(self::TIMELINE_PENDING, $key);

			return;
		}

		$this->durableCache->removeShared(self::TIMELINE_FAILED, $key);
		if ($updateCursor) {
			$this->durableCache->setShared(
				self::TIMELINE_CURSOR,
				$key,
				$cursor ?? ['complete' => true],
				self::TIMELINE_CURSOR_TTL
			);
		}
		$this->durableCache->removeShared(self::TIMELINE_PENDING, $key);
	}

	/**
	 * Queues a read of the rest of a conversation (`Cron\FillThread`), at
	 * most once per `THREAD_FILL_INTERVAL` per post, for a post that may
	 * have replies elsewhere: one from another server, or a public one of
	 * this server's, which other networks read too.
	 *
	 * @return bool whether a read was asked for
	 */
	public function fillThread(Stream $post): bool {
		if ($post->getId() === '' || ($post->isLocal() && $post->getVisibility() !== Stream::TYPE_PUBLIC)) {
			return false;
		}
		$key = md5($post->getId());
		try {
			// The web request asks cron to do the work, and cron may allow a
			// continuation pass. Keep this throttle in the shared store so both
			// processes see the same state, even with local-only APCu caching.
			if ($this->durableCache->getShared(self::THREAD_FILLED, $key) !== null) {
				return $this->isThreadFillPending($post->getId());
			}
			$this->durableCache->setShared(self::THREAD_FILLED, $key, 1, self::THREAD_FILL_INTERVAL);
			$this->durableCache->setShared(self::THREAD_PENDING, $key, 1, self::THREAD_FILL_INTERVAL + 60);
		} catch (Throwable $e) {
			// the job list's own dedupe still holds
		}

		if (!$this->queue(FillThread::class, ['post' => $post->getId()])) {
			$this->completeThreadFill($post->getId());
			try {
				$this->durableCache->removeShared(self::THREAD_FILLED, $key);
			} catch (Throwable $e) {
				$this->logger->info('[RemoteFetchQueue] could not reset thread fill throttle', ['post' => $post->getId(), 'exception' => $e]);
			}

			return false;
		}

		return true;
	}

	/** Whether a queued conversation read has finished, as the HTTP reader can see it. */
	public function isThreadFillPending(string $postId): bool {
		try {
			return $this->durableCache->getShared(self::THREAD_PENDING, md5($postId)) !== null;
		} catch (Throwable $e) {
			$this->logger->info('[RemoteFetchQueue] could not read thread fill state', ['post' => $postId, 'exception' => $e]);

			return false;
		}
	}

	/** Clears the shared pending state when the queued conversation read ends. */
	public function completeThreadFill(string $postId): void {
		try {
			$this->durableCache->removeShared(self::THREAD_PENDING, md5($postId));
		} catch (Throwable $e) {
			$this->logger->info('[RemoteFetchQueue] could not clear thread fill state', ['post' => $postId, 'exception' => $e]);
		}
	}

	/** Allow another thread-fill pass when the worker stopped at a saved cursor. */
	public function retryThreadFill(string $postId): void {
		$key = md5($postId);
		try {
			$this->durableCache->removeShared(self::THREAD_FILLED, $key);
		} catch (Throwable $e) {
			$this->logger->info('[RemoteFetchQueue] could not clear incomplete thread fill throttle', ['post' => $postId, 'exception' => $e]);
		}
		$this->completeThreadFill($postId);
	}

	/**
	 * Queues a read of who liked, boosted or quoted a post where it lives
	 * (`Cron\FillInteractions`), at most once per `INTERACTIONS_INTERVAL` per
	 * post and kind, for a post that may have them elsewhere.
	 *
	 * @return bool whether a read was asked for
	 */
	public function fillInteractions(Stream $post, string $type): bool {
		if ($post->getId() === '' || ($post->isLocal() && $post->getVisibility() !== Stream::TYPE_PUBLIC)) {
			return false;
		}
		$key = md5($type . "\0" . $post->getId());
		try {
			if ($this->durableCache->getShared(self::INTERACTIONS_FILLED, $key) !== null) {
				return false;
			}
			$this->durableCache->setShared(self::INTERACTIONS_FILLED, $key, 1, self::INTERACTIONS_INTERVAL);
		} catch (Throwable $e) {
			// the job list's own dedupe still holds
		}

		if (!$this->queue(FillInteractions::class, ['post' => $post->getId(), 'type' => $type])) {
			// Do not suppress another attempt for the full interval when the
			// background job could not be persisted. The visible request can
			// report that the list is still filling and try again shortly.
			$this->clearSharedThrottle(self::INTERACTIONS_FILLED, $key, $post->getId(), 'interaction fill');

			return false;
		}

		return true;
	}

	/** Let the next visible request resume a reaction list the last job left incomplete. */
	public function completeInteractionFill(string $postId, string $type): void {
		$key = md5($type . "\0" . $postId);
		$this->clearSharedThrottle(self::INTERACTIONS_FILLED, $key, $postId, 'interaction fill');
	}

	/**
	 * Queues a read of who follows an account on another server, or whom it
	 * follows, where it lives (`Cron\FillFollowLists`), at most once per
	 * `FOLLOW_LISTS_INTERVAL` per account and direction.
	 *
	 * @param string $direction `FollowListService::FOLLOWERS` or `::FOLLOWING`
	 * @return bool whether a read was asked for
	 */
	public function fillFollowList(Person $account, string $direction): bool {
		if ($account->isLocal() || $account->getId() === '') {
			return false;
		}
		$key = md5($direction . "\0" . $account->getId());
		try {
			if ($this->durableCache->get(self::FOLLOW_LISTS_FILLED, $key) !== null) {
				return false;
			}
			$this->durableCache->set(self::FOLLOW_LISTS_FILLED, $key, 1, self::FOLLOW_LISTS_INTERVAL);
		} catch (Throwable $e) {
			// the job list's own dedupe still holds
		}

		if (!$this->queue(FillFollowLists::class, ['actor' => $account->getId(), 'direction' => $direction])) {
			$this->clearThrottle(self::FOLLOW_LISTS_FILLED, $key, $account->getId(), 'follow-list fill');

			return false;
		}

		return true;
	}

	/** Whether a queued follow-list read has not produced its cache yet. */
	public function followListPending(Person $account, string $direction): bool {
		$key = md5($direction . "\0" . $account->getId());
		try {
			return $this->durableCache->get(self::FOLLOW_LISTS_FILLED, $key) !== null;
		} catch (Throwable $e) {
			return false;
		}
	}

	/**
	 * Queues a read of the posts with a hashtag, or matching a search, beyond
	 * this server (`Cron\FillPosts`), at most once per `POSTS_INTERVAL` per
	 * kind and term — a search per person, as it is asked as them where
	 * they are known.
	 *
	 * @param string $kind `PostDiscoveryService::TAG` or `::SEARCH`
	 * @return bool whether a read was asked for
	 */
	public function fillPosts(string $kind, string $term, ?Person $viewer = null): bool {
		if (!$this->claimPostsFill($kind, $term, $viewer)) {
			return false;
		}

		$term = trim(mb_strtolower($term));
		$viewerId = ($viewer === null || $kind !== 'search') ? '' : $viewer->getId();
		if (!$this->queue(FillPosts::class, ['kind' => $kind, 'term' => $term, 'viewer' => $viewerId])) {
			$key = md5($kind . "\0" . $term . "\0" . $viewerId);
			$this->clearThrottle(self::POSTS_FILLED, $key, $term, 'post fill');

			return false;
		}

		return true;
	}

	/**
	 * Whether the posts with a hashtag, or matching a search, may be read
	 * from beyond this server now, and if so, that they are being: the
	 * `POSTS_INTERVAL` throttle `fillPosts()` keeps, for a caller that reads
	 * them itself (`Service\Discovery\FollowedTagsFill`).
	 *
	 * @param string $kind `PostDiscoveryService::TAG` or `::SEARCH`
	 */
	public function claimPostsFill(string $kind, string $term, ?Person $viewer = null): bool {
		$term = trim(mb_strtolower($term));
		if ($term === '' || mb_strlen($term) > 200) {
			return false;
		}
		$viewerId = ($viewer === null || $kind !== 'search') ? '' : $viewer->getId();
		$key = md5($kind . "\0" . $term . "\0" . $viewerId);
		try {
			if ($this->durableCache->get(self::POSTS_FILLED, $key) !== null) {
				return false;
			}
			$this->durableCache->set(self::POSTS_FILLED, $key, 1, self::POSTS_INTERVAL);
		} catch (Throwable $e) {
			// the job list's own dedupe still holds
		}

		return true;
	}

	/**
	 * Queues a read of what these posts' likes, boosts and replies are now
	 * where they live (`Cron\RefreshCounts`), each post at most once per its
	 * own interval, at most `MAX_PER_CALL` posts a call.
	 *
	 * @param array<string, int> $intervals post id => seconds until it may be asked for again
	 * @return bool whether a read was asked for
	 */
	public function refreshCounts(array $intervals, bool $force = false): bool {
		$wanted = [];
		foreach ($intervals as $id => $interval) {
			if (count($wanted) >= self::MAX_PER_CALL) {
				break;
			}
			$id = (string)$id;
			if ($id === '') {
				continue;
			}
			$key = md5($id);
			$throttle = $force ? self::VISIBLE_COUNTS_ASKED : self::COUNTS_ASKED;
			try {
				if ($this->durableCache->get($throttle, $key) !== null) {
					continue;
				}
				$this->durableCache->set($throttle, $key, 1, $interval);
			} catch (Throwable $e) {
				// the post's own stamp, written when it is asked, still holds
			}
			$wanted[] = $id;
		}

		$queued = false;
		$enqueueBatch = function (array $batch) use (&$queued, $force): void {
			if ($batch === []) {
				return;
			}
			if ($this->queue(RefreshCounts::class, ['posts' => $batch] + ($force ? ['force' => true] : []))) {
				$queued = true;

				return;
			}

			// A post whose refresh job could not be stored must remain eligible
			// for a later request; otherwise the interval marker would make its
			// counts look as though a refresh were already in progress.
			foreach ($batch as $id) {
				$throttle = $force ? self::VISIBLE_COUNTS_ASKED : self::COUNTS_ASKED;
				$this->clearThrottle($throttle, md5($id), $id, 'count refresh');
			}
		};
		$batch = [];
		foreach ($wanted as $id) {
			$argument = ['posts' => [...$batch, $id]] + ($force ? ['force' => true] : []);
			if ($batch !== [] && strlen((string)json_encode($argument)) > self::ARGUMENT_LENGTH) {
				$enqueueBatch($batch);
				$batch = [];
			}
			$batch[] = $id;
		}
		$enqueueBatch($batch);

		return $queued;
	}

	/**
	 * Queues a fetch of each actor not cached here.
	 *
	 * @param string[] $ids actor ids; what is not an http(s) URL is ignored
	 */
	public function resolveActors(array $ids): void {
		$wanted = [];
		foreach ($ids as $id) {
			$anchor = strpos($id, '#');
			if ($anchor !== false) {
				$id = substr($id, 0, $anchor);
			}

			if (preg_match('#^https?://#i', $id) === 1) {
				$wanted[$id] = $id;
			}
		}

		foreach (array_slice(array_values($wanted), 0, self::MAX_PER_CALL) as $id) {
			$this->queue(ResolveActor::class, ['id' => $id]);
		}
	}

	/**
	 * Refreshes cached actor profiles whose lightweight reaction view omitted
	 * fields such as the avatar. The refresh runs away from the request and is
	 * throttled per actor so opening a post cannot create a profile-fetch loop.
	 *
	 * @param string[] $ids actor ids
	 */
	public function refreshActors(array $ids): void {
		$wanted = [];
		foreach ($ids as $id) {
			$anchor = strpos($id, '#');
			if ($anchor !== false) {
				$id = substr($id, 0, $anchor);
			}
			if (preg_match('#^https?://#i', $id) === 1) {
				$wanted[$id] = $id;
			}
		}

		foreach (array_slice(array_values($wanted), 0, self::MAX_PER_CALL) as $id) {
			$key = md5($id);
			try {
				if ($this->durableCache->get(self::ACTORS_REFRESHED, $key) !== null) {
					continue;
				}
				$this->durableCache->set(self::ACTORS_REFRESHED, $key, 1, self::ACTOR_REFRESH_INTERVAL);
			} catch (Throwable $e) {
				// IJobList still deduplicates a refresh that is waiting to run.
			}

			if (!$this->queue(ResolveActor::class, ['id' => $id, 'refresh' => true])) {
				$this->clearThrottle(self::ACTORS_REFRESHED, $key, $id, 'actor profile refresh');
			}
		}
	}

	/**
	 * Queues a lookup of each account named by its `user@host` handle, for
	 * a page that lists accounts by handle and shows the ones not cached
	 * here as not found yet.
	 *
	 * @param string[] $handles
	 */
	public function resolveAccounts(array $handles): void {
		$wanted = [];
		foreach ($handles as $handle) {
			$handle = ltrim(trim($handle), '@');
			if (preg_match('/^[^@\s\/]+@[^@\s\/]+$/', $handle) === 1) {
				$wanted[$handle] = $handle;
			}
		}

		foreach (array_slice(array_values($wanted), 0, self::MAX_PER_CALL) as $handle) {
			$this->queue(ResolveActor::class, ['account' => $handle]);
		}
	}

	/**
	 * @param class-string $job
	 * @param array<string, string|bool|list<string>> $argument
	 */
	private function queue(string $job, array $argument): bool {
		try {
			if (!$this->jobList->has($job, $argument)) {
				$this->jobList->add($job, $argument);
			}
		} catch (Throwable $e) {
			// the page is served either way; what is lost is the fetch, which
			// the next look at the page asks for again
			$this->logger->info('[RemoteFetchQueue] could not queue ' . $job, [
				'argument' => $argument, 'exception' => $e,
			]);

			return false;
		}

		return true;
	}

	/** Let a later request retry work whose queue write failed. */
	private function clearThrottle(string $namespace, string $key, string $resource, string $operation): void {
		try {
			$this->durableCache->remove($namespace, $key);
		} catch (Throwable $e) {
			$this->logger->info('[RemoteFetchQueue] could not reset ' . $operation . ' throttle', [
				'resource' => $resource,
				'exception' => $e,
			]);
		}
	}

	/** A background job and an HTTP request must see the same retry throttle. */
	private function clearSharedThrottle(string $namespace, string $key, string $resource, string $operation): void {
		try {
			$this->durableCache->removeShared($namespace, $key);
		} catch (Throwable $e) {
			$this->logger->info('[RemoteFetchQueue] could not reset shared ' . $operation . ' throttle', [
				'resource' => $resource,
				'exception' => $e,
			]);
		}
	}
}
