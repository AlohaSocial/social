<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\ModerationRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Mention;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\NotificationPolicy;
use OCA\Social\Service\AccountRelationService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\NotificationPolicyService;
use OCA\Social\Service\TimelineRevisionService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * The notification policy: which notifications are held back, and how the
 * reader is asked about them.
 *
 * The failure that matters is holding something back that should have been
 * shown — a mention nobody ever sees is worse than a noisy inbox — so the
 * default and the "already decided" paths are pinned here as hard as the
 * filtering itself.
 */
#[AllowMockObjectsWithoutExpectations]
class NotificationPolicyServiceTest extends TestCase {
	private const VIEWER = 'https://cloud.example/users/alice';
	private const STRANGER = 'https://elsewhere.example/users/carol';

	private ConfigService|Stub $configService;
	private FollowsRequest|MockObject $followsRequest;
	private ModerationRequest|Stub $moderationRequest;
	private TimelineRevisionService|MockObject $timelineRevisionService;
	private AccountRelationService|Stub $accountRelationService;
	private NotificationPolicyService $service;

	/** @var array<string, string> the user values the store holds */
	private array $stored = [];
	/** @var array<string, string> the app values the store holds */
	private array $appValues = [];
	/** @var string[] the accounts this instance silenced */
	private array $silenced = [];
	/** @var array{accepted: array<string, bool>, dismissed: array<string, bool>} */
	private array $decisions = ['accepted' => [], 'dismissed' => []];

	protected function setUp(): void {
		$this->configService = $this->createStub(ConfigService::class);
		$this->configService->method('getUserValue')
			->willReturnCallback(fn (string $key, string $userId = '', string $app = ''): string
				=> $this->stored[$key] ?? '');
		$this->configService->method('setValueForUser')
			->willReturnCallback(function (string $userId, string $key, string $value): void {
				$this->stored[$key] = $value;
			});
		$this->configService->method('getAppValue')
			->willReturnCallback(fn (string $key): string => $this->appValues[$key] ?? '');
		$this->configService->method('setAppValue')
			->willReturnCallback(function (string $key, string $value): void {
				$this->appValues[$key] = $value;
			});

		$this->followsRequest = $this->createMock(FollowsRequest::class);
		$this->followsRequest->method('getBetweenMany')
			->willReturn(['following' => [], 'followedBy' => []]);

		$this->moderationRequest = $this->createStub(ModerationRequest::class);
		$this->moderationRequest->method('getActorIdsAt')->willReturnCallback(fn (): array => $this->silenced);

		$this->timelineRevisionService = $this->createMock(TimelineRevisionService::class);

		$this->accountRelationService = $this->createStub(AccountRelationService::class);
		$this->accountRelationService->method('notificationDecisions')
			->willReturnCallback(fn (): array => $this->decisions);

		$this->service = new NotificationPolicyService(
			$this->configService,
			$this->followsRequest,
			$this->moderationRequest,
			$this->accountRelationService,
			$this->timelineRevisionService
		);
	}

	private function viewer(): Person {
		$viewer = new Person();
		$viewer->setId(self::VIEWER);
		$viewer->setUserId('alice');

		return $viewer;
	}

	private function from(string $actorId, string $subType = Mention::TYPE, int $creation = 0): Stream {
		$sender = new Person();
		$sender->setId($actorId);
		$sender->setNid(7);
		$sender->setCreation($creation);

		$notification = new Stream();
		$notification->setNid(1);
		$notification->setSubType($subType);
		$notification->setPublishedTime(1_700_000_000);
		$notification->setActor($sender);

		return $notification;
	}

	public function testNothingIsHeldByDefault(): void {
		$page = [$this->from(self::STRANGER)];

		$this->assertSame($page, $this->service->partition($this->viewer(), $page)['shown']);
	}

	/** An untouched policy costs no queries at all. */
	public function testAnUntouchedPolicyAsksNothing(): void {
		$this->followsRequest->expects($this->never())->method('getBetweenMany');

		$this->service->partition($this->viewer(), [$this->from(self::STRANGER)]);
	}

	public function testSomebodyTheReaderDoesNotFollowCanBeHeld(): void {
		$this->service->save('alice', [NotificationPolicy::NOT_FOLLOWING => NotificationPolicy::FILTER]);

		$partitioned = $this->service->partition($this->viewer(), [$this->from(self::STRANGER)]);

		$this->assertSame([], $partitioned['shown']);
		$this->assertCount(1, $partitioned['held']);
	}

	public function testSomebodyTheReaderFollowsIsNotHeld(): void {
		$this->service->save('alice', [NotificationPolicy::NOT_FOLLOWING => NotificationPolicy::FILTER]);
		$follow = new Follow();
		$this->followsRequest = $this->createMock(FollowsRequest::class);
		$this->followsRequest->method('getBetweenMany')
			->willReturn(['following' => [self::STRANGER => $follow], 'followedBy' => []]);
		$this->service = new NotificationPolicyService(
			$this->configService,
			$this->followsRequest,
			$this->moderationRequest,
			$this->accountRelationService,
			$this->timelineRevisionService
		);

		$this->assertCount(
			1, $this->service->partition($this->viewer(), [$this->from(self::STRANGER)])['shown']
		);
	}

	/** An account with no creation date is not thereby a new account. */
	public function testAnAccountWithNoCreationDateIsNotNew(): void {
		$this->service->save('alice', [NotificationPolicy::NEW_ACCOUNTS => NotificationPolicy::FILTER]);

		$this->assertCount(
			1,
			$this->service->partition($this->viewer(), [$this->from(self::STRANGER, Like::TYPE, 0)])['shown']
		);
	}

	public function testAFreshAccountIsHeld(): void {
		$this->service->save('alice', [NotificationPolicy::NEW_ACCOUNTS => NotificationPolicy::FILTER]);

		$this->assertCount(
			1,
			$this->service->partition(
				$this->viewer(), [$this->from(self::STRANGER, Like::TYPE, time() - 86400)]
			)['held']
		);
	}

	public function testAnOldAccountIsNotHeld(): void {
		$this->service->save('alice', [NotificationPolicy::NEW_ACCOUNTS => NotificationPolicy::FILTER]);

		$this->assertCount(
			1,
			$this->service->partition(
				$this->viewer(), [$this->from(self::STRANGER, Like::TYPE, time() - 400 * 86400)]
			)['shown']
		);
	}

	/**
	 * An account the reader has said yes to is never held again, whatever the
	 * policy says: the decision is the reader's and outranks it.
	 */
	public function testAnAcceptedSenderIsNeverHeld(): void {
		$this->service->save('alice', [NotificationPolicy::NOT_FOLLOWING => NotificationPolicy::FILTER]);
		$this->decisions = ['accepted' => [self::STRANGER => true], 'dismissed' => []];

		$this->assertCount(
			1, $this->service->partition($this->viewer(), [$this->from(self::STRANGER)])['shown']
		);
	}

	public function testADismissedSenderStaysHeld(): void {
		$this->service->save('alice', [NotificationPolicy::NOT_FOLLOWING => NotificationPolicy::FILTER]);
		$this->decisions = ['accepted' => [], 'dismissed' => [self::STRANGER => true]];

		$this->assertCount(
			1, $this->service->partition($this->viewer(), [$this->from(self::STRANGER)])['held']
		);
	}

	/** Changing one of the five leaves the other four as they were. */
	public function testSavingOneDecisionLeavesTheRest(): void {
		$this->service->save('alice', [NotificationPolicy::NOT_FOLLOWING => NotificationPolicy::FILTER]);
		$this->service->save('alice', [NotificationPolicy::NEW_ACCOUNTS => NotificationPolicy::DROP]);

		$policy = $this->service->of('alice');

		$this->assertSame(NotificationPolicy::FILTER, $policy->get(NotificationPolicy::NOT_FOLLOWING));
		$this->assertSame(NotificationPolicy::DROP, $policy->get(NotificationPolicy::NEW_ACCOUNTS));
		$this->assertSame(NotificationPolicy::ACCEPT, $policy->get(NotificationPolicy::NOT_FOLLOWERS));
	}

	/** A decision this does not recognise leaves the key alone. */
	public function testAnUnknownDecisionIsIgnored(): void {
		$this->service->save('alice', [NotificationPolicy::NOT_FOLLOWING => 'incinerate']);

		$this->assertSame(
			NotificationPolicy::ACCEPT,
			$this->service->of('alice')->get(NotificationPolicy::NOT_FOLLOWING)
		);
	}

	public function testHeldNotificationsBecomeOneRowPerSender(): void {
		$held = [
			$this->from(self::STRANGER),
			$this->from(self::STRANGER),
			$this->from('https://elsewhere.example/users/dave'),
		];

		$requests = $this->service->requestsFrom($held);

		$this->assertCount(2, $requests);
		$this->assertSame(2, $requests[0]->getCount() + $requests[1]->getCount() - 1);
	}

	public function testADismissedSenderIsNotOfferedAgain(): void {
		$requests = $this->service->requestsFrom(
			[$this->from(self::STRANGER)], [self::STRANGER => true]
		);

		$this->assertSame([], $requests);
	}

	/** A direct message from a stranger, which is the case the policy exists for. */
	public function testAPrivateMentionFromAStrangerCanBeHeld(): void {
		$this->service->save('alice', [NotificationPolicy::PRIVATE_MENTIONS => NotificationPolicy::FILTER]);

		$notification = $this->from(self::STRANGER, Mention::TYPE);
		$post = new Note();
		$post->setVisibility(Stream::TYPE_DIRECT);
		$notification->setObject($post);

		$this->assertCount(
			1, $this->service->partition($this->viewer(), [$notification])['held']
		);
	}

	public function testAPublicMentionIsNotAPrivateOne(): void {
		$this->service->save('alice', [NotificationPolicy::PRIVATE_MENTIONS => NotificationPolicy::FILTER]);

		$notification = $this->from(self::STRANGER, Mention::TYPE);
		$post = new Note();
		$post->setVisibility(Stream::TYPE_PUBLIC);
		$notification->setObject($post);

		$this->assertCount(
			1, $this->service->partition($this->viewer(), [$notification])['shown']
		);
	}

	// what is decided when a notification is stored

	/** @return array<string, array{string, int, bool, string[], string[]}> key, creation, direct, following, followers */
	public static function eachKey(): array {
		return [
			'somebody the reader does not follow' => [NotificationPolicy::NOT_FOLLOWING, 0, false, [], [self::STRANGER]],
			'somebody who does not follow the reader' => [NotificationPolicy::NOT_FOLLOWERS, 0, false, [self::STRANGER], []],
			'a new account' => [NotificationPolicy::NEW_ACCOUNTS, 0, false, [self::STRANGER], [self::STRANGER]],
			'a private mention from a stranger' => [NotificationPolicy::PRIVATE_MENTIONS, 0, true, [], [self::STRANGER]],
			'an account this instance limited' => [NotificationPolicy::LIMITED_ACCOUNTS, 0, false, [self::STRANGER], [self::STRANGER]],
		];
	}

	/**
	 * @param string[] $following
	 * @param string[] $followers
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('eachKey')]
	public function testEachKeyHoldsOneNotificationAsItIsStored(string $key, int $creation, bool $direct, array $following, array $followers): void {
		$this->withFollows($following, $followers);
		if ($key === NotificationPolicy::NEW_ACCOUNTS) {
			$creation = time() - 86400;
		}
		if ($key === NotificationPolicy::LIMITED_ACCOUNTS) {
			$this->silenced = [self::STRANGER];
		}

		$this->assertFalse(
			$this->service->isHeldFor($this->viewer(), self::STRANGER, $creation, $direct),
			'nothing is held while the key accepts'
		);

		$this->service->save('alice', [$key => NotificationPolicy::FILTER]);

		$this->assertTrue($this->service->isHeldFor($this->viewer(), self::STRANGER, $creation, $direct));
	}

	/** `drop` holds too: what this app drops is held for good, never thrown away unseen. */
	public function testDropHoldsAsItIsStored(): void {
		$this->service->save('alice', [NotificationPolicy::NOT_FOLLOWING => NotificationPolicy::DROP]);

		$this->assertTrue($this->service->isHeldFor($this->viewer(), self::STRANGER, 0, false));
	}

	public function testAnAcceptedSenderIsNeverHeldAsItIsStored(): void {
		$this->service->save('alice', NotificationPolicy::CALM);
		$this->decisions['accepted'][self::STRANGER] = true;

		$this->assertFalse($this->service->isHeldFor($this->viewer(), self::STRANGER, time() - 60, true));
	}

	/** Storing a notification for an account that never chose a policy asks nothing more. */
	public function testAnUntouchedPolicyAsksNothingAsItIsStored(): void {
		$this->followsRequest->expects($this->never())->method('getBetweenMany');

		$this->assertFalse($this->service->isHeldFor($this->viewer(), self::STRANGER, time() - 60, true));
	}

	public function testANotificationWithNoSenderIsNotHeld(): void {
		$this->service->save('alice', NotificationPolicy::CALM);

		$this->assertFalse($this->service->isHeldFor($this->viewer(), '', 0, false));
	}

	/** The page and the single notification are judged alike. */
	public function testThePageAndTheStoredNotificationAgree(): void {
		$this->service->save('alice', NotificationPolicy::CALM);
		$notification = $this->from(self::STRANGER, Like::TYPE, time() - 86400);

		$this->assertSame(
			$this->service->partition($this->viewer(), [$notification])['held'] !== [],
			$this->service->isHeldFor($this->viewer(), self::STRANGER, time() - 86400, false)
		);
	}

	// what a new account starts with

	public function testANewAccountStartsCalm(): void {
		$this->service->startCalm('alice');

		$this->assertSame(NotificationPolicy::CALM, $this->service->of('alice')->getDecisions());
	}

	/** Whoever already chose keeps their choice. */
	public function testStartingCalmLeavesAStoredPolicyAlone(): void {
		$this->service->save('alice', [NotificationPolicy::NOT_FOLLOWING => NotificationPolicy::ACCEPT]);

		$this->service->startCalm('alice');

		$this->assertTrue($this->service->of('alice')->isEverythingAccepted());
	}

	/** An account without a stored policy, which is every older account, holds nothing. */
	public function testAnAccountWithoutAStoredPolicyAcceptsEverything(): void {
		$this->assertTrue($this->service->of('alice')->isEverythingAccepted());
	}

	public function testANewAccountStartsWithWhatTheAdministratorChose(): void {
		$this->service->saveDefaults([NotificationPolicy::NOT_FOLLOWING => NotificationPolicy::ACCEPT]);

		$this->service->startCalm('alice');

		$policy = $this->service->of('alice');
		$this->assertSame(NotificationPolicy::ACCEPT, $policy->get(NotificationPolicy::NOT_FOLLOWING));
		$this->assertSame(NotificationPolicy::FILTER, $policy->get(NotificationPolicy::NEW_ACCOUNTS));
	}

	public function testTheDefaultsAreCalmUntilAnAdministratorChooses(): void {
		$this->assertSame(NotificationPolicy::CALM, $this->service->defaults()->getDecisions());
	}

	/** An administrator never makes somebody else's notifications disappear. */
	public function testTheAdministratorCannotChooseDrop(): void {
		try {
			$this->service->saveDefaults([
				NotificationPolicy::NOT_FOLLOWERS => NotificationPolicy::FILTER,
				NotificationPolicy::NOT_FOLLOWING => NotificationPolicy::DROP,
			]);
			$this->fail('drop was accepted as a default');
		} catch (InvalidResourceException $e) {
			$this->assertStringContainsString(NotificationPolicy::NOT_FOLLOWING, $e->getMessage());
		}

		$this->assertSame([], $this->appValues, 'a refused change writes nothing');
	}

	/** A stored default that is not one of the two answers is read as the calm one. */
	public function testAStoredDefaultOutsideTheTwoAnswersIsIgnored(): void {
		$this->appValues[NotificationPolicyService::DEFAULTS_KEY]
			= (string)json_encode([NotificationPolicy::NOT_FOLLOWING => NotificationPolicy::DROP]);

		$this->assertSame(NotificationPolicy::CALM, $this->service->defaults()->getDecisions());
	}

	/** Changing the defaults leaves every existing account's policy as it is. */
	public function testChangingTheDefaultsTouchesNoExistingAccount(): void {
		$this->service->startCalm('alice');

		$this->service->saveDefaults(array_fill_keys(NotificationPolicy::KEYS, NotificationPolicy::ACCEPT));

		$this->assertSame(NotificationPolicy::CALM, $this->service->of('alice')->getDecisions());
	}

	// the one-time notice

	public function testAnAccountWithoutAStoredPolicyIsPointedAtIt(): void {
		$this->assertTrue($this->service->of('alice')->hasNotice());
		$this->assertTrue($this->service->of('alice')->jsonSerialize()['notice']);
	}

	public function testANewAccountIsNeverPointedAtIt(): void {
		$this->service->startCalm('alice');

		$this->assertFalse($this->service->of('alice')->hasNotice());
	}

	public function testDismissingTheNoticePutsItAwayForGood(): void {
		$this->service->dismissNotice('alice');

		$this->assertFalse($this->service->of('alice')->hasNotice());
		$this->assertTrue($this->service->of('alice')->isEverythingAccepted(), 'dismissing changes no decision');
	}

	public function testSavingThePolicyPutsTheNoticeAway(): void {
		$this->service->save('alice', [NotificationPolicy::NOT_FOLLOWING => NotificationPolicy::ACCEPT]);

		$this->assertFalse($this->service->of('alice')->hasNotice());
	}

	// always allowed

	public function testTheAlwaysAllowedAreTheAcceptedSenders(): void {
		$carol = new Person();
		$carol->setId(self::STRANGER);
		$this->accountRelationService = $this->createMock(AccountRelationService::class);
		$this->accountRelationService->expects($this->once())->method('acceptedSenders')
			->with($this->anything(), 80)
			->willReturn([$carol]);
		$this->rebuild();

		$this->assertSame([$carol], $this->service->allowed($this->viewer(), 500));
	}

	public function testStoppingToAllowReturnsTheSenderToThePolicy(): void {
		$carol = new Person();
		$carol->setId(self::STRANGER);
		$this->accountRelationService = $this->createMock(AccountRelationService::class);
		$this->accountRelationService->expects($this->once())->method('forgetAcceptedSender')
			->with($this->anything(), $this->identicalTo($carol));
		$this->accountRelationService->expects($this->never())->method('dismissNotifications');
		$this->rebuild();
		$this->timelineRevisionService->expects($this->once())->method('bump')->with('alice');

		$this->service->stopAllowing($this->viewer(), $carol);
	}

	/** A decision about a sender changes the unread count, which is tagged by the revision. */
	public function testDecidingAboutASenderMovesTheRevision(): void {
		$this->timelineRevisionService->expects($this->exactly(2))->method('bump')->with('alice');
		$carol = new Person();

		$this->service->accept($this->viewer(), $carol);
		$this->service->dismiss($this->viewer(), $carol);
	}

	/**
	 * @param string[] $following
	 * @param string[] $followers
	 */
	private function withFollows(array $following, array $followers): void {
		$this->followsRequest = $this->createMock(FollowsRequest::class);
		$this->followsRequest->method('getBetweenMany')->willReturn([
			'following' => array_fill_keys($following, new Follow()),
			'followedBy' => array_fill_keys($followers, new Follow()),
		]);
		$this->rebuild();
	}

	private function rebuild(): void {
		$this->accountRelationService->method('notificationDecisions')
			->willReturnCallback(fn (): array => $this->decisions);
		$this->service = new NotificationPolicyService(
			$this->configService,
			$this->followsRequest,
			$this->moderationRequest,
			$this->accountRelationService,
			$this->timelineRevisionService
		);
	}
}
