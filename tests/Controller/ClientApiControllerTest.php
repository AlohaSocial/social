<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\ClientApiController;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * The contract every client-API controller answers through: who the caller
 * is, whether their token may do this, and what a failure looks like.
 */
#[AllowMockObjectsWithoutExpectations]
class ClientApiControllerTest extends TestCase {
	private array $headers = [];
	private bool $csrf = true;
	private ?IUser $user = null;
	private ClientService $clientService;
	private AccountService $accountService;

	protected function setUp(): void {
		$this->clientService = $this->createMock(ClientService::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->accountService->method('getActorFromUserId')->willReturnCallback(function (string $userId): Person {
			$person = new Person();
			$person->setId('https://cloud.example/users/' . $userId);
			$person->setPreferredUsername($userId);

			return $person;
		});
	}

	private function controller(string $authorization = ''): ProbeController {
		$this->headers = ['Authorization' => $authorization];
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(fn (string $name): string => $this->headers[$name] ?? '');
		$request->method('passesCSRFCheck')->willReturnCallback(fn (): bool => $this->csrf);
		$request->method('getRequestUri')->willReturn('/api/v1/probe?limit=5&max_id=9&_route=x');
		\OC::$server->register(IRequest::class, $request);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->user);

		return new ProbeController($request, $session, new NullLogger(), $this->accountService, $this->clientService);
	}

	private function token(array $scopes): void {
		$client = new SocialClient();
		$client->setAuthUserId('alice');
		$client->setAuthScopes($scopes);
		$this->clientService->method('getFromToken')->with('t')->willReturn($client);
	}

	public static function scopeCases(): array {
		return [
			'the scope itself' => [['read:lists'], ['read:lists'], Http::STATUS_OK],
			'the broad scope containing it' => [['read'], ['read:lists'], Http::STATUS_OK],
			'a sibling is not the parent' => [['read:statuses'], ['read:lists'], Http::STATUS_FORBIDDEN],
			'a granular scope does not meet a broad requirement' => [['read:statuses'], ['read'], Http::STATUS_FORBIDDEN],
			'any one of several' => [['follow'], ['write:follows', 'follow'], Http::STATUS_OK],
			'no scope asked means any token' => [['write:favourites'], [], Http::STATUS_OK],
		];
	}

	#[DataProvider('scopeCases')]
	public function testScopes(array $granted, array $asked, int $status): void {
		$this->token($granted);

		$response = $this->controller('Bearer t')->probe($asked);

		$this->assertSame($status, $response->getStatus());
		if ($status === Http::STATUS_FORBIDDEN) {
			$this->assertArrayNotHasKey('WWW-Authenticate', $response->getHeaders());
		}
	}

	public function testASessionPassingItsCsrfCheckIsTheViewer(): void {
		$this->user = $this->createMock(IUser::class);
		$this->user->method('getUID')->willReturn('alice');

		$this->assertSame('alice', $this->controller()->probe(['read'])->getData()['viewer']);
	}

	public function testASessionFailingItsCsrfCheckIsNobody(): void {
		$this->user = $this->createMock(IUser::class);
		$this->user->method('getUID')->willReturn('alice');
		$this->csrf = false;

		$response = $this->controller()->probe(['read']);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('Bearer error="invalid_token"', $response->getHeaders()['WWW-Authenticate']);
	}

	public function testAnOptionalViewerLeavesACallerWithoutCredentialsAnonymous(): void {
		$this->assertNull($this->controller()->probe(['read'], false)->getData()['viewer']);
	}

	/** a token that said who it was and lacks the grant is refused, not downgraded */
	public function testAnOptionalViewerStillRefusesATokenWithoutTheScope(): void {
		$this->token(['write']);

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller('Bearer t')->probe(['read'], false)->getStatus());
	}

	public function testPrepareViewerRunsBeforeTheRouteAndItsFailureIsTheCredentialsFailing(): void {
		$this->token(['read']);
		$controller = $this->controller('Bearer t');
		$controller->failPreparing = true;

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->probe(['read'])->getStatus());
	}

	public static function errorCases(): array {
		return [
			'a missing record' => [new CacheActorDoesNotExistException('x'), Http::STATUS_NOT_FOUND, 'Record not found'],
			'an invalid resource' => [new InvalidResourceException('bad limit'), Http::STATUS_UNPROCESSABLE_ENTITY, 'bad limit'],
			'no credentials' => [new ClientNotFoundException(''), Http::STATUS_UNAUTHORIZED, 'the access_token is invalid'],
			'anything else says nothing about itself' => [new RuntimeException('SQLSTATE secret'), Http::STATUS_INTERNAL_SERVER_ERROR, 'internal server error'],
		];
	}

	#[DataProvider('errorCases')]
	public function testErrors(Throwable $e, int $status, string $message): void {
		$response = $this->controller()->fail($e);

		$this->assertSame($status, $response->getStatus());
		$this->assertSame($message, $response->getData()['error']);
	}

	public function testPageUrlKeepsTheFiltersAndReplacesTheCursor(): void {
		$this->assertSame('/api/v1/probe?limit=5&min_id=3', $this->controller()->page(['min_id' => '3']));
	}
}

/** The smallest controller there can be on the contract. */
class ProbeController extends ClientApiController {
	public bool $failPreparing = false;

	public function probe(array $scopes, bool $required = true): DataResponse {
		try {
			$this->initViewer($scopes, $required);

			return new DataResponse(['viewer' => $this->viewer?->getPreferredUsername()], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	public function fail(Throwable $e): DataResponse {
		return $this->error($e);
	}

	public function page(array $cursor): string {
		return $this->pageUrl($cursor);
	}

	#[\Override]
	protected function prepareViewer(Person $viewer): Person {
		if ($this->failPreparing) {
			throw new RuntimeException('the local actor is gone');
		}

		return $viewer;
	}
}
