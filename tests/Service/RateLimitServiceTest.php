<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Controller\AccountApiController;
use OCA\Social\Controller\ActivityPubController;
use OCA\Social\Controller\ApiController;
use OCA\Social\Controller\InstanceApiController;
use OCA\Social\Controller\MediaApiController;
use OCA\Social\Controller\QueueController;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\RateLimitService;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The budget every API request is counted against.
 *
 * What is asserted here is the agreement between the two halves: the headers a
 * client paces itself by and the refusal when it does not. They are computed
 * separately, and a client told "3 remaining" and then refused has been lied
 * to — which is a sign-in that fails for reasons nothing on the server records.
 */
class RateLimitServiceTest extends TestCase {
	private IRequest|MockObject $request;
	private IUserSession|MockObject $userSession;
	private ICacheFactory|MockObject $cacheFactory;
	private ConfigService|MockObject $configService;
	private RateLimitService $service;

	/** the app values, as an administrator would have set them */
	private array $appValues = [];
	/** what the cache holds, and whether there is one at all */
	private array $held = [];
	private bool $distributed = true;
	private bool $local = true;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getRemoteAddress')->willReturn('198.51.100.7');

		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);

		$this->configService = $this->createMock(ConfigService::class);
		$this->configService->method('getAppValue')
			->willReturnCallback(fn (string $key): string => (string)($this->appValues[$key] ?? ''));

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(
			function (string $key) {
				return $this->held[$key] ?? null;
			}
		);
		$cache->method('set')->willReturnCallback(
			function (string $key, $value): bool {
				$this->held[$key] = $value;

				return true;
			}
		);

		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->cacheFactory->method('isAvailable')->willReturnCallback(fn (): bool => $this->distributed);
		$this->cacheFactory->method('isLocalCacheAvailable')->willReturnCallback(fn (): bool => $this->local);
		$this->cacheFactory->method('createDistributed')->willReturn($cache);
		$this->cacheFactory->method('createLocal')->willReturn($cache);

		$this->service = $this->build();
	}

	private function build(): RateLimitService {
		return new RateLimitService(
			$this->request, $this->userSession, $this->cacheFactory, $this->configService
		);
	}

	private function signedInAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->userSession = $session;
		$this->service = $this->build();
	}

	// the budget

	public function testASignedInClientGetsTheAccountBudgetAndEverybodyElseTheAddressOne(): void {
		$this->appValues = [
			ConfigService::SOCIAL_RATE_LIMIT_USER => '900',
			ConfigService::SOCIAL_RATE_LIMIT_ANON => '300',
			ConfigService::SOCIAL_RATE_LIMIT_WINDOW => '300',
		];

		$this->assertSame([300, 300], $this->service->defaultBudget());

		$this->signedInAs('alice');
		$this->assertSame([900, 300], $this->service->defaultBudget());
	}

	/**
	 * An instance behind its own limiter turns this off, and off has to mean
	 * no counting rather than a budget of nothing.
	 */
	public function testAZeroBudgetMeansNoLimitRatherThanNoRequests(): void {
		$this->appValues = [ConfigService::SOCIAL_RATE_LIMIT_ANON => '0'];

		$this->assertNull($this->service->defaultBudget());
	}

	public function testAWindowOfZeroIsNotAWindowOfZero(): void {
		$this->appValues = [
			ConfigService::SOCIAL_RATE_LIMIT_ANON => '300',
			ConfigService::SOCIAL_RATE_LIMIT_WINDOW => '0',
		];

		// a period of 0 would divide by zero on the next line of every caller
		$this->assertSame([300, 1], $this->service->defaultBudget());
	}

	// counting

	public function testCountingAddsUpWithinAWindow(): void {
		$this->assertSame(1, $this->service->count(RateLimitService::BUCKET_API, 300));
		$this->assertSame(2, $this->service->count(RateLimitService::BUCKET_API, 300));
		$this->assertSame(3, $this->service->count(RateLimitService::BUCKET_API, 300));
	}

	/**
	 * The header and the refusal are computed separately and have to agree:
	 * `used()` must see exactly what `count()` wrote, under the same key.
	 */
	public function testWhatWasCountedIsWhatIsRead(): void {
		$this->service->count(RateLimitService::BUCKET_API, 300);
		$this->service->count(RateLimitService::BUCKET_API, 300);

		$this->assertSame(2, $this->service->used(RateLimitService::BUCKET_API, 300));
	}

	public function testReadingABudgetDoesNotSpendIt(): void {
		$this->service->count(RateLimitService::BUCKET_API, 300);
		$this->service->used(RateLimitService::BUCKET_API, 300);
		$this->service->used(RateLimitService::BUCKET_API, 300);

		$this->assertSame(2, $this->service->count(RateLimitService::BUCKET_API, 300));
	}

	/** Two accounts are two budgets, and neither is the address's. */
	public function testEveryIdentityCountsSeparately(): void {
		$this->service->count(RateLimitService::BUCKET_API, 300);

		$this->signedInAs('alice');
		$this->assertSame(1, $this->service->count(RateLimitService::BUCKET_API, 300));

		$this->signedInAs('bob');
		$this->assertSame(1, $this->service->count(RateLimitService::BUCKET_API, 300));
	}

	public function testTwoBucketsAreTwoBudgets(): void {
		$this->service->count(RateLimitService::BUCKET_API, 300);

		$this->assertSame(1, $this->service->count('follow', 300));
	}

	/**
	 * The counter is keyed on the window's own start rather than a sliding
	 * expiry, so that the count and the `Reset` header end at the same moment.
	 * A client told "0 remaining, resets in 4 seconds" and then refused for
	 * another minute was told something untrue.
	 */
	public function testTheWindowEndsWhereTheCounterRollsOver(): void {
		$period = 300;
		$end = $this->service->windowEnd($period);

		$this->assertGreaterThan(time(), $end);
		$this->assertSame(0, $end % $period, 'the window ends on a multiple of its own length');
		$this->assertLessThanOrEqual($period, $end - time());
	}

	public function testAWindowOfZeroDoesNotDivideByZero(): void {
		$this->assertGreaterThan(time(), $this->service->windowEnd(0));
	}

	/**
	 * A limiter that cannot count must not guess. On an instance with no
	 * memcache — the default on a small install — nothing is counted and
	 * nothing is refused, rather than everything being refused.
	 */
	public function testWithNowhereToCountNothingIsCountedAndNothingIsRefused(): void {
		$this->distributed = false;
		$this->local = false;
		$service = $this->build();

		$this->assertNull($service->count(RateLimitService::BUCKET_API, 300));
		$this->assertNull($service->used(RateLimitService::BUCKET_API, 300));
	}

	public function testALocalCacheIsTheHonestSecondBest(): void {
		$this->distributed = false;
		$service = $this->build();

		$this->assertSame(1, $service->count(RateLimitService::BUCKET_API, 300));
	}

	// which routes the default governs

	public function testFederationIsNotACLientPacingItself(): void {
		$activityPub = $this->createMock(ActivityPubController::class);
		$queue = $this->createMock(QueueController::class);

		$this->assertFalse($this->service->appliesDefaultTo($activityPub, 'inbox'));
		$this->assertFalse($this->service->appliesDefaultTo($queue, 'asyncForRequest'));
	}

	/**
	 * One public page is forty requests for pictures. Counting those against a
	 * page budget refuses the page rather than slowing the caller.
	 */
	public function testServingBytesIsNotCountedAgainstThePageBudget(): void {
		$media = $this->createMock(MediaApiController::class);
		$instance = $this->createMock(InstanceApiController::class);

		$this->assertFalse($this->service->appliesDefaultTo($media, 'mediaOpen'));
		$this->assertFalse($this->service->appliesDefaultTo($instance, 'emojiOpen'));
	}

	/**
	 * Nextcloud already counts a route that declares its own limit, and a
	 * second limiter over the top refuses at a number neither of them
	 * publishes.
	 *
	 * A partial mock with nothing overridden, not `createMock()`: a full mock
	 * replaces every method with a generated one, and a generated method
	 * carries none of the original's attributes — so the service would read
	 * every route as unlimited and this test would pass while asserting the
	 * opposite of the truth.
	 */
	public function testARouteWithItsOwnLimitIsLeftToNextcloud(): void {
		$api = $this->createPartialMock(ApiController::class, []);

		$this->assertFalse(
			$this->service->appliesDefaultTo($api, 'statusNew'),
			'statusNew carries a rate-limit attribute of its own'
		);
	}

	public function testAnOrdinaryRouteIsGovernedByTheDefault(): void {
		$api = $this->createPartialMock(AccountApiController::class, []);

		$this->assertTrue($this->service->appliesDefaultTo($api, 'verifyCredentials'));
	}

	/** A method that is not there is not this service's problem to report. */
	public function testAMethodThatDoesNotExistIsLeftAlone(): void {
		$api = $this->createPartialMock(ApiController::class, []);

		$this->assertFalse($this->service->appliesDefaultTo($api, 'noSuchMethodAnywhere'));
	}

	/**
	 * Both attribute spellings mean "Nextcloud is counting this". Asserted
	 * against the real methods, so that a route losing its attribute upstream
	 * shows up here rather than silently doubling its limiter.
	 */
	public function testBothRateLimitAttributesCount(): void {
		$statusNew = new \ReflectionMethod(ApiController::class, 'statusNew');
		$this->assertNotEmpty(
			array_merge(
				$statusNew->getAttributes(UserRateLimit::class),
				$statusNew->getAttributes(AnonRateLimit::class)
			),
			'the fixture above still declares a limit of its own'
		);

		$verify = new \ReflectionMethod(AccountApiController::class, 'verifyCredentials');
		$this->assertSame(
			[],
			array_merge(
				$verify->getAttributes(UserRateLimit::class),
				$verify->getAttributes(AnonRateLimit::class)
			),
			'and the one it contrasts with still declares none'
		);
	}
}
