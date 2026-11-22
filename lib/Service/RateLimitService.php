<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\AppInfo\Application;
use OCA\Social\Controller\ActivityPubController;
use OCA\Social\Controller\InstanceApiController;
use OCA\Social\Controller\LocalController;
use OCA\Social\Controller\MediaApiController;
use OCA\Social\Controller\NavigationController;
use OCA\Social\Controller\QueueController;
use OCA\Social\Controller\SocialPubController;
use OCA\Social\Middleware\AnonymousRateLimit;
use OCA\Social\Security\ClientAddress;
use OCP\AppFramework\Controller;
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

	/** The account a bearer token on this request acts for: '' for none, null before it is looked up. */
	private ?string $tokenUser = null;

	public function __construct(
		private IRequest $request,
		private IUserSession $userSession,
		private ICacheFactory $cacheFactory,
		private ConfigService $configService,
		private ?ITimeFactory $timeFactory = null,
		private ?ClientService $clientService = null,
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
	 * declares its own limit, and not for traffic that is not a client pacing
	 * itself.
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

	/**
	 * The `UserRateLimit` of a route called with a bearer token and no
	 * session. Nextcloud's limiter decides by the session alone, so it never
	 * applies that limit to a token, and the app counts it instead (bucket
	 * `routeBucket()`).
	 *
	 * @return array{0: int, 1: int}|null limit and period, or null when the
	 *                                    route or the caller has none
	 */
	public function tokenRouteBudget(Controller $controller, string $methodName): ?array {
		if ($this->userSession->getUser() !== null || $this->tokenUser() === '') {
			return null;
		}

		foreach (self::EXEMPT_CONTROLLERS as $class) {
			if ($controller instanceof $class) {
				return null;
			}
		}

		try {
			$attributes = (new ReflectionMethod($controller, $methodName))->getAttributes(UserRateLimit::class);
		} catch (Throwable $e) {
			return null;
		}
		if ($attributes === []) {
			return null;
		}

		$limit = $attributes[0]->newInstance();

		return [$limit->getLimit(), $limit->getPeriod()];
	}

	/**
	 * The `AnonymousRateLimit` a route holds this caller to, as Nextcloud
	 * applies its `AnonRateLimit`: to a caller who is not an account, and to
	 * everybody on a route without a `UserRateLimit`. An account is a session
	 * or a valid bearer token, so a Mastodon app is held to the route's
	 * per-account limit instead (`tokenRouteBudget()`). An administrator's
	 * `ratelimit_overwrite` for the route is honoured, as Nextcloud honours it.
	 *
	 * @return array{0: int, 1: int}|null limit and period, or null when the
	 *                                    route has none for this caller
	 */
	public function anonRouteBudget(Controller $controller, string $methodName): ?array {
		try {
			$method = new ReflectionMethod($controller, $methodName);
		} catch (Throwable $e) {
			return null;
		}

		$attributes = $method->getAttributes(AnonymousRateLimit::class);
		if ($attributes === []
			|| ($method->getAttributes(UserRateLimit::class) !== [] && $this->signedIn())) {
			return null;
		}

		$limit = $attributes[0]->newInstance();

		return $this->anonOverwrite($controller, $methodName) ?? [$limit->getLimit(), $limit->getPeriod()];
	}

	/**
	 * The `anon` entry of `ratelimit_overwrite` in config.php for a route,
	 * keyed and checked as Nextcloud's `RateLimitingMiddleware` does.
	 *
	 * @return array{0: int, 1: int}|null
	 */
	private function anonOverwrite(Controller $controller, string $methodName): ?array {
		$overwrites = $this->configService->getSystemValue('ratelimit_overwrite');
		if (!is_array($overwrites)) {
			return null;
		}

		$name = substr($controller::class, strrpos($controller::class, '\\') + 1);
		$key = strtolower(Application::APP_ID . '.' . substr($name, 0, -strlen('Controller')) . '.' . $methodName);
		$anon = $overwrites[$key]['anon'] ?? null;
		if (!is_array($anon) || (int)($anon['limit'] ?? 0) < 1 || (int)($anon['period'] ?? 0) < 1) {
			return null;
		}

		return [(int)$anon['limit'], (int)$anon['period']];
	}

	/** The bucket a route's own limit is counted in. */
	public static function routeBucket(Controller $controller, string $methodName): string {
		return $controller::class . '/' . $methodName;
	}

	/** Whether the route declares a limit of its own. */
	private function declaresItsOwn(Controller $controller, string $methodName): bool {
		try {
			$method = new ReflectionMethod($controller, $methodName);
		} catch (Throwable $e) {
			// no such method is not this service's problem to report
			return true;
		}

		return $method->getAttributes(UserRateLimit::class) !== []
			|| $method->getAttributes(AnonymousRateLimit::class) !== [];
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

	/** Whether the caller is an account: by its session, or by a bearer token. */
	public function signedIn(): bool {
		return $this->userSession->getUser() !== null || $this->tokenUser() !== '';
	}

	/**
	 * The account a valid bearer token on this request acts for, or '' for
	 * none, an app token's nobody included. Looked up once per request,
	 * through the lookup the controller then reuses.
	 */
	private function tokenUser(): string {
		if ($this->tokenUser !== null) {
			return $this->tokenUser;
		}

		$this->tokenUser = '';
		$header = trim($this->request->getHeader('Authorization'));
		if ($this->clientService === null || stripos($header, 'bearer ') !== 0) {
			return $this->tokenUser;
		}

		try {
			$this->tokenUser = $this->clientService->getFromTokenOfRequest(trim(substr($header, 7)))->getAuthUserId();
		} catch (Throwable $e) {
			// a stale or made-up token is spent from the address's budget
		}

		return $this->tokenUser;
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
	 * Who the budget belongs to: the signed-in account, else the account of
	 * the bearer token, else the address, an IPv6 one by its /64
	 * (`ClientAddress`).
	 */
	private function identity(): string {
		$user = $this->userSession->getUser();
		if ($user !== null) {
			return 'u:' . $user->getUID();
		}

		if ($this->tokenUser() !== '') {
			return 'u:' . $this->tokenUser();
		}

		return 'a:' . ClientAddress::bucket($this->request->getRemoteAddress());
	}

	private function now(): int {
		return $this->timeFactory?->getTime() ?? time();
	}
}
