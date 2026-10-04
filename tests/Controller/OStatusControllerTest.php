<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\OStatusController;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\RetrieveAccountFormatException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\MiscService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class OStatusControllerTest extends TestCase {
	/** @var IInitialState&Stub */
	private $initialState;
	/** @var CacheActorService&MockObject */
	private $cacheActorService;
	/** @var AccountService&MockObject */
	private $accountService;
	/** @var CurlService&Stub */
	private $curlService;
	/** @var IUserSession&Stub */
	private $userSession;
	private OStatusController $controller;
	private array $states = [];

	protected function setUp(): void {
		$this->initialState = $this->createStub(IInitialState::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->curlService = $this->createStub(CurlService::class);
		$this->userSession = $this->createStub(IUserSession::class);

		$this->initialState->method('provideInitialState')
			->willReturnCallback(function (string $key, $data): void {
				$this->states['social'][$key] = $data;
			});

		$this->controller = new OStatusController(
			$this->createStub(IRequest::class),
			$this->initialState,
			$this->cacheActorService,
			$this->accountService,
			$this->curlService,
			$this->createStub(MiscService::class),
			$this->userSession
		);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	private function loggedIn(string $uid, string $displayName): void {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($displayName);
		$this->userSession->method('getUser')->willReturn($user);
	}

	/** @return Person&MockObject */
	private function actorWithAccount(string $account): Person {
		$actor = $this->createMock(Person::class);
		$actor->method('getAccount')->willReturn($account);

		return $actor;
	}

	private function assertFailure(DataResponse $response, string $exceptionClass, int $status = Http::STATUS_INTERNAL_SERVER_ERROR): void {
		$this->assertSame($status, $response->getStatus());
		$this->assertSame(-1, $response->getData()['status']);
		// internals ($exceptionClass) must never reach the response
		$this->assertSame('request failed', $response->getData()['error']);
		$this->assertArrayNotHasKey('exception', $response->getData());
	}

	public function testSubscribeRendersTheAppWithTargetAccountAndCurrentUser(): void {
		$this->loggedIn('alice', 'Alice A.');
		$this->cacheActorService->method('getFromAccount')->with('bob@remote.example')
			->willReturn($this->actorWithAccount('bob@remote.example'));

		$response = $this->controller->subscribe('bob@remote.example');

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('main', $response->getTemplateName());
		$this->assertSame([
			'account' => 'bob@remote.example',
			'currentUser' => ['uid' => 'alice', 'displayName' => 'Alice A.'],
		], $this->states['social']['serverData']);
	}

	public function testSubscribeFallsBackToActorIdWhenUriIsNotAnAccount(): void {
		$this->loggedIn('alice', 'Alice');
		$this->cacheActorService->method('getFromAccount')->willThrowException(new InvalidResourceException());
		$this->cacheActorService->expects($this->once())->method('getFromId')->with('https://remote.example/users/bob')
			->willReturn($this->actorWithAccount('bob@remote.example'));

		$response = $this->controller->subscribe('https://remote.example/users/bob');

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('bob@remote.example', $this->states['social']['serverData']['account']);
	}

	public function testSubscribeFailsWithoutASessionUser(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->cacheActorService->method('getFromAccount')->willReturn($this->actorWithAccount('bob@remote.example'));

		$response = $this->controller->subscribe('bob@remote.example');

		$this->assertFailure($response, \Exception::class);
	}

	public function testSubscribeFailsForUnknownActor(): void {
		$this->cacheActorService->method('getFromAccount')->willThrowException(new CacheActorDoesNotExistException());

		$this->assertFailure(
			$this->controller->subscribe('ghost@remote.example'), CacheActorDoesNotExistException::class, Http::STATUS_NOT_FOUND
		);
	}

	public function testFollowRemoteRendersAGuestPageForTheLocalAccount(): void {
		$this->accountService->method('getActor')->with('alice')->willReturn($this->actorWithAccount('alice@cloud.example'));

		$response = $this->controller->followRemote('alice');

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('guest', $response->getRenderAs());
		$this->assertSame(['local' => 'alice', 'account' => 'alice@cloud.example'], $this->states['social']['serverData']);
	}

	public function testFollowRemoteFailsForUnknownLocalAccount(): void {
		$this->accountService->method('getActor')->willThrowException(new AccountDoesNotExistException());

		$this->assertFailure($this->controller->followRemote('ghost'), AccountDoesNotExistException::class);
	}

	public function testGetLinkBuildsTheRemoteSubscribeUrlFromWebfinger(): void {
		$this->accountService->method('getActor')->with('alice')->willReturn($this->actorWithAccount('alice@cloud.example'));
		$this->curlService->method('webfingerAccount')->willReturn([
			'links' => [
				['rel' => 'self', 'href' => 'https://remote.example/users/bob'],
				['rel' => 'http://ostatus.org/schema/1.0/subscribe', 'template' => 'https://remote.example/authorize_interaction?uri={uri}'],
			],
		]);

		$response = $this->controller->getLink('alice', 'bob@remote.example');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['result' => ['url' => 'https://remote.example/authorize_interaction?uri=alice@cloud.example'], 'status' => 1],
			$response->getData()
		);
	}

	public function testGetLinkFailsWhenRemoteHasNoSubscribeTemplate(): void {
		$this->accountService->method('getActor')->willReturn($this->actorWithAccount('alice@cloud.example'));
		$this->curlService->method('webfingerAccount')->willReturn(['links' => [['rel' => 'self', 'href' => 'x']]]);

		$this->assertFailure(
			$this->controller->getLink('alice', 'bob@remote.example'), RetrieveAccountFormatException::class,
			Http::STATUS_UNPROCESSABLE_ENTITY
		);
	}

	/**
	 * The template is the remote server's to write and the browser is sent
	 * to the result, so a result that is not a web address is refused the way
	 * a missing template is.
	 */
	public function testGetLinkRefusesATemplateWhoseResultIsNotAWebAddress(): void {
		$this->accountService->method('getActor')->willReturn($this->actorWithAccount('alice@cloud.example'));

		foreach (['javascript:alert(1)//{uri}', 'data:text/html,{uri}', 'ftp://remote.example/{uri}', '//remote.example/{uri}'] as $template) {
			$this->curlService = $this->createStub(CurlService::class);
			$this->curlService->method('webfingerAccount')->willReturn([
				'links' => [['rel' => 'http://ostatus.org/schema/1.0/subscribe', 'template' => $template]],
			]);
			$controller = new OStatusController(
				$this->createStub(IRequest::class), $this->initialState, $this->cacheActorService,
				$this->accountService, $this->curlService, $this->createStub(MiscService::class), $this->userSession
			);

			$this->assertFailure(
				$controller->getLink('alice', 'bob@remote.example'), RetrieveAccountFormatException::class,
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}
	}

	public function testGetLinkFailsWhenWebfingerFails(): void {
		$this->accountService->method('getActor')->willReturn($this->actorWithAccount('alice@cloud.example'));
		$this->curlService->method('webfingerAccount')->willThrowException(new \RuntimeException('unreachable'));

		$this->assertFailure($this->controller->getLink('alice', 'bob@remote.example'), \RuntimeException::class);
	}
}
