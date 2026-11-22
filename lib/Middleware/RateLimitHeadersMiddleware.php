<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Middleware;

use OCA\Social\Service\RateLimitService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use ReflectionMethod;
use Throwable;

/**
 * Adds the `X-RateLimit-Limit`, `-Remaining` and `-Reset` headers Mastodon
 * clients expect, so they can pace themselves.
 *
 * The count is advisory: Nextcloud's limiter enforces the limits (through
 * `ApiRateLimitMiddleware` for `AnonymousRateLimit`, and that middleware
 * counts a bearer token's `UserRateLimit` itself) and this never touches the
 * refusal. Routes without a rate-limit attribute
 * get no headers, and without a memcache only the limit is sent, as an
 * unmeasured remainder is worse than none.
 */
class RateLimitHeadersMiddleware extends Middleware {
	public function __construct(
		private RateLimitService $rateLimitService,
	) {
	}

	/**
	 * @param Controller $controller
	 * @param string $methodName
	 * @param Response $response
	 */
	#[\Override]
	public function afterController(Controller $controller, string $methodName, Response $response): Response {
		try {
			$route = $this->budgetOf($controller, $methodName);
			if ($route === null) {
				return $response;
			}

			[$bucket, $limit, $period] = $route;
			$response->addHeader('X-RateLimit-Limit', (string)$limit);

			// Without a memcache `createDistributed()` stores nothing and the
			// count would stay at 1, so `Remaining` is left out (see the class
			// comment). A bucket the app enforces reads the enforcing
			// middleware's own count, so the header never disagrees with it.
			$used = ($bucket === RateLimitService::BUCKET_API
				|| $this->rateLimitService->tokenRouteBudget($controller, $methodName) !== null)
				? $this->rateLimitService->used($bucket, $period)
				: $this->rateLimitService->count($bucket, $period);
			if ($used === null) {
				return $response;
			}

			$response->addHeader('X-RateLimit-Remaining', (string)max(0, $limit - $used));
			// seconds since the epoch, as Mastodon sends it: the moment the
			// window this request fell in runs out
			$response->addHeader('X-RateLimit-Reset', (string)$this->rateLimitService->windowEnd($period));
		} catch (Throwable $e) {
			// a header is not worth failing a response over
		}

		return $response;
	}

	/**
	 * The limit and period this route carries, or null when it has none.
	 * `UserRateLimit` first, as it applies to a signed-in caller when both
	 * attributes are present.
	 *
	 * @return array{0: string, 1: int, 2: int}|null bucket, limit and period
	 */
	private function budgetOf(Controller $controller, string $methodName): ?array {
		$method = new ReflectionMethod($controller, $methodName);

		$order = $this->rateLimitService->signedIn()
			? [UserRateLimit::class, AnonymousRateLimit::class]
			: [AnonymousRateLimit::class, UserRateLimit::class];

		foreach ($order as $class) {
			foreach ($method->getAttributes($class) as $attribute) {
				$limit = $attribute->newInstance();

				return [
					RateLimitService::routeBucket($controller, $methodName),
					$limit->getLimit(),
					$limit->getPeriod(),
				];
			}
		}

		// a route with no attribute is governed by the app's default, which is
		// a budget worth publishing rather than an unlimited one
		if (!$this->rateLimitService->appliesDefaultTo($controller, $methodName)) {
			return null;
		}

		$default = $this->rateLimitService->defaultBudget();

		return ($default === null)
			? null : [RateLimitService::BUCKET_API, $default[0], $default[1]];
	}
}
