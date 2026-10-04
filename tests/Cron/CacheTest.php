<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Cron\Cache;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CacheActorSweepService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\FediverseDirectoryService;
use OCA\Social\Service\GroupListService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\PollService;
use OCA\Social\Service\ProfileLinkVerifier;
use OCA\Social\Service\StreamPruneService;
use OCA\Social\Service\StreamService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class CacheTest extends TestCase {
	private const NOW = 1700000000;

	/** @var AccountService&MockObject */
	private $accountService;
	/** @var CacheActorService&MockObject */
	private $cacheActorService;
	/** @var DocumentService&MockObject */
	private $documentService;
	/** @var HashtagService&MockObject */
	private $hashtagService;
	/** @var StreamService&MockObject */
	private $streamService;
	private $streamPruneService;
	private PollService|Stub $pollService;
	private GroupListService|MockObject $groupListService;
	private ProfileLinkVerifier|MockObject $profileLinkVerifier;
	private CacheActorSweepService|MockObject $sweepService;
	private ConfigService|MockObject $configService;
	private DurableCache|MockObject $durableCache;
	private FediverseDirectoryService|MockObject $directoryService;
	/** The clock every part of the job reads; a step may move it. */
	private int $now = self::NOW;
	/** @var CacheActorsRequest&MockObject */
	private $cacheActorsRequest;
	/** @var IJobList&MockObject */
	private $jobList;
	/** @var LoggerInterface&MockObject */
	private $logger;
	/** @var array<int, array{message: string, context: array}> what captureWarnings() collected */
	private array $warnings = [];
	private Cache $job;

	protected function setUp(): void {
		$this->now = self::NOW;
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$this->accountService = $this->createMock(AccountService::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->documentService = $this->createMock(DocumentService::class);
		$this->hashtagService = $this->createMock(HashtagService::class);
		$this->streamService = $this->createMock(StreamService::class);
		$this->streamPruneService = $this->createStub(StreamPruneService::class);
		$this->cacheActorsRequest = $this->createMock(CacheActorsRequest::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->pollService = $this->createStub(PollService::class);
		$this->groupListService = $this->createMock(GroupListService::class);
		$this->profileLinkVerifier = $this->createMock(ProfileLinkVerifier::class);
		$this->sweepService = $this->createMock(CacheActorSweepService::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->durableCache = $this->createMock(DurableCache::class);
		$this->directoryService = $this->createMock(FediverseDirectoryService::class);

		$this->job = new Cache(
			$time,
			$this->accountService,
			$this->cacheActorService,
			$this->documentService,
			$this->hashtagService,
			$this->streamService,
			$this->streamPruneService,
			$this->cacheActorsRequest,
			$this->pollService,
			$this->logger,
			$this->groupListService,
			$this->profileLinkVerifier,
			$this->sweepService,
			$this->configService,
			null,
			$this->durableCache,
			$this->directoryService
		);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	public function testIsATimedJobRunningEveryTwelveMinutes(): void {
		$this->assertInstanceOf(TimedJob::class, $this->job);

		$interval = new \ReflectionProperty(TimedJob::class, 'interval');

		$this->assertSame(12 * 60, $interval->getValue($this->job));
	}

	public function testRunRefreshesEveryCacheAndSyncsRemoteTimelines(): void {
		$this->accountService->expects($this->once())->method('manageDeletedActors');
		$this->accountService->expects($this->once())->method('manageCacheLocalActors');
		$this->cacheActorService->expects($this->once())->method('manageCacheRemoteActors');
		$this->cacheActorService->expects($this->once())->method('manageDetailsRemoteActors');
		$this->documentService->expects($this->once())->method('manageCacheDocuments');
		$this->hashtagService->expects($this->once())->method('manageHashtags');
		// the catch-all behind the group listener runs every pass
		$this->groupListService->expects($this->once())->method('reconcile');
		$this->profileLinkVerifier->expects($this->once())->method('verifyLocalActors');
		$bob = $this->createStub(Person::class);
		$carol = $this->createStub(Person::class);
		$this->cacheActorsRequest->expects($this->once())->method('getRemoteActorsToSync')->willReturn([$bob, $carol]);
		$synced = [];
		$this->streamService->expects($this->exactly(2))->method('syncRemoteTimeline')
			->willReturnCallback(function (Person $actor) use (&$synced): int {
				$synced[] = $actor;

				return 0;
			});
		$this->jobList->expects($this->once())->method('setLastRun')->with($this->job);

		$this->job->start($this->jobList);

		$this->assertSame([$bob, $carol], $synced);
	}

	/**
	 * The two remote-actor refreshes are given a time to fill rather than one
	 * batch of fifty, each its own share of the pass so neither takes it all.
	 */
	public function testTheRemoteActorRefreshesAreGivenTheirOwnShareOfThePass(): void {
		$given = [];
		$this->cacheActorService->expects($this->once())->method('manageCacheRemoteActors')
			->willReturnCallback(function (bool $force, int $deadline) use (&$given): int {
				$given['refresh'] = [$force, $deadline];

				return 0;
			});
		$this->cacheActorService->expects($this->once())->method('manageDetailsRemoteActors')
			->willReturnCallback(function (bool $force, int $deadline) use (&$given): int {
				$given['details'] = [$force, $deadline];

				return 0;
			});
		$this->cacheActorsRequest->method('getRemoteActorsToSync')->willReturn([]);

		$this->job->start($this->jobList);

		$this->assertSame([false, self::NOW + Cache::REMOTE_ACTOR_SECONDS], $given['refresh']);
		$this->assertSame([false, self::NOW + Cache::REMOTE_ACTOR_SECONDS], $given['details']);
		$this->assertLessThan(Cache::MAX_DURATION, 2 * Cache::REMOTE_ACTOR_SECONDS);
	}

	public function testAFailingStepDoesNotStopTheOthers(): void {
		// An Error (e.g. the undefined-constant fatal manageDeletedActors used to
		// raise) is not an Exception, so a catch (Exception) would have let it kill
		// the whole run; the cron catches Throwable.
		$this->accountService->method('manageDeletedActors')->willThrowException(new \Error('undefined constant'));
		$this->cacheActorService->method('manageCacheRemoteActors')->willThrowException(new \RuntimeException('network'));
		$this->accountService->expects($this->once())->method('manageCacheLocalActors');
		$this->cacheActorService->expects($this->once())->method('manageDetailsRemoteActors');
		$this->documentService->expects($this->once())->method('manageCacheDocuments');
		$this->hashtagService->expects($this->once())->method('manageHashtags');
		$this->cacheActorsRequest->expects($this->once())->method('getRemoteActorsToSync')->willReturn([]);
		$this->captureWarnings();

		$this->job->start($this->jobList);

		$this->assertCount(2, $this->warnings, 'both failing steps have to be reported');
	}

	/**
	 * The seven catches used to log at `debug` with one identical message, so
	 * on a default instance (loglevel 2 = warn) nothing was written at all,
	 * and even at loglevel 0 you could not tell which step had failed.
	 */
	public function testEachFailingStepIsLoggedAtWarningAndNamed(): void {
		$this->accountService->method('manageDeletedActors')->willThrowException(new \RuntimeException('boom'));
		$this->cacheActorsRequest->method('getRemoteActorsToSync')->willReturn([]);
		$this->logger->expects($this->never())->method('debug');
		$this->captureWarnings();

		$this->job->start($this->jobList);

		$this->assertCount(1, $this->warnings);
		$this->assertStringContainsString('manageDeletedActors', $this->warnings[0]['message']);
		$this->assertStringContainsString('boom', $this->warnings[0]['message']);
		$this->assertSame('manageDeletedActors', $this->warnings[0]['context']['step'] ?? null);
		$this->assertInstanceOf(\RuntimeException::class, $this->warnings[0]['context']['exception'] ?? null);
	}

	public function testEveryStepIsNamedByADistinctMessage(): void {
		$this->accountService->method('manageDeletedActors')->willThrowException(new \RuntimeException('a'));
		$this->accountService->method('manageCacheLocalActors')->willThrowException(new \RuntimeException('b'));
		$this->cacheActorService->method('manageCacheRemoteActors')->willThrowException(new \RuntimeException('c'));
		$this->cacheActorService->method('manageDetailsRemoteActors')->willThrowException(new \RuntimeException('d'));
		$this->documentService->method('manageCacheDocuments')->willThrowException(new \RuntimeException('e'));
		$this->hashtagService->method('manageHashtags')->willThrowException(new \RuntimeException('f'));
		$this->streamPruneService->method('prune')->willThrowException(new \RuntimeException('g'));
		$this->cacheActorsRequest->method('getRemoteActorsToSync')->willThrowException(new \RuntimeException('h'));
		$this->captureWarnings();

		$this->job->start($this->jobList);

		$steps = array_column(array_column($this->warnings, 'context'), 'step');

		$this->assertCount(8, $this->warnings);
		$this->assertSame($steps, array_unique($steps), 'two steps report the same name');
	}

	public function testOneUnreachableRemoteActorDoesNotStopTheTimelineSync(): void {
		$gone = $this->createStub(Person::class);
		$gone->method('getId')->willReturn('https://gone.example/users/x');
		$alive = $this->createStub(Person::class);
		$alive->method('getId')->willReturn('https://alive.example/users/y');
		$this->cacheActorsRequest->method('getRemoteActorsToSync')->willReturn([$gone, $alive]);
		$this->streamService->expects($this->exactly(2))->method('syncRemoteTimeline')
			->willReturnCallback(function (Person $actor) use ($gone): int {
				if ($actor === $gone) {
					throw new \RuntimeException('410 Gone');
				}

				return 3;
			});
		$this->captureWarnings();

		$this->job->start($this->jobList);

		$this->assertCount(1, $this->warnings, 'the unreachable actor has to be reported');
		$this->assertStringContainsString('https://gone.example/users/x', $this->warnings[0]['message']);
	}

	/**
	 * Records what the job logs at `warning` into $this->warnings.
	 *
	 * The returned name is only there to read naturally at the call site; the
	 * array itself is the property, which fills up while the job runs.
	 */
	private function captureWarnings(): void {
		$this->warnings = [];
		$this->logger->method('warning')
			->willReturnCallback(function (string $message, array $context = []): void {
				$this->warnings[] = ['message' => $message, 'context' => $context];
			});
	}

	/**
	 * Records the order the steps run in.
	 *
	 * Each step is a different mock, so "which ran first" cannot be asserted
	 * with expectations; they all write into one list instead.
	 *
	 * @return list<string> filled while the job runs
	 */
	private function &recordStepOrder(): array {
		$order = [];
		$note = static function (string $step) use (&$order): int {
			$order[] = $step;

			return 0;
		};

		$this->accountService->method('manageDeletedActors')->willReturnCallback(fn (): int => $note('manageDeletedActors'));
		$this->accountService->method('manageCacheLocalActors')->willReturnCallback(fn (): int => $note('manageCacheLocalActors'));
		$this->cacheActorService->method('manageCacheRemoteActors')->willReturnCallback(fn (): int => $note('manageCacheRemoteActors'));
		$this->cacheActorService->method('manageDetailsRemoteActors')->willReturnCallback(fn (): int => $note('manageDetailsRemoteActors'));
		$this->documentService->method('manageCacheDocuments')->willReturnCallback(fn (): int => $note('manageCacheDocuments'));
		$this->hashtagService->method('manageHashtags')->willReturnCallback(fn (): int => $note('manageHashtags'));
		$this->pollService->method('announceClosedPolls')->willReturnCallback(fn (): int => $note('announceClosedPolls'));
		$this->streamPruneService->method('prune')->willReturnCallback(fn (): array => ['streams' => $note('prune')]);
		$this->sweepService->method('sweep')->willReturnCallback(fn (): array => ['actors' => $note('sweepCachedActors')]);
		$this->cacheActorsRequest->method('getRemoteActorsToSync')->willReturnCallback(function () use ($note): array {
			$note('syncRemoteTimelines');

			return [];
		});
		$this->profileLinkVerifier->method('verifyLocalActors')->willReturnCallback(fn (): int => $note('verifyProfileLinks'));
		$this->groupListService->method('reconcile')->willReturnCallback(fn (): int => $note('reconcileGroupLists'));

		return $order;
	}

	/**
	 * A read already ignores an expired row of the durable cache's table;
	 * without a purge the table would still grow by every counter and replay
	 * record an instance without a memcache ever wrote.
	 */
	public function testTheDurableCacheIsPurgedOnEveryPass(): void {
		$this->cacheActorsRequest->method('getRemoteActorsToSync')->willReturn([]);
		$this->durableCache->expects($this->once())->method('purgeExpired')->willReturn(3);

		$this->job->start($this->jobList);
	}

	/** The Discover page reads what this finds out, and never asks itself. */
	public function testTheDirectorySourcesAreRefreshedHere(): void {
		$this->cacheActorsRequest->method('getRemoteActorsToSync')->willReturn([]);
		$this->directoryService->expects($this->once())->method('refresh');

		$this->job->start($this->jobList);
	}

	public function testTheCacheCronEvictsTheActorsNobodyRefersToAnyMore(): void {
		$this->cacheActorsRequest->method('getRemoteActorsToSync')->willReturn([]);
		$this->sweepService->expects($this->once())->method('sweep')
			->with(null, false, Cache::SWEEP_BATCH)
			->willReturn(['actors' => 3, 'documents' => 4]);

		$this->job->start($this->jobList);
	}

	/**
	 * The job used to run until it was done or something killed it, so a
	 * handful of slow peers could hold a cron slot open past the interval and
	 * overlap the next run. `Cron\Queue` has had a budget for a while.
	 */
	public function testAStepThatSpendsTheBudgetStopsTheRestOfTheRun(): void {
		$order = &$this->recordStepOrder();
		$this->accountService->method('manageDeletedActors')->willReturnCallback(function (): int {
			$this->now += Cache::MAX_DURATION;

			return 0;
		});
		$this->captureWarnings();

		$this->job->start($this->jobList);

		$this->assertSame(
			['manageDeletedActors'], $order,
			'nothing after the step that ran out of time may run'
		);
		$this->assertCount(1, $this->warnings);
		$this->assertStringContainsString('15 step(s) skipped', $this->warnings[0]['message']);
		$this->assertStringContainsString('manageCacheLocalActors', $this->warnings[0]['message']);
	}

	/**
	 * Run from the top every time under a budget, the tail of the list is the
	 * part that never runs: `verifyProfileLinks` and `reconcileGroupLists` sit
	 * behind two steps that make a request per remote actor.
	 */
	public function testTheNextRunStartsWithTheStepThisOneSkipped(): void {
		$this->recordStepOrder();
		$this->accountService->method('manageCacheLocalActors')->willReturnCallback(function (): int {
			$this->now += Cache::MAX_DURATION;

			return 0;
		});
		$this->configService->expects($this->once())->method('setAppValue')
			->with(ConfigService::SOCIAL_CACHE_CRON_START, '2');

		$this->job->start($this->jobList);
	}

	public function testARunThatGetsThroughEverythingStartsFromTheTopAgain(): void {
		$this->recordStepOrder();
		$this->configService->expects($this->once())->method('setAppValue')
			->with(ConfigService::SOCIAL_CACHE_CRON_START, '0');
		$this->logger->expects($this->never())->method('warning');

		$this->job->start($this->jobList);
	}

	public function testARunPicksUpWhereTheLastOneStopped(): void {
		$order = &$this->recordStepOrder();
		$this->configService->method('getAppValueInt')
			->with(ConfigService::SOCIAL_CACHE_CRON_START)->willReturn(10);

		$this->job->start($this->jobList);

		$this->assertSame('verifyProfileLinks', $order[0]);
		$this->assertSame('reconcileGroupLists', $order[1]);
		$this->assertSame('manageDeletedActors', $order[2]);
		$this->assertCount(12, $order, 'every step still gets a turn, only in a rotated order');
	}

	/**
	 * One request per actor and a batch of fifty: on its own this step can
	 * outlast the whole budget against a slow peer, so it watches the clock
	 * between actors rather than only at its own start.
	 */
	public function testTheTimelineSyncStopsMidBatchWhenTheBudgetIsSpent(): void {
		$actors = [];
		foreach (['a', 'b', 'c'] as $name) {
			$actor = $this->createStub(Person::class);
			$actor->method('getId')->willReturn('https://slow.example/users/' . $name);
			$actors[] = $actor;
		}
		$this->cacheActorsRequest->method('getRemoteActorsToSync')->willReturn($actors);
		$this->streamService->expects($this->once())->method('syncRemoteTimeline')
			->willReturnCallback(function (): int {
				$this->now += Cache::MAX_DURATION;

				return 0;
			});

		$this->job->start($this->jobList);
	}

	/**
	 * The timeline sync used to be handed the refresh's own batch, which
	 * worked only because nothing could make an actor stop being stale. Now
	 * that a refresh stamps what it touched, sharing the selection would mean
	 * that on an instance with fewer than fifty remote actors the refresh
	 * consumed the whole set and no timeline was ever synced again.
	 */
	public function testTheTimelineSyncHasABatchTheRefreshCannotConsume(): void {
		$this->cacheActorsRequest->expects($this->never())->method('getRemoteActorsToUpdate');
		$this->cacheActorsRequest->expects($this->once())->method('getRemoteActorsToSync')->willReturn([]);

		$this->job->start($this->jobList);
	}

	public function testRunIsSkippedWhenTheLastRunIsTooRecent(): void {
		$this->job->setLastRun(self::NOW - 60);
		$this->accountService->expects($this->never())->method('manageDeletedActors');
		$this->jobList->expects($this->never())->method('setLastRun');

		$this->job->start($this->jobList);
	}
}
