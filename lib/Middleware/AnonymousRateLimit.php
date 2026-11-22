<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Middleware;

use Attribute;

/**
 * The limit for a caller who is not an account, per address, in place of
 * Nextcloud's `AnonRateLimit`. Nextcloud decides by the session alone and
 * would apply that one to a Mastodon app's bearer token as well;
 * `ApiRateLimitMiddleware` applies this one, through the same limiter and
 * counters, only to callers who are neither signed in nor carry a valid
 * token, and, as Nextcloud does, to everybody on a route without a
 * `UserRateLimit`.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class AnonymousRateLimit {
	public function __construct(
		private int $limit,
		private int $period,
	) {
	}

	public function getLimit(): int {
		return $this->limit;
	}

	public function getPeriod(): int {
		return $this->period;
	}
}
