<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Middleware;

use OCA\Social\Service\RateLimitService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\Security\RateLimiting\ILimiter;

/**
 * The app's default rate limit for routes without a `UserRateLimit` of their
 * own, so nobody can read the whole instance at machine speed. Routes with
 * their own limit are left to Nextcloud.
 *
 * Exempt: federation (`ActivityPubController`, `SocialPubController`), whose
 * bursty peers have the per-host `inbox_throttle` instead; bytes (avatars,
 * attachments, emoji, GIFs), forty to a page; and the internal async queue.
 * The default is generous, and `rate_limit_user`/`rate_limit_anon` can switch
 * it off behind another limiter.
 *
 * A bearer token is counted as its account. Nextcloud's limiter sees no
 * session on such a request, so this also counts a route's own
 * `UserRateLimit` for a token, and applies the routes' `AnonymousRateLimit`
 * (in place of Nextcloud's `AnonRateLimit`, which would apply to the token
 * as well) only to callers who are not an account.
 */
class ApiRateLimitMiddleware extends Middleware {
	public function __construct(
		private RateLimitService $rateLimitService,
		private ILimiter $limiter,
		private IRequest $request,
	) {
	}

	#[\Override]
	public function beforeController(Controller $controller, string $methodName): void {
		$anon = $this->rateLimitService->anonRouteBudget($controller, $methodName);
		if ($anon !== null) {
			// Nextcloud's limiter and counters, under the name its own
			// middleware gives the route; that middleware answers the 429.
			$this->limiter->registerAnonRequest(
				$controller::class . '::' . $methodName, $anon[0], $anon[1], $this->request->getRemoteAddress()
			);
		}

		$own = $this->rateLimitService->tokenRouteBudget($controller, $methodName);
		if ($own !== null) {
			$this->spend(RateLimitService::routeBucket($controller, $methodName), $own[0], $own[1]);

			return;
		}

		if (!$this->rateLimitService->appliesDefaultTo($controller, $methodName)) {
			return;
		}

		$budget = $this->rateLimitService->defaultBudget();
		if ($budget === null) {
			return;
		}

		// one bucket for the whole API: a per-route default would multiply the
		// allowance by the number of routes
		$this->spend(RateLimitService::BUCKET_API, $budget[0], $budget[1]);
	}

	/** @throws RateLimitedException */
	private function spend(string $bucket, int $limit, int $period): void {
		$used = $this->rateLimitService->count($bucket, $period);
		if ($used !== null && $used > $limit) {
			throw new RateLimitedException($limit, $period, $this->rateLimitService->windowEnd($period));
		}
	}

	#[\Override]
	public function afterException(
		Controller $controller, string $methodName, \Exception $exception,
	): Response {
		if (!($exception instanceof RateLimitedException)) {
			throw $exception;
		}

		$response = new JSONResponse(
			['error' => 'Too many requests. Try again later.'],
			Http::STATUS_TOO_MANY_REQUESTS
		);
		// what every Mastodon client reads to decide when to come back
		$response->addHeader('Retry-After', (string)max(1, $exception->getResetAt() - time()));
		$response->addHeader('X-RateLimit-Limit', (string)$exception->getLimit());
		$response->addHeader('X-RateLimit-Remaining', '0');
		$response->addHeader('X-RateLimit-Reset', (string)$exception->getResetAt());

		return $response;
	}
}
