<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Controller\ActivityPubController;
use OCA\Social\Controller\InstanceApiController;
use OCA\Social\Controller\LocalController;
use OCA\Social\Controller\MediaApiController;
use OCA\Social\Controller\NavigationController;
use OCA\Social\Controller\QueueController;
use OCA\Social\Controller\SocialPubController;
use OCA\Social\Security\ClientAddress;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IUserSession;
use ReflectionMethod;
use Throwable;

/**
 * Who is spending what, and how much they are allowed: one place for the
 * identity, window and key, so the headers a client paces itself by agree
 * with the refusal.
 */
class RateLimitService {
	/** The cache the counters live in; distinct from anything else this app caches. */
	private const CACHE_PREFIX = 'social_ratelimit';

	/** One bucket for the whole API; see `appliesDefaultTo()`. */
	public const BUCKET_API = 'api';

	/**
	 * Controllers whose traffic is not a client pacing itself: federation comes
	 * in bursts from a few addresses and has its own per-origin limiter
	 * (`inbox_throttle`), and the queue controller is this server calling
	 * itself.
	 */
	private const EXEMPT_CONTROLLERS = [
		ActivityPubController::class,
		SocialPubController::class,
		QueueController::class,
	];

	/**
	 * Routes that serve bytes rather than answers: one public page is forty
	 * requests for images, which must not count against a page budget.
	 */
	private const EXEMPT_ROUTES = [
		NavigationController::class => ['documentGetPublic', 'resizedGetPublic'],
		MediaApiController::class => ['mediaOpen'],
		InstanceApiController::class => ['emojiOpen', 'gifOpen'],
		LocalController::class => ['globalActorAvatar'],
	];

	public function __construct(
		private IRequest $request,
		private IUserSession $userSession,
		private ICacheFactory $cacheFactory,
		private ConfigService $configService,
		private ?ITimeFactory $timeFactory = null,
	) {
	}

	/**
	 * The budget a route with no attribute of its own gets, shaped like
	 * Mastodon's: a per-account allowance for a signed-in client and a smaller
	 * per-address one for everybody else, generous enough for a polling client,
	 * tight enough to stop a scraper. `0` on either key switches the default
	 * off, for an instance behind its own limiter.
	 *
	 * @return array{0: int, 1: int}|null limit and period, or null for no limit
	 */
	public function defaultBudget(): ?array {
		$limit = (int)$this->configService->getAppValue(
			$this->signedIn() ? ConfigService::SOCIAL_RATE_LIMIT_USER : ConfigService::SOCIAL_RATE_LIMIT_ANON
		);
		if ($limit < 1) {
			return null;
		}

		return [$limit, max(1, (int)$this->configService->getAppValue(ConfigService::SOCIAL_RATE_LIMIT_WINDOW))];
	}

	/**
	 * Records one request against a budget and says how many are now in the
	 * window, keyed by the window's start so the counter and the `Reset`
	 * header agree about where the window ends.
	 *
	 * @param string $bucket what the budget is for: a route, or the whole API
	 */
	public function count(string $bucket, int $period): ?int {
		$cache = $this->counter();
		if ($cache === null) {
			return null;
		}

		$key = implode('/', [$this->identity(), $bucket, (string)intdiv($this->now(), max(1, $period))]);
		$used = (int)$cache->get($key) + 1;
		$cache->set($key, $used, $period);

		return $used;
	}

	/**
	 * Whether the app's default budget governs this route: not where the route
	 * declares its own limit, which Nextcloud counts, and not for traffic that
	 * is not a client pacing itself.
	 */
	public function appliesDefaultTo(Controller $controller, string $methodName): bool {
		foreach (self::EXEMPT_CONTROLLERS as $class) {
			if ($controller instanceof $class) {
				return false;
			}
		}

		foreach (self::EXEMPT_ROUTES as $class => $methods) {
			if ($controller instanceof $class && in_array($methodName, $methods, true)) {
				return false;
			}
		}

		return !$this->declaresItsOwn($controller, $methodName);
	}

	/** Whether Nextcloud is already counting this route. */
	private function declaresItsOwn(Controller $controller, string $methodName): bool {
		try {
			$method = new ReflectionMethod($controller, $methodName);
		} catch (Throwable $e) {
			// no such method is not this service's problem to report
			return true;
		}

		return $method->getAttributes(UserRateLimit::class) !== []
			|| $method->getAttributes(AnonRateLimit::class) !== [];
	}

	/** What the count of a bucket stands at, without adding to it. */
	public function used(string $bucket, int $period): ?int {
		$cache = $this->counter();
		if ($cache === null) {
			return null;
		}

		return (int)$cache->get(
			implode('/', [$this->identity(), $bucket, (string)intdiv($this->now(), max(1, $period))])
		);
	}

	/** The moment the window this request fell in runs out. */
	public function windowEnd(int $period): int {
		$period = max(1, $period);

		return (intdiv($this->now(), $period) + 1) * $period;
	}

	public function signedIn(): bool {
		return $this->userSession->getUser() !== null;
	}

	/**
	 * Somewhere to keep the count: the distributed cache, as a budget spans web
	 * servers, else the local one (per server, so a budget looks larger behind
	 * a load balancer). Null without any memcache, and then nothing is counted
	 * or refused, as a limiter that cannot count must not guess.
	 */
	private function counter(): ?ICache {
		if ($this->cacheFactory->isAvailable()) {
			return $this->cacheFactory->createDistributed(self::CACHE_PREFIX);
		}

		if ($this->cacheFactory->isLocalCacheAvailable()) {
			return $this->cacheFactory->createLocal(self::CACHE_PREFIX);
		}

		return null;
	}

	/**
	 * Who the budget belongs to: the signed-in account, else the address,
	 * an IPv6 one by its /64 (`ClientAddress`).
	 */
	private function identity(): string {
		$user = $this->userSession->getUser();
		if ($user !== null) {
			return 'u:' . $user->getUID();
		}

		return 'a:' . ClientAddress::bucket($this->request->getRemoteAddress());
	}

	private function now(): int {
		return $this->timeFactory?->getTime() ?? time();
	}
}
