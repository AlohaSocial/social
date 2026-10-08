<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Model;

/**
 * A pushed authorization request (PAR) of a Bluesky app: what it asked for,
 * the DPoP key it asked with, and, once the person agreed, who agreed and
 * the hash of the code that was handed out for it.
 */
final class OAuthRequest {
	/**
	 * @param array{method: string, kid?: string, alg?: string, jkt?: string} $clientAuth
	 * @param array{redirect_uri: string, scope: string, state: string, code_challenge: string, login_hint?: string, response_mode?: string} $params
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $requestId,
		public readonly string $clientId,
		public readonly array $clientAuth,
		public readonly array $params,
		public readonly string $dpopJkt,
		public readonly string $userId = '',
		public readonly string $did = '',
		public readonly string $codeHash = '',
		public readonly string $sessionId = '',
		public readonly int $expires = 0,
	) {
	}

	public function isApproved(): bool {
		return $this->codeHash !== '';
	}
}
