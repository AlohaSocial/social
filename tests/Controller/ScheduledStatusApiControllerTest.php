<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\ScheduledStatusApiController;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\ScheduledStatusService;
use OCA\Social\Service\StreamService;
use OCA\Social\Tests\Helper\RoutedRequest;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * `/api/v1/scheduled_statuses`: posts waiting to go out, which nobody but
 * their author may list, read, move or cancel.
 *
 * The routes are public so a bearer token can reach them, so the refusals
 * here are the controller's own: no credentials, a session cookie without
 * its CSRF token (which is what a cross-site form sends), and a token that
 * may only read asking to change something.
 */
#[AllowMockObjectsWithoutExpectations]
class ScheduledStatusApiControllerTest extends TestCase {
	private array $headers = [];
	private bool $csrf = true;
	private ?IUser $user = null;
	private string $verb = 'GET';
	private string $route = '';
	private ClientService&MockObject $clientService;
	private ScheduledStatusService&MockObject $scheduled;

	protected function setUp(): void {
		$this->clientService = $this->createMock(ClientService::class);
		$this->scheduled = $this->createMock(ScheduledStatusService::class);
	}

	/** @return array<string, array{string, string, callable(ScheduledStatusApiController): DataResponse}> */
	public static function routes(): array {
		return [
			'list' => ['GET', 'scheduledStatuses', static fn (ScheduledStatusApiController $c): DataResponse => $c->scheduledStatuses()],
			'read one' => ['GET', 'scheduledStatusGet', static fn (ScheduledStatusApiController $c): DataResponse => $c->scheduledStatusGet(7)],
			'reschedule' => ['PUT', 'scheduledStatusUpdate', static fn (ScheduledStatusApiController $c): DataResponse => $c->scheduledStatusUpdate(7)],
			'cancel' => ['DELETE', 'scheduledStatusDelete', static fn (ScheduledStatusApiController $c): DataResponse => $c->scheduledStatusDelete(7)],
		];
	}

	#[DataProvider('routes')]
	public function testAnAnonymousCallerIsRefusedBeforeAnyScheduledPostIsRead(string $verb, string $route, callable $call): void {
		$this->expectNoServiceCall();

		$response = $call($this->controller($verb, $route));

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	/** A cookie without the CSRF token is what a cross-site form carries. */
	#[DataProvider('routes')]
	public function testASessionWithoutItsCsrfTokenIsRefused(string $verb, string $route, callable $call): void {
		$this->user = $this->createStub(IUser::class);
		$this->user->method('getUID')->willReturn('alice');
		$this->csrf = false;
		$this->expectNoServiceCall();

		$response = $call($this->controller($verb, $route));

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	/** @return array<string, array{string, string, callable(ScheduledStatusApiController): DataResponse}> */
	public static function changes(): array {
		$routes = self::routes();

		return ['reschedule' => $routes['reschedule'], 'cancel' => $routes['cancel']];
	}

	#[DataProvider('changes')]
	public function testAReadOnlyTokenCannotMoveOrCancelAScheduledPost(string $verb, string $route, callable $call): void {
		$this->token(['read']);
		$this->expectNoServiceCall();

		$response = $call($this->controller($verb, $route, 'Bearer t'));

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	/** The account the service is asked about is the token's, never a parameter. */
	public function testAReadTokenListsTheScheduledPostsOfItsOwnAccount(): void {
		$this->token(['read:statuses']);
		$this->scheduled->expects($this->once())->method('getAll')
			->with($this->callback(fn (Person $actor): bool => $actor->getId() === 'https://cloud.example/users/alice'))
			->willReturn([['id' => '7']]);

		$response = $this->controller('GET', 'scheduledStatuses', 'Bearer t')->scheduledStatuses();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([['id' => '7']], $response->getData());
	}

	private function expectNoServiceCall(): void {
		$this->scheduled->expects($this->never())->method($this->anything());
	}

	/** @param list<string> $scopes */
	private function token(array $scopes): void {
		$client = new SocialClient();
		$client->setAuthUserId('alice');
		$client->setAuthScopes($scopes);
		$this->clientService->method('getFromToken')->with('t')->willReturn($client);
	}

	private function controller(string $verb, string $route, string $authorization = ''): ScheduledStatusApiController {
		$this->headers = ['Authorization' => $authorization];
		$this->verb = $verb;
		$this->route = 'social.ScheduledStatusApi.' . $route;

		$request = $this->createStub(RoutedRequest::class);
		$request->urlParams = ['_route' => $this->route];
		$request->method('getHeader')->willReturnCallback(fn (string $name): string => $this->headers[$name] ?? '');
		$request->method('passesCSRFCheck')->willReturnCallback(fn (): bool => $this->csrf);
		$request->method('getMethod')->willReturnCallback(fn (): string => $this->verb);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($key === '_route') ? $this->route : $default
		);

		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->user);

		$accountService = $this->createStub(AccountService::class);
		$accountService->method('getActorFromUserId')->willReturnCallback(fn (string $userId): Person => self::person($userId));
		$cacheActorService = $this->createStub(CacheActorService::class);
		$cacheActorService->method('getFromLocalAccount')->willReturnCallback(fn (string $username): Person => self::person($username));

		return new ScheduledStatusApiController(
			$request,
			$this->createStub(IURLGenerator::class),
			$session,
			new NullLogger(),
			$this->clientService,
			$accountService,
			$cacheActorService,
			$this->createStub(StreamService::class),
			$this->createStub(FollowService::class),
			$this->scheduled,
		);
	}

	private static function person(string $username): Person {
		$person = new Person();
		$person->setId('https://cloud.example/users/' . $username);
		$person->setPreferredUsername($username);
		$person->setUserId($username);

		return $person;
	}
}
