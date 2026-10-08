<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Security;

use OCP\Security\ICrypto;

/**
 * Proof that a request to `/async/request/{token}` came from this server.
 *
 * The route has to be public — the server calls itself over HTTP, with no
 * session — and a token is enough to have it spend up to a minute and a half
 * of a PHP worker delivering. Tokens are random, but they are written to the
 * log on every failed delivery. So the call carries an HMAC of the token under
 * the instance secret, which nothing outside `config.php` can produce, and a
 * request without a valid one is refused before the queue is read.
 */
class AsyncRequestSigner {
	public const HEADER = 'X-Social-Async-Signature';

	public function __construct(
		private ICrypto $crypto,
	) {
	}

	public function sign(string $token): string {
		return bin2hex($this->crypto->calculateHMAC('social-async-request:' . $token));
	}

	public function verify(string $token, string $signature): bool {
		return $token !== '' && $signature !== '' && hash_equals($this->sign($token), $signature);
	}
}
