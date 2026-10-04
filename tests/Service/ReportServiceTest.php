<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\ReportsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ReportNotFoundException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Flag;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Report;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ModeratorService;
use OCA\Social\Service\ReportForwardService;
use OCA\Social\Service\ReportService;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class ReportServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example/apps/social/@alice';
	private const BOB = 'https://cloud.example/apps/social/@bob';
	private const REMOTE_ACTOR = 'https://mastodon.social/actor';

	private ReportsRequest|MockObject $reportsRequest;
	private CacheActorService|MockObject $cacheActorService;
	private ModeratorService|Stub $moderatorService;
	private INotificationManager|MockObject $notificationManager;
	private ReportForwardService|MockObject $reportForwardService;
	private StreamRequest|MockObject $streamRequest;
	private ReportService $service;

	protected function setUp(): void {
		$this->reportsRequest = $this->createMock(ReportsRequest::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->moderatorService = $this->createStub(ModeratorService::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->reportForwardService = $this->createMock(ReportForwardService::class);
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());

		$this->service = new ReportService(
			$this->reportsRequest,
			$this->cacheActorService,
			$this->moderatorService,
			$this->notificationManager,
			$this->reportForwardService,
			$this->streamRequest,
			new NullLogger()
		);
	}

	private function person(string $id, bool $local = true): Person {
		$person = new Person();
		$person->setId($id);
		$person->setLocal($local);

		return $person;
	}

	/** one moderator; captures the subjects of the notifications sent */
	private function withAdmin(): \Closure {
		$this->moderatorService->method('moderators')->willReturn(['admin']);

		$subjects = [];
		$this->notificationManager->method('createNotification')
			->willReturnCallback(function () use (&$subjects): INotification {
				$notification = $this->createMock(INotification::class);
				foreach (['setApp', 'setDateTime', 'setUser', 'setObject'] as $method) {
					$notification->method($method)->willReturnSelf();
				}
				$notification->method('setSubject')
					->willReturnCallback(function (string $subject, array $params) use (&$subjects, $notification): INotification {
						$subjects[] = [$subject, $params];

						return $notification;
					});

				return $notification;
			});

		return function () use (&$subjects): array {
			return $subjects;
		};
	}

	public function testReportFromLocalStoresTheReportAndNotifiesOnlyAdmins(): void {
		$subjects = $this->withAdmin();

		$saved = null;
		$this->reportsRequest->expects($this->once())->method('save')
			->willReturnCallback(function (Report $report) use (&$saved): int {
				$saved = $report;

				return 7;
			});
		$this->notificationManager->expects($this->once())->method('notify');

		$report = $this->service->reportFromLocal(
			$this->person(self::ALICE), $this->person(self::BOB), ['12', ''], 'spam bot', 'spam'
		);

		$this->assertSame($saved, $report);
		$this->assertSame(self::ALICE, $report->getActorId());
		$this->assertSame(self::BOB, $report->getAccountId());
		$this->assertSame(['12'], $report->getStatusIds(), 'empty status ids are dropped');
		$this->assertSame('spam bot', $report->getComment());
		$this->assertSame('spam', $report->getCategory());
		$this->assertTrue($report->isLocal());
		$this->assertFalse($report->isResolved());

		$this->assertSame([['report_new', ['reporter' => self::ALICE, 'account' => self::BOB, 'local' => true]]], $subjects());
	}

	public function testAnUnknownCategoryFallsBackToOther(): void {
		$this->withAdmin();
		$this->reportsRequest->method('save')->willReturn(1);

		$report = $this->service->reportFromLocal(
			$this->person(self::ALICE), $this->person(self::BOB), [], '', 'weird-category'
		);

		$this->assertSame(Report::CATEGORY_OTHER, $report->getCategory());
	}

	public function testForwardIsNotAttemptedUnlessTheReporterAskedForIt(): void {
		$this->withAdmin();
		$this->reportsRequest->method('save')->willReturn(11);
		$this->reportForwardService->expects($this->never())->method('forward');

		$report = $this->service->reportFromLocal(
			$this->person(self::ALICE), $this->person(self::REMOTE_ACTOR, false), [], 'spam', 'spam'
		);

		$this->assertFalse($report->isForwarded());
	}

	public function testAForwardedReportIsRecordedAsForwarded(): void {
		$this->withAdmin();
		$this->reportsRequest->method('save')->willReturnCallback(
			static function (Report $report): int {
				$report->setId(12);

				return 12;
			}
		);
		$this->reportForwardService->expects($this->once())->method('forward')->willReturn(true);
		// stored, not only held in memory: the moderation API reads it back
		$this->reportsRequest->expects($this->once())->method('setForwarded')->with(12, true);

		$report = $this->service->reportFromLocal(
			$this->person(self::ALICE), $this->person(self::REMOTE_ACTOR, false), [], 'spam', 'spam', true
		);

		$this->assertTrue($report->isForwarded());
	}

	public function testAForwardThatCouldNotBeDeliveredIsNotRecordedAsForwarded(): void {
		$this->withAdmin();
		$this->reportsRequest->method('save')->willReturn(13);
		$this->reportForwardService->method('forward')->willReturn(false);
		$this->reportsRequest->expects($this->never())->method('setForwarded');

		$report = $this->service->reportFromLocal(
			$this->person(self::ALICE), $this->person(self::REMOTE_ACTOR, false), [], 'spam', 'spam', true
		);

		$this->assertFalse($report->isForwarded());
	}

	public function testAFailingForwardStillFilesTheReport(): void {
		$this->withAdmin();
		$this->reportsRequest->expects($this->once())->method('save')->willReturnCallback(
			static function (Report $report): int {
				$report->setId(14);

				return 14;
			}
		);
		$this->reportForwardService->method('forward')
			->willThrowException(new \RuntimeException('the other instance is on fire'));

		// the report is for our own moderators first; what the other instance
		// does with it must never decide whether it was filed
		$report = $this->service->reportFromLocal(
			$this->person(self::ALICE), $this->person(self::REMOTE_ACTOR, false), [], 'spam', 'spam', true
		);

		$this->assertSame(14, $report->getId());
		$this->assertFalse($report->isForwarded());
	}

	public function testReportFromFlagSplitsTheLocalAccountFromTheStatuses(): void {
		$this->withAdmin();
		$this->cacheActorService->method('getCachedFromIds')
			->willReturn([self::ALICE => $this->person(self::ALICE)]);
		$this->reportsRequest->expects($this->once())->method('save')->willReturn(3);

		$flag = new Flag();
		$flag->import([
			'type' => 'Flag',
			'actor' => self::REMOTE_ACTOR,
			'content' => 'reported from remote',
			'object' => [self::ALICE . '/status/1', self::ALICE, self::ALICE . '/status/2'],
		]);

		$report = $this->service->reportFromFlag($flag);

		$this->assertSame(self::REMOTE_ACTOR, $report->getActorId());
		$this->assertSame(self::ALICE, $report->getAccountId(), 'the resolvable local account is the target');
		$this->assertSame(
			[self::ALICE . '/status/1', self::ALICE . '/status/2'],
			$report->getStatusIds(),
			'everything else is treated as a reported status'
		);
		$this->assertSame('reported from remote', $report->getComment());
		$this->assertFalse($report->isLocal());
	}

	/**
	 * A Flag naming nothing of this instance has nothing here for a moderator
	 * to act on. It used to be filed against the first id it named, so any
	 * sender could put a row and a notification in front of every moderator
	 * for any URL at all.
	 */
	public function testReportFromFlagNamingNothingOfThisInstanceIsDropped(): void {
		$this->moderatorService->method('moderators')->willReturn(['admin']);
		$this->cacheActorService->method('getCachedFromIds')->willReturn([]);
		$this->reportsRequest->expects($this->never())->method('save');
		$this->notificationManager->expects($this->never())->method('notify');

		$flag = new Flag();
		$flag->import([
			'type' => 'Flag',
			'actor' => self::REMOTE_ACTOR,
			'object' => ['https://gone.example/@x', 'https://gone.example/@x/1'],
		]);

		$this->assertNull($this->service->reportFromFlag($flag));
	}

	/** Mastodon may list only the status; the report is then about its author. */
	public function testReportFromFlagAboutALocalPostIsFiledAgainstItsAuthor(): void {
		$this->withAdmin();
		$this->cacheActorService->method('getCachedFromIds')->willReturn([]);
		$post = new Note();
		$post->setId(self::ALICE . '/status/1');
		$post->setAttributedTo(self::ALICE);
		$post->setLocal(true);
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->streamRequest->method('getStreamById')
			->willReturnCallback(function (string $id) use ($post): Note {
				if ($id === $post->getId()) {
					return $post;
				}

				throw new StreamNotFoundException();
			});
		$this->service = new ReportService(
			$this->reportsRequest, $this->cacheActorService, $this->moderatorService,
			$this->notificationManager, $this->reportForwardService, $this->streamRequest, new NullLogger()
		);
		$this->reportsRequest->expects($this->once())->method('save')->willReturn(7);

		$flag = new Flag();
		$flag->import([
			'type' => 'Flag',
			'actor' => self::REMOTE_ACTOR,
			'object' => ['https://gone.example/@x/9', self::ALICE . '/status/1'],
		]);

		$report = $this->service->reportFromFlag($flag);

		$this->assertSame(self::ALICE, $report->getAccountId());
		$this->assertSame(['https://gone.example/@x/9', self::ALICE . '/status/1'], $report->getStatusIds());
	}

	/** A cached copy of somebody else's post is not a post of this instance. */
	public function testReportFromFlagAboutARemotePostHeldHereIsStillDropped(): void {
		$this->cacheActorService->method('getCachedFromIds')->willReturn([]);
		$post = new Note();
		$post->setId('https://remote.example/notes/5');
		$post->setAttributedTo(self::REMOTE_ACTOR);
		$post->setLocal(false);
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->streamRequest->method('getStreamById')->willReturn($post);
		$this->service = new ReportService(
			$this->reportsRequest, $this->cacheActorService, $this->moderatorService,
			$this->notificationManager, $this->reportForwardService, $this->streamRequest, new NullLogger()
		);
		$this->reportsRequest->expects($this->never())->method('save');

		$flag = new Flag();
		$flag->import(['type' => 'Flag', 'actor' => self::REMOTE_ACTOR, 'object' => [$post->getId()]]);

		$this->assertNull($this->service->reportFromFlag($flag));
	}

	/**
	 * Every id is the sender's to choose: fetching the ones not cached made a
	 * single Flag a request to each of them, from inside the inbox.
	 */
	public function testReportFromFlagFetchesNothingItNames(): void {
		$this->withAdmin();
		$ids = [];
		for ($i = 0; $i < 20; $i++) {
			$ids[] = 'https://target' . $i . '.example/users/x';
		}
		$this->cacheActorService->expects($this->once())
			->method('getCachedFromIds')->with($ids)->willReturn([]);
		$this->cacheActorService->expects($this->never())->method('getFromId');
		$this->cacheActorService->expects($this->never())->method('getFromAccount');
		$this->reportsRequest->expects($this->never())->method('save');

		$flag = new Flag();
		$flag->import(['type' => 'Flag', 'actor' => self::REMOTE_ACTOR, 'object' => $ids]);

		// the posts are looked up in the local table only, and none is here
		$this->assertNull($this->service->reportFromFlag($flag));
	}

	public function testReportFromFlagDoesNotTakeACachedRemoteAccountAsTheTarget(): void {
		$this->withAdmin();
		$this->cacheActorService->method('getCachedFromIds')->willReturn([
			self::REMOTE_ACTOR => $this->person(self::REMOTE_ACTOR, false),
			self::ALICE => $this->person(self::ALICE),
		]);
		$this->reportsRequest->method('save')->willReturn(6);

		$flag = new Flag();
		$flag->import(['type' => 'Flag', 'actor' => self::REMOTE_ACTOR, 'object' => [self::REMOTE_ACTOR, self::ALICE]]);

		$report = $this->service->reportFromFlag($flag);

		$this->assertSame(self::ALICE, $report->getAccountId());
		$this->assertSame([self::REMOTE_ACTOR], $report->getStatusIds());
	}

	public function testGetReportsResolvesTargetAccountsAndSurvivesFailures(): void {
		$known = new Report();
		$known->setAccountId(self::BOB);
		$gone = new Report();
		$gone->setAccountId('https://gone.example/@x');
		$this->reportsRequest->method('getAll')->willReturn([$known, $gone]);

		// one query for the accounts of the whole page, and no federated
		// request on a miss: a page of reports must not be able to hang on
		// someone else's instance
		$this->cacheActorService->expects($this->once())
			->method('getCachedFromIds')
			->with([self::BOB, 'https://gone.example/@x'])
			->willReturn([self::BOB => $this->person(self::BOB)]);
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$reports = $this->service->getReports();

		$this->assertCount(2, $reports);
		$this->assertNotNull($reports[0]->getTargetAccount());
		$this->assertNull($reports[1]->getTargetAccount());
	}

	/**
	 * The panel used to read the first two hundred reports, open and resolved
	 * together: an instance with a busy month of resolved complaints pushed
	 * the open ones off the bottom, and past two hundred off the page.
	 */
	public function testAPageOfOpenReportsIsAskedForByOffsetAndSaysHowManyThereAre(): void {
		$report = new Report();
		$report->setAccountId(self::BOB);
		$this->reportsRequest->expects($this->once())->method('getPage')
			->with(false, ReportsRequest::PAGE, 2 * ReportsRequest::PAGE)
			->willReturn([$report]);
		$this->reportsRequest->expects($this->once())->method('count')->with(false)->willReturn(137);
		$this->cacheActorService->method('getCachedFromIds')->willReturn([]);

		$page = $this->service->page(false, 3);

		$this->assertCount(1, $page['reports']);
		$this->assertSame(137, $page['total']);
		$this->assertSame(3, $page['page']);
		$this->assertSame(ReportsRequest::PAGE, $page['perPage']);
	}

	public function testAPageBelowTheFirstIsTheFirst(): void {
		$this->reportsRequest->expects($this->once())->method('getPage')
			->with(true, ReportsRequest::PAGE, 0)->willReturn([]);
		$this->cacheActorService->method('getCachedFromIds')->willReturn([]);

		$this->assertSame(1, $this->service->page(true, 0)['page']);
	}

	public function testThePageResolvesItsAccountsTheSameWayTheWholeListDid(): void {
		$report = new Report();
		$report->setAccountId(self::BOB);
		$this->reportsRequest->method('getPage')->willReturn([$report]);
		$this->cacheActorService->expects($this->once())->method('getCachedFromIds')
			->with([self::BOB])->willReturn([self::BOB => $this->person(self::BOB)]);

		$this->assertNotNull($this->service->page(false)['reports'][0]->getTargetAccount());
	}

	public function testHowManyHaveBeenResolvedIsAskedForSeparately(): void {
		$this->reportsRequest->expects($this->once())->method('count')->with(true)->willReturn(12);

		$this->assertSame(12, $this->service->countResolved());
	}

	public function testSetResolvedOnAnUnknownReportThrows(): void {
		$this->reportsRequest->method('getById')->willThrowException(new ReportNotFoundException());
		$this->reportsRequest->expects($this->never())->method('setResolved');

		$this->expectException(ReportNotFoundException::class);

		$this->service->setResolved(9, true);
	}

	/**
	 * The moderation routes accept whoever the Social settings section has
	 * been delegated to, and the notification used to go to the `admin` group
	 * alone: a delegated moderator was given the job and never told there was
	 * anything to do.
	 */
	public function testEverybodyWhoMayActOnAReportIsToldAboutIt(): void {
		$this->moderatorService->method('moderators')->willReturn(['admin', 'moderator']);

		$told = [];
		$this->notificationManager->method('createNotification')
			->willReturnCallback(function () use (&$told): INotification {
				$notification = $this->createMock(INotification::class);
				foreach (['setApp', 'setDateTime', 'setObject', 'setSubject'] as $method) {
					$notification->method($method)->willReturnSelf();
				}
				$notification->method('setUser')
					->willReturnCallback(function (string $uid) use (&$told, $notification): INotification {
						$told[] = $uid;

						return $notification;
					});

				return $notification;
			});

		$this->service->reportFromLocal(
			$this->person(self::ALICE), $this->person(self::BOB), [], 'spam bot', 'spam'
		);

		$this->assertSame(['admin', 'moderator'], $told);
	}

	public function testABrokenNotificationDoesNotLoseTheReport(): void {
		$this->moderatorService->method('moderators')
			->willThrowException(new \RuntimeException('directory down'));
		$this->reportsRequest->expects($this->once())->method('save')->willReturn(5);

		$report = $this->service->reportFromLocal(
			$this->person(self::ALICE), $this->person(self::BOB), [], '', 'other'
		);

		$this->assertSame(self::BOB, $report->getAccountId());
	}
}
