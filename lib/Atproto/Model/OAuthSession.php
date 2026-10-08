<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Model;

/**
 * A Bluesky app signed in through OAuth: the account, the app, what it may
 * do, the DPoP key every token of it is bound to, and the one refresh token
 * that is good, by hash.
 */
final class OAuthSession {
	/**
	 * @param array{method: string, kid?: string, alg?: string, jkt?: string} $clientAuth
	 * @param int $expires when the session ends whatever happens, 0 for never
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $sessionId,
		public readonly string $userId,
		public readonly string $did,
		public readonly string $clientId,
		public readonly array $clientAuth,
		public readonly string $scope,
		public readonly string $dpopJkt,
		public readonly string $refreshHash,
		public readonly int $refreshExpires,
		public readonly int $expires,
		public readonly int $creation = 0,
		public readonly int $lastUsed = 0,
	) {
	}

	/** @return string[] */
	public function scopes(): array {
		return array_values(array_filter(explode(' ', $this->scope)));
	}
}
