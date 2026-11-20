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
 */
class ApiRateLimitMiddleware extends Middleware {
	public function __construct(
		private RateLimitService $rateLimitService,
	) {
	}

	#[\Override]
	public function beforeController(Controller $controller, string $methodName): void {
		if (!$this->rateLimitService->appliesDefaultTo($controller, $methodName)) {
			return;
		}

		$budget = $this->rateLimitService->defaultBudget();
		if ($budget === null) {
			return;
		}

		[$limit, $period] = $budget;
		// one bucket for the whole API: a per-route default would multiply the
		// allowance by the number of routes
		$used = $this->rateLimitService->count(RateLimitService::BUCKET_API, $period);
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
