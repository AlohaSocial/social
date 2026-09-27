<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\FollowsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Mention;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\NotificationPolicy;
use OCA\Social\Service\AccountRelationService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\ModerationService;
use OCA\Social\Service\NotificationPolicyService;
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
	private ModerationService|Stub $moderationService;
	private AccountRelationService|Stub $accountRelationService;
	private NotificationPolicyService $service;

	/** @var array<string, string> the user values the store holds */
	private array $stored = [];
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

		$this->followsRequest = $this->createMock(FollowsRequest::class);
		$this->followsRequest->method('getBetweenMany')
			->willReturn(['following' => [], 'followedBy' => []]);

		$this->moderationService = $this->createStub(ModerationService::class);
		$this->moderationService->method('silenced')->willReturn([]);

		$this->accountRelationService = $this->createStub(AccountRelationService::class);
		$this->accountRelationService->method('notificationDecisions')
			->willReturnCallback(fn (): array => $this->decisions);

		$this->service = new NotificationPolicyService(
			$this->configService,
			$this->followsRequest,
			$this->moderationService,
			$this->accountRelationService
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
			$this->moderationService,
			$this->accountRelationService
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
}
