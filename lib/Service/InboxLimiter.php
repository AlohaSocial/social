<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Exceptions\PayloadTooLargeException;
use OCA\Social\Exceptions\TooManyRequestsException;
use OCA\Social\Security\ClientAddress;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;

/**
 * A cheap per-origin ceiling on incoming inbox deliveries, applied before
 * the expensive signature verification (which fetches the signing key).
 *
 * Before verification the bucket is the connecting address, which the sender
 * cannot choose; the keyId's host is unproven, and counting against it would
 * let anyone silence a large instance. assertOriginAllowed() applies the
 * per-origin ceiling with the origin the signature proved.
 *
 * Fixed one-minute windows in `DurableCache`, so it works without a
 * memcache. Refused deliveries count too. A limit of 0 disables the check.
 */
class InboxLimiter {
	public const WINDOW = 60;

	/**
	 * The per-origin bucket is looser than the per-address one: a large instance
	 * legitimately delivers from several addresses.
	 */
	public const HOST_LIMIT_FACTOR = 4;

	/**
	 * The largest delivery this instance will read; see `readBody()`.
	 */
	public const MAX_BODY = 1048576;

	/** The `DurableCache` namespace the counters live in. */
	public const CACHE_NAMESPACE = 'social.inbox';

	public function __construct(
		private DurableCache $cache,
		private ConfigService $configService,
		private ?ITimeFactory $timeFactory = null,
	) {
	}

	/**
	 * The ceiling that applies before anything about the request is known: the
	 * source address (an IPv6 one by its /64, see `ClientAddress`).
	 *
	 * @throws TooManyRequestsException
	 */
	public function assertAllowed(IRequest $request): void {
		$limit = $this->limit();
		if ($limit <= 0) {
			return;
		}

		$this->consume('ip.' . md5(ClientAddress::bucket($request->getRemoteAddress())), $this->window(), $limit);
	}

	/**
	 * The ceiling on one origin's total, across however many addresses it
	 * delivers from. $origin must be the host the HTTP signature verified.
	 *
	 * @throws TooManyRequestsException
	 */
	public function assertOriginAllowed(string $origin): void {
		$origin = strtolower(trim($origin));
		$limit = $this->limit();
		if ($limit <= 0 || $origin === '') {
			return;
		}

		$this->consume(
			'host.' . md5($origin), $this->window(), $limit * self::HOST_LIMIT_FACTOR
		);
	}

	private function limit(): int {
		return (int)$this->configService->getAppValue(ConfigService::SOCIAL_INBOX_THROTTLE);
	}

	private function window(): int {
		return intdiv($this->timeFactory?->getTime() ?? time(), self::WINDOW);
	}

	/**
	 * The delivery's body, or nothing if it is too big to be one.
	 *
	 * The inboxes are public and hash the body for the `Digest` before
	 * verifying the signature, and `post_max_size` is large for file uploads,
	 * so the read is capped at MAX_BODY (as Mastodon does); activities are a
	 * few kilobytes. `Content-Length` is checked first, then the read itself is
	 * bounded, since the header may lie or be absent (chunked).
	 *
	 * @throws PayloadTooLargeException
	 */
	public function readBody(IRequest $request): string {
		$declared = (int)$request->getHeader('Content-Length');
		if ($declared > self::MAX_BODY) {
			throw new PayloadTooLargeException(
				'inbox body of ' . $declared . ' bytes declared, limit is ' . self::MAX_BODY
			);
		}

		$body = (string)file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
		if (strlen($body) > self::MAX_BODY) {
			throw new PayloadTooLargeException('inbox body over ' . self::MAX_BODY . ' bytes');
		}

		return $body;
	}

	/**
	 * @throws TooManyRequestsException
	 */
	private function consume(string $bucket, int $window, int $limit): void {
		$key = $bucket . '.' . $window;

		if ($this->cache->inc(self::CACHE_NAMESPACE, $key, self::WINDOW * 2) > $limit) {
			throw new TooManyRequestsException('inbox rate limit exceeded');
		}
	}
}
