<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use Exception;
use OCA\Social\Controller\ConversationController;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\Conversation;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConversationService;
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
 * The three routes a client keeps its direct messages on.
 *
 * A conversation is somebody's private correspondence, so what is checked here
 * is as much about what a caller is refused as about the entity shape: no
 * credentials is a 401, the wrong granular scope is a 403, and a conversation
 * that is not the caller's is a 404 with nothing written.
 */
#[AllowMockObjectsWithoutExpectations]
class ConversationControllerTest extends TestCase {
	private const VIEWER = 'https://cloud.example/users/alice';
	private const BOB = 'https://remote.example/users/bob';
	/** A thread root's nid wider than a PHP int. */
	private const WIDE = '92233720368547758070';

	/** @var IRequest&Stub */
	private $request;
	private AccountService|Stub $accountService;
	private ClientService|Stub $clientService;
	private ConversationService|MockObject $conversationService;
	private IUserSession|Stub $userSession;

	/** @var array<string, string> the request headers the controller will see */
	private array $headers = [];
	/** @var array{conversations: Conversation[], next: int, prev: int} */
	private array $page = ['conversations' => [], 'next' => 0, 'prev' => 0];
	/** @var array<int, array> [method, id] of every call that changes something */
	private array $writes = [];
	/** @var array<int|string> the conversation ids the viewer has */
	private array $own = [10, self::WIDE];
	private string $uri = '/index.php/apps/social/api/v1/conversations';
	private bool $csrf = true;

	protected function setUp(): void {
		$this->request = $this->createStub(IRequest::class);
		$this->request->method('getId')->willReturn('test');
		$this->request->method('getHeader')
			->willReturnCallback(fn (string $name): string => $this->headers[$name] ?? '');
		$this->request->method('passesCSRFCheck')->willReturnCallback(fn (): bool => $this->csrf);
		$this->request->method('getRequestUri')->willReturnCallback(fn (): string => $this->uri);
		$this->request->method('getParam')->willReturn('');
		$this->request->method('getParams')->willReturn([]);

		$this->userSession = $this->createStub(IUserSession::class);
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->accountService = $this->createStub(AccountService::class);
		$this->accountService->method('getActorFromUserId')->willReturn($this->person(self::VIEWER));

		$this->clientService = $this->createStub(ClientService::class);

		$this->conversationService = $this->createMock(ConversationService::class);
		$this->conversationService->method('getPage')->willReturnCallback(
			fn (): array => $this->page
		);
		$this->conversationService->method('markRead')
			->willReturnCallback(function (Person $viewer, int|string $id): Conversation {
				$this->mine($id);
				$this->writes[] = ['markRead', $id];

				return $this->conversation($id, 11, false);
			});
		$this->conversationService->method('markAllRead')
			->willReturnCallback(function (): int {
				$this->writes[] = ['markAllRead'];

				return count($this->own);
			});
		$this->conversationService->method('remove')
			->willReturnCallback(function (Person $viewer, int|string $id): void {
				$this->mine($id);
				$this->writes[] = ['remove', $id];
			});

		// Response::getHeaders() asks the container for the request
		\OC::$server->register(IRequest::class, $this->request);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	/** @throws ItemNotFoundException a conversation that is not the viewer's */
	private function mine(int|string $id): void {
		if (!in_array($id, $this->own, true)) {
			throw new ItemNotFoundException('Record not found');
		}
	}

	private function person(string $id): Person {
		$person = new Person();
		$person->setId($id);

		return $person;
	}

	private function conversation(int|string $id, int $lastNid, bool $unread = true): Conversation {
		$note = new Note();
		$note->setId('https://a/' . $lastNid)->setNid($lastNid);

		return (new Conversation())
			->setId($id)
			->setRootId('https://a/' . $id)
			->setUnread($unread)
			->setAccounts([$this->person(self::BOB)])
			->setLastStatus($note);
	}

	/**
	 * The bearer token is parsed in the constructor, so a test that presents
	 * one has to say so before the controller exists.
	 */
	private function controller(string $authorization = ''): ConversationController {
		// the getHeader() callback is registered once, in setUp(): a second
		// method() on the same mock never wins over the first
		$this->headers = ['Authorization' => $authorization];

		return new ConversationController(
			$this->request,
			$this->userSession,
			new NullLogger(),
			$this->accountService,
			$this->clientService,
			$this->conversationService,
		);
	}

	private function token(array $scopes): SocialClient {
		$this->csrf = false;
		$client = new SocialClient();
		$client->setAuthUserId('alice');
		$client->setAuthScopes($scopes);
		$this->clientService->method('getFromToken')->willReturn($client);

		return $client;
	}

	public function testTheIndexIsMastodonsConversationEntity(): void {
		$this->page = [
			'conversations' => [$this->conversation(10, 11)],
			'next' => 0,
			'prev' => 11,
		];

		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$entity = $response->getData()[0]->jsonSerialize();
		$this->assertSame(['id', 'unread', 'accounts', 'last_status'], array_keys($entity));
		$this->assertSame('10', $entity['id'], 'a string, as every id on this wire is');
		$this->assertTrue($entity['unread']);
		$this->assertCount(1, $entity['accounts']);
	}

	public function testThePageCarriesTheCursorMastoJsReads(): void {
		$this->uri = '/index.php/apps/social/api/v1/conversations?limit=2';
		$this->page = [
			'conversations' => [$this->conversation(10, 11)],
			'next' => 7,
			'prev' => 11,
		];

		$link = $this->controller()->index(2)->getHeaders()['Link'] ?? '';

		$this->assertStringContainsString('max_id=7', $link);
		$this->assertStringContainsString('rel="next"', $link);
		$this->assertStringContainsString('min_id=11', $link);
		$this->assertStringContainsString('rel="prev"', $link);
		$this->assertStringContainsString('limit=2', $link, 'the caller\'s own filters survive');
	}

	public function testTheEndOfTheListHasNoNextCursor(): void {
		$this->page = ['conversations' => [$this->conversation(10, 11)], 'next' => 0, 'prev' => 11];

		$link = $this->controller()->index()->getHeaders()['Link'] ?? '';

		$this->assertStringNotContainsString('rel="next"', $link);
		$this->assertStringContainsString('rel="prev"', $link);
	}

	public function testAnEmptyListCarriesNoCursorAtAll(): void {
		$response = $this->controller()->index();

		$this->assertSame([], $response->getData());
		$this->assertArrayNotHasKey('Link', $response->getHeaders());
	}

	public function testMarkingReadAnswersWithTheConversation(): void {
		// a client redraws the row from the answer rather than guessing
		$response = $this->controller()->read(10);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertFalse($response->getData()->jsonSerialize()['unread']);
		$this->assertSame([['markRead', 10]], $this->writes);
	}

	public function testDeletingAnswersWithAnEmptyObject(): void {
		$response = $this->controller()->delete(10);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData());
		$this->assertSame([['remove', 10]], $this->writes);
	}

	/**
	 * A conversation is named by its thread root's nid. Typed `int`, the
	 * framework clamped one wider than a PHP int to PHP_INT_MAX, and the
	 * route marked or dismissed a different conversation, or none.
	 */
	public function testAWideConversationIdReachesTheServiceExactly(): void {
		$read = $this->controller()->read(self::WIDE);
		$deleted = $this->controller()->delete(self::WIDE);

		$this->assertSame(Http::STATUS_OK, $read->getStatus());
		$this->assertSame(self::WIDE, $read->getData()->jsonSerialize()['id']);
		$this->assertSame(Http::STATUS_OK, $deleted->getStatus());
		$this->assertSame([['markRead', self::WIDE], ['remove', self::WIDE]], $this->writes);
	}

	public function testAConversationThatIsNotTheViewersIsNotThere(): void {
		// and it is a 404, not a 403: telling the two apart would say whether a
		// thread exists and who is in it
		$controller = $this->controller();

		foreach ([$controller->read(99), $controller->delete(99)] as $response) {
			$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
			$this->assertSame('Record not found', $response->getData()['error']);
		}

		$this->assertSame([], $this->writes);
	}

	public function testWithoutCredentialsNothingIsAnswered(): void {
		$this->csrf = false;

		$controller = $this->controller();
		foreach ([$controller->index(), $controller->read(10), $controller->delete(10)] as $response) {
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
			$this->assertStringContainsString(
				'invalid_token', $response->getHeaders()['WWW-Authenticate'] ?? ''
			);
		}

		$this->assertSame([], $this->writes);
	}

	public function testAStaleTokenIsAnsweredAndNotLoggedAsAFault(): void {
		$this->csrf = false;
		$this->clientService->method('getFromToken')
			->willThrowException(new ClientNotFoundException('the access_token was revoked'));

		$response = $this->controller('Bearer stale')->index();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('the access_token was revoked', $response->getData()['error']);
	}

	public function testTheBroadScopeCarriesTheGranularOne(): void {
		$this->token(['read']);

		$this->assertSame(Http::STATUS_OK, $this->controller('Bearer t')->index()->getStatus());
	}

	public function testAnotherGranularReadScopeIsNotThisOne(): void {
		// read:lists is not a grant to read the reader's correspondence
		$this->token(['read:lists']);

		$response = $this->controller('Bearer t')->index();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertArrayNotHasKey('WWW-Authenticate', $response->getHeaders());
	}

	public function testATokenThatMayOnlyReadMayNotWrite(): void {
		$this->token(['read:statuses']);

		$controller = $this->controller('Bearer readonly');
		foreach ([$controller->read(10), $controller->delete(10), $controller->readAll()] as $response) {
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		}

		$this->assertSame([], $this->writes);

		// and reading is still a read
		$this->assertSame(Http::STATUS_OK, $controller->index()->getStatus());
	}

	public function testAWriteTokenMayMarkReadAndDismiss(): void {
		$this->token(['write:conversations']);

		$controller = $this->controller('Bearer t');

		$this->assertSame(Http::STATUS_OK, $controller->read(10)->getStatus());
		$this->assertSame(Http::STATUS_OK, $controller->delete(10)->getStatus());
		$this->assertSame(Http::STATUS_OK, $controller->readAll()->getStatus());
	}

	public function testAFailureThisSideIsNotPublished(): void {
		// these are #[PublicPage] routes, and echoing getMessage() publishes
		// whatever the failure happened to name
		$this->conversationService = $this->createMock(ConversationService::class);
		$this->conversationService->method('getPage')
			->willThrowException(new Exception('SQLSTATE[42S02] table social_convo_state'));

		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('internal server error', $response->getData()['error']);
	}

	// the badge

	public function testTheUnreadCountIsAPlainNumber(): void {
		$this->conversationService->method('countUnread')->willReturn(4);

		$response = $this->controller()->unreadCount();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['count' => 4], $response->getData());
	}

	/**
	 * Nothing else in the web interface marks a conversation read, because
	 * nothing else shows one as a thing of its own -- so without this the
	 * badge would never come down.
	 */
	public function testReadingThePageMarksThemAllRead(): void {
		$response = $this->controller()->readAll();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['count' => 2], $response->getData());
		$this->assertSame([['markAllRead']], $this->writes);
	}
}
