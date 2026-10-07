<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\ConversationsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\NotificationPolicy;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\MarkerService;
use OCA\Social\Service\NotificationInboxService;
use OCA\Social\Service\NotificationPolicyService;
use OCA\Social\Service\StreamService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * The notification list once the policy has had its say: what is unread, what
 * is waiting, and what accepting a sender releases.
 *
 * The count matters most: the sidebar adds the waiting senders to it, so a
 * held notification counted here as well would be counted twice, and one
 * released behind the marker and not counted would be news nobody is told of.
 */
#[AllowMockObjectsWithoutExpectations]
class NotificationInboxServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example/users/alice';
	private const STRANGER = 'https://elsewhere.example/users/carol';
	private const FRIEND = 'https://elsewhere.example/users/dave';

	private StreamService|Stub $streamService;
	private StreamRequest|MockObject $streamRequest;
	private MarkerService|MockObject $markerService;
	private NotificationPolicyService|Stub $policyService;
	private NotificationInboxService $service;

	private NotificationPolicy $policy;
	/** @var Stream[] what the notification query serves */
	private array $page = [];
	/** @var ProbeOptions[] every page asked for */
	private array $asked = [];
	/** @var string[] what was released behind the marker */
	private array $released = [];

	protected function setUp(): void {
		$this->policy = new NotificationPolicy();

		$this->streamService = $this->createStub(StreamService::class);
		$this->streamService->method('getTimeline')->willReturnCallback(function (ProbeOptions $options): array {
			$this->asked[] = $options;

			return array_slice($this->page, 0, $options->getLimit());
		});

		$this->streamRequest = $this->createMock(StreamRequest::class);

		$this->markerService = $this->createMock(MarkerService::class);
		$this->markerService->method('lastReadId')->willReturn('100');
		$this->markerService->method('unreadBehind')->willReturnCallback(fn (): array => $this->released);

		// the stranger is held whenever the policy holds anything
		$this->policyService = $this->createStub(NotificationPolicyService::class);
		$this->policyService->method('of')->willReturnCallback(fn (): NotificationPolicy => $this->policy);
		$this->policyService->method('partition')->willReturnCallback(
			fn (Person $viewer, array $page): array => [
				'shown' => array_values(array_filter($page, static fn (Stream $n): bool => $n->getActor()->getId() !== self::STRANGER)),
				'held' => array_values(array_filter($page, static fn (Stream $n): bool => $n->getActor()->getId() === self::STRANGER)),
			]
		);
		$this->policyService->method('decisionsAbout')->willReturn(['accepted' => [], 'dismissed' => []]);
		$this->policyService->method('requestsFrom')->willReturnCallback(
			fn (array $held): array => array_map(
				static fn (Stream $n): \OCA\Social\Model\Client\NotificationRequest
					=> new \OCA\Social\Model\Client\NotificationRequest($n->getActor(), 1, 0, 0),
				$held
			)
		);

		$this->service = new NotificationInboxService(
			$this->streamService,
			$this->streamRequest,
			$this->policyService,
			$this->markerService,
			$this->createStub(ConversationsRequest::class)
		);
	}

	private function viewer(): Person {
		$viewer = new Person();
		$viewer->setId(self::ALICE);
		$viewer->setUserId('alice');

		return $viewer;
	}

	private function from(string $actorId, int $nid): Stream {
		$sender = new Person();
		$sender->setId($actorId);

		$notification = new Stream();
		$notification->setNid($nid);
		$notification->setSubType(Like::TYPE);
		$notification->setActor($sender);

		return $notification;
	}

	private function holding(): void {
		$this->policy = (new NotificationPolicy())->set(NotificationPolicy::NOT_FOLLOWING, NotificationPolicy::FILTER);
	}

	/** An account whose policy holds nothing is counted by the database and nothing is read. */
	public function testTheUnreadCountOfAnUntouchedPolicyIsTheDatabasesCount(): void {
		$this->streamRequest->expects($this->once())->method('countNotificationsSince')
			->with($this->anything(), '100', 99)
			->willReturn(4);

		$this->assertSame(4, $this->service->unreadCount($this->viewer(), 'alice'));
		$this->assertSame([], $this->asked);
	}

	/** What the policy holds is waiting, not unread: the sidebar counts it as a request. */
	public function testTheUnreadCountLeavesOutWhatIsHeld(): void {
		$this->holding();
		$this->page = [$this->from(self::FRIEND, 130), $this->from(self::STRANGER, 120), $this->from(self::FRIEND, 110)];
		$this->streamRequest->expects($this->never())->method('countNotificationsSince');

		$this->assertSame(2, $this->service->unreadCount($this->viewer(), 'alice'));
		$this->assertSame('100', (string)$this->asked[0]->getSince(), 'only past the marker');
	}

	public function testReleasedNotificationsBehindTheMarkerCountAsUnread(): void {
		$this->streamRequest->method('countNotificationsSince')->willReturn(1);
		$this->released = ['40', '50'];

		$this->assertSame(3, $this->service->unreadCount($this->viewer(), 'alice'));
	}

	public function testTheUnreadCountStopsJustPastTheCap(): void {
		$this->streamRequest->method('countNotificationsSince')->willReturn(100);
		$this->released = ['40', '50'];

		$this->assertSame(100, $this->service->unreadCount($this->viewer(), 'alice'));
	}

	public function testNothingIsHeldOrWaitingWhileThePolicyAcceptsEverything(): void {
		$this->page = [$this->from(self::STRANGER, 120)];

		$this->assertSame([], $this->service->held($this->viewer()));
		$this->assertSame([], $this->service->requests($this->viewer()));
		$this->assertSame([], $this->asked, 'nothing is read for it');
	}

	public function testTheWaitingAreTheHeldSenders(): void {
		$this->holding();
		$this->page = [$this->from(self::FRIEND, 130), $this->from(self::STRANGER, 120)];

		$requests = $this->service->requests($this->viewer());

		$this->assertCount(1, $requests);
		$this->assertSame(self::STRANGER, $requests[0]->getAccount()->getId());
	}

	/**
	 * Accepting decides about the sender and lets what they sent while held
	 * count as unread; no notification is raised for any of it.
	 */
	public function testReleasingAcceptsAndMarksWhatWasHeldUnread(): void {
		$this->holding();
		$this->page = [$this->from(self::STRANGER, 120), $this->from(self::FRIEND, 110), $this->from(self::STRANGER, 90)];
		$stranger = new Person();
		$stranger->setId(self::STRANGER);

		$policy = $this->createMock(NotificationPolicyService::class);
		$policy->method('of')->willReturnCallback(fn (): NotificationPolicy => $this->policy);
		$policy->method('partition')->willReturnCallback(fn (Person $v, array $page): array => $this->policyService->partition($v, $page));
		$policy->expects($this->once())->method('accept')->with($this->anything(), $this->identicalTo($stranger));
		$this->markerService->expects($this->once())->method('markUnread')->with('alice', 'notifications', ['120', '90']);

		(new NotificationInboxService(
			$this->streamService, $this->streamRequest, $policy, $this->markerService,
			$this->createStub(ConversationsRequest::class)
		))->release($this->viewer(), $stranger);
	}

	/** Rows Mastodon has no name for are not served: one would lose a client the whole page. */
	public function testANamelessSubTypeIsNotServed(): void {
		$nameless = $this->from(self::FRIEND, 130);
		$nameless->setSubType('SomethingNobodyNamed');
		$this->page = [$nameless, $this->from(self::FRIEND, 120)];

		$this->assertCount(1, $this->service->timeline($this->viewer(), 40));
	}

	/** The count's tag moves with the policy and with what was released. */
	public function testTheUnreadStateFollowsThePolicyAndTheReleased(): void {
		$before = $this->service->unreadState($this->viewer(), 'alice');

		$this->holding();
		$afterPolicy = $this->service->unreadState($this->viewer(), 'alice');
		$this->released = ['40'];
		$afterRelease = $this->service->unreadState($this->viewer(), 'alice');

		$this->assertNotSame($before, $afterPolicy);
		$this->assertNotSame($afterPolicy, $afterRelease);
	}
}
