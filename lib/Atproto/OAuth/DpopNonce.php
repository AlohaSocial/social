<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\OAuth;

use OCA\Social\Atproto\Protocol\Encoding;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ICrypto;

/**
 * The server-issued DPoP nonce (RFC 9449 §8), which AT Protocol makes
 * mandatory: one value for everybody, derived from the instance secret and
 * the current two-minute window, so it needs no storage and rotates on its
 * own. The one before it is still taken, so a nonce lives at most four
 * minutes and a client with requests in flight across a rotation is not
 * refused.
 */
class DpopNonce {
	public const WINDOW = 120;

	public function __construct(
		private ICrypto $crypto,
		private ITimeFactory $time,
	) {
	}

	public function current(): string {
		return $this->forWindow(intdiv($this->time->getTime(), self::WINDOW));
	}

	public function isValid(string $nonce): bool {
		$window = intdiv($this->time->getTime(), self::WINDOW);

		return $nonce !== '' && (hash_equals($this->forWindow($window), $nonce) || hash_equals($this->forWindow($window - 1), $nonce));
	}

	private function forWindow(int $window): string {
		return Encoding::base64UrlEncode(substr(hash('sha256', $this->crypto->calculateHMAC('social-atproto-dpop-nonce|' . $window), true), 0, 24));
	}
}
