<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Security;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ICrypto;

/**
 * Proof that a request to `/async/request/{token}` came from this server.
 *
 * The route has to be public — the server calls itself over HTTP, with no
 * session — and a token is enough to have it spend up to a minute and a half
 * of a PHP worker delivering. So the call carries an HMAC of the token and
 * the time it was made under the instance secret, which nothing outside
 * `config.php` can produce, and a request without a valid one, or one made
 * more than MAX_AGE ago, is refused before the queue is read.
 *
 * The header reads `<unix time>.<hex HMAC>`.
 *
 * A follow-up round of the same drain (`QueueController::MAX_ROUNDS`) names
 * its number in `ROUND_HEADER`, and the number is signed with the token, so
 * a signature for one round cannot start another.
 */
class AsyncRequestSigner {
	public const HEADER = 'X-Social-Async-Signature';
	/** how long a signature is good for, in seconds, either way of the clock */
	public const MAX_AGE = 300;
	/** which round of a token's drain the call starts; absent for the first */
	public const ROUND_HEADER = 'X-Social-Async-Round';

	public function __construct(
		private ICrypto $crypto,
		private ITimeFactory $time,
	) {
	}

	/**
	 * What a log line names a queue token by: enough to match a row, not
	 * the token itself.
	 */
	public static function fingerprint(string $token): string {
		return substr(hash('sha256', $token), 0, 8);
	}

	public function sign(string $token, int $round = 0): string {
		$at = $this->time->getTime();

		return $at . '.' . $this->mac($token, $at, $round);
	}

	public function verify(string $token, string $signature, int $round = 0): bool {
		if ($token === '' || !str_contains($signature, '.')) {
			return false;
		}
		[$at, $mac] = explode('.', $signature, 2);
		if ($at === '' || !ctype_digit($at) || abs($this->time->getTime() - (int)$at) > self::MAX_AGE) {
			return false;
		}

		return hash_equals($this->mac($token, (int)$at, $round), $mac);
	}

	private function mac(string $token, int $at, int $round): string {
		$message = 'social-async-request:' . $at . ':' . $token;
		if ($round > 0) {
			$message .= ':' . $round;
		}

		return bin2hex($this->crypto->calculateHMAC($message));
	}
}
