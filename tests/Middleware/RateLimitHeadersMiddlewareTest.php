<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Middleware;

use OCA\Social\Middleware\RateLimitHeadersMiddleware;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\RateLimitService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * A controller with one route of each kind, for the reflection to read.
 */
class RateLimitedController extends Controller {
	#[UserRateLimit(limit: 60, period: 60)]
	public function limited(): DataResponse {
		return new DataResponse([]);
	}

	#[UserRateLimit(limit: 10, period: 60)]
	#[AnonRateLimit(limit: 3, period: 60)]
	public function both(): DataResponse {
		return new DataResponse([]);
	}

	public function unlimited(): DataResponse {
		return new DataResponse([]);
	}
}

/**
 * What a client is told about its budget, and what it is not told.
 */
#[AllowMockObjectsWithoutExpectations]
class RateLimitHeadersMiddlewareTest extends TestCase {
	private IUserSession|Stub $userSession;
	private ICacheFactory|Stub $cacheFactory;
	private IRequest|MockObject $request;
	private array $store = [];
	private RateLimitHeadersMiddleware $middleware;
	private RateLimitedController $controller;
	/** @var array<string, string> the app's own rate-limit values */
	private array $appValues = [
		ConfigService::SOCIAL_RATE_LIMIT_USER => '900',
		ConfigService::SOCIAL_RATE_LIMIT_ANON => '300',
		ConfigService::SOCIAL_RATE_LIMIT_WINDOW => '300',
	];

	protected function tearDown(): void {
		\OC::$server->reset();
		parent::tearDown();
	}

	protected function setUp(): void {
		parent::setUp();

		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn('203.0.113.7');
		$request->method('getId')->willReturn('test-request');
		// Response::getHeaders() resolves IRequest out of the container for
		// the X-Request-Id it merges in
		\OC::$server->register(IRequest::class, $request);

		$cache = $this->createStub(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $k) => $this->store[$k] ?? null);
		$cache->method('set')->willReturnCallback(
			function (string $k, $v): bool {
				$this->store[$k] = $v;

				return true;
			}
		);

		$this->cacheFactory = $this->createStub(ICacheFactory::class);
		$this->cacheFactory->method('createDistributed')->willReturn($cache);
		$this->cacheFactory->method('createLocal')->willReturn($cache);
		$this->cacheFactory->method('isAvailable')->willReturn(true);

		$this->userSession = $this->createStub(IUserSession::class);
		$this->controller = new RateLimitedController('social', $request);

		$this->request = $request;
		$this->middleware = new RateLimitHeadersMiddleware(
			$this->userSession, $this->service($this->cacheFactory)
		);
	}

	private function service(ICacheFactory|MockObject $cacheFactory): RateLimitService {
		$configService = $this->createStub(ConfigService::class);
		$configService->method('getAppValue')
			->willReturnCallback(fn (string $key): string => $this->appValues[$key] ?? '');

		return new RateLimitService(
			$this->request, $this->userSession, $cacheFactory, $configService
		);
	}

	private function signedInAs(string $uid): void {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	private function call(string $method): DataResponse {
		/** @var DataResponse $response */
		$response = $this->middleware->afterController(
			$this->controller, $method, new DataResponse([])
		);

		return $response;
	}

	public function testTheBudgetOnTheRouteIsWhatIsReported(): void {
		$this->signedInAs('alice');

		$headers = $this->call('limited')->getHeaders();

		$this->assertSame('60', $headers['X-RateLimit-Limit']);
		$this->assertSame('59', $headers['X-RateLimit-Remaining'], 'this request is one of them');
	}

	/** The point of the header: a client can see the budget going down. */
	public function testEachRequestSpendsOneOfThem(): void {
		$this->signedInAs('alice');

		$this->call('limited');
		$this->call('limited');
		$headers = $this->call('limited')->getHeaders();

		$this->assertSame('57', $headers['X-RateLimit-Remaining']);
	}

	/** One account's spending is not another's. */
	public function testTwoAccountsHaveTwoBudgets(): void {
		$user = $this->createStub(IUser::class);
		$uid = 'alice';
		$user->method('getUID')->willReturnCallback(static function () use (&$uid): string {
			return $uid;
		});
		$this->userSession->method('getUser')->willReturn($user);

		$this->call('limited');
		$this->call('limited');

		$uid = 'bob';
		$headers = $this->call('limited')->getHeaders();

		$this->assertSame('59', $headers['X-RateLimit-Remaining']);
	}

	/** And neither is one route's the other's. */
	public function testTwoRoutesHaveTwoBudgets(): void {
		$this->signedInAs('alice');

		$this->call('limited');
		$headers = $this->call('both')->getHeaders();

		$this->assertSame('10', $headers['X-RateLimit-Limit']);
		$this->assertSame('9', $headers['X-RateLimit-Remaining']);
	}

	/**
	 * Where a route carries both, the one that applies is the one Nextcloud
	 * would enforce for this caller — otherwise the header and the refusal
	 * would be describing different budgets.
	 */
	public function testAnAnonymousCallerIsToldTheAnonymousBudget(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$headers = $this->call('both')->getHeaders();

		$this->assertSame('3', $headers['X-RateLimit-Limit']);
	}

	/**
	 * A route that declares no limit of its own is governed by the app's
	 * default, and a budget a caller can be refused against is one worth
	 * publishing.
	 */
	public function testARouteWithNoLimitOfItsOwnIsToldTheAppsDefault(): void {
		$this->signedInAs('alice');

		$headers = $this->call('unlimited')->getHeaders();

		$this->assertSame('900', $headers['X-RateLimit-Limit']);
	}

	public function testAnAnonymousCallerGetsTheSmallerDefault(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->assertSame('300', $this->call('unlimited')->getHeaders()['X-RateLimit-Limit']);
	}

	/**
	 * "Unlimited" and "I did not measure" are different answers, and a client
	 * should be able to tell them apart — so an instance that has switched the
	 * default off publishes nothing rather than a made-up budget.
	 */
	public function testARouteWithNoLimitAnywhereIsGivenNoHeaders(): void {
		$this->appValues[ConfigService::SOCIAL_RATE_LIMIT_USER] = '0';
		$this->signedInAs('alice');

		$headers = $this->call('unlimited')->getHeaders();

		$this->assertArrayNotHasKey('X-RateLimit-Limit', $headers);
		$this->assertArrayNotHasKey('X-RateLimit-Remaining', $headers);
	}

	/**
	 * The default is counted by the middleware that enforces it; counting it a
	 * second time here would tell a client it had spent twice what it had.
	 */
	public function testTheDefaultBudgetIsReadRatherThanSpentTwice(): void {
		$this->signedInAs('alice');

		$this->call('unlimited');
		$headers = $this->call('unlimited')->getHeaders();

		$this->assertSame('900', $headers['X-RateLimit-Remaining'], 'the headers spent the budget');
	}

	/** The reset is when the window ends, in seconds since the epoch. */
	public function testTheResetIsTheEndOfTheWindowThisRequestFellIn(): void {
		$this->signedInAs('alice');

		$reset = (int)$this->call('limited')->getHeaders()['X-RateLimit-Reset'];

		$this->assertGreaterThan(time(), $reset);
		$this->assertLessThanOrEqual(time() + 60, $reset);
		$this->assertSame(0, $reset % 60, 'the window boundary, not "now plus a minute"');
	}

	/** Remaining never goes below zero, whatever the counter says. */
	public function testTheRemainingBudgetIsNeverNegative(): void {
		$this->signedInAs('alice');

		for ($i = 0; $i < 12; $i++) {
			$headers = $this->call('both')->getHeaders();
		}

		$this->assertSame('0', $headers['X-RateLimit-Remaining']);
	}

	/**
	 * Found on devel, which has no memcache: `createDistributed()` hands back
	 * a cache that stores nothing, so the count came back 1 on every request
	 * and two posts in a row both reported 29 left.
	 *
	 * A number that was never measured is worse than no number. The budget is
	 * still published — a client reading `Limit` without `Remaining` knows
	 * what it has and counts its own requests, which is what it did before
	 * these headers existed.
	 */
	public function testAnInstanceWithNoCacheIsToldTheBudgetAndNotTheSpending(): void {
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturn(false);
		$cacheFactory->method('isLocalCacheAvailable')->willReturn(false);
		$cacheFactory->expects($this->never())->method('createDistributed');

		$middleware = new RateLimitHeadersMiddleware(
			$this->userSession, $this->service($cacheFactory)
		);
		$this->signedInAs('alice');

		$headers = $middleware
			->afterController($this->controller, 'limited', new DataResponse([]))
			->getHeaders();

		$this->assertSame('60', $headers['X-RateLimit-Limit']);
		$this->assertArrayNotHasKey('X-RateLimit-Remaining', $headers);
		$this->assertArrayNotHasKey('X-RateLimit-Reset', $headers);
	}

	/**
	 * A local cache is the honest second best: the count is per web server, so
	 * a client behind a load balancer sees a budget that looks larger than it
	 * is — which errs towards pacing rather than towards being refused.
	 */
	public function testWithoutADistributedCacheItFallsBackToTheLocalOne(): void {
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturn(false);
		$cacheFactory->method('isLocalCacheAvailable')->willReturn(true);
		$cacheFactory->expects($this->once())->method('createLocal')
			->willReturn($this->createStub(ICache::class));

		$middleware = new RateLimitHeadersMiddleware(
			$this->userSession, $this->service($cacheFactory)
		);
		$this->signedInAs('alice');

		$headers = $middleware
			->afterController($this->controller, 'limited', new DataResponse([]))
			->getHeaders();

		$this->assertArrayHasKey('X-RateLimit-Remaining', $headers);
	}
}
