<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Middleware;

use OCA\Social\Controller\ActivityPubController;
use OCA\Social\Controller\NavigationController;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Middleware\AnonymousRateLimit;
use OCA\Social\Middleware\ApiRateLimitMiddleware;
use OCA\Social\Middleware\RateLimitedException;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\RateLimitService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\RateLimiting\ILimiter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** A controller with one route of each kind, for the reflection to read. */
class BudgetedController extends Controller {
	#[UserRateLimit(limit: 3, period: 60)]
	public function limited(): DataResponse {
		return new DataResponse([]);
	}

	public function unlimited(): DataResponse {
		return new DataResponse([]);
	}

	#[UserRateLimit(limit: 10, period: 60)]
	#[AnonymousRateLimit(limit: 4, period: 120)]
	public function both(): DataResponse {
		return new DataResponse([]);
	}

	#[AnonymousRateLimit(limit: 6, period: 60)]
	public function anonOnly(): DataResponse {
		return new DataResponse([]);
	}
}

/**
 * The budget for the three hundred routes nobody gave one to.
 *
 * An unauthenticated caller could page through every public timeline, profile
 * and hashtag on the instance as fast as the database would answer, and
 * nothing anywhere counted. What is asserted here is that something does now —
 * and, as carefully, everything this must not start counting.
 */
class ApiRateLimitMiddlewareTest extends TestCase {
	private IUserSession|Stub $userSession;
	private ICacheFactory|Stub $cacheFactory;
	private IRequest|Stub $request;
	private ApiRateLimitMiddleware $middleware;
	private BudgetedController $controller;
	/** @var list<array{string, int, int, string}> what Nextcloud's limiter was asked to count */
	private array $anonRequests = [];

	/** the Authorization header the request carries */
	private string $authorization = '';
	/** @var array<string, mixed> the counter */
	private array $store = [];
	/** @var array<string, string> the app's rate-limit values */
	private array $appValues = [
		ConfigService::SOCIAL_RATE_LIMIT_USER => '5',
		ConfigService::SOCIAL_RATE_LIMIT_ANON => '2',
		ConfigService::SOCIAL_RATE_LIMIT_WINDOW => '60',
	];

	protected function tearDown(): void {
		\OC::$server->reset();
		parent::tearDown();
	}

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createStub(IRequest::class);
		$this->request->method('getRemoteAddress')->willReturn('203.0.113.7');
		$this->request->method('getId')->willReturn('test-request');
		$this->request->method('getHeader')->willReturnCallback(
			fn (string $name): string => ($name === 'Authorization') ? $this->authorization : ''
		);
		\OC::$server->register(IRequest::class, $this->request);

		$cache = $this->createStub(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $k) => $this->store[$k] ?? null);
		$cache->method('set')->willReturnCallback(function (string $k, $v): bool {
			$this->store[$k] = $v;

			return true;
		});

		$this->cacheFactory = $this->createStub(ICacheFactory::class);
		$this->cacheFactory->method('isAvailable')->willReturn(true);
		$this->cacheFactory->method('createDistributed')->willReturn($cache);

		$this->userSession = $this->createStub(IUserSession::class);
		$this->controller = new BudgetedController('social', $this->request);
		$this->middleware = $this->middleware($this->service($this->cacheFactory));
	}

	private function middleware(RateLimitService $service): ApiRateLimitMiddleware {
		$limiter = $this->createStub(ILimiter::class);
		$limiter->method('registerAnonRequest')->willReturnCallback(
			function (string $identifier, int $limit, int $period, string $ip): void {
				$this->anonRequests[] = [$identifier, $limit, $period, $ip];
			}
		);

		return new ApiRateLimitMiddleware($service, $limiter, $this->request);
	}

	private function service(ICacheFactory|MockObject $cacheFactory): RateLimitService {
		$configService = $this->createStub(ConfigService::class);
		$configService->method('getAppValue')
			->willReturnCallback(fn (string $key): string => $this->appValues[$key] ?? '');

		$clients = $this->createStub(ClientService::class);
		$clients->method('getFromTokenOfRequest')->willReturnCallback(static function (string $token): SocialClient {
			if (!str_starts_with($token, 't-')) {
				throw new ClientNotFoundException();
			}

			return (new SocialClient())->setAuthUserId(substr($token, 2));
		});

		return new RateLimitService($this->request, $this->userSession, $cacheFactory, $configService, null, $clients);
	}

	private function signedInAs(string $uid): void {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	private function callTimes(int $times, string $method = 'unlimited', ?Controller $controller = null): void {
		for ($i = 0; $i < $times; $i++) {
			$this->middleware->beforeController($controller ?? $this->controller, $method);
		}
	}

	public function testACallerWithinItsBudgetIsLetThrough(): void {
		$this->signedInAs('alice');

		$this->callTimes(5);

		$this->expectNotToPerformAssertions();
	}

	public function testACallerPastItsBudgetIsRefused(): void {
		$this->signedInAs('alice');
		$this->callTimes(5);

		$this->expectException(RateLimitedException::class);
		$this->middleware->beforeController($this->controller, 'unlimited');
	}

	/** Anonymous callers get the smaller of the two budgets. */
	public function testAnAnonymousCallerIsHeldToTheAnonymousBudget(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->callTimes(2);

		$this->expectException(RateLimitedException::class);
		$this->middleware->beforeController($this->controller, 'unlimited');
	}

	/** One account's spending is not another's. */
	public function testTwoAccountsHaveTwoBudgets(): void {
		$user = $this->createStub(IUser::class);
		$uid = 'alice';
		$user->method('getUID')->willReturnCallback(function () use (&$uid): string {
			return $uid;
		});
		$this->userSession->method('getUser')->willReturn($user);

		$this->callTimes(5);
		$uid = 'bob';
		$this->middleware->beforeController($this->controller, 'unlimited');

		$this->expectNotToPerformAssertions();
	}

	/**
	 * One budget for the whole API, not one per route: a caller reading the
	 * instance spends the same budget whichever route it reads through, and a
	 * per-route default would multiply the allowance by the number of routes
	 * there happen to be.
	 */
	public function testTheBudgetIsForTheApiRatherThanForEachRoute(): void {
		$this->signedInAs('alice');
		$this->callTimes(3, 'unlimited');
		$this->callTimes(2, 'alsoUnlimited', new AnotherController('social', $this->request));

		$this->expectException(RateLimitedException::class);
		$this->middleware->beforeController($this->controller, 'unlimited');
	}

	/**
	 * Nextcloud already counts a route that declares its own limit, and a
	 * second limiter over the top would refuse at a number neither publishes.
	 */
	public function testARouteWithItsOwnLimitIsLeftToNextcloud(): void {
		$this->signedInAs('alice');

		$this->callTimes(50, 'limited');

		$this->assertSame([], $this->store, 'the app counted a route Nextcloud is counting');
	}

	/**
	 * Nextcloud's limiter sees no session on a bearer-token request and
	 * never applies a route's per-account limit to it, so the app does.
	 */
	public function testARoutesOwnLimitHoldsForABearerToken(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->authorization = 'Bearer t-alice';

		$this->callTimes(3, 'limited');

		$this->expectException(RateLimitedException::class);
		$this->middleware->beforeController($this->controller, 'limited');
	}

	/** And the token spends its account's budget for the rest of the API. */
	public function testABearerTokenHasTheAccountBudget(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->authorization = 'Bearer t-alice';
		$this->callTimes(5);

		$this->authorization = '';
		$this->middleware = $this->middleware($this->service($this->cacheFactory));
		$this->callTimes(2);

		$this->expectException(RateLimitedException::class);
		$this->middleware->beforeController($this->controller, 'unlimited');
	}

	/**
	 * Somebody who is nobody is held to the route's anonymous limit, by
	 * Nextcloud's own limiter under the name Nextcloud gives the route, so the
	 * count and the refusal are what they were with `AnonRateLimit`.
	 */
	public function testAnAnonymousCallerIsHeldToTheRoutesAnonymousLimit(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->callTimes(1, 'both');

		$this->assertSame([[BudgetedController::class . '::both', 4, 120, '203.0.113.7']], $this->anonRequests);
	}

	/** A token that names nobody, or nobody known, is anonymous too. */
	public function testAnInvalidTokenIsHeldToTheAnonymousLimit(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->authorization = 'Bearer made-up';

		$this->callTimes(1, 'both');

		$this->assertCount(1, $this->anonRequests);
	}

	/**
	 * An account, by its session or its token, is held to the route's
	 * per-account limit and not to the address's, so people behind one
	 * address do not use up each other's posts.
	 */
	public function testAnAccountIsNotHeldToTheAnonymousLimitWhereTheRouteHasItsOwn(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->authorization = 'Bearer t-alice';
		$this->callTimes(3, 'both');

		$this->authorization = '';
		$this->signedInAs('bob');
		$this->callTimes(3, 'both');

		$this->assertSame([], $this->anonRequests);
	}

	/** As Nextcloud does, a route with only an anonymous limit holds everybody to it. */
	public function testARouteWithOnlyAnAnonymousLimitHoldsEverybodyToIt(): void {
		$this->signedInAs('alice');

		$this->callTimes(1, 'anonOnly');

		$this->assertSame([[BudgetedController::class . '::anonOnly', 6, 60, '203.0.113.7']], $this->anonRequests);
	}

	/**
	 * Deliveries arrive in bursts from a handful of addresses, and one busy
	 * peer would exhaust an address budget for every other peer behind the
	 * same relay. The inbox is limited per origin host instead.
	 */
	public function testFederationIsNotMeasuredAsIfItWereAClient(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$inbox = $this->createStub(ActivityPubController::class);

		$this->callTimes(20, 'sharedInbox', $inbox);

		$this->assertSame([], $this->store);
	}

	/**
	 * One public page is forty requests for pictures; counting those against
	 * a page budget would refuse the page rather than slow the caller.
	 */
	public function testServingBytesDoesNotSpendAReadersBudget(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$navigation = $this->createStub(NavigationController::class);

		$this->callTimes(20, 'documentGetPublic', $navigation);

		$this->assertSame([], $this->store);
	}

	/** An instance behind its own limiter switches the default off. */
	public function testAnInstanceCanSwitchTheDefaultOff(): void {
		$this->appValues[ConfigService::SOCIAL_RATE_LIMIT_USER] = '0';
		$this->signedInAs('alice');

		$this->callTimes(50);

		$this->assertSame([], $this->store);
	}

	/**
	 * A limiter that cannot count must not guess: refusing on a guess would
	 * take the app away from the instances least able to debug it.
	 */
	public function testAnInstanceWithNoCacheRefusesNobody(): void {
		$cacheFactory = $this->createStub(ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturn(false);
		$cacheFactory->method('isLocalCacheAvailable')->willReturn(false);
		$middleware = $this->middleware($this->service($cacheFactory));
		$this->signedInAs('alice');

		for ($i = 0; $i < 50; $i++) {
			$middleware->beforeController($this->controller, 'unlimited');
		}

		$this->expectNotToPerformAssertions();
	}

	/** What a client reads to decide when to come back. */
	public function testTheRefusalSaysWhenToTryAgain(): void {
		$reset = time() + 42;

		/** @var JSONResponse $response */
		$response = $this->middleware->afterException(
			$this->controller, 'unlimited', new RateLimitedException(5, 60, $reset)
		);
		$headers = $response->getHeaders();

		$this->assertSame(Http::STATUS_TOO_MANY_REQUESTS, $response->getStatus());
		$this->assertSame('5', $headers['X-RateLimit-Limit']);
		$this->assertSame('0', $headers['X-RateLimit-Remaining']);
		$this->assertSame((string)$reset, $headers['X-RateLimit-Reset']);
		$this->assertLessThanOrEqual(42, (int)$headers['Retry-After']);
		$this->assertGreaterThan(0, (int)$headers['Retry-After']);
	}

	/** Every other failure is somebody else's to answer. */
	public function testAnyOtherFailureIsPassedOn(): void {
		$this->expectException(RuntimeException::class);
		$this->middleware->afterException($this->controller, 'unlimited', new RuntimeException('boom'));
	}
}

/** A second controller, to show the budget is shared across routes. */
class AnotherController extends Controller {
	public function alsoUnlimited(): DataResponse {
		return new DataResponse([]);
	}
}
