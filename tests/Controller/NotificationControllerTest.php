<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\NotificationController;
use OCA\Social\Db\ConversationsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\ModerationRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\NotificationPolicy;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Model\NotificationDelivery;
use OCA\Social\Service\AccountRelationService;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FilterService;
use OCA\Social\Service\MarkerService;
use OCA\Social\Service\NotificationDeliveryService;
use OCA\Social\Service\NotificationGroupService;
use OCA\Social\Service\NotificationInboxService;
use OCA\Social\Service\NotificationPolicyService;
use OCA\Social\Service\NotificationService;
use OCA\Social\Service\StreamService;
use OCA\Social\Service\TimelineRevisionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The routes behind the dismiss button.
 *
 * Checked here is what a caller is refused as much as what they get back: no
 * credentials is a 401, a token without the granular scope is a 403, a
 * notification that is not the caller's is a 404 that says nothing about
 * whether it exists, and dismissing one that is already gone is a success —
 * the client is asking for a state that already holds.
 */
#[AllowMockObjectsWithoutExpectations]
class NotificationControllerTest extends TestCase {
	private const VIEWER = 'https://cloud.example/users/alice';

	/** @var IRequest&Stub */
	private $request;
	private AccountService|Stub $accountService;
	private ClientService|Stub $clientService;
	private NotificationService|Stub $notificationService;
	private NotificationPolicyService|MockObject $notificationPolicyService;
	private NotificationDeliveryService|MockObject $notificationDeliveryService;
	private FilterService|Stub $filterService;
	private CacheActorService|Stub $cacheActorService;
	private IUserSession|Stub $userSession;

	/** @var array<string, string> the request headers the controller will see */
	private array $headers = [];
	/** @var int[] the notification ids the viewer has */
	private array $own = [7];
	/** @var array<int, array> [method, id] of every call that changes something */
	private array $writes = [];
	private bool $csrf = true;
	/** @var Stream[] the page of notifications the stream service serves */
	private array $page = [];
	private MarkerService|MockObject $markerService;

	protected function setUp(): void {
		$this->request = $this->createStub(IRequest::class);
		$this->request->method('getId')->willReturn('test');
		$this->request->method('getHeader')
			->willReturnCallback(fn (string $name): string => $this->headers[$name] ?? '');
		$this->request->method('passesCSRFCheck')->willReturnCallback(fn (): bool => $this->csrf);
		$this->request->method('getParam')->willReturn('');
		$this->request->method('getParams')->willReturn([]);

		$this->userSession = $this->createStub(IUserSession::class);
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->accountService = $this->createStub(AccountService::class);
		$this->accountService->method('getActorFromUserId')->willReturnCallback(
			function (): Person {
				$viewer = new Person();
				$viewer->setId(self::VIEWER);
				// the policy is stored per Nextcloud user, so the viewer has
				// to carry one
				$viewer->setUserId('alice');

				return $viewer;
			}
		);

		$this->clientService = $this->createStub(ClientService::class);

		$this->notificationService = $this->createStub(NotificationService::class);
		$this->notificationService->method('get')
			->willReturnCallback(function (Person $viewer, int $id): Stream {
				$this->mine($id);

				$notification = new SocialAppNotification();
				$notification->setNid($id);

				return $notification;
			});
		$this->notificationService->method('dismiss')
			->willReturnCallback(function (Person $viewer, int $id): void {
				$this->mine($id);
				$this->writes[] = ['dismiss', $id];
			});
		$this->notificationService->method('clear')
			->willReturnCallback(function (): int {
				$this->writes[] = ['clear', 0];

				return 3;
			});

		$this->notificationPolicyService = $this->createMock(NotificationPolicyService::class);
		// nothing is held unless a test says so: an account that has not
		// touched the policy is the case every other test is about
		$this->notificationPolicyService->method('partition')
			->willReturnCallback(static fn (Person $viewer, array $page): array
				=> ['shown' => $page, 'held' => []]);
		$this->notificationPolicyService->method('of')->willReturn(new NotificationPolicy());
		$this->notificationPolicyService->method('decisionsAbout')
			->willReturn(['accepted' => [], 'dismissed' => []]);
		$this->notificationDeliveryService = $this->createMock(NotificationDeliveryService::class);
		$this->notificationDeliveryService->method('of')->willReturn(new NotificationDelivery());
		$this->filterService = $this->createStub(FilterService::class);
		$this->filterService->method('applyToNotifications')->willReturnArgument(0);
		$this->cacheActorService = $this->createStub(CacheActorService::class);
		$this->markerService = $this->createMock(MarkerService::class);

		// Response::getHeaders() asks the container for the request
		\OC::$server->register(IRequest::class, $this->request);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	/**
	 * Built per test rather than in setUp(): the controller reads the
	 * Authorization header in its constructor, so a token set afterwards would
	 * never be seen.
	 */
	private function controller(): NotificationController {
		return new NotificationController(
			$this->request,
			$this->userSession,
			new NullLogger(),
			$this->accountService,
			$this->clientService,
			$this->notificationService,
			new NotificationGroupService(),
			$this->notificationPolicyService,
			$this->notificationDeliveryService,
			$this->filterService,
			$this->cacheActorService,
			$this->inbox()
		);
	}

	/** The real inbox over a stream service that serves `$page`, and the policy double. */
	private function inbox(): NotificationInboxService {
		$streamService = $this->createStub(StreamService::class);
		$streamService->method('getTimeline')->willReturnCallback(fn (): array => $this->page);

		return new NotificationInboxService(
			$streamService,
			$this->createStub(StreamRequest::class),
			$this->notificationPolicyService,
			$this->markerService,
			$this->createStub(ConversationsRequest::class)
		);
	}

	/** @throws ItemNotFoundException a notification that is not the viewer's */
	private function mine(int $id): void {
		if (!in_array($id, $this->own, true)) {
			throw new ItemNotFoundException('Record not found');
		}
	}

	/** @param string[] $scopes */
	private function withToken(array $scopes): void {
		$this->headers['Authorization'] = 'Bearer token-1';

		$client = $this->createStub(SocialClient::class);
		$client->method('getAuthUserId')->willReturn('alice');
		$client->method('getAuthScopes')->willReturn($scopes);
		$this->clientService->method('getFromToken')->willReturn($client);
	}

	private function withoutCredentials(): void {
		// no bearer token, and a session that fails the CSRF check is no
		// session at all as far as this API is concerned
		$this->csrf = false;
	}

	public function testGetAnswersTheNotification(): void {
		$response = $this->controller()->get(7);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertInstanceOf(Stream::class, $response->getData());
		$this->assertSame(7, $response->getData()->getNid());
	}

	public function testGetOfANotificationThatIsNotTheViewersIsNotFound(): void {
		$response = $this->controller()->get(8);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Record not found'], $response->getData());
	}

	public function testGetWithoutCredentialsIsRefused(): void {
		$this->withoutCredentials();

		$response = $this->controller()->get(7);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertArrayHasKey('WWW-Authenticate', $response->getHeaders());
	}

	public function testGetNeedsAReadScope(): void {
		$this->withToken(['write:notifications']);

		$response = $this->controller()->get(7);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testDismissRemovesTheNotification(): void {
		$this->withToken(['write:notifications']);

		$response = $this->controller()->dismiss(7);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData());
		$this->assertSame([['dismiss', 7]], $this->writes);
	}

	public function testDismissingSomethingAlreadyGoneIsASuccess(): void {
		$response = $this->controller()->dismiss(8);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData());
	}

	public function testDismissNeedsAWriteScope(): void {
		$this->withToken(['read']);

		$response = $this->controller()->dismiss(7);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame([], $this->writes);
	}

	public function testDismissWithoutCredentialsIsRefused(): void {
		$this->withoutCredentials();

		$response = $this->controller()->dismiss(7);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame([], $this->writes);
	}

	public function testClearEmptiesTheList(): void {
		$this->withToken(['write:notifications']);

		$response = $this->controller()->clear();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData());
		$this->assertSame([['clear', 0]], $this->writes);
	}

	public function testTheBroadWriteScopeCoversClearing(): void {
		$this->withToken(['write']);

		$response = $this->controller()->clear();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([['clear', 0]], $this->writes);
	}

	public function testAnotherGranularWriteScopeDoesNotCoverClearing(): void {
		$this->withToken(['write:statuses']);

		$response = $this->controller()->clear();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame([], $this->writes);
	}

	public function testClearWithoutCredentialsIsRefused(): void {
		$this->withoutCredentials();

		$response = $this->controller()->clear();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame([], $this->writes);
	}

	public function testAFailureOnThisSideSaysNothingAboutItself(): void {
		$this->notificationService = $this->createStub(NotificationService::class);
		$this->notificationService->method('clear')
			->willThrowException(new \RuntimeException('SQLSTATE[42S02] social_stream'));

		$response = $this->controller()->clear();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'internal server error'], $response->getData());
	}

	public function testARevokedTokenIsAnUnauthorizedAnswer(): void {
		$this->headers['Authorization'] = 'Bearer token-1';
		$this->clientService->method('getFromToken')
			->willThrowException(new ClientNotFoundException());

		$response = $this->controller()->get(7);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	// Mastodon 4.3: grouping, the policy, and the requests inbox

	/** A page of notifications the stubbed service will serve. */
	private function timeline(array $notifications): void {
		$this->page = $notifications;
	}

	private function favourite(int $nid, int $senderNid, int $postNid): Stream {
		$sender = new Person();
		$sender->setNid($senderNid);
		$sender->setId('https://cloud.example/users/' . $senderNid);

		$post = new Note();
		$post->setNid($postNid);
		$post->setId('https://cloud.example/@alice/' . $postNid);

		$notification = new Stream();
		$notification->setNid($nid);
		$notification->setSubType(Like::TYPE);
		$notification->setPublishedTime(1_700_000_000 + $nid);
		$notification->setActor($sender);
		$notification->setObject($post);

		return $notification;
	}

	public function testTheGroupedListGroupsFavouritesOfOnePost(): void {
		$this->timeline([$this->favourite(2, 200, 10), $this->favourite(1, 100, 10)]);

		$data = $this->controller()->indexV2()->getData();

		$this->assertCount(1, $data['notification_groups']);
		$this->assertSame('favourite-10', $data['notification_groups'][0]['group_key']);
		$this->assertCount(2, $data['accounts']);
		$this->assertCount(1, $data['statuses']);
	}

	public function testTheUnreadCountCountsGroupsRatherThanRows(): void {
		$this->timeline([$this->favourite(2, 200, 10), $this->favourite(1, 100, 10)]);

		$this->assertSame(['count' => 1], $this->controller()->unreadCountV2()->getData());
	}

	public function testOneGroupCanBeFetched(): void {
		$this->timeline([$this->favourite(2, 200, 10), $this->favourite(1, 100, 11)]);

		$data = $this->controller()->group('favourite-10')->getData();

		$this->assertCount(1, $data['notification_groups']);
		$this->assertSame(1, $data['notification_groups'][0]['notifications_count']);
	}

	public function testAGroupNobodyIsInIsNotFound(): void {
		$this->timeline([]);

		$this->assertSame(
			Http::STATUS_NOT_FOUND, $this->controller()->group('favourite-99')->getStatus()
		);
	}

	public function testTheAccountsOfAGroupAreEverybodyInIt(): void {
		$this->timeline([$this->favourite(2, 200, 10), $this->favourite(1, 100, 10)]);

		$this->assertCount(2, $this->controller()->groupAccounts('favourite-10')->getData());
	}

	/** Dismissing a group dismisses the rows behind it, not only the newest. */
	public function testDismissingAGroupDismissesEveryNotificationInIt(): void {
		$this->own = [1, 2];
		$this->timeline([$this->favourite(2, 200, 10), $this->favourite(1, 100, 10)]);

		$this->assertSame(Http::STATUS_OK, $this->controller()->groupDismiss('favourite-10')->getStatus());
		$this->assertSame([['dismiss', 2], ['dismiss', 1]], $this->writes);
	}

	// -- notification delivery ------------------------------------------------

	/** @param array<string, mixed> $body */
	private function requestWithBody(array $body): void {
		$this->request = $this->createStub(IRequest::class);
		$this->request->method('getId')->willReturn('test');
		$this->request->method('getHeader')
			->willReturnCallback(fn (string $name): string => $this->headers[$name] ?? '');
		$this->request->method('passesCSRFCheck')->willReturn(true);
		$this->request->method('getParam')->willReturn('');
		$this->request->method('getParams')->willReturn($body);
	}

	public function testTheDeliverySettingIsAnsweredWholeWithItsDefaults(): void {
		$response = $this->controller()->delivery();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([
			'mode' => 'instant',
			'times' => ['08:00', '18:00'],
			'passthrough' => ['direct' => true, 'mentions_from_followed' => true],
			'quiet' => ['from' => '', 'to' => ''],
		], $response->getData()->jsonSerialize());
	}

	public function testTheDeliverySettingIsReadForTheNextcloudUser(): void {
		$this->notificationDeliveryService->expects($this->once())
			->method('of')->with('alice')->willReturn(new NotificationDelivery());

		$this->controller()->delivery();
	}

	public function testReadingTheDeliverySettingNeedsTheReadScope(): void {
		$this->withToken(['write:notifications']);

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->delivery()->getStatus());
	}

	public function testReadingTheDeliverySettingNeedsAViewer(): void {
		$this->withoutCredentials();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->delivery()->getStatus());
	}

	public function testChangingTheDeliverySettingWritesTheBodyAndAnswersTheWhole(): void {
		$body = ['mode' => 'digest', 'times' => ['20:00', '09:00']];
		$saved = (new NotificationDelivery())->apply($body);
		$this->notificationDeliveryService->expects($this->once())
			->method('save')->with('alice', $body)->willReturn($saved);
		$this->requestWithBody($body);

		$response = $this->controller()->deliveryUpdate();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('digest', $response->getData()->jsonSerialize()['mode']);
		$this->assertSame(['09:00', '20:00'], $response->getData()->jsonSerialize()['times']);
	}

	public function testAnInvalidDeliverySettingIs422WithTheReason(): void {
		$this->notificationDeliveryService->method('save')
			->willThrowException(new InvalidResourceException('mode must be "instant" or "digest"'));
		$this->requestWithBody(['mode' => 'weekly']);

		$response = $this->controller()->deliveryUpdate();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => 'mode must be "instant" or "digest"'], $response->getData());
	}

	public function testChangingTheDeliverySettingNeedsTheWriteScope(): void {
		$this->withToken(['read:notifications']);
		$this->notificationDeliveryService->expects($this->never())->method('save');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->deliveryUpdate()->getStatus());
	}

	public function testChangingTheDeliverySettingNeedsAViewer(): void {
		$this->withoutCredentials();
		$this->notificationDeliveryService->expects($this->never())->method('save');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->deliveryUpdate()->getStatus());
	}

	public function testThePolicyIsAnsweredWithItsSummary(): void {
		$this->timeline([]);

		$data = $this->controller()->policy()->getData()->jsonSerialize();

		$this->assertSame(NotificationPolicy::ACCEPT, $data[NotificationPolicy::NOT_FOLLOWING]);
		$this->assertSame(
			['pending_requests_count' => 0, 'pending_notifications_count' => 0], $data['summary']
		);
	}

	public function testChangingThePolicyWritesOnlyWhatWasNamed(): void {
		$this->timeline([]);
		$this->notificationPolicyService->expects($this->once())
			->method('save')
			->with('alice', [NotificationPolicy::NOT_FOLLOWING => NotificationPolicy::FILTER])
			->willReturn(new NotificationPolicy());

		$this->request = $this->createStub(IRequest::class);
		$this->request->method('getId')->willReturn('test');
		$this->request->method('getHeader')
			->willReturnCallback(fn (string $name): string => $this->headers[$name] ?? '');
		$this->request->method('passesCSRFCheck')->willReturn(true);
		$this->request->method('getParam')->willReturn('');
		$this->request->method('getParams')
			->willReturn([NotificationPolicy::NOT_FOLLOWING => NotificationPolicy::FILTER]);

		$this->assertSame(Http::STATUS_OK, $this->controller()->policyUpdate()->getStatus());
	}

	public function testTheRequestsInboxIsOneRowPerHeldSender(): void {
		$held = [$this->favourite(2, 200, 10), $this->favourite(1, 200, 11)];
		$this->page = $held;
		$this->notificationPolicyService = $this->createMock(NotificationPolicyService::class);
		$this->notificationPolicyService->method('of')
			->willReturn((new NotificationPolicy())->set(NotificationPolicy::NOT_FOLLOWING, NotificationPolicy::FILTER));
		$this->notificationPolicyService->method('partition')
			->willReturn(['shown' => [], 'held' => $held]);
		$this->notificationPolicyService->method('decisionsAbout')
			->willReturn(['accepted' => [], 'dismissed' => []]);
		$this->notificationPolicyService->method('requestsFrom')
			->willReturnCallback(
				fn (array $rows, array $dismissed = []): array => (new NotificationPolicyService(
					$this->createStub(ConfigService::class),
					$this->createStub(FollowsRequest::class),
					$this->createStub(ModerationRequest::class),
					$this->createStub(AccountRelationService::class),
					$this->createStub(TimelineRevisionService::class)
				))->requestsFrom($rows, $dismissed)
			);

		$data = $this->controller()->requests()->getData();

		$this->assertCount(1, $data);
		$this->assertSame(2, $data[0]->getCount());
	}

	public function testAcceptingARequestDecidesAboutTheAccount(): void {
		$account = new Person();
		$account->setId('https://cloud.example/users/200');
		$this->cacheActorService->method('getFromNids')->willReturn([$account]);
		$this->notificationPolicyService->expects($this->once())
			->method('accept')
			->with($this->anything(), $this->identicalTo($account));

		$this->assertSame(Http::STATUS_OK, $this->controller()->requestAccept(200)->getStatus());
	}

	public function testDismissingARequestDecidesAboutTheAccount(): void {
		$account = new Person();
		$this->cacheActorService->method('getFromNids')->willReturn([$account]);
		$this->notificationPolicyService->expects($this->once())->method('dismiss');

		$this->assertSame(Http::STATUS_OK, $this->controller()->requestDismiss(200)->getStatus());
	}

	/** One stale id in a bulk decision must not lose the rest. */
	public function testAnUnknownAccountInABulkDecisionIsSkipped(): void {
		$account = new Person();
		$this->cacheActorService->method('getFromNids')
			->willReturnCallback(static fn (array $nids): array => ($nids === [200]) ? [new Person()] : []);
		$this->notificationPolicyService->expects($this->once())->method('accept');

		$this->assertSame(
			Http::STATUS_OK, $this->controller()->requestsAccept(['999', '200'])->getStatus()
		);
	}

	public function testNothingIsEverInFlight(): void {
		$this->assertSame(['merged' => true], $this->controller()->requestsMerged()->getData());
	}

	public function testTheGroupedRoutesNeedCredentials(): void {
		$this->withoutCredentials();
		$this->timeline([]);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->indexV2()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->policy()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->requests()->getStatus());
	}

	// accepting releases without ringing; the always-allowed list; the notice

	/** What the accepted sender sent while held is unread now, even behind the marker. */
	public function testAcceptingMarksWhatWasHeldUnread(): void {
		$held = [$this->favourite(31, 200, 10), $this->favourite(30, 200, 11), $this->favourite(29, 300, 12)];
		$this->page = $held;
		$this->notificationPolicyService = $this->createMock(NotificationPolicyService::class);
		$this->notificationPolicyService->method('of')
			->willReturn((new NotificationPolicy())->set(NotificationPolicy::NOT_FOLLOWING, NotificationPolicy::FILTER));
		$this->notificationPolicyService->method('partition')->willReturn(['shown' => [], 'held' => $held]);
		$account = new Person();
		$account->setId('https://cloud.example/users/200');
		$this->cacheActorService->method('getFromNids')->willReturn([$account]);

		$this->notificationPolicyService->expects($this->once())->method('accept');
		$this->markerService->expects($this->once())->method('markUnread')
			->with('alice', 'notifications', ['31', '30']);

		$this->assertSame(Http::STATUS_OK, $this->controller()->requestAccept(200)->getStatus());
	}

	/** Accepting rings nothing: no notification is raised, now or for what was held. */
	public function testAcceptingRaisesNoNotification(): void {
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->notificationService->expects($this->never())->method('onNotification');
		$this->cacheActorService->method('getFromNids')->willReturn([new Person()]);

		$this->assertSame(Http::STATUS_OK, $this->controller()->requestAccept(200)->getStatus());
	}

	public function testThePolicySaysWhetherToPointAtIt(): void {
		$this->notificationPolicyService = $this->createMock(NotificationPolicyService::class);
		$this->notificationPolicyService->method('of')->willReturn((new NotificationPolicy())->setNotice(true));

		$data = $this->controller()->policy()->getData()->jsonSerialize();

		$this->assertTrue($data['notice']);
	}

	public function testDismissingTheNoticeIsStoredForTheViewer(): void {
		$this->notificationPolicyService->expects($this->once())->method('dismissNotice')->with('alice');

		$response = $this->controller()->policyNoticeDismiss();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertInstanceOf(NotificationPolicy::class, $response->getData());
	}

	public function testDismissingTheNoticeNeedsTheWriteScope(): void {
		$this->withToken(['read:notifications']);
		$this->notificationPolicyService->expects($this->never())->method('dismissNotice');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->policyNoticeDismiss()->getStatus());
	}

	public function testTheAlwaysAllowedAreAnsweredAsAccounts(): void {
		$carol = new Person();
		$carol->setId('https://elsewhere.example/users/carol');
		$this->notificationPolicyService->expects($this->once())->method('allowed')
			->with($this->anything(), 20)
			->willReturn([$carol]);

		$response = $this->controller()->allowed(20);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([$carol], $response->getData());
	}

	public function testTheAlwaysAllowedNeedTheReadScope(): void {
		$this->withToken(['write:notifications']);
		$this->notificationPolicyService->expects($this->never())->method('allowed');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->allowed()->getStatus());
	}

	public function testStoppingToAllowASenderReturnsThemToThePolicy(): void {
		$carol = new Person();
		$this->cacheActorService->method('getFromNids')->willReturn([$carol]);
		$this->notificationPolicyService->expects($this->once())->method('stopAllowing')
			->with($this->anything(), $this->identicalTo($carol));

		$this->assertSame(Http::STATUS_OK, $this->controller()->allowedRemove('200')->getStatus());
	}

	/** Nobody by that id is already not allowed: the state asked for holds. */
	public function testStoppingToAllowNobodyIsNotAnError(): void {
		$this->cacheActorService->method('getFromNids')->willReturn([]);
		$this->notificationPolicyService->expects($this->never())->method('stopAllowing');

		$this->assertSame(Http::STATUS_OK, $this->controller()->allowedRemove('999')->getStatus());
	}

	public function testStoppingToAllowNeedsTheWriteScope(): void {
		$this->withToken(['read:notifications']);
		$this->notificationPolicyService->expects($this->never())->method('stopAllowing');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->allowedRemove('200')->getStatus());
	}
}
