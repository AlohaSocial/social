<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use Exception;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\AvatarThumbnailBackfill;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CacheActorSweepService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\FediverseDirectoryService;
use OCA\Social\Service\GroupListService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\MediaUsageService;
use OCA\Social\Service\PollService;
use OCA\Social\Service\ProfileLinkVerifier;
use OCA\Social\Service\RemoteCountService;
use OCA\Social\Service\RemoteMediaPrefetcher;
use OCA\Social\Service\StreamPruneService;
use OCA\Social\Service\StreamService;
use OCA\Social\Service\VideoViewService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

class Cache extends TimedJob {
	/**
	 * How long one pass may spend on the steps below, in seconds: well inside the
	 * twelve-minute interval, as `Cron\Queue`'s, so slow peers cannot make two
	 * runs overlap. Every step takes a bounded batch, and the next run starts
	 * with the steps that did not get a turn.
	 */
	public const MAX_DURATION = 300;

	/**
	 * How long each of the two remote-actor refresh steps may take, in seconds,
	 * inside the pass's own budget; they take batch after batch until it runs
	 * out. Ninety each leaves the other steps a third of the pass.
	 */
	public const REMOTE_ACTOR_SECONDS = 90;

	/** Cached remote actors evicted per pass; see CacheActorSweepService. */
	public const SWEEP_BATCH = 500;

	/**
	 * How long one pass may spend asking remote servers for posts' current
	 * counts, in seconds, inside the pass's own budget: twenty posts in parallel,
	 * round after round, the rest left for the next run.
	 */
	public const COUNTS_SECONDS = 60;

	/**
	 * How long one pass may spend fetching remote media files, in seconds,
	 * inside the pass's own budget: wave after wave of downloads until this
	 * runs out (`RemoteMediaPrefetcher::prefetchPending()`).
	 */
	public const MEDIA_SECONDS = 90;

	/**
	 * How often the disk is added up, in seconds: once a day, as it is a `stat`
	 * per stored file.
	 */
	public const MEDIA_USAGE_EVERY = 86400;

	public function __construct(
		ITimeFactory $time,
		private AccountService $accountService,
		private CacheActorService $cacheActorService,
		private RemoteMediaPrefetcher $mediaPrefetcher,
		private HashtagService $hashtagService,
		private StreamService $streamService,
		private StreamPruneService $streamPruneService,
		private CacheActorsRequest $cacheActorsRequest,
		private PollService $pollService,
		private LoggerInterface $logger,
		private ?GroupListService $groupListService = null,
		private ?ProfileLinkVerifier $profileLinkVerifier = null,
		private ?CacheActorSweepService $cacheActorSweepService = null,
		private ?ConfigService $configService = null,
		private ?MediaUsageService $mediaUsageService = null,
		private ?DurableCache $durableCache = null,
		private ?FediverseDirectoryService $fediverseDirectoryService = null,
		private ?RemoteCountService $remoteCountService = null,
		private ?ClientService $clientService = null,
		private ?VideoViewService $videoViewService = null,
		private ?AvatarThumbnailBackfill $avatarThumbnailBackfill = null,
	) {
		parent::__construct($time);
		$this->setInterval(12 * 60);
	}

	/**
	 * The steps of one pass, in the order they are meant to run.
	 *
	 * @param int $deadline the wall clock past which this run is over; the two
	 *                      remote-actor steps watch it themselves, since either
	 *                      can outlast the whole budget
	 *
	 * @return array<string, callable(): void>
	 */
	private function steps(int $deadline): array {
		return [
			'manageDeletedActors' => function (): void {
				$this->accountService->manageDeletedActors();
			},
			'manageCacheLocalActors' => function () use ($deadline): void {
				// pages and resumes, and is told when to stop
				$this->accountService->manageCacheLocalActors($deadline);
			},
			'manageCacheRemoteActors' => function () use ($deadline): void {
				$this->cacheActorService->manageCacheRemoteActors(false, $this->stepDeadline($deadline));
			},
			'manageDetailsRemoteActors' => function () use ($deadline): void {
				$this->cacheActorService->manageDetailsRemoteActors(false, $this->stepDeadline($deadline));
			},
			'manageCacheDocuments' => function () use ($deadline): void {
				// the backstop behind the per-post queue: every remote file
				// still waiting, newest first, several at a time
				$this->mediaPrefetcher->prefetchPending(
					min($deadline, $this->time->getTime() + self::MEDIA_SECONDS)
				);
			},
			'manageHashtags' => function (): void {
				$this->hashtagService->manageHashtags();
			},
			'announceClosedPolls' => function (): void {
				// a poll closes by its end time passing, so nothing happens at the
				// moment it does and something has to look
				$this->pollService->announceClosedPolls();
			},
			'prune' => function (): void {
				// bounded per run so retention never dominates a cron slot
				$this->streamPruneService->prune(null, false, 5000);
			},
			'sweepCachedActors' => function (): void {
				// the cached remote actors nothing here refers to any more, and
				// their avatars; bounded for the same reason as the prune above
				$this->cacheActorSweepService?->sweep(null, false, self::SWEEP_BATCH);
			},
			'syncRemoteTimelines' => function () use ($deadline): void {
				$this->syncRemoteTimelines($deadline);
			},
			'refreshRemoteCounts' => function () use ($deadline): void {
				// what a remote post's likes, boosts and replies are now, asked of the
				// server that holds it
				$this->remoteCountService?->refresh(
					false, RemoteCountService::BATCH,
					min($deadline, $this->time->getTime() + self::COUNTS_SECONDS)
				);
			},
			'verifyProfileLinks' => function () use ($deadline): void {
				// this instance's own accounts; remote ones are checked with their
				// details refresh
				$this->profileLinkVerifier?->verifyLocalActors(ProfileLinkVerifier::LOCAL_BATCH, $deadline);
			},
			'measureMediaUsage' => function (): void {
				// a `stat` per stored file, so here and not on the administration page,
				// which reads what this left
				if ($this->time->getTime() - $this->lastMediaMeasurement() >= self::MEDIA_USAGE_EVERY) {
					$this->mediaUsageService?->measureAndStore();
				}
			},
			'reconcileGroupLists' => function (): void {
				// the catch-all behind the listener: an account made after its
				// group's lists were, a change the listener did not see
				$this->groupListService?->reconcile();
			},
			'refreshDirectorySources' => function (): void {
				// which servers the Discover page may ask, found out here so
				// that the page never waits on a remote host to list them
				$this->fediverseDirectoryService?->refresh();
			},
			'purgeDurableCache' => function (): void {
				// a read already ignores an expired row; this keeps the table
				// the size of what is live
				$this->durableCache?->purgeExpired();
			},
			'sweepClients' => function (): void {
				// expired authorizations, and the registrations the public
				// app endpoint left that nobody ever signed in with
				$this->clientService?->sweep();
			},
			'forgetVideoViewers' => function (): void {
				// who was counted watching a video is kept for the day it was
				// counted on, and not a day longer
				$this->videoViewService?->forgetEarlierDays();
			},
			'thumbnailAvatars' => function (): void {
				// the small copy the web UI draws, for icons cached without one
				$this->avatarThumbnailBackfill?->next();
			},
		];
	}

	/** The sooner of the pass's deadline and a remote-actor step's own budget. */
	private function stepDeadline(int $deadline): int {
		return min($deadline, $this->time->getTime() + self::REMOTE_ACTOR_SECONDS);
	}

	/** When the disk was last added up, as the stored measurement says. */
	private function lastMediaMeasurement(): int {
		return (int)($this->mediaUsageService?->lastMeasured()['measured'] ?? 0);
	}

	#[\Override]
	protected function run($argument) {
		$deadline = $this->time->getTime() + self::MAX_DURATION;
		$steps = $this->steps($deadline);
		$names = array_keys($steps);
		$start = $this->startingStep(count($names));

		$skipped = [];
		for ($offset = 0; $offset < count($names); $offset++) {
			$name = $names[($start + $offset) % count($names)];
			if ($this->time->getTime() >= $deadline) {
				$skipped[] = $name;
				continue;
			}

			$this->step($name, $steps[$name]);
		}

		$this->rememberStartingStep($skipped === [] ? 0 : (int)array_search($skipped[0], $names, true));

		if ($skipped !== []) {
			$this->logger->warning(
				'[Cron\\Cache] out of time after ' . self::MAX_DURATION . 's, '
				. count($skipped) . ' step(s) skipped: ' . implode(', ', $skipped)
				. '; the next run starts with ' . $skipped[0],
				['skipped' => $skipped, 'budget' => self::MAX_DURATION]
			);
		}
	}

	/**
	 * Which step this pass begins with. The steps depend on no order, but run
	 * from the top under a budget the tail would never run on a busy instance,
	 * so a pass that ran out of time hands the next one the step it stopped at.
	 */
	private function startingStep(int $steps): int {
		$stored = $this->configService?->getAppValueInt(ConfigService::SOCIAL_CACHE_CRON_START) ?? 0;

		return ($stored < 0) ? 0 : $stored % $steps;
	}

	private function rememberStartingStep(int $step): void {
		try {
			$this->configService?->setAppValue(ConfigService::SOCIAL_CACHE_CRON_START, (string)$step);
		} catch (\Throwable $e) {
			// losing the rotation costs fairness between the steps, not correctness
			$this->logger->warning(
				'[Cron\\Cache] could not record which step to start with next time',
				['exception' => $e]
			);
		}
	}

	/**
	 * Runs one step of the cron and keeps going if it fails, logging at
	 * `warning` with the step's name, since Nextcloud's default log level drops
	 * `debug`.
	 */
	private function step(string $step, callable $work): void {
		// each step reads the actors it touches afresh: cron.php runs every job in
		// one process, and an actor memoised earlier would hide what a step wrote
		$this->cacheActorService->forgetMemoised();
		try {
			$work();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[Cron\\Cache] step "' . $step . '" failed: ' . $e->getMessage(),
				['exception' => $e, 'step' => $step]
			);
		}
	}

	/**
	 * Syncs remote actors, each caught and logged on its own so one unreachable
	 * instance does not stop the others. One request per actor, so the clock is
	 * checked between actors. Its own batch: see `getRemoteActorsToSync()`.
	 */
	private function syncRemoteTimelines(int $deadline): void {
		foreach ($this->cacheActorsRequest->getRemoteActorsToSync() as $actor) {
			if ($this->time->getTime() >= $deadline) {
				$this->logger->info(
					'[Cron\\Cache] out of time while syncing remote timelines; the rest wait for the next run',
					['step' => 'syncRemoteTimelines']
				);

				return;
			}

			try {
				$this->streamService->syncRemoteTimeline($actor);
			} catch (Exception $e) {
				$this->logger->warning(
					'[Cron\\Cache] could not sync the timeline of ' . $actor->getId()
					. ': ' . $e->getMessage(),
					['exception' => $e, 'step' => 'syncRemoteTimelines', 'actor' => $actor->getId()]
				);
			}
		}
	}
}
