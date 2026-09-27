<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\StrikesRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Moderation;
use OCA\Social\Model\Strike;
use OCA\Social\Service\NotificationService;
use OCA\Social\Service\StrikeService;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The history a decision leaves behind, and the warning that decides nothing.
 *
 * `social_moderation` holds one row an account, replaced by the next decision
 * and deleted by a lift — so the third silence in a month looked exactly like
 * the first, and whoever lifted the last one took the only evidence that it
 * had ever happened. What is asserted here is that a strike is written for
 * every decision, that the account is told when it is one of ours, and that
 * neither of those can take a moderator's decision down with it.
 */
#[AllowMockObjectsWithoutExpectations]
class StrikeServiceTest extends TestCase {
	private const LOCAL = 'https://cloud.example/users/alice';
	private const REMOTE = 'https://remote.example/users/bob';

	private StrikesRequest|MockObject $strikesRequest;
	private ActorsRequest|Stub $actorsRequest;
	private INotificationManager|MockObject $notificationManager;
	private IUserSession|Stub $userSession;
	private StrikeService $service;

	/** @var Strike[] what was written */
	private array $saved = [];
	/** @var array<string, mixed>|null what the account was told */
	private ?array $notified = null;
	/** Who is taking the decision. */
	private ?string $currentUser = 'mod';
	private NotificationService|Stub $notificationService;
	/** @var array<int, array<string, mixed>> the warnings a client would see */
	private array $clientWarnings = [];

	protected function setUp(): void {
		$this->strikesRequest = $this->createMock(StrikesRequest::class);
		$this->actorsRequest = $this->createStub(ActorsRequest::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->userSession = $this->createStub(IUserSession::class);

		$this->strikesRequest->method('save')->willReturnCallback(
			function (Strike $strike): void {
				$this->saved[] = $strike;
			}
		);

		$this->userSession->method('getUser')->willReturnCallback(
			function (): ?IUser {
				if ($this->currentUser === null) {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($this->currentUser);

				return $user;
			}
		);

		$this->notificationManager->method('createNotification')->willReturnCallback(
			function (): INotification {
				$notification = $this->createMock(INotification::class);
				$this->notified = ['user' => '', 'subject' => '', 'params' => []];
				$notification->method('setApp')->willReturnSelf();
				$notification->method('setDateTime')->willReturnSelf();
				$notification->method('setObject')->willReturnSelf();
				$notification->method('setUser')->willReturnCallback(
					function (string $userId) use ($notification): INotification {
						$this->notified['user'] = $userId;

						return $notification;
					}
				);
				$notification->method('setSubject')->willReturnCallback(
					function (string $subject, array $params) use ($notification): INotification {
						$this->notified['subject'] = $subject;
						$this->notified['params'] = $params;

						return $notification;
					}
				);

				return $notification;
			}
		);

		$this->notificationService = $this->createStub(NotificationService::class);
		$this->notificationService->method('onModerationWarning')->willReturnCallback(
			function (string $actorId, string $action, string $text): void {
				$this->clientWarnings[] = compact('actorId', 'action', 'text');
			}
		);

		$this->service = new StrikeService(
			$this->strikesRequest, $this->actorsRequest, $this->notificationManager,
			$this->userSession, $this->notificationService, new NullLogger()
		);
	}

	/** Nobody local answers to a remote actor id. */
	private function remoteOnly(): void {
		$this->actorsRequest->method('getFromId')
			->willThrowException(new ActorDoesNotExistException());
	}

	private function localActor(string $userId = 'alice'): void {
		$actor = new Person();
		$actor->setId(self::LOCAL)->setPreferredUsername('alice')->setUserId($userId);
		$this->actorsRequest->method('getFromId')->willReturnCallback(
			static function (string $actorId) use ($actor): Person {
				if ($actorId !== self::LOCAL) {
					throw new ActorDoesNotExistException();
				}

				return $actor;
			}
		);
	}

	public function testADecisionIsWrittenDownWithWhoTookItAndWhy(): void {
		$this->remoteOnly();

		$strike = $this->service->record(self::REMOTE, Moderation::SILENCE, 'spam', 4);

		$this->assertCount(1, $this->saved);
		$this->assertSame(self::REMOTE, $this->saved[0]->getActorId());
		$this->assertSame(Moderation::SILENCE, $this->saved[0]->getAction());
		$this->assertSame('spam', $this->saved[0]->getText());
		$this->assertSame('mod', $this->saved[0]->getModerator());
		$this->assertSame(4, $this->saved[0]->getReportId());
		$this->assertSame(self::REMOTE, $strike->getActorId());
	}

	/** A decision taken by a command or a job was taken by nobody. */
	public function testADecisionTakenWithNobodyLoggedInNamesNobody(): void {
		$this->remoteOnly();
		$this->currentUser = null;

		$this->service->record(self::REMOTE, Moderation::SUSPEND);

		$this->assertSame('', $this->saved[0]->getModerator());
	}

	/**
	 * The step the ladder was missing: without it the lightest thing a
	 * moderator could do was take the account out of the timelines.
	 */
	public function testAWarningIsAStrikeThatAppliedNothing(): void {
		$this->remoteOnly();

		$strike = $this->service->record(self::REMOTE, Strike::WARNING, 'stop that');

		$this->assertTrue($strike->isWarning());
		$this->assertSame(Strike::WARNING, $this->saved[0]->getAction());
	}

	public function testAnActionThatIsNotOneIsRefusedBeforeAnythingIsWritten(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('unknown strike action: banish');

		try {
			$this->service->record(self::REMOTE, 'banish');
		} finally {
			$this->assertSame([], $this->saved);
		}
	}

	/**
	 * A decision nobody was told about is one the account can only discover by
	 * noticing that its posts stopped appearing.
	 */
	public function testALocalAccountIsToldWhatWasDecided(): void {
		$this->localActor();

		$this->service->record(self::LOCAL, Moderation::SILENCE, 'spam');

		$this->assertSame('alice', $this->notified['user']);
		$this->assertSame('moderation_warning', $this->notified['subject']);
		$this->assertSame(
			['action' => Moderation::SILENCE, 'text' => 'spam'], $this->notified['params']
		);
	}

	/**
	 * Telling a remote account means telling its instance, and there is no
	 * activity that says "your user has been warned".
	 */
	public function testARemoteAccountIsNotTold(): void {
		$this->remoteOnly();
		$this->notificationManager->expects($this->never())->method('notify');

		$this->service->record(self::REMOTE, Moderation::SUSPEND);

		$this->assertNull($this->notified);
	}

	/** An actor row with no Nextcloud user behind it has nobody to tell. */
	public function testAnActorWithNoAccountBehindItIsNotTold(): void {
		$this->localActor('');
		$this->notificationManager->expects($this->never())->method('notify');

		$this->service->record(self::LOCAL, Moderation::SILENCE);

		$this->assertNull($this->notified);
	}

	/**
	 * The decision has already been recorded and applied, and a moderator
	 * waiting on a notification queue is a moderator who cannot moderate.
	 */
	public function testTheStrikeStandsEvenWhenTheAccountCannotBeTold(): void {
		$this->localActor();
		$this->notificationManager->method('notify')
			->willThrowException(new \Exception('no notification backend'));

		$this->service->record(self::LOCAL, Moderation::SILENCE);

		$this->assertCount(1, $this->saved);
	}

	public function testTheHistoryIsTheAccountsOwn(): void {
		$this->strikesRequest->expects($this->once())
			->method('getForActor')->with(self::REMOTE, 50)
			->willReturn([new Strike(self::REMOTE, Moderation::SILENCE)]);

		$this->assertCount(1, $this->service->history(self::REMOTE));
	}

	/** One query for a page rather than one an account. */
	public function testTheCountsForAPageAreAskedForTogether(): void {
		$this->strikesRequest->expects($this->once())
			->method('countForActors')->with([self::LOCAL, self::REMOTE])
			->willReturn([self::REMOTE => 2]);

		$this->assertSame(
			[self::REMOTE => 2], $this->service->countFor([self::LOCAL, self::REMOTE])
		);
	}

	/**
	 * A lift says the decision no longer stands, not that it was never taken.
	 * Nothing in the service removes a strike, and a test that only called
	 * `record()` would not notice a delete creeping into the write path.
	 */
	public function testNothingHereEverRemovesAStrike(): void {
		$this->remoteOnly();
		$this->strikesRequest->expects($this->never())->method('deleteForActor');

		$this->service->record(self::REMOTE, Moderation::SILENCE);
		$this->service->history(self::REMOTE);
		$this->service->countFor([self::REMOTE]);
	}

	/**
	 * The warning reached a local account through Nextcloud's bell, which a
	 * Mastodon client cannot see — so somebody moderated through a client was
	 * told nothing a client could show them.
	 */
	public function testAWarningIsAlsoRaisedWhereAClientCanSeeIt(): void {
		$this->remoteOnly();

		$this->service->record(self::REMOTE, Moderation::SILENCE, 'spam');

		$this->assertSame([[
			'actorId' => self::REMOTE,
			'action' => Moderation::SILENCE,
			'text' => 'spam',
		]], $this->clientWarnings);
	}
}
