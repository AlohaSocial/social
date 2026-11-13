<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\BadgeController;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\NotificationPolicy;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConversationService;
use OCA\Social\Service\NotificationInboxService;
use OCA\Social\Service\NotificationPolicyService;
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
 * The sidebar's three counts in one answer, under one tag.
 */
#[AllowMockObjectsWithoutExpectations]
class BadgeControllerTest extends TestCase {
	/** @var IRequest&Stub */
	private $request;
	private IUserSession|Stub $userSession;
	private AccountService|Stub $accountService;
	private ClientService|Stub $clientService;
	private NotificationInboxService|MockObject $inbox;
	private NotificationPolicyService|Stub $policies;
	private ConversationService|MockObject $conversations;

	/** @var array<string, string> the request headers the controller will see */
	private array $headers = [];
	private bool $csrf = true;
	private string $notificationTag = 'n1';
	private string $conversationTag = 'c1';

	protected function setUp(): void {
		$this->request = $this->createStub(IRequest::class);
		$this->request->method('getId')->willReturn('test');
		$this->request->method('getHeader')
			->willReturnCallback(fn (string $name): string => $this->headers[$name] ?? '');
		$this->request->method('passesCSRFCheck')->willReturnCallback(fn (): bool => $this->csrf);

		$this->userSession = $this->createStub(IUserSession::class);
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$viewer = new Person();
		$viewer->setId('https://cloud.example/users/alice');
		$viewer->setUserId('alice');
		$this->accountService = $this->createStub(AccountService::class);
		$this->accountService->method('getActorFromUserId')->willReturn($viewer);

		$this->clientService = $this->createStub(ClientService::class);

		$this->inbox = $this->createMock(NotificationInboxService::class);
		$this->inbox->method('badgeTag')->willReturnCallback(fn (): string => $this->notificationTag);
		$this->inbox->method('unreadCount')->willReturn(3);
		$this->inbox->method('pending')->willReturn(['requests' => 2, 'notifications' => 5]);

		$this->policies = $this->createStub(NotificationPolicyService::class);
		$this->policies->method('of')->willReturn((new NotificationPolicy())->setNotice(true));

		$this->conversations = $this->createMock(ConversationService::class);
		$this->conversations->method('unreadTag')->willReturnCallback(fn (): string => $this->conversationTag);
		$this->conversations->method('countUnread')->willReturn(1);

		// Response::getHeaders() asks the container for the request
		\OC::$server->register(IRequest::class, $this->request);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	private function controller(string $authorization = ''): BadgeController {
		$this->headers['Authorization'] = $authorization;

		return new BadgeController(
			$this->request,
			$this->userSession,
			new NullLogger(),
			$this->accountService,
			$this->clientService,
			$this->inbox,
			$this->policies,
			$this->conversations,
		);
	}

	public function testAllThreeCountsComeInOneAnswer(): void {
		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([
			'unread_notifications' => 3,
			'pending_requests' => 2,
			'notice' => true,
			'unread_conversations' => 1,
		], $response->getData());
		$this->assertSame('private, no-cache', $response->getHeaders()['Cache-Control'] ?? '');
	}

	/** An unchanged poll is one `304`, and none of the three is counted again. */
	public function testAnUnchangedPollIsNotModified(): void {
		$etag = $this->controller()->index()->getHeaders()['ETag'] ?? '';
		$this->assertNotSame('', $etag);

		$this->inbox->expects($this->never())->method('unreadCount');
		$this->inbox->expects($this->never())->method('pending');
		$this->conversations->expects($this->never())->method('countUnread');

		$controller = $this->controller();
		$this->headers['If-None-Match'] = $etag;
		$response = $controller->index();

		$this->assertSame(Http::STATUS_NOT_MODIFIED, $response->getStatus());
		$this->assertSame($etag, $response->getHeaders()['ETag'] ?? '');
	}

	/** A change to either kind of count is a new tag. */
	public function testTheTagMovesWithEitherKind(): void {
		$first = $this->controller()->index()->getHeaders()['ETag'] ?? '';

		$this->conversationTag = 'c2';
		$second = $this->controller()->index()->getHeaders()['ETag'] ?? '';

		$this->notificationTag = 'n2';
		$third = $this->controller()->index()->getHeaders()['ETag'] ?? '';

		$this->assertCount(3, array_unique([$first, $second, $third]));

		$this->headers['If-None-Match'] = $first;
		$this->assertSame(Http::STATUS_OK, $this->controller()->index()->getStatus());
	}

	public function testNobodyIsRefused(): void {
		$this->csrf = false;

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->index()->getStatus());
	}

	/** A token reads all three or none: it needs the broad `read` scope. */
	public function testATokenNeedsTheReadScope(): void {
		$this->csrf = false;
		$client = new SocialClient();
		$client->setAuthUserId('alice');
		$client->setAuthScopes(['read:notifications']);
		$this->clientService->method('getFromToken')->willReturn($client);

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller('Bearer t')->index()->getStatus());

		$client->setAuthScopes(['read']);
		$this->assertSame(Http::STATUS_OK, $this->controller('Bearer t')->index()->getStatus());
	}
}
