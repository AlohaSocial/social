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
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CacheActorSweepService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\FediverseDirectoryService;
use OCA\Social\Service\GroupListService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\MediaUsageService;
use OCA\Social\Service\PollService;
use OCA\Social\Service\ProfileLinkVerifier;
use OCA\Social\Service\RemoteCountService;
use OCA\Social\Service\StreamPruneService;
use OCA\Social\Service\StreamService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

class Cache extends TimedJob {
	/**
	 * How long one pass may spend on the steps below, in seconds.
	 *
	 * `Cron\Queue` has had a budget for a while; this job, which does more and
	 * talks to more servers, had none. Eleven steps ran one after another, two
	 * of them a request per remote actor, until they were done or something
	 * killed the process — so a handful of slow peers could hold a cron slot
	 * open past the twelve-minute interval and overlap the next run. Same
	 * value as the queue's, and for the same reason: well inside the interval,
	 * so two runs cannot meet.
	 *
	 * Nothing is lost when the budget runs out. Every step is resumable by
	 * design — each takes a bounded batch of whatever is due — and the steps
	 * that did not get a turn are where the next run starts.
	 */
	public const MAX_DURATION = 300;

	/**
	 * How long each of the two remote-actor refresh steps may take, in
	 * seconds, inside the pass's own budget.
	 *
	 * They take batch after batch until this runs out, rather than one batch
	 * of fifty — which could not keep a ten-day lifetime past about 60,000
	 * cached actors. Ninety each leaves the other steps a third of the pass.
	 */
	public const REMOTE_ACTOR_SECONDS = 90;

	/** Cached remote actors evicted per pass; see CacheActorSweepService. */
	public const SWEEP_BATCH = 500;

	/**
	 * How long one pass may spend asking remote servers what a post's counts
	 * are now, in seconds, inside the pass's own budget.
	 *
	 * Like the two steps above, it takes round after round of posts until this
	 * runs out rather than one batch: twenty posts in parallel, sixty seconds
	 * of them, and the posts still due are the next run's to start with.
	 */
	public const COUNTS_SECONDS = 60;

	/**
	 * How often the disk is added up, in seconds.
	 *
	 * Once a day: it is a `stat` per stored file, and a number about disk does
	 * not change in ways an administrator has to see within the hour.
	 */
	public const MEDIA_USAGE_EVERY = 86400;

	public function __construct(
		ITimeFactory $time,
		private AccountService $accountService,
		private CacheActorService $cacheActorService,
		private DocumentService $documentService,
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
	) {
		parent::__construct($time);
		$this->setInterval(12 * 60);
	}

	/**
	 * The steps of one pass, in the order they are meant to run.
	 *
	 * @param int $deadline the wall clock past which this run is over; the two
	 *                      steps that loop over remote servers watch it themselves,
	 *                      because either can outlast the whole budget on its own
	 *
	 * @return array<string, callable(): void>
	 */
	private function steps(int $deadline): array {
		return [
			'manageDeletedActors' => function (): void {
				$this->accountService->manageDeletedActors();
			},
			'manageCacheLocalActors' => function () use ($deadline): void {
				// the one step that used to read the whole of a table into
				// memory; it pages and resumes now, and is told when to stop
				$this->accountService->manageCacheLocalActors($deadline);
			},
			'manageCacheRemoteActors' => function () use ($deadline): void {
				$this->cacheActorService->manageCacheRemoteActors(false, $this->stepDeadline($deadline));
			},
			'manageDetailsRemoteActors' => function () use ($deadline): void {
				$this->cacheActorService->manageDetailsRemoteActors(false, $this->stepDeadline($deadline));
			},
			'manageCacheDocuments' => function (): void {
				$this->documentService->manageCacheDocuments();
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
				// what a remote post's likes, boosts and replies are now, asked
				// of the server that holds it: the counts stored with a post
				// were what its document said the moment it arrived, and
				// nothing has asked again since
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
				// a `stat` per stored file, which is why it is here and not on
				// the administration page: the page reads what this left and
				// says when it was measured. Once a day is enough for a number
				// about disk.
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
	 * Which step this pass begins with.
	 *
	 * The order above is the order the steps want to run in, not an order any
	 * of them depends on — each reads what is due and writes what it finished.
	 * Run from the top every time under a budget, though, the tail of the list
	 * is the part that never runs on a busy instance: `verifyProfileLinks` and
	 * `reconcileGroupLists` sit behind two steps that each make one request per
	 * remote actor. So a pass that ran out of time hands the next one the step
	 * it stopped at, and a pass that finished starts from the top again.
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
	 * Runs one step of the cron and keeps going if it fails.
	 *
	 * At `warning`, not `debug`: Nextcloud's default loglevel is 2, so a
	 * `debug` line is written on no default instance, and the four cron steps
	 * that keep the caches alive used to fail invisibly. The step is named,
	 * because seven identical messages could not tell an administrator which
	 * one broke.
	 */
	private function step(string $step, callable $work): void {
		// each step reads the actors it touches afresh: cron.php is one process
		// for every job it runs, and an actor memoised by an earlier step
		// would hide what that step wrote
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
	 * One unreachable remote instance must not stop the sync of the others,
	 * so each actor is caught on its own — but it is still logged, with the
	 * actor it happened on.
	 *
	 * One request per actor, and the batch is fifty: on its own this step can
	 * outlast the whole budget against a slow peer, so it checks the clock
	 * between actors rather than only at its own start.
	 *
	 * Its own batch, not the refresh's: `getRemoteActorsToSync()` says why.
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
