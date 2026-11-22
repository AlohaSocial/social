<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\StoryController;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\StoryService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * `/api/v1/stories`: who may see and post stories.
 *
 * Every route needs a viewer, even reading somebody else's stories: who may
 * see a story depends on who is looking, so an anonymous caller is refused
 * before the story layer is asked anything, rather than answered with
 * whatever an empty viewer would match.
 */
#[AllowMockObjectsWithoutExpectations]
class StoryControllerTest extends TestCase {
	private array $headers = [];
	private bool $csrf = true;
	private ?IUser $user = null;
	private ClientService&MockObject $clientService;
	private StoryService&MockObject $stories;
	private CacheActorService&MockObject $cacheActorService;

	protected function setUp(): void {
		$this->clientService = $this->createMock(ClientService::class);
		$this->stories = $this->createMock(StoryService::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
	}

	/** @return array<string, array{callable(StoryController): DataResponse}> */
	public static function routes(): array {
		return [
			'carousel' => [static fn (StoryController $c): DataResponse => $c->carousel()],
			'own' => [static fn (StoryController $c): DataResponse => $c->own()],
			'add' => [static fn (StoryController $c): DataResponse => $c->add(3, 'hello')],
			'destroy' => [static fn (StoryController $c): DataResponse => $c->destroy(3)],
			'seen' => [static fn (StoryController $c): DataResponse => $c->seen(3)],
			'by account' => [static fn (StoryController $c): DataResponse => $c->byAccount('42')],
		];
	}

	#[DataProvider('routes')]
	public function testAnAnonymousCallerIsRefusedBeforeAnyStoryIsRead(callable $call): void {
		$this->expectNoStoryCall();

		$response = $call($this->controller());

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	#[DataProvider('routes')]
	public function testASessionWithoutItsCsrfTokenIsRefused(callable $call): void {
		$this->user = $this->createStub(IUser::class);
		$this->user->method('getUID')->willReturn('alice');
		$this->csrf = false;
		$this->expectNoStoryCall();

		$response = $call($this->controller());

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	/** @return array<string, array{callable(StoryController): DataResponse}> */
	public static function changes(): array {
		$routes = self::routes();

		return ['add' => $routes['add'], 'destroy' => $routes['destroy'], 'seen' => $routes['seen']];
	}

	#[DataProvider('changes')]
	public function testAReadOnlyTokenCannotPostDeleteOrMarkAStory(callable $call): void {
		$this->token(['read']);
		$this->expectNoStoryCall();

		$response = $call($this->controller('Bearer t'));

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	/** Somebody else's stories are read as the viewer, not as their owner. */
	public function testAnotherAccountsStoriesAreReadAsTheViewer(): void {
		$this->token(['read:stories']);
		$owner = self::person('bob');
		$this->cacheActorService->method('resolve')->with('42')->willReturn($owner);
		$this->stories->expects($this->once())->method('forAccount')
			->with(
				$this->callback(fn (Person $viewer): bool => $viewer->getId() === 'https://cloud.example/users/alice'),
				$owner
			)
			->willReturn([]);

		$response = $this->controller('Bearer t')->byAccount('42');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	private function expectNoStoryCall(): void {
		$this->stories->expects($this->never())->method($this->anything());
		$this->cacheActorService->expects($this->never())->method('resolve');
	}

	/** @param list<string> $scopes */
	private function token(array $scopes): void {
		$client = new SocialClient();
		$client->setAuthUserId('alice');
		$client->setAuthScopes($scopes);
		$this->clientService->method('getFromTokenOfRequest')->with('t')->willReturn($client);
	}

	private function controller(string $authorization = ''): StoryController {
		$this->headers = ['Authorization' => $authorization];

		$request = $this->createStub(IRequest::class);
		$request->method('getHeader')->willReturnCallback(fn (string $name): string => $this->headers[$name] ?? '');
		$request->method('passesCSRFCheck')->willReturnCallback(fn (): bool => $this->csrf);

		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->user);

		$accountService = $this->createStub(AccountService::class);
		$accountService->method('getActorFromUserId')->willReturnCallback(fn (string $userId): Person => self::person($userId));

		return new StoryController(
			$request, $session, new NullLogger(), $accountService, $this->clientService,
			$this->cacheActorService, $this->stories,
		);
	}

	private static function person(string $username): Person {
		$person = new Person();
		$person->setId('https://cloud.example/users/' . $username);
		$person->setPreferredUsername($username);

		return $person;
	}
}
