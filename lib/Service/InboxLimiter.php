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
 * A cheap per-origin ceiling on incoming inbox deliveries, applied before the
 * (expensive) signature verification: signatures and replay windows prove
 * authenticity, but nothing else caps how fast a valid-but-hostile peer can
 * fill the stream queue — and the verification itself fetches the signing key,
 * so a flood is expensive long before anything about it is known to be true.
 *
 * The bucket checked before anything is known is the connecting address, which
 * is the one thing about an unauthenticated request the sender cannot choose.
 * The host named in the Signature header's keyId cannot be a bucket of its own
 * there: nothing has checked that the sender has anything to do with it, so
 * counting a request against the host it *names* lets any four cheap addresses
 * fill a large instance's bucket every minute and cut this server off from it —
 * the deliveries that are genuinely from it are then answered 429 and the local
 * timelines simply go quiet. That is a better attack than the one the bucket
 * was there to stop.
 *
 * A per-origin ceiling is still worth having, so it moved behind the signature:
 * assertOriginAllowed() is called with the origin the HTTP signature actually
 * proved, and only a peer that can sign for a host can spend that host's
 * budget.
 *
 * Fixed one-minute windows in `DurableCache`, so the ceiling holds on an
 * instance with no memcache too: in the distributed cache alone it read zero
 * on every delivery there and never refused one. A refused delivery counts
 * as well — a sender that keeps knocking stays over the ceiling. A limit of 0
 * disables the check.
 */
class InboxLimiter {
	public const WINDOW = 60;

	/**
	 * The per-origin bucket is deliberately looser than the per-address one: a
	 * large instance legitimately delivers from several addresses, and this
	 * ceiling exists only to bound one origin's total.
	 */
	public const HOST_LIMIT_FACTOR = 4;

	/**
	 * The largest delivery this instance will read. See `readBody()` for why
	 * there has to be one at all.
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
	 * source address (an IPv6 one by its /64, see `ClientAddress`), and
	 * nothing else the sender wrote.
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
	 * delivers from.
	 *
	 * $origin must be the host the HTTP signature verified against — the keyId
	 * as it arrived proves nothing, and spending a bucket on an unproven name
	 * is a way to silence the instance that owns it.
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
	 * Both inboxes are public, unauthenticated routes, and what they did first
	 * was read the whole request into a string and hash it: the `Digest` is
	 * checked over the body **before** the signature is verified, because a
	 * signature over a body nobody has hashed proves nothing about the body.
	 * The only ceiling on that was PHP's `post_max_size`, which a Nextcloud
	 * sets to hundreds of megabytes so that file uploads work — so anyone at
	 * all could hand every worker in the pool half a gigabyte to allocate and
	 * SHA-256, as often as the per-address bucket allows. The rate limit bounds
	 * requests, not bytes, and one request was already enough to hold a worker
	 * for seconds.
	 *
	 * An activity is a few kilobytes. The largest legitimate one — a long
	 * article naming many recipients and carrying many attachments — is well
	 * inside a megabyte, which is what Mastodon accepts as well. A sender that
	 * genuinely has more to say has `Collection` pages to say it in.
	 *
	 * `Content-Length` is checked first because it costs nothing, and then the
	 * read itself is bounded anyway: the header is the sender's claim, and a
	 * chunked request carries none at all.
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
