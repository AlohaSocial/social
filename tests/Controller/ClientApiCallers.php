<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * The three ways a client-API controller is called, for the unit tests of
 * one: a bearer token with the scopes it was granted, the web page's session
 * with or without its CSRF token, and nobody.
 *
 * `setUpCallers()` builds the doubles and starts as nobody; the test then
 * picks a caller before it builds the controller. `$clientService` is a mock,
 * so a test may also expect calls on it.
 */
trait ClientApiCallers {
	private IRequest $request;
	private IUserSession $userSession;
	private AccountService $accountService;
	private ClientService&MockObject $clientService;

	/** @var array<string, mixed> the parameters the request carries */
	private array $params = [];
	private string $authorization = '';
	private bool $csrf = true;
	private ?string $sessionUid = null;

	private function setUpCallers(): void {
		$request = $this->createStub(IRequest::class);
		$request->method('getId')->willReturn('test');
		$request->method('getHeader')->willReturnCallback(
			fn (string $name): string => ($name === 'Authorization') ? $this->authorization : ''
		);
		$request->method('passesCSRFCheck')->willReturnCallback(fn (): bool => $this->csrf);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => $this->params[$key] ?? $default
		);
		$this->request = $request;
		// Response::getHeaders() asks the container for the request
		\OC::$server->register(IRequest::class, $request);

		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturnCallback(function (): ?IUser {
			if ($this->sessionUid === null) {
				return null;
			}
			$user = $this->createStub(IUser::class);
			$user->method('getUID')->willReturn($this->sessionUid);

			return $user;
		});
		$this->userSession = $session;

		$accounts = $this->createStub(AccountService::class);
		$accounts->method('getActorFromUserId')->willReturnCallback(static function (string $userId): Person {
			$person = new Person();
			$person->setId('https://cloud.example/users/' . $userId);
			$person->setUserId($userId);
			$person->setPreferredUsername($userId);

			return $person;
		});
		$this->accountService = $accounts;

		$this->clientService = $this->createMock(ClientService::class);
		$this->clientService->method('getFromTokenOfRequest')->willReturnCallback(function (string $token): SocialClient {
			if ($token !== 'granted' || $this->token === null) {
				throw new ClientNotFoundException();
			}

			return $this->token;
		});
	}

	private ?SocialClient $token = null;

	/**
	 * A bearer token of `$uid`'s, granted `$scopes`, behind the authorization
	 * row `$authId`.
	 *
	 * @param string[] $scopes
	 */
	private function asToken(array $scopes, string $uid = 'alice', int $authId = 1): void {
		$this->token = (new SocialClient())->setAuthId($authId);
		$this->token->setAuthUserId($uid);
		$this->token->setAuthScopes($scopes);
		$this->authorization = 'Bearer granted';
		$this->sessionUid = null;
	}

	/** Signed in to Nextcloud, as the web page or an app password is. */
	private function asSession(string $uid = 'alice', bool $csrf = true): void {
		$this->sessionUid = $uid;
		$this->csrf = $csrf;
		$this->authorization = '';
		$this->token = null;
	}

	private function asNobody(): void {
		$this->sessionUid = null;
		$this->authorization = '';
		$this->token = null;
	}
}
