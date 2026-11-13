<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\AlohaDirectory;

use RuntimeException;

/**
 * The Aloha Directory refused a request, or could not be reached (status 0).
 *
 * `errorCode` is the directory's machine-readable `error`, such as
 * `validation_failed` or `reapply_too_early`; `details` carries what it added,
 * for a validation error the problems keyed by field path.
 */
class DirectoryApiException extends RuntimeException {
	/**
	 * @param array<string, mixed> $details
	 */
	public function __construct(
		private int $status,
		private string $errorCode,
		string $message,
		private array $details = [],
	) {
		parent::__construct($message);
	}

	public function getStatus(): int {
		return $this->status;
	}

	public function getErrorCode(): string {
		return $this->errorCode;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getDetails(): array {
		return $this->details;
	}

	/** Whether trying again later can succeed: unreachable, rate limited, a conflict or a server error. */
	public function isTemporary(): bool {
		return $this->status === 0 || $this->status === 409 || $this->status === 429 || $this->status >= 500;
	}
}
