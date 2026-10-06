<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Middleware;

use OCA\Social\Controller\ActivityPubController;
use OCA\Social\Controller\FilterController;
use OCA\Social\Controller\LocalController;
use OCA\Social\Controller\TimelineApiController;
use OCA\Social\Middleware\HideCountsMiddleware;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\CountsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class HideCountsMiddlewareTest extends TestCase {
	/** @var array<string, bool> user id => hides */
	private array $hides = ['alice' => true, 'bob' => false];
	private string $authorization = '';
	private ?string $sessionUser = 'alice';

	private function middleware(): HideCountsMiddleware {
		$counts = $this->createStub(CountsService::class);
		$counts->method('hides')->willReturnCallback(fn (string $userId): bool => $this->hides[$userId] ?? false);
		$counts->method('strip')->willReturnCallback(static fn (mixed $data): mixed => (new CountsService(
			(new \ReflectionClass(\OCA\Social\Service\ConfigService::class))->newInstanceWithoutConstructor()
		))->strip($data));

		$clients = $this->createStub(ClientService::class);
		$clients->method('getFromToken')->willReturnCallback(static function (string $token): SocialClient {
			if ($token !== 'bobs-token') {
				throw new \OCA\Social\Exceptions\ClientNotFoundException();
			}

			return (new SocialClient())->setAuthUserId('bob');
		});

		$request = $this->createStub(IRequest::class);
		$request->method('getHeader')->willReturnCallback(fn (string $name): string => $name === 'Authorization' ? $this->authorization : '');

		$session = $this->createStub(IUserSession::class);
		$user = $this->sessionUser === null ? null : $this->createStub(IUser::class);
		$user?->method('getUID')->willReturn($this->sessionUser);
		$session->method('getUser')->willReturn($user);

		return new HideCountsMiddleware($counts, $clients, $session, $request);
	}

	private static function post(): array {
		return ['id' => '1', 'favourites_count' => 12, 'reblogs_count' => 3, 'account' => ['acct' => 'carol', 'followers_count' => 40]];
	}

	private function after(string $controller, DataResponse|JSONResponse $response): DataResponse|JSONResponse {
		$result = $this->middleware()->afterController($this->createStub($controller), 'route', $response);
		$this->assertInstanceOf($response::class, $result);

		return $result;
	}

	private static function encoded(DataResponse|JSONResponse $response): array {
		return json_decode((string)json_encode($response->getData()), true);
	}

	/**
	 * What the after-middlewares are handed in a real request: Nextcloud has
	 * rendered the route's DataResponse into a JSONResponse by then. Found on
	 * devel, where every number still arrived.
	 */
	public function testTheRenderedJsonResponseOfARealRequestIsStrippedToo(): void {
		$out = self::encoded($this->after(TimelineApiController::class, new JSONResponse([self::post()])));

		$this->assertSame(0, $out[0]['favourites_count']);
		$this->assertSame(0, $out[0]['account']['followers_count']);
	}

	public function testTheReaderWhoHidesTheNumbersIsSentNone(): void {
		$out = self::encoded($this->after(TimelineApiController::class, new DataResponse([self::post()])));

		$this->assertSame(0, $out[0]['favourites_count']);
		$this->assertSame(0, $out[0]['reblogs_count']);
		$this->assertSame(0, $out[0]['account']['followers_count']);
	}

	/** Every family of client-API controller, the web app's own routes included. */
	public function testEveryClientApiIsCovered(): void {
		foreach ([FilterController::class, LocalController::class] as $controller) {
			$this->assertSame(0, self::encoded($this->after($controller, new DataResponse(self::post())))['favourites_count'], $controller);
		}
	}

	public function testAReaderWhoShowsTheNumbersGetsThem(): void {
		$this->sessionUser = 'bob';

		$this->assertSame(12, self::encoded($this->after(TimelineApiController::class, new DataResponse(self::post())))['favourites_count']);
	}

	/** A phone app is the token's account, whoever is signed in to the browser. */
	public function testAnAppIsAnsweredForTheAccountItsTokenBelongsTo(): void {
		$this->authorization = 'Bearer bobs-token';
		$this->assertSame(12, self::encoded($this->after(TimelineApiController::class, new DataResponse(self::post())))['favourites_count']);

		$this->hides['bob'] = true;
		$this->assertSame(0, self::encoded($this->after(TimelineApiController::class, new DataResponse(self::post())))['favourites_count']);
	}

	public function testATokenNobodyHoldsChangesNothing(): void {
		$this->authorization = 'Bearer made-up';

		$this->assertSame(12, self::encoded($this->after(TimelineApiController::class, new DataResponse(self::post())))['favourites_count']);
	}

	/** An actor document is answered to another server, not to this reader. */
	public function testWhatIsAnsweredToOtherServersIsLeftAlone(): void {
		$this->assertSame(12, self::encoded($this->after(ActivityPubController::class, new DataResponse(self::post())))['favourites_count']);
	}

	public function testAnErrorIsLeftAsItIs(): void {
		$response = new DataResponse(['error' => 'nope', 'favourites_count' => 1, 'reblogs_count' => 1], Http::STATUS_NOT_FOUND);

		$this->assertSame(['error' => 'nope', 'favourites_count' => 1, 'reblogs_count' => 1], $this->after(TimelineApiController::class, $response)->getData());
	}
}
