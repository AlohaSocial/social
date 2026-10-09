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

	/** Seconds between two syncs of one account's outbox asked for by its profile. */
	public const TIMELINE_SYNC_INTERVAL = 900;

	private const TIMELINE_SYNCED = 'social.outboxsync';

	/** Seconds between two reads of one conversation asked for by opening it. */
	public const THREAD_FILL_INTERVAL = 600;

	private const THREAD_FILLED = 'social.threadfill';

	/** Seconds between two reads of who reacted to one post. */
	public const INTERACTIONS_INTERVAL = 600;

	private const INTERACTIONS_FILLED = 'social.reactfill';

	/** Seconds between two reads of one account's followers, or of whom it follows. */
	public const FOLLOW_LISTS_INTERVAL = 600;

	private const FOLLOW_LISTS_FILLED = 'social.followfill';

	/** Seconds between two reads of one hashtag, or of one search. */
	public const POSTS_INTERVAL = 600;

	private const POSTS_FILLED = 'social.postsfill';

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
			if ($this->durableCache->get(self::TIMELINE_SYNCED, $key) !== null) {
				return false;
			}
			$this->durableCache->set(self::TIMELINE_SYNCED, $key, 1, self::TIMELINE_SYNC_INTERVAL);
		} catch (Throwable $e) {
			// without the stamp the job list's own dedupe still holds: one
			// pending sync per account
		}

		$this->queue(SyncRemoteTimeline::class, ['actor' => $actor->getId()]);

		return true;
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
			if ($this->durableCache->get(self::THREAD_FILLED, $key) !== null) {
				return false;
			}
			$this->durableCache->set(self::THREAD_FILLED, $key, 1, self::THREAD_FILL_INTERVAL);
		} catch (Throwable $e) {
			// the job list's own dedupe still holds
		}

		$this->queue(FillThread::class, ['post' => $post->getId()]);

		return true;
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
			if ($this->durableCache->get(self::INTERACTIONS_FILLED, $key) !== null) {
				return false;
			}
			$this->durableCache->set(self::INTERACTIONS_FILLED, $key, 1, self::INTERACTIONS_INTERVAL);
		} catch (Throwable $e) {
			// the job list's own dedupe still holds
		}

		$this->queue(FillInteractions::class, ['post' => $post->getId(), 'type' => $type]);

		return true;
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

		$this->queue(FillFollowLists::class, ['actor' => $account->getId(), 'direction' => $direction]);

		return true;
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
		$this->queue(FillPosts::class, ['kind' => $kind, 'term' => $term, 'viewer' => $viewerId]);

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
	 * @param class-string $job
	 * @param array<string, string> $argument
	 */
	private function queue(string $job, array $argument): void {
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
		}
	}
}
