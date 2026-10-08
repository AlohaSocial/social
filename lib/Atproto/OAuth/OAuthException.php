<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\OAuth;

use Exception;

/**
 * An OAuth error answer (RFC 6749 §5.2): the code a client acts on, a
 * description for its developer, the status, and the headers that go with
 * it — a fresh DPoP nonce, or the challenge of a refused resource request.
 */
class OAuthException extends Exception {
	/**
	 * @param array<string, string> $headers
	 */
	public function __construct(
		public readonly string $error,
		string $description,
		public readonly int $status = 400,
		public readonly array $headers = [],
	) {
		parent::__construct($description);
	}

	public static function invalidRequest(string $description): self {
		return new self('invalid_request', $description);
	}

	public static function invalidGrant(string $description): self {
		return new self('invalid_grant', $description);
	}

	public static function invalidClient(string $description): self {
		return new self('invalid_client', $description, 401);
	}

	public static function invalidDpop(string $description): self {
		return new self('invalid_dpop_proof', $description);
	}

	/** @return array{error: string, error_description: string} */
	public function toArray(): array {
		return ['error' => $this->error, 'error_description' => $this->getMessage()];
	}
}
