<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Xrpc;

use Exception;

/**
 * An XRPC error, as the answer carries it: a status, an error name, a
 * message, and the headers a client needs to act on it (a DPoP challenge).
 */
class XrpcException extends Exception {
	/**
	 * @param array<string, string> $headers
	 */
	public function __construct(
		public readonly int $status,
		public readonly string $error,
		string $message = '',
		public readonly array $headers = [],
	) {
		parent::__construct($message === '' ? $error : $message);
	}

	public static function invalidRequest(string $message): self {
		return new self(400, 'InvalidRequest', $message);
	}

	public static function notImplemented(string $method): self {
		return new self(501, 'MethodNotImplemented', 'This server does not implement ' . $method);
	}

	public static function authenticationRequired(): self {
		return new self(401, 'AuthenticationRequired', 'Authentication required');
	}

	public function toArray(): array {
		return ['error' => $this->error, 'message' => $this->getMessage()];
	}
}
