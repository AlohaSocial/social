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
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IUserSession;
use ReflectionMethod;
use Throwable;

/**
 * Who is spending what, and how much they are allowed.
 *
 * Counting requests is done in two places — the headers a client paces itself
 * by, and the refusal when it does not — and the two have to agree about the
 * identity, the window and the key, or a client is told it has budget left and
 * then refused for spending it.
 */
class RateLimitService {
	/** The cache the counters live in; distinct from anything else this app caches. */
	private const CACHE_PREFIX = 'social_ratelimit';

	/** One bucket for the whole API; see `appliesDefaultTo()`. */
	public const BUCKET_API = 'api';

	/**
	 * Controllers whose traffic is not a client pacing itself.
	 *
	 * Federation arrives in bursts from a handful of addresses, and one busy
	 * peer would exhaust an address budget for every other peer behind the
	 * same relay; the inbox has a limiter of its own in `inbox_throttle`,
	 * counted per origin host, which is the unit that makes sense there. The
	 * queue controller is this server calling itself.
	 */
	private const EXEMPT_CONTROLLERS = [
		ActivityPubController::class,
		SocialPubController::class,
		QueueController::class,
	];

	/**
	 * Routes that serve bytes rather than answers.
	 *
	 * One public page is forty requests for pictures, and counting those
	 * against a page budget would refuse the page rather than slow the caller.
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
	) {
	}

	/**
	 * The budget a route with no attribute of its own gets.
	 *
	 * Mastodon's shape: a per-account allowance for a signed-in client, a
	 * smaller per-address one for everybody else. Generous rather than tight —
	 * a client that polls four timelines and a notification count is doing
	 * nothing wrong, and this exists to stop a scraper reading the whole
	 * instance at machine speed, not to pace an app.
	 *
	 * `0` on either key switches the default off, which is what an instance
	 * behind its own limiter wants.
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
	 * window.
	 *
	 * Keyed by the window's own start rather than by a sliding expiry, so the
	 * counter and the `Reset` header agree about where the window ends — a
	 * client told "0 remaining, resets in 4 seconds" and refused for another
	 * minute would have been told something untrue.
	 *
	 * @param string $bucket what the budget is for: a route, or the whole API
	 */
	public function count(string $bucket, int $period): ?int {
		$cache = $this->counter();
		if ($cache === null) {
			return null;
		}

		$key = implode('/', [$this->identity(), $bucket, (string)intdiv(time(), max(1, $period))]);
		$used = (int)$cache->get($key) + 1;
		$cache->set($key, $used, $period);

		return $used;
	}

	/**
	 * Whether the app's default budget is the one that governs this route.
	 *
	 * False where the route declares a limit of its own — Nextcloud already
	 * counts those, and a second limiter over the top would refuse at a number
	 * neither of them publishes — and false for the traffic that is not a
	 * client pacing itself.
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
			implode('/', [$this->identity(), $bucket, (string)intdiv(time(), max(1, $period))])
		);
	}

	/** The moment the window this request fell in runs out. */
	public function windowEnd(int $period): int {
		$period = max(1, $period);

		return (intdiv(time(), $period) + 1) * $period;
	}

	public function signedIn(): bool {
		return $this->userSession->getUser() !== null;
	}

	/**
	 * Somewhere to keep the count, or null when there is nowhere.
	 *
	 * Distributed first, because a budget is per account and not per web
	 * server: two requests that land on different servers are two requests
	 * against one budget. A local cache is the honest second best — the count
	 * is then per server, so a client behind a load balancer sees a budget
	 * that looks larger than it is, which errs towards pacing rather than
	 * being refused.
	 *
	 * Null when Nextcloud has no memcache at all, which is the default on a
	 * small install. Nothing is counted then and nothing is refused: a limiter
	 * that cannot count must not guess, and refusing on a guess would take the
	 * app away from the instances least able to debug it.
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

	/** Who the budget belongs to: the signed-in account, else the address. */
	private function identity(): string {
		$user = $this->userSession->getUser();
		if ($user !== null) {
			return 'u:' . $user->getUID();
		}

		return 'a:' . $this->request->getRemoteAddress();
	}
}
